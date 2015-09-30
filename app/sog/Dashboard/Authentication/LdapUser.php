<?php
namespace SOG\Dashboard\Authentication;

use Symfony\Component\Security\Core\User\UserInterface;
use Zend\Ldap\Attribute;

/**
 * This class represents an authenticated LDAP user.
 * The implementation follows https://github.com/DerManoMann/ldap-auth-service-provider - thank you!
 *
 * Class LdapUser
 * @package SOG\Dashboard\Authentication
 */
class LdapUser implements UserInterface
{
    /**
     * @var string The unique usernam (LDAP: uid) of the represented user
     */
    protected $username;
    /**
     * @var string The hashed password
     */
    protected $password;
    /**
     * @var array All LDAP attributes
     */
    protected $attributes;
    /**
     * @var array All roles associated with this user
     */
    protected $roles;
    /**
     * @var array All groups the user is a member of
     */
    protected $memberships;
    /**
     * @var array All groups the user is an owner of
     */
    protected $ownerships;

    /**
     * LdapUser constructor, set members.
     *
     * @param string $username
     * @param string $password
     * @param array $attributes
     * @param array $roles
     * @param array $memberships
     * @param array $ownerships
     */
    public function __construct($username, $password, array $attributes = [], array $roles = [], array $memberships = [], array $ownerships = [])
    {
        $this->username = $username;
        $this->password = $password;
        $this->attributes = $attributes;
        $this->roles = $roles;
        $this->memberships = $memberships;
        $this->ownerships = array_map(function($group) {
            return $group['ou'][0];
        }, $ownerships);
    }

    /**
     * Retrieve the requested attribute with $index, there may be many of any given kind.
     *
     * @param string $attribName The name of the attribute
     * @param int $index The index. Set null, if an array containing all attributes should be returned
     * @return string|array Returns the specified attribute of the user or an array containing all attributes, if $index is null
     */
    public function getAttribute($attribName, $index = null)
    {
        return Attribute::getAttribute($this->attributes, $attribName, $index);
    }

    /**
     * Returns all attributes
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles()
    {
        return $this->roles;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * {@inheritdoc}
     */
    public function getSalt()
    {
        // we don't use a salt.
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Returns the groups the user is member of.
     *
     * @return array The groups containing the user as member
     */
    public function getGroups()
    {
        return $this->memberships;
    }

    /**
     * Returns the groups the user is owner of.
     *
     * @return array The groups containing the user as owner
     */
    public function getOwnerships()
    {
        return $this->ownerships;
    }

    /**
     * Get an attribute of one of the associated groups.
     *
     * @param int $groupIndex
     * @param string $attribName
     * @param null $index
     * @return array|mixed
     */
    public function getGroupAttribute($groupIndex, $attribName, $index = null)
    {
        return Attribute::getAttribute($this->memberships[$groupIndex], $attribName, $index);
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
        // we don't store the plaintext password with the user object
    }
}