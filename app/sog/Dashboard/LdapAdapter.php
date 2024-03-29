<?php
namespace SOG\Dashboard;

use Zend\Ldap\Attribute;
use Zend\Ldap\Collection;
use Zend\Ldap\Dn;
use Zend\Ldap\Exception\LdapException;
use Zend\Ldap\Ldap;

/**
 * This class provides an adapter for nicer querying just abobe the concrete Zend\Ldap implementation.
 *
 * Class LdapAdapter
 * @package SOG\Dashboard
 */
class LdapAdapter extends Ldap
{
    /**
     * @var string The algorithm used for password generation.
     */
    private $password_algorithm = Attribute::PASSWORD_HASH_SSHA;

    const DN_GROUPS = 'ou=groups,o=sog-de,dc=sog';
    const DN_PEOPLE = 'ou=people,o=sog-de,dc=sog';
    const DN_PEOPLE_ACTIVE = 'ou=active,ou=people,o=sog-de,dc=sog';
    const DN_PEOPLE_INACTIVE = 'ou=inactive,ou=people,o=sog-de,dc=sog';
    const DN_PEOPLE_GUESTS = 'ou=guests,ou=people,o=sog-de,dc=sog';

    public function getDnOfGroup($ou) {
      return 'ou='.$ou.','.self::DN_GROUPS;
    }

    public function getDnOfActivePerson($uid) {
      return 'uid='.$uid.','.self::DN_PEOPLE_ACTIVE;
    }

    public function getDnOfInactivePerson($uid) {
      return 'uid='.$uid.','.self::DN_PEOPLE_INACTIVE;
    }

    public function getDnOfGuest($uid) {
      return 'uid='.$uid.','.self::DN_PEOPLE_GUESTS;
    }

    /**
     * Returns all groups (OUs) with their common names
     *
     * @param array $fields A list of fields we want to return from the search
     * @return bool|\Zend\Ldap\Collection
     * @throws LdapException
     */
    public function getGroups($fields = ['cn'])
    {
        $results = $this->search(
            '(objectClass=groupOfNames)',
            self::DN_GROUPS,
            self::SEARCH_SCOPE_ONE,
            $fields,
            'cn'
        );
        return $results;
    }

    /**
     * Retrieves an alphabetically sorted list of all users, by recusively search the tree.
     * This will find users in ou=active and ou=inactive
     * Note that this search is subject (as all other searches) to the maximum results returned by the LDAP server,
     * it might not contain *all* users.
     *
     * @return Collection
     * @throws LdapException
     */
    public function getAllUsers()
    {
        $results = $this->search(
            'objectClass=inetOrgPerson',
            self::DN_PEOPLE,
            self::SEARCH_SCOPE_SUB,
            ['cn', 'uid', 'mail', 'dn'],
            'cn'
        );
        return $results;
    }

    /**
     * Adds the DN to the given group
     *
     * @param string $userID
     * @param string $ou group ou
     * @param string $role A groupOfNames attribute, such as owner, pending, member (default)
     * @throws LdapException
     */
    public function addToGroup($userID, $ou, $role = 'member')
    {
        $dnOfGroup = $this->getDnOfGroup($ou);
        $dnOfUser =  $this->findUserDN($userID);
        $entry = $this->getEntry($dnOfGroup);
        Attribute::setAttribute($entry, $role, $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Removes the DN from the given group
     *
     * @param string $userID
     * @param string $ou ou of the group
     * @param string $role A groupOfNames attribute, such as owner, pending, member (default)
     * @throws LdapException
     */
    public function removeFromGroup($userID, $ou, $role = 'member')
    {
        $dnOfGroup = $this->getDnOfGroup($ou);
        $dnOfUser =  $this->findUserDN($userID);
        $entry = $this->getEntry($dnOfGroup);
        Attribute::removeFromAttribute($entry, $role, $dnOfUser);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Checks whether the given user is member of the given group.
     *
     * @param string $uid
     * @param string $dnOfGroup
     * @return bool True if user is member of group, false otherwise
     * @throws LdapException
     */
    public function isMemberOfGroup($uid, $dnOfGroup)
    {
        $user_dn = $this->findUserDN($uid);
        return (
            $this->search(
                sprintf('member=%s', $user_dn),
                $dnOfGroup,
                self::SEARCH_SCOPE_BASE
            )->count() > 0);
    }

    /**
     * Retrieve the members of the given group with their requested details
     *
     * @param string $group_ou OU of the group
     * @param array $details Attributes to fetch
     * @return array The details of all owners indexed by their `uid`
     */
    public function getOwnerDetails($group_ou, $details = ['mail'])
    {
        $owners = $this->getOwners($group_ou)->toArray();
        // make sure to include uid, as we want to index the returned array by it
        $details = array_merge($details, ['uid']);
        $result = [];
        if (empty($owners) === false) {
            foreach ($owners[0]['owner'] as $owner) {
                try {
                    // retrieve details and add to result array
                    $entry = $this->getEntry($owner, $details, true);
                    $result[Attribute::getAttribute($entry, 'uid', 0)] = $entry;
                } catch (LdapException $ex) {
                    // it's ok.
                }
            }
        }
        return $result;
    }

    /**
     * Retrieve the members of the given group
     *
     * @param string $group_ou OU of the group
     * @return bool|Collection
     */
    public function getOwners($group_ou)
    {
        return $this->getMembers($group_ou, ['owner']);
    }

    /**
     * Retrieves all members for the given group CN
     *
     * @param string $group_ou The common name of the group for which we want to retrieve the members
     * @param array $fields A list of fields we want to return from the search
     * @return bool|\Zend\Ldap\Collection
     * @throws LdapException
     */
    public function getMembers($group_ou, $fields = ['member'])
    {
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(ou=%s))', $group_ou),
            self::DN_GROUPS,
            self::SEARCH_SCOPE_ONE,
            $fields,
            $fields[0]
        );
        return $results;
    }

    /**
     * Attempts to retrieve the member or guest (DN) with the given mail address.
     *
     * @param string $mail The given mail address
     * @param string $attribute The name of the mail attribute to check
     * @return bool|string False if the search returned no results, the users' attributes otherwise.
     * @throws LdapException
     */
    public function getMemberByMail($mail, $attribute = 'mail-alternative')
    {
        $results = $this->search(
            sprintf('(&(objectClass=inetOrgPerson)(%s=%s))', $attribute, $mail),
            self::DN_PEOPLE,
            self::SEARCH_SCOPE_SUB,
            ['dn', 'uid', 'cn', 'displayName', $attribute],
            'dn'
        );
        if ($results->count() > 0)
            return $results->getFirst();
        else
            return false;
    }

    /**
     * This method can be used to assign the ROLE_GROUP_ADMIN role to a user. It checks if the given DN is a owner
     * of any group. Further checks should be done somewhere else.
     *
     * @param string $user_dn The user DN for which we want to check
     * @return bool True if the given user is a owner of any group, false otherwise.
     * @throws LdapException
     */
    public function isOwner($uid)
    {
        $result = $this->getOwnedGroups($uid);
        // we don't care about specifics, we only want to know if the user is owner of any group
        return ($result != null && $result->count() > 0);
    }

    /**
     * Returns the groups owned by the user with the given dn
     *
     * @param string $uid The uid for which we want to check
     * @return bool|Collection The groups owned by the user
     */
    public function getOwnedGroups($uid)
    {
        return $this->getMemberships($uid, ['cn', 'ou'], 'owner');
    }

    /**
     * Retrieves all memberships for the given DN
     *
     * @param string $userID
     * @param array $fields A list of fields we want to return from the search
     * @param string $attribute The attribute which we use for filtering
     * @return bool|\Zend\Ldap\Collection
     * @throws LdapException
     */
    public function getMemberships($uid, $fields = ['cn'], $attribute = 'member')
    {
        $user_dn = $this->findUserDN($uid);
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(%s=%s))', $attribute, $user_dn),
            self::DN_GROUPS,
            self::SEARCH_SCOPE_ONE,
            $fields,
            'cn'
        );
        return $results;
    }

    /**
     * Adds an email alias the given DN.
     *
     * @param string $dn User's dn
     * @param string $newEmail The new email address
     * @throws LdapException
     */
    public function addEmailAlias($dn, $newEmail)
    {
        $dn = $this->findUserDN($dn);
        $entry = $this->getEntry($dn);
        Attribute::setAttribute($entry, 'mailAlias', $newEmail, true);
        Attribute::removeDuplicatesFromAttribute($entry, 'mailAlias');
        $this->update($dn, $entry);
    }

    /**
     * Removes the email alias for the given DN.
     *
     * @param string $dn User's dn
     * @param string $newEmail The new email address
     * @throws LdapException
     */
    public function removeEmailAlias($dn, $mail)
    {
        $dn = $this->findUserDN($dn);
        $entry = $this->getEntry($dn);
        Attribute::removeFromAttribute($entry, 'mailAlias', $mail);
        $this->update($dn, $entry);
    }

    /**
     * Updates the email for the given DN.
     *
     * @param string $dn User's dn
     * @param string $newEmail The new email address
     * @throws LdapException
     */
    public function updateEmail($dn, $newEmail)
    {
        $entry = $this->getEntry($dn);
        Attribute::setAttribute($entry, 'mail-alternative', $newEmail);
        $this->update($dn, $entry);
    }


    /**
     * Updates the password for the given DN. Only the DN him/herself can change the password.
     * Thus we need to bind as the DN first, update the password and then rebind to the privileged user.
     *
     * @param string $dn The DN for which to update the password
     * @param string $old_password The old password, we need to bind to the DN first
     * @param string $new_password The new password
     * @throws LdapException
     */
    public function updatePassword($dn, $old_password, $new_password)
    {
        try {
            $this->bind($dn, $old_password);
            $attributes = [];
            Attribute::setPassword($attributes, $new_password, $this->password_algorithm);
            $this->update($dn, $attributes);
        } catch (LdapException $ex) {
            throw $ex;
        } finally {
            // rebind to privileged user
            $this->bind();
        }
    }

    /**
     * Force a password update using the privileged user, this is used by the password reset process.
     *
     * @param string $dn
     * @param string $new_password
     * @throws LdapException
     */
    public function forceUpdatePassword($dn, $new_password)
    {
        $attributes = [];
        Attribute::setPassword($attributes, $new_password, $this->password_algorithm);
        $this->update($dn, $attributes);
    }


    /**
     * Adds a new object with the given parameters to the ou=inactive subtree.
     *
     * @param string $username The username of the new member
     * @param string $password The password in plaintext
     * @param string $firstName The first name of the new member
     * @param string $lastName The last name of the new member
     * @param string $mail The users' personal email address
     * @throws LdapException
     * @return array All stored attributes of the new member
     */
    public function createMember($username, $password, $firstName, $lastName, $mail)
    {
        $dn = $this->getDnOfInactivePerson($username);
        $sog_mail = sprintf('%s@studieren-ohne-grenzen.org', $username);
        $sog_mail_alias = sprintf('%s@s-o-g.org', $username);
        $info = [];

        // core data
        Attribute::setAttribute($info, 'dn', $dn);
        Attribute::setAttribute($info, 'uid', $username);
        Attribute::setAttribute($info, 'cn', $firstName . " " . $lastName);
        Attribute::setAttribute($info, 'displayName', $firstName);
        Attribute::setAttribute($info, 'givenName', $firstName);
        Attribute::setAttribute($info, 'sn', $lastName);

        // password
        Attribute::setPassword($info, $password, $this->password_algorithm);

        // meta data
        Attribute::setAttribute($info, 'mail', $sog_mail);
        Attribute::setAttribute($info, 'mail-alternative', $mail);
        Attribute::setAttribute($info, 'mailAlias', $sog_mail_alias);
        Attribute::setAttribute($info, 'mailHomeDirectory', sprintf('/srv/vmail/%s', $sog_mail));
        Attribute::setAttribute($info, 'mailStorageDirectory', sprintf('maildir:/srv/vmail/%s/Maildir', $sog_mail));
        Attribute::setAttribute($info, 'mailEnabled', 'TRUE');
        Attribute::setAttribute($info, 'mailGidNumber', 5000);
        Attribute::setAttribute($info, 'mailUidNumber', 5000);
        Attribute::setAttribute($info, 'objectClass', [
            'person',
            'sogperson',
            'organizationalPerson',
            'inetOrgPerson',
            'top',
            'PostfixBookMailAccount',
            'PostfixBookMailForward'
        ]);

        $this->add($dn, $info);
        return $info;
    }

    /**
     * Adds a new object with the given parameters to the ou=inactive subtree.
     *
     * @param string $dn The dn of the member
     * @throws LdapException
     */
    public function deleteUser($uid)
    {
        $dn = $this->getDnOfActivePerson($uid);
        $groups = $this->getMemberships($dn, ['ou']);
        foreach($groups as $group) {
          $ou = $group['ou'][0];
          $this->removeFromGroup($dn, $ou, 'member');
        }
        $ownedGroups = $this->getOwnedGroups($dn);
        foreach($ownedGroups as $group) {
          $ou = $group['ou'][0];
          $this->removeFromGroup($dn, $ou, 'owner');
        }
        $this->delete($dn);
    }

    /**
     * Create a guest user, reserved for non-members who should receive messages to a mailing list.
     *
     * @param string $name The name of the guest
     * @param string $mail The guests' email address
     * @return array The LDAP attributes for the guest
     * @throws LdapException If adding the DN wasn't successful
     */
    public function createGuest($name, $mail)
    {
        $username = 'guest'.$this->generateUsername($name);
        $dn = $this->getDnOfGuest($username);
        $info = [];

        // core data
        Attribute::setAttribute($info, 'dn', $dn);
        Attribute::setAttribute($info, 'uid', $username);
        Attribute::setAttribute($info, 'cn', "Guest ".$name);
        Attribute::setAttribute($info, 'displayName', "Guest ".$name);
        Attribute::setAttribute($info, 'sn', "Guest ".$name);
        Attribute::setAttribute($info, 'cn', "Guest ".$name);

        // meta data
        Attribute::setAttribute($info, 'mail', $mail);
        Attribute::setAttribute($info, 'objectClass', [
            'inetOrgPerson',
            'top'
        ]);

        $this->add($dn, $info);
        return $info;
    }

    /**
     * Generates a unique username by replacing some special characters, converting to lowercase and then
     * appending an optional number if the username already exists.
     *
     * @param string $name The given name for the member
     * @return string The generated unique UID
     */
    public function generateUsername($username)
    {
        // Replace the German special characters with their nice alternatives
        // Of course, this does not replace things like é, we will do that later.
        // We have to have the rule Ö -> Oe because strtolower only lowers ASCII.
        $username = str_replace(
            ['ä',  'ö',  'ü',  'ß',  'Ü',  'Ö',  'Ä',  'ẞ'],
            ['ae', 'oe', 'ue', 'ss', 'Ue', 'Oe', 'Ae', 'Ss'],
            $username
        );
        // Remove the rest of the special characters and replace them with alternatives.
        // This would only replace ü with u for instance, not ue, so the rule above make sense.
        $username = transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $username);
        // Lowercase - important that this happens only when everything is now ASCII
        $username = strtolower($username);
        // Replace spaces in names with dots
        $username = str_replace([' '], ['.'], $username);

        $suffix = 2;
        $check = $username;
        while ($this->usernameExists($check) === true) {
            $check = $username . $suffix;
            $suffix++;
        }

        return $check;
    }

    /**
     * Checks if the given username is already taken, looks in the active and inactive subtree
     *
     * @param string $uid Does this username already exist?
     * @return bool True if member exists, false otherwise.
     */
    public function usernameExists($uid)
    {
        return ($this->exists($this->getDnOfActivePerson($uid)) ||
            $this->exists($this->getDnOfInactivePerson($uid)) ||
            $this->exists($this->getDnOfGuest($uid)));
    }

    /**
     * Allowing the given user to access the given group. This will move the entry from the `pending` to the `member`
     * field.
     *
     * @param string $uid The username for which to approve the membership in $group
     * @param string $group The group for which the membership of $user is approved, we expect the `ou` value
     * @throws LdapException
     */
    public function approveGroupMembership($uid, $group)
    {

        $dnOfGroup = $this->getDnOfGroup($group);
        $entry = $this->getEntry($dnOfGroup);
        if (is_null($entry)) {
            throw new LdapException($this, sprintf('Can\'t find group %s', $group));
        }
        $dnOfUser = $this->findUserDN($uid);
        Attribute::removeFromAttribute($entry, 'pending', $dnOfUser);
        Attribute::setAttribute($entry, 'member', $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Returns the dn of the first user with the given uid
     *
     * @param string $uid The uid of the user
     * @return string dn of the first user with the given uid
     * @throws LdapException
     */
    public function findUserDN($uid)
    {
        // return early if we deal with a DN anyways
        if (Dn::checkDn($uid)) {
            return $uid;
        }
        $results = $this->search(
            sprintf('(&(objectClass=inetOrgPerson)(uid=%s))', $uid),
            self::DN_PEOPLE,
            self::SEARCH_SCOPE_SUB,
            ['dn'],
            'dn'
        );
        return $results->getFirst()['dn'];
    }

    /**
     * Move a (new) inactive member to the active subtree. Being in ou=active is required for certain actions such as
     * accessing the Dashboard or Open Atrium.
     *
     * @param string $uid The username for the member to be activated
     * @throws LdapException
     */
    public function activateMember($uid)
    {
        $active = $this->getDnOfActivePerson($uid);
        $inactive = $this->getDnOfInactivePerson($uid);
        $this->move($inactive, $active);

        $this->refreshPendingRequests($inactive, $active);
    }

    /**
     * For all groups, update the pending field from the inactive DN to the active DN.
     *
     * @param string $from Inactive DN
     * @param string $to Active DN
     * @throws LdapException
     */
    private function refreshPendingRequests($from, $to)
    {
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(pending=%s))', $from),
            self::DN_GROUPS,
            self::SEARCH_SCOPE_ONE,
            ['ou']
        );

        foreach ($results as $group) {
            $this->dropMembershipRequest($from, Attribute::getAttribute($group, 'ou', 0));
            $this->requestGroupMembership($to, Attribute::getAttribute($group, 'ou', 0));
        }
    }

    /**
     * Remove a membership request for $group. This will remove the user's dn from the `pending` attribute of the $group
     *
     * @param string $uid The username of the user who has done the request
     * @param string $group The group for which the request shall be removed, we expect the `ou` value
     * @throws LdapException If group can't be found or update wasn't successful
     * @return boolean True, if pending contained $user and the entry has been deleted; false otherwise
     */
    public function dropMembershipRequest($uid, $group)
    {
        $dnOfGroup = $this->getDnOfGroup($group);
        $entry = $this->getEntry($dnOfGroup);
        if (is_null($entry)) {
            throw new LdapException($this, sprintf('Can\'t find group %s', $group));
        }
        $dnOfUser = $this->findUserDN($uid);
        if (!Attribute::attributeHasValue($entry, 'pending', $dnOfUser)) {
            return false;
        }
        Attribute::removeFromAttribute($entry, 'pending', $dnOfUser);
        $this->update($dnOfGroup, $entry);
        return true;
    }

    /**
     * Requesting access for the given user to $group. This will add an entry to the `pending` attribute of the $group
     *
     * @param string $uid The username for which to request the membership in $group
     * @param string $group The group for which the membership of $user is requested, we expect the `ou` value
     * @throws LdapException If group can't be found or update wasn't successful
     * @return boolean True, if pending didn't already contain $user; false otherwise (also when already a member)
     */
    public function requestGroupMembership($uid, $group)
    {
        $dnOfGroup = $this->getDnOfGroup($group);
        $entry = $this->getEntry($dnOfGroup);
        if (is_null($entry)) {
            throw new LdapException($this, sprintf('Can\'t find group %s', $group));
        }
        $dnOfUser = $this->findUserDN($uid);
        if (Attribute::attributeHasValue($entry, 'member', $dnOfUser) || Attribute::attributeHasValue($entry, 'pending', $dnOfUser)) {
            return false;
        }
        Attribute::setAttribute($entry, 'pending', $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
        return true;
    }

    /**
     * Move an active member to the inactive subtree, thus preventing him/her from logging on to certain services.
     *
     * @param string $uid The username of the member to be deactivated
     * @throws LdapException
     */
    public function deactivateMember($uid)
    {
        $active = $this->getDnOfActivePerson($uid);
        $inactive = $this->getDnOfInactivePerson($uid);
        $this->move($active, $inactive);
    }
}
