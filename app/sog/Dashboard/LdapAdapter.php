<?php
namespace SOG\Dashboard;

use Zend\Ldap\Attribute;
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
     * Returns all groups (OUs) with their common names
     *
     * @param array $fields A list of fields we want to return from the search
     * @return bool|null|\Zend\Ldap\Collection
     */
    public function getGroups($fields = ['cn'])
    {
        $results = null;
        try {
            $results = $this->search(
                '(objectClass=groupOfNames)',
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                $fields
            );
            return $results;
        } catch (LdapException $ex) {
            return false;
        }
    }

    /**
     * Adds the DN to the given group
     *
     * @param string $dnOfUser
     * @param string $dnOfGroup
     */
    public function addToGroup($dnOfUser, $dnOfGroup)
    {
        // TODO: implement
        // $this->setAttribute($groupData, 'member', $dnOfUser, true /* for appending */);
        // try { $this->update($dnOfGroup, $groupData); } catch (LdapException $ex) {}
    }

    /**
     * Removes the DN from the given group
     *
     * @param $dnOfUser
     * @param $dnOfGroup
     */
    public function removeFromGroup($dnOfUser, $dnOfGroup)
    {
        // TODO: implement
    }

    /**
     * Retrieves all memberships for the given DN
     *
     * @param string $user_dn The DN for which to get the memberships
     * @param array $fields A list of fields we want to return from the search
     * @return bool|null|\Zend\Ldap\Collection
     */
    public function getMemberships($user_dn, $fields = ['cn'])
    {
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(member=%s))', $user_dn),
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                $fields
            );
            return $results;
        } catch (LdapException $ex) {
            return false;
        }
    }


    /**
     * Retrieves all members for the given group CN
     *
     * @param string $group_ou The common name of the group for which we want to retrieve the members
     * @param array $fields A list of fields we want to return from the search
     * @return bool|null|\Zend\Ldap\Collection
     */
    public function getMembers($group_ou, $fields = ['member'])
    {
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(ou=%s))', $group_ou),
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                $fields
            );
            return $results;
        } catch (LdapException $ex) {
            return false;
        }
    }


    /**
     * This method can be used to assign the ROLE_GROUP_ADMIN role to a user. It checks if the given DN is a owner
     * of any group. Further checks should be done somewhere else.
     *
     * @param string $user_dn The user DN for which we want to check
     * @return bool True if the given user is a owner of any group, false otherwise.
     */
    public function isOwner($user_dn)
    {
        // we don't care about specifics, we only want to know if the user is owner of any group
        return ($this->getOwnedGroups($user_dn) != null && $this->getOwnedGroups($user_dn)->count() > 0);
    }

    /**
     * This method can be used to assign the ROLE_GROUP_ADMIN role to a user. It checks if the given DN is a owner
     * of any group. Further checks should be done somewhere else.
     *
     * @param string $user_dn The user DN for which we want to check
     * @return bool True if the given user is a owner of any group, false otherwise.
     */
    public function getOwnedGroups($user_dn)
    {
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(owner=%s))', $user_dn),
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                ['cn', 'ou']
            );

            return $results;
        } catch (LdapException $ex) {
            return null;
        }
    }
    
    public function updateEmail($dn, $newEmail)
    {
    	$success = false;
    	try {
    		$entry = $this->getEntry($dn);
    		Attribute::setAttribute($entry, 'mail-alternative', $newEmail);
    		$this->update($dn, $attributes);
    		$success = true;
    	} catch (LdapException $ex) {
    		$success = false;
    	}
    	return $success;
    }


    /**
     * Updates the password for the given DN. Only the DN him/herself can change the password.
     * Thus we need to bind as the DN first, update the password and then rebind to the privileged user.
     *
     * @param string $dn The DN for which to update the password
     * @param string $old_password The old password, we need to bind to the DN first
     * @param string $new_password The new password
     * @return bool True on success, false otherwise
     */
    public function updatePassword($dn, $old_password, $new_password)
    {
        $success = false;
        try {
            $this->bind($dn, $old_password);
            $attributes = [];
            Attribute::setPassword($attributes, $new_password, Attribute::PASSWORD_HASH_SSHA);
            $this->update($dn, $attributes);
            $success = true;
        } catch (LdapException $ex) {
            $success = false;
        } finally {
            // rebind to privileged user
            $this->bind();
        }
        return $success;
    }
}