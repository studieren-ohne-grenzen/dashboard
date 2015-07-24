<?php
namespace SOG\Dashboard;

use Zend\Ldap\Attribute;
use Zend\Ldap\Collection;
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
     * @var Attribute The algorithm used for password generation.
     */
    private $password_algorithm = Attribute::PASSWORD_HASH_SSHA;

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
            'ou=groups,o=sog-de,dc=sog',
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
            'ou=people,o=sog-de,dc=sog',
            self::SEARCH_SCOPE_SUB,
            ['displayname', 'mail', 'dn'],
            'displayname'
        );
        return $results;
    }

    /**
     * Adds the DN to the given group
     *
     * @param string $dnOfUser dn of the user to add
     * @param string $dnOfGroup dn of the group
     * @throws LdapException
     */
    public function addToGroup($dnOfUser, $dnOfGroup)
    {
        $entry = $this->getEntry($dnOfGroup);
        Attribute::setAttribute($entry, 'member', $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Removes the DN from the given group
     *
     * @param string $dnOfUser dn of the user to remove
     * @param string $dnOfGroup dn of the group
     * @throws LdapException
     */
    public function removeFromGroup($dnOfUser, $dnOfGroup)
    {
        $entry = $this->getEntry($dnOfGroup);
        Attribute::removeFromAttribute($entry, 'member', $dnOfUser);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Returns the dn of the first group with the given ou
     *
     * @param string $group_ou The common name of the group
     * @throws LdapException
     */
    public function getGroupDnByOu($group_ou)
    {
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(ou=%s))', $group_ou),
            'ou=groups,o=sog-de,dc=sog',
            self::SEARCH_SCOPE_ONE,
            ['dn']
        );
        return $results->getFirst()['dn'];
    }

    /**
     * Retrieves all memberships for the given DN
     *
     * @param string $user_dn The DN for which to get the memberships
     * @param array $fields A list of fields we want to return from the search
     * @return bool|\Zend\Ldap\Collection
     * @throws LdapException
     */
    public function getMemberships($user_dn, $fields = ['cn'])
    {
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(member=%s))', $user_dn),
            'ou=groups,o=sog-de,dc=sog',
            self::SEARCH_SCOPE_ONE,
            $fields,
            'cn'
        );
        return $results;
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
            'ou=groups,o=sog-de,dc=sog',
            self::SEARCH_SCOPE_ONE,
            $fields,
            'member'
        );
        return $results;
    }


    /**
     * This method can be used to assign the ROLE_GROUP_ADMIN role to a user. It checks if the given DN is a owner
     * of any group. Further checks should be done somewhere else.
     *
     * @param string $user_dn The user DN for which we want to check
     * @return bool True if the given user is a owner of any group, false otherwise.
     * @throws LdapException
     */
    public function isOwner($user_dn)
    {

        $result = $this->getOwnedGroups($user_dn);
        // we don't care about specifics, we only want to know if the user is owner of any group
        return ($result != null && $result->count() > 0);
    }

    /**
     * Returns the groups owned by the user with the given dn
     *
     * @param string $user_dn The user DN for which we want to check
     * @return Collection The groups owned by the user
     * @throws LdapException
     */
    public function getOwnedGroups($user_dn)
    {
        $results = $this->search(
            sprintf('(&(objectClass=groupOfNames)(owner=%s))', $user_dn),
            'ou=groups,o=sog-de,dc=sog',
            self::SEARCH_SCOPE_ONE,
            ['cn', 'ou'],
            'cn'
        );

        return $results;
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
        $dn = sprintf('uid=%s,ou=inactive,ou=people,o=sog-de,dc=sog', $username);
        $sog_mail = sprintf('%s@studieren-ohne-grenzen.org', $username);
        $info = [];

        // core data
        Attribute::setAttribute($info, 'dn', $dn);
        Attribute::setAttribute($info, 'uid', $username);
        Attribute::setAttribute($info, 'cn', $firstName . " " . $lastName);
        Attribute::setAttribute($info, 'displayName', $firstName);
        Attribute::setAttribute($info, 'givenName', $firstName);
        Attribute::setAttribute($info, 'sn', $lastName);
        Attribute::setAttribute($info, 'cn', $firstName . " " . $lastName);

        // password
        Attribute::setPassword($attributes, $password, $this->password_algorithm);

        // meta data
        Attribute::setAttribute($info, 'mail', $sog_mail);
        Attribute::setAttribute($info, 'mail-alternative', $mail);
        Attribute::setAttribute($info, 'mailAlias', $mail);
        Attribute::setAttribute($info, 'mailHomeDirectory', sprintf('/srv/vmail/%s', $sog_mail));
        Attribute::setAttribute($info, 'mailStorageDirectory', sprintf('maildir:/srv/vmail/%s/Maildir', $sog_mail));
        Attribute::setAttribute($info, 'mailEnabled', true);
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
     * Requesting access for the given user to $group. This will add an entry to the `pending` attribute of the $group
     *
     * @param string $uid The username for which to request the membership in $group
     * @param string $group The group for which the membership of $user is requested
     * @throws LdapException
     */
    public function requestGroupMembership($uid, $group)
    {
        $dnOfGroup = sprintf('cn=%s,ou=groups,o=sog-de,dc=sog', $group);
        // TODO: user may not yet be in ou=active - leave like this or put in ou=inactive and update on approval?
        $dnOfUser = sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid);
        $entry = $this->getEntry($dnOfGroup);
        Attribute::setAttribute($entry, 'pending', $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
    }

    /**
     * Allowing the given user to access the given group. This will move the entry from the `pending` to the `member`
     * field.
     *
     * @param string $uid The username for which to approve the membership in $group
     * @param string $group The group for which the membership of $user is approved
     * @throws LdapException
     */
    public function approveGroupMembership($uid, $group)
    {
        $dnOfGroup = sprintf('cn=%s,ou=groups,o=sog-de,dc=sog', $group);
        // TODO: user may not yet be in ou=active - leave like this or put in ou=inactive and update on approval?
        $dnOfUser = sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid);

        $entry = $this->getEntry($dnOfGroup);
        Attribute::removeFromAttribute($entry, 'pending', $dnOfUser);
        Attribute::setAttribute($entry, 'member', $dnOfUser, true);
        $this->update($dnOfGroup, $entry);
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
        $active = sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid);
        $inactive = sprintf('uid=%s,ou=inactive,ou=people,o=sog-de,dc=sog', $uid);
        $this->move($inactive, $active);
    }

    /**
     * Move an active member to the inactive subtree, thus preventing him/her from logging on to certain services.
     *
     * @param string $uid The username of the member to be deactivated
     * @throws LdapException
     */
    public function deactivateMember($uid)
    {
        $active = sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid);
        $inactive = sprintf('uid=%s,ou=inactive,ou=people,o=sog-de,dc=sog', $uid);
        $this->move($active, $inactive);
    }

    /**
     * Checks if the given username is already taken, looks in the active and inactive subtree
     *
     * @param string $uid Does this username already exist?
     * @return bool True if member exists, false otherwise.
     */
    public function usernameExists($uid)
    {
        return ($this->exists(sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid)) ||
            $this->exists(sprintf('uid=%s,ou=inactive,ou=people,o=sog-de,dc=sog', $uid)));
    }
}