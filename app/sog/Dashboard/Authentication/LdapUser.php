<?php
namespace SOG\Dashboard\Authentication;

use Symfony\Component\Security\Core\User\UserInterface;

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

    /**
     * @param string $username
     * @param string $password
     * @param array $attributes
     * @param array $roles
     */
    public function __construct($username, $password, array $attributes = [], array $roles = [])
    {
        $this->username = $username;
        $this->password = $password;
        $this->attributes = $attributes;
        $this->roles = $roles;
    }

    /**
     * @return array Returns all attributes of the user, indexed by property
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * @param mixed $key The key of the property
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
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
        // we don't store the plaintext password with the user object
    }
}