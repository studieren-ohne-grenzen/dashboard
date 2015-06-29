<?php
namespace SOG\Dashboard\Authentication;

use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Zend\Ldap\Exception\LdapException;
use Zend\Ldap\Ldap;

/**
 * This class maps a requested user to its LDAP entry.
 * The implementation follows https://github.com/DerManoMann/ldap-auth-service-provider - thank you!
 *
 * Class LdapUserProvider
 * @package SOG\Dashboard\Authentication
 */
class LdapUserProvider implements UserProviderInterface
{
    /**
     * @var Ldap The LDAP resource
     */
    private $ldap;

    function __construct(Ldap $ldap)
    {
        $this->ldap = $ldap;
    }

    /**
     * {@inheritdoc}
     */
    public function refreshUser(UserInterface $user)
    {
        return $this->loadUserByUsername($user->getUsername());
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($username)
    {
        try {
            // first retrieve the full DN, this might throw an exception
            $dn = $this->ldap->getCanonicalAccountName($username, LDAP::ACCTNAME_FORM_DN);
            // then get all associated attributes
            $attributes = $this->ldap->getEntry($dn);
            $roles = []; // TODO: assign
            
            $groups = $this->ldap->getMemberships($dn)->toArray();
            
            foreach ($groups as &$g){
            	$g = new LdapGroup($g);
            }
            
            return new LdapUser($username, null, $attributes, $roles, $groups);
        } catch (LdapException $ex) {
            throw new UsernameNotFoundException($ex->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supportsClass($class)
    {
        return ($class === '\\SOG\\Dashboard\\Authentication\\LdapUser');
    }
}