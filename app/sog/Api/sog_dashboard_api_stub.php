<?php

/**
 * The external Dashboard API
 *
 * example usage:
 *      $api = new SogDashboardApi();
 *      $username = $api->createUser($firstName, $lastName, $email, $lokalgruppe);
 */
class SogDashboardApi
{
    /**
     * Create a new user registers it for all relevant services and informs the user and its group admin.
     */
    public function createUser($firstName, $lastName, $email, $group) {
        $username = $this->generateUsername($firstName, $lastName);
        
        $this->createLdapUser($username, $firstName, $lastName, $email);
        $this->requestGroupMembership($username, $group);
        $this->requestGroupMembership($username, "Allgemein");
        
        $this->resetPassword($username);
        // password details are sent here, so $this->notifyNewUser() may be unnecessary
        
        $this->notifyNewUserAdmin($firstName, $lastName, $email, $group);

        return $username;
    }

    /**
     * Reset the password for the given user.
     */
    public function resetPassword($uid) {
        //TODO: implement resetPassword
    }

    /**
     * Request membership in the given group for a user
     */
    public function requestGroupMembership($uid, $group) {
        //TODO: implement requestGroupMembership
    }





    /**
     * Generate a unique username.
     * Tests whether the username is already taken. If so, appends 
     * an increasing number until the username does not already exist
     */
    private function generateUsername($firstName, $lastName) {
        //TODO: adapt this to LDAP system
        
        $username = trim($firstName) . "." . trim($lastName);

        $foundUsername = false;
        $i = 0;
        while (!$foundUsername) {
            if ($i == 0) {
                $param = $username;
            } else {
                $param = $username.$i;
            }
            $result = db_result(db_query("SELECT uid FROM `users` WHERE name = '%s'", $param));

            if ($result == FALSE) {
                $foundUsername = true;
                if ($i != 0)
                    $username = $username . $i;
            } else {
                ++$i;
            }
        }

        // normalize special chars in username
        //TODO: change username format
        /*$usernameEasy = str_replace("ä", "ae", $username);
        $usernameEasy = str_replace("ö", "oe", $usernameEasy);
        $usernameEasy = str_replace("ü", "ue", $usernameEasy);
        $usernameEasy = str_replace("ß", "ss", $usernameEasy);
        $usernameEasy = str_replace(" ", ".", $usernameEasy);*/
        
        return $username;
    }


    /**
     * Helper function to add a new user's actual LDAP record
     */
    private function createLdapUser($username, $firstName, $lastName, $mail) {

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
     * Send a mail to the user.
     * This is send only for OpenAtrium account details, a welcome mail is send through CiviCRM!
     */
    function notifyNewUser($firstName, $username, $email, $password) {
        //TODO: is this mail needed or are we just handling it through $this->resetPassword ?

        $text = '
<html><head><title></title></head><body>
Hallo ' . $firstName . ',<br />
Wir freuen uns sehr, dich als neues Mitglied bei Studieren ohne Grenzen begrüßen zu dürfen.<br />
<br />
Damit du direkt einsteigen und mitarbeiten kannst, haben wir dir automatisch einen Zugang für unsere Onlineplattform OpenAtrium erstellt. Über diese Plattform tauschen wir wichtige Nachrichten, Informationen und Dateien aus und diskutieren fleißig.<br />
Dein Account wird freigeschaltet, sobald dein Lokalkoordinator bestätigt hat, dass du tatsächlich bei Studieren Ohne Grenzen aktiv bist.
<br /><br />
Um dich bei OpenAtrium einzuloggen, klicke entweder ganz unten in der Fußzeile unserer Webseite auf "OpenAtrium" oder folge diesem Link: http://www.studieren-ohne-grenzen.org/atrium <br />
<br />
Du kannst dich dann mit folgenden Daten einloggen:<br />

Benutzername: ' . $username . '<br />
Password:     ' . $password . '<br />
<br />
Viele Grüße,<br />
Das SOG-IT-Team<br />
it@studieren-ohne-grenzen.org
</body>
</html>
';
        $text = wordwrap($text, 70);
        # $extra= 'From: it@studieren-ohne-grenzen.org' . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=ISO-8859-1' . "\r\n" . 'Content-Transfer-Encoding: quoted-printable';
        $extra = "From: it@studieren-ohne-grenzen.org\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: 8bit";
        @mail($email, "Zugangsdaten OpenAtrium - Studieren Ohne Grenzen", $text, $extra);
    }


    /**
     * Send email to group administrator to inform about new member
     */
    private function notifyNewUserAdmin($firstname, $lastname, $email, $standort) {
        //TODO: This mail for newly registered member in addition to a group join request mail? Or is this one unnecessary now?

            $text = "Soeben hat sich ein neues Mitglied fuer Deine Lokalgruppe angemeldet.<br>
Das neue Mitglied ist schon auf dem Lokalgruppen-Verteiler eingetragen und hat einen OpenAtrium-Account erhalten.<br><br>
<b>Achtung: </b> Der OA-Account des Mitglieds muss erst von der Mitgliederbetreuung aktiviert werden. Bitte bestätige am Besten jetzt direkt formlos per Email an <mitglieder@studieren-ohne-grenzen.org>, dass ".$vornfirstnameame." ".$lastname." tatsächlich in eurer LG aktiv ist!
<br><br>
Hier die Daten des neuen Mitglieds:<br>";
            $text .= "Vorname: ".$firstname."<br>";
            $text .= "Nachname: ".$lastname."<br>";
            $text .= "Mail: ".$email."<br>";
            $text .= "Standort: ".$standort."<br>";
            $extra = "From:mitglieder@studieren-ohne-grenzen.org\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: 8bit";
            @mail(strtolower($standort)."@studieren-ohne-grenzen.org","Neuanmeldung in deiner Lokalgruppe",$text,$extra);
    }
}
