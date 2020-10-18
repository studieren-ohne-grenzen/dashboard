<?php
namespace SOG\Api;

use Silex\Application;
use Silex\Provider\SwiftmailerServiceProvider;
use SOG\Dashboard\DataUtilityServiceProvider;
use SOG\Dashboard\GroupControllerProvider;
use SOG\Dashboard\RandomStringServiceProvider;
use SOG\Dashboard\ZendLdapServiceProvider;
use Ahc\Jwt\JWT;

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
     * @var string Full URL to the Vogelnest application
     */
    private $vogelnest_url = 'https://vogelnest.studieren-ohne-grenzen.org';
    /**
     * @var string Full URL to the Vogelnest-API
     */
    private $vogelnest_api_url = 'https://vogelnest-api.studieren-ohne-grenzen.org';
    /**
     * @var int The default length for a random initial user password - replaced after email verification
     */
    private $password_length = 24;

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
        // 2020-03-24: Fix for sending mails with the new server
        $this->app['swiftmailer.transport'] = $this->app->share(function($app) {
          return new \Swift_SmtpTransport($app['swiftmailer.options']['host'], $app['swiftmailer.options']['port'], 'tls');
        });

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

        // $this->createSieveForwarding($data['mail'][0], $email);

        $this->requestGroupMembership($username, $group);
        $this->requestGroupMembership($username, 'allgemein');

        $this->notifyNewUser($firstName, $username, $email);
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
     * Send a mail to the user.
     * This is send only for OpenAtrium account details, a welcome mail is send through CiviCRM!
     *
     * @param string $firstName
     * @param string $username
     * @param string $email
     */
    private function notifyNewUser($firstName, $username, $email)
    {
        $token = (new JWT($dashboard_config['jwt_key'], 'HS256', 3600))
                  ->encode(['type' => 'initial_confirmation', 'username' => $username, 'email' => $email]));

        $html = "
<html><head><title></title></head><body>
Hallo " . $firstName . ",<br />
Wir freuen uns sehr, dich als neues Mitglied bei Studieren Ohne Grenzen begrüßen zu dürfen.<br />
<br />
Damit du direkt einsteigen und mitarbeiten kannst, haben wir dir automatisch einen Zugang für unsere Online-Plattformen erstellt.
Über diese Plattformen tauschen wir wichtige Nachrichten, Informationen und Dateien aus und diskutieren auch Lokalgruppen-übergreifend.<br />
<br />
Dein Benutzername ist: " . $username . "<br />
Bitte öffne folgenden Link, um deine Mailadresse zu bestätigen und ein Passwort zu vergeben:<br />
<a href='" . $this->$vogelnest_api_url . "/confirm?key=" . $token . "'>Account bestätigen</a><br />
<br />
Dein Account wird freigeschaltet, sobald dein Lokalkoordinator bestätigt hat, dass du tatsächlich bei Studieren Ohne Grenzen aktiv bist.<br />
<br />
Mit deinem Benutzernamen und dem von dir vergebenen Passwort kannst du dich auf allen SOG-Systeme einloggen.<br />
<br />
Eine Übersicht deiner Daten und Gruppen gibt dir das Vogelnest: " . $this->$vogelnest_url . "<br />
Viele Grüße,<br />
Das SOG-IT-Team
</body>
</html>
";

        $text = "
Hallo " . $firstName . ",\n
Wir freuen uns sehr, dich als neues Mitglied bei Studieren Ohne Grenzen begrüßen zu dürfen.\n
\n
Damit du direkt einsteigen und mitarbeiten kannst, haben wir dir automatisch einen Zugang für unsere Online-Plattformen erstellt.
Über diese Plattformen tauschen wir wichtige Nachrichten, Informationen und Dateien aus und diskutieren auch Lokalgruppen-übergreifend.\n
\n
Dein Benutzername ist: " . $username . "\n
Bitte öffne folgenden Link, um deine Mailadresse zu bestätigen und ein Passwort zu vergeben:\n
" . $this->$vogelnest_api_url . "/confirm?key=" . $token . "\n
\n
Dein Account wird freigeschaltet, sobald dein Lokalkoordinator bestätigt hat, dass du tatsächlich bei Studieren Ohne Grenzen aktiv bist.\n
\n
Mit deinem Benutzernamen und dem von dir vergebenen Passwort kannst du dich auf allen SOG-Systeme einloggen.\n
\n
Eine Übersicht deiner Daten und Gruppen gibt dir das Vogelnest: " . $this->$vogelnest_url . "\n
Viele Grüße,\n
Das SOG-IT-Team
";
        $message = \Swift_Message::newInstance()
            ->setSubject('[Studieren Ohne Grenzen] Zugangsdaten')
            ->setFrom([$this->app['mailer.from']])
            ->setTo([$email => $firstName])
            ->setBody($html, 'text/html');
            ->addPart($text, 'text/plain');
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
        $html = "
<html><head><title></title></head><body>
Soeben hat sich" . $firstName . " " . $lastName . " für Deine Lokalgruppe " . $group . "angemeldet.<br />
Das neue Mitglied ist schon auf dem Lokalgruppen-Verteiler eingetragen und hat einen Account erhalten.<br />
<b>Achtung:</b> Der SOG-Account des Mitglieds muss erst von dir aktiviert werden.
Bitte bestätige am Besten jetzt <a href='" . $this->vogelnest_url . "/groups/" . $group . "'>direkt im Vogelnest</a>, dass " . $firstName . " " . $lastName . " tatsächlich in eurer LG aktiv ist!<br />
Viele Grüße,<br />
Das SOG-IT-Team
</body>
</html>
";

        $text = "
Soeben hat sich" . $firstName . " " . $lastName . " für Deine Lokalgruppe " . $group . "angemeldet.\n
Das neue Mitglied ist schon auf dem Lokalgruppen-Verteiler eingetragen und hat einen Account erhalten.\n
Achtung: Der SOG-Account des Mitglieds muss erst von dir aktiviert werden.\n
Bitte bestätige am Besten jetzt direkt im Vogelnest, dass " . $firstName . " " . $lastName . " tatsächlich in eurer LG aktiv ist:\n
" . $this->vogelnest_url . "/groups/" . $group . "\n
Viele Grüße,\n
Das SOG-IT-Team
";

        return $this->app['notify_owners']($group, '[Studieren Ohne Grenzen] Neuanmeldung in deiner Lokalgruppe', $html, $text);
    }


    /**
     * Updates the password for the given user.
     *
     * @param string $uid The user for which to update the password
     * @param string $old_password The old password, we need to bind to the DN first
     * @param string $new_password The new password
     * @throws LdapException
     */
    public function updateUserPassword($uid, $old_password, $new_password) {
      $dn = $this->app['ldap']->findUserDN($uid);
      $this->app['ldap']->updatePassword($dn, $old_password, $new_password);
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
     * Remove a membership request for $group.
     *
     * @param string $uid The username of the user who has done the request
     * @param string $group The group for which the request shall be removed, we expect the `ou` value
     * @throws LdapException If group can't be found or update wasn't successful
     * @return boolean True, if pending contained $user and the entry has been deleted; false otherwise
     */
    public function dropMembershipRequest($uid, $group) {
      return $this->app['ldap']->dropMembershipRequest($uid, $group);
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

    /**
     * Checks if the passed user is the owner of a group
     *
     * @param string $group The group
     * @param string $uid The user
     * @return bool True if the given user is a owner of the group, false otherwise.
     * @throws LdapException
     */
    public function isOwnerOfGroup($group, $uid) {
      $groups = $this->app['ldap']->getOwnedGroups($uid);
      foreach($groups as $group) {
        if ($group['ou'] === $group) return true;
      }
      return false;
    }

    /**
     * Get members of group
     *
     * @param string $group
     */
    public function getOwnersOfGroup($group)
    {
        return $this->app['ldap']->getOwners($group);
    }

    /**
     * Get the current members of a group
     *
     * @param string $group
     */
    public function getMembersOfGroup($group)
    {
        $this->app['ldap']->getMembers($group);
    }

    /**
     * Get the pending members of a group
     *
     * @param string $group
     */
    public function getPendingMembersOfGroup($group)
    {
        $this->app['ldap']->getMembers($group, 'pending');
    }

    /**
     * Add a user as an owner
     *
     * @param string $group
     * @param string $userID
     */
    public function addMemberToGroup($group, $userID)
    {
        $dnOfUser = $this->app['ldap']->getDnOfUser($userID);
        $this->app['ldap']->addToGroup($dnOfUser, $group, 'member');
    }

    /**
     * Removes the current user from the current group as owner.
     *
     * @param string $group
     * @param string $userID
     */
    public function removeMemberFromGroup($group, $userID)
    {
        $dnOfUser = $this->app['ldap']->getDnOfUser($userID);
        $this->app['ldap']->removeFromGroup($dnOfUser, $group, 'member');
    }

    /**
     * Add a user as an owner
     *
     * @param string $group
     * @param string $userID
     */
    public function addOwnerToGroup($group, $userID)
    {
        $dnOfUser = $this->app['ldap']->getDnOfActivePerson($userID);
        $this->app['ldap']->addToGroup($dnOfUser, $group, 'owner');
    }

    /**
     * Removes the current user from the current group as owner.
     *
     * @param string $group
     * @param string $userID
     */
    public function removeOwnerFromGroup($group, $userID)
    {
        $dnOfUser = $this->app['ldap']->getDnOfActivePerson($userID);
        $this->app['ldap']->removeFromGroup($dnOfUser, $group, 'owner');
    }

    /**
     * Move a (new) inactive member to the active subtree.
     *
     * @param string $uid The username for the member to be activated
     * @throws LdapException
     */
    public function activateUser($uid) {
      $this->app['ldap']->activateMember($uid);
    }

    /**
     * Moves the given user to the inactive tree
     *
     * @param string $uid
     */
    public function deactivateUser($uid) {
      $this->app['ldap']->deactivateMember($uid);
    }

    /**
     * Delete user entirely
     *
     * @param string $uid The generated unique username for the member
     */
    public function deleteUser($uid)
    {
        return $this->app['ldap']->deleteUser($uid);
    }

    public function ensureEmailAliasesForAllGroups() {
      $output = '';
      foreach($groups as $group) {
        $attrs = $this->app['ldap']->getEntry($group['dn'], ['mail', 'ou']);
        $output .= $group['dn'].' '.var_export($attrs, true)."\r\n";
        if (!isset($attrs['mail'])) continue;
        $groupMail = $attrs['mail'][0];
        $owners = $this->app['ldap']->getOwners($attrs['ou'][0])->toArray();
        foreach ($owners[0]['owner'] as $owner) {
          $ownerEntry = $this->app['ldap']->getEntry($owner);
          if (!isset($ownerEntry) || strpos($owner, 'guest') !== FALSE) continue;
          $output .= $attrs['ou'][0]." : $owner -> $groupMail\r\n";
          $app['ldap']->addEmailAlias($owner, $groupMail);
        }
      }
      return $output;
    }
}
