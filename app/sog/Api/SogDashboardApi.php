<?php
namespace SOG\Api;

use Silex\Application;
use Silex\Provider\SwiftmailerServiceProvider;
use SOG\Dashboard\DataUtilityServiceProvider;
use SOG\Dashboard\GroupControllerProvider;
use SOG\Dashboard\RandomStringServiceProvider;
use SOG\Dashboard\ZendLdapServiceProvider;

/**
 * Class SogDashboardApi
 *
 * The external Dashboard API
 *
 * example usage:
 *
 * ```php
 * include $pathToConfigFile; // this will make the variable $config available
 * $api = new SogDashboardApi($config);
 * $username = $api->createUser($firstName, $lastName, $email, $group);
 * ```
 *
 * @package SOG\Api
 */
class SogDashboardApi
{
    /**
     * @var Application The Silex application for the API
     */
    private $app;
    /**
     * @var string Full URL to the Dashboard application
     */
    private $dashboard_url = 'https://dashboard.studieren-ohne-grenzen.org';
    /**
     * @var int The default length for a random user password
     */
    private $password_length = 8;

    /**
     * Instantiates a new LdapAdapter for creating and updating relevant entities.
     *
     * @param array $config The configuration as e.g. stored in the config.php file
     */
    public function __construct(array $config)
    {
        $this->app = new Application();

        // LdapAdapter is now available as $this->app['ldap']
        $this->app->register(new ZendLdapServiceProvider(), [
            'ldap.options' => $config['ldap.options']
        ]);

        // SwiftMailer is now available as $this->app['mailer']
        $this->app->register(new SwiftmailerServiceProvider());
        $this->app['mailer.from'] = $config['mailer.from'];
        $this->app['swiftmailer.options'] = $config['swiftmailer.options'];
        $this->app['swiftmailer.use_spool'] = false;

        // can be used for passwords etc, by calling $this->app['random']($length = 8)
        $this->app->register(new RandomStringServiceProvider());

        $this->app->register(new DataUtilityServiceProvider());

        // used to notify group owners
        $this->app->mount('/groups', new GroupControllerProvider());
    }


    /**
     * Create a new user in the LDAP tree. Send notifications to the user and its group admin.
     * The account will be inactive by default. Memberships for the general group and the given $group
     * are also requested.
     *
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $group
     * @return string The new username.
     */
    public function createUser($firstName, $lastName, $email, $group)
    {
        $username = $this->generateUsername($firstName, $lastName);
        $password = $this->app['random']($this->password_length);

        $data = $this->app['ldap']->createMember($username, $password, $firstName, $lastName, $email);

        $this->createSieveForwarding($data['mail'][0], $email);

        $this->requestGroupMembership($username, $group);
        $this->requestGroupMembership($username, 'allgemein');

        $this->notifyNewUser($firstName, $username, $email, $password);
        $this->notifyNewUserAdmin($firstName, $lastName, $email, $group);

        return $username;
    }

    /**
     * Generate a unique username by passing it to the LDAP adapter
     * which executes additional transformations and checks.
     *
     * @param string $firstName
     * @param string $lastName
     * @return string The unique username
     */
    private function generateUsername($firstName, $lastName)
    {
        return $this->app['ldap']->generateUsername(trim($firstName) . " " . trim($lastName));
    }

    /**
     * Shells out to a bash script to generate a sieve script for initial email forwarding.
     * This could be improved, for sure. Note that the apache user needs the NOPASSWD: tag in sudoers(5)
     *
     * @param string $from The mail address for the new member
     * @param string $to The personal mail address to forward messages to
     */
    private function createSieveForwarding($from, $to)
    {
        $cmd_tpl = 'sudo %s/create_sieve_forwarding.sh %s %s';
        $cmd = sprintf($cmd_tpl, __DIR__ . '/../..', escapeshellarg($from), escapeshellarg($to));
        shell_exec($cmd);
    }

    /**
     * Request membership in the given group for a user.
     *
     * @param string $uid The generated unique username for the member
     * @param string $group The CN of the group for which to request the membership
     * @return boolean True, if there isn't already an active request from the user for the group; false otherwise
     */
    public function requestGroupMembership($uid, $group)
    {
        return $this->app['ldap']->requestGroupMembership($uid, $group);
    }

    /**
     * Send a mail to the user.
     * This is send only for OpenAtrium account details, a welcome mail is send through CiviCRM!
     *
     * @param string $firstName
     * @param string $username
     * @param string $email
     * @param string $password
     */
    private function notifyNewUser($firstName, $username, $email, $password)
    {
        $text = '
<html><head><title></title></head><body>
Hallo ' . $firstName . ',<br />
Wir freuen uns sehr, dich als neues Mitglied bei Studieren Ohne Grenzen begrüßen zu dürfen.<br />
<br />
Damit du direkt einsteigen und mitarbeiten kannst, haben wir dir automatisch einen Zugang für unsere Online-Plattform erstellt. Über diese Plattform tauschen wir wichtige Nachrichten, Informationen und Dateien aus und diskutieren auch Lokalgruppen-übergreifend.<br />
<br />
Benutzername: ' . $username . '<br />
Passwort:     ' . $password . '<br />
<br />
Dein Account wird freigeschaltet, sobald dein Lokalkoordinator bestätigt hat, dass du tatsächlich bei Studieren Ohne Grenzen aktiv bist.<br />
<br />
Mit diesen Zugangsdaten kannst du dich auf allen SOG-Systeme einloggen. Insbesondere auf OpenAtrium, unsere wiki-ähnliche Online-Plattform mit allen Informationen und Dokumenten: https://atrium.studieren-ohne-grenzen.org<br />
<br />
Eine Übersicht deiner Daten und Gruppen gibt dir das Dashboard: https://dashboard.studieren-ohne-grenzen.org<br />
Viele Grüße,<br />
Das SOG-IT-Team
</body>
</html>
';
        $message = \Swift_Message::newInstance()
            ->setSubject('[Studieren Ohne Grenzen] Zugangsdaten OpenAtrium')
            ->setFrom([$this->app['mailer.from']])
            ->setTo([$email => $firstName])
            ->setBody($text, 'text/html');
        return $this->app['mailer']->send($message);
    }

    /**
     * Send email to group administrator to inform about new member
     *
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $group
     * @return int Number of accepted recipients
     */
    private function notifyNewUserAdmin($firstName, $lastName, $email, $group)
    {
        $text = "Soeben hat sich ein neues Mitglied fuer Deine Lokalgruppe angemeldet.<br>
Das neue Mitglied ist schon auf dem Lokalgruppen-Verteiler eingetragen und hat einen OpenAtrium-Account erhalten.<br><br>
<b>Achtung:</b> Der SOG-Account des Mitglieds muss erst von dir aktiviert werden. Bitte bestätige am Besten jetzt <a href='" . $this->dashboard_url . "'>direkt im Dashboard</a>, dass " . $firstName . " " . $lastName . " tatsächlich in eurer LG aktiv ist!
<br><br>
Hier die Daten des neuen Mitglieds:<br>";
        $text .= "Vorname: " . $firstName . "<br>";
        $text .= "Nachname: " . $lastName . "<br>";
        $text .= "Mail: " . $email . "<br>";
        $text .= "Standort: " . $group . "<br>";

        return $this->app['notify_owners']($group, '[Studieren Ohne Grenzen] Neuanmeldung in deiner Lokalgruppe', $text);
    }

    /**
     * Update the mail-alternative field for the given UID
     *
     * @param string $uid The user id for which to update
     * @param string $email The new alternative mail address
     */
    public function updateAlternativeMail($uid, $email)
    {
        $dn = $this->app['ldap']->findUserDN($uid);
        $this->app['ldap']->updateEmail($dn, $email);
    }
}
