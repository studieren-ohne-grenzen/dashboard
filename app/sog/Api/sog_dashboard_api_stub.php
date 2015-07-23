<?php
namespace SOG\Api;

use Silex\Application;
use Silex\Provider\SwiftmailerServiceProvider;
use SOG\Dashboard\ZendLdapServiceProvider;

/**
 * Class SogDashboardApi
 *
 * The external Dashboard API
 *
 * example usage:
 *
 * ```php
 * include $pathToConfigFile;
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
    private $dashboard_url = 'https://studieren-ohne-grenzen.org/dashboard';

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
    }


    /**
     * Create a new user registers it for all relevant services and informs the user and its group admin.
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

        $this->createLdapUser($username, $firstName, $lastName, $email);
        $this->requestGroupMembership($username, $group);
        $this->requestGroupMembership($username, "Allgemein");

        $this->resetPassword($username);
        // TODO: password details are sent here, so $this->notifyNewUser() may be unnecessary

        $this->notifyNewUserAdmin($firstName, $lastName, $email, $group);

        return $username;
    }

    /**
     * Generate a unique username.
     * Tests whether the username is already taken. If so, appends
     * an increasing number until the username does not already exist
     *
     * @param $firstName
     * @param $lastName
     * @return string The unique username
     */
    private function generateUsername($firstName, $lastName)
    {
        //TODO: adapt this to LDAP system
        //TODO: change username format

        $username = strtolower(trim($firstName) . "." . trim($lastName));
        // normalize special chars in username
        $username = str_replace(
            ['ä', 'ö', 'ü', 'ß', ' '],
            ['ae', 'oe', 'ue', 'ss', '.'],
            $username
        );

        $foundUsername = false;
        $i = 0;
        while (!$foundUsername) {
            if ($i === 0) {
                $check = $username;
            } else {
                $check = $username . $i;
            }
            if ($this->app['ldap']->exists($check)) {
                ++$i;
            } else {
                $foundUsername = true;
                if ($i !== 0)
                    $username = $username . $i;
            }
        }

        return $username;
    }

    /**
     * Helper function to add a new user's actual LDAP record
     *
     * @param $username
     * @param $firstName
     * @param $lastName
     * @param $mail
     */
    private function createLdapUser($username, $firstName, $lastName, $mail)
    {
        // TODO: This should be in LdapAdapter?

        // add LDAP record for new user
        // needed LDAP parameters:
        $base_dn = "ou=inactive,ou=people,o=sog-de,dc=sog";
        $dn = "uid=$username,$base_dn";
        $info["dn"] = $dn;
        $info["uid"] = $username;
        $info["cn"] = "$firstName $lastName";
        $info["displayname"] = "$firstName $lastName";
        $info["givenname"] = "$firstName";
        $info["sn"] = "$lastName";
        $info["cn"] = "$firstName $lastName";

        //TODO: instead use the "regular" password reset?
        //$salt = substr(sha1(rand()), 0, 4);
        //$info["userpassword"] = "{SSHA}" . base64_encode( sha1($password . $salt, true) . $salt );

        $sog_mail = "$username@studieren-ohne-grenzen.org";
        $info["mail"] = $sog_mail;
        $info["mail-alternative"] = $mail;
        $info["mailalias"] = $mail;
        $info["mailhomedirectory"] = "/srv/vmail/$sog_mail";
        $info["mailstoragedirectory"] = "maildir:/srv/vmail/$sog_mail/Maildir";
        $info["mailenabled"] = "TRUE";
        $info["mailgidnumber"] = "5000";
        $info["mailuidnumber"] = "5000";
        $info["objectclass"] = "person";
        $info["objectclass"] = "sogperson";
        $info["objectclass"] = "organizationalPerson";
        $info["objectclass"] = "inetOrgPerson";
        $info["objectclass"] = "top";
        $info["objectclass"] = "PostfixBookMailAccount";
        $info["objectclass"] = "PostfixBookMailForward";
    }

    /**
     * Request membership in the given group for a user
     *
     * @param string $uid
     * @param string $group
     */
    public function requestGroupMembership($uid, $group)
    {
        //TODO: implement requestGroupMembership
    }

    /**
     * Reset the password for the given user.
     *
     * @param string $uid
     */
    public function resetPassword($uid)
    {
        //TODO: implement resetPassword
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
<b>Achtung: </b> Der OA-Account des Mitglieds muss erst von dir aktiviert werden. Bitte bestätige am Besten jetzt direkt im <a href='" . $this->dashboard_url . "'>Dashboard</a>, dass " . $firstName . " " . $lastName . " tatsächlich in eurer LG aktiv ist!
<br><br>
Hier die Daten des neuen Mitglieds:<br>";
        $text .= "Vorname: " . $firstName . "<br>";
        $text .= "Nachname: " . $lastName . "<br>";
        $text .= "Mail: " . $email . "<br>";
        $text .= "Standort: " . $group . "<br>";

        $message = \Swift_Message::newInstance()
            ->setSubject('[Studieren Ohne Grenzen] Neuanmeldung in deiner Lokalgruppe')
            ->setFrom([$this->app['mailer.from']])
            ->setTo([strtolower($group) . '@studieren-ohne-grenzen.org'])
            ->setBody($text);
        return $this->app['mailer']->send($message);
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
    function notifyNewUser($firstName, $username, $email, $password)
    {
        $text = '
<html><head><title></title></head><body>
Hallo ' . $firstName . ',<br />
Wir freuen uns sehr, dich als neues Mitglied bei Studieren Ohne Grenzen begrüßen zu dürfen.<br />
<br />
Damit du direkt einsteigen und mitarbeiten kannst, haben wir dir automatisch einen Zugang für unsere Onlineplattform OpenAtrium erstellt. Über diese Plattform tauschen wir wichtige Nachrichten, Informationen und Dateien aus und diskutieren fleißig.<br />
Dein Account wird freigeschaltet, sobald dein Lokalkoordinator bestätigt hat, dass du tatsächlich bei Studieren Ohne Grenzen aktiv bist.
<br /><br />
Um dich bei OpenAtrium einzuloggen, klicke entweder ganz unten in der Fußzeile unserer Webseite auf "OpenAtrium" oder folge diesem Link: <a href="https://www.studieren-ohne-grenzen.org/atrium">https://www.studieren-ohne-grenzen.org/atrium</a><br />
<br />
Du kannst dich dann mit folgenden Daten einloggen:<br />

Benutzername: ' . $username . '<br />
Password:     ' . $password . '<br />
<br />
Viele Grüße,<br />
Das SOG-IT-Team
</body>
</html>
';
        $message = \Swift_Message::newInstance()
            ->setSubject('[Studieren Ohne Grenzen] Zugangsdaten OpenAtrium')
            ->setFrom([$this->app['mailer.from']])
            ->setTo([$email])
            ->setBody($text);
        return $this->app['mailer']->send($message);
    }
}
