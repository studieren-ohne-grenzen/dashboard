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
     * 
     * @param string $attribName The name of the attribute
     * @param int $index The index. Set null, if an array containing all attributes should be returned
     * @return string|array Returns the specified attribute of the user or an array containing all attributes, if $index is null
     */
    public function getAttribute($attribName, $index = null)
    {
        return Attribute::getAttribute($this->attributes, $attribName, $index);
    }
    
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
     * @return The groups containing the user
     */
    public function getGroups()
    {
    	return $this->groups;
    }
    
    public function getGroupAttribute($groupIndex, $attribName, $index=null)
    {
    	return Attribute::getAttribute($this->groups[$groupIndex], $attribName, $index);
    }
    
    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
        // we don't store the plaintext password with the user object
    }
}