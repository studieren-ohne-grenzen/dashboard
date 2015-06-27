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
    public function getGroups()
    {
        $results = null;
        try {
            $results = $this->search(
                '(objectClass=groupOfNames)',
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                ['cn']
            );
            return $results;
        } catch (LdapException $ex) {
            return false;
        }
    }

    /**
     * Adds the DN to the given group
     *
     * @param $dnOfUser
     * @param $dnOfGroup
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
     * @param $dn The DN for which to get the memberships
     * @return bool|null|\Zend\Ldap\Collection
     */
    public function getMemberships($dn)
    {
        $results = null;
        try {
            $results = $this->search(
                sprintf('(&(objectClass=groupOfNames)(member=%s))', $dn),
                'ou=groups,o=sog-de,dc=sog',
                self::SEARCH_SCOPE_ONE,
                ['cn']
            );
            return $results;
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