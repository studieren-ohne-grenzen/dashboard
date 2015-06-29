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
     * @param string $group_cn The common name of the group for which we want to retrieve the members
     * @param array $fields A list of fields we want to return from the search
     * @return bool|null|\Zend\Ldap\Collection
     */
    public function getMembers($group_cn, $fields = ['member'])
    {
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(cn=%s))', $group_cn),
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
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(owner=%s))', $user_dn),
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                ['cn']
            );
            // we don't care about specifics, we only want to know if the user is owner of any group
            return ($results->count() > 0);
        } catch (LdapException $ex) {
            return false;
        }
    }


    /**
     * Updates the password for the given DN
     *
     * @param string $dn The DN for which to update the password
     * @param string $password The new password
     * @return bool True on success, false otherwise
     */
    public function updatePassword($dn, $password)
    {
        $attributes = [];
        Attribute::setPassword($attributes, $password, Attribute::PASSWORD_HASH_SHA);

        try {
            $this->update($dn, $attributes);
            return true;
        } catch (LdapException $ex) {
            return false;
        }
    }
}