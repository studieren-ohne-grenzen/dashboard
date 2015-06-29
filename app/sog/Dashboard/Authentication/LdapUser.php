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
    protected $username;
    protected $password;
    protected $attributes;
    protected $roles;
    protected $groups;

    /**
     * @param string $username
     * @param string $password
     * @param array $attributes
     * @param array $roles
     */
    public function __construct($username, $password, array $attributes = [], array $roles = [], array $groups = [])
    {
        $this->username = $username;
        $this->password = $password;
        $this->attributes = $attributes;
        $this->roles = $roles;
        $this->groups = $groups;
    }

    /**
     * @return array Returns all attributes of the user, indexed by property
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
    
    public function getSingleAttribute($attribName, $index)
    {
    	return Attribute::getAttribute($this->attributes, $attribName, $index);
    }

    /**
     * @param $key The key of the property
     * @return null|mixed The value or null if the given key is not a property
     */
    public function getAttribute($key)
    {
        if (isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }
        return null;
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
     * @return The groups containing the user
     */
    public function getGroups()
    {
    	return $this->groups;
    }
    
    public function getGroupInfo($attribName, $index)
    {
    	return Attribute::getAttribute($this->attributes, $attribName, $index);
    }
    
    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
        // we don't store the plaintext password with the user object
    }
}