<?php
namespace SOG\Dashboard\Authentication;

use SOG\Dashboard\LdapAdapter;
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
     * @var LdapAdapter The LDAP resource
     */
    private $ldap;

    public function __construct(LdapAdapter $ldap)
    {
        $this->ldap = $ldap;
    }

    /**
     * {@inheritdoc}
     */
    public function refreshUser(UserInterface $user)
    {
        // This could be implemented in a different manner (session?) to reduce server load
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
            // also get the groups the user is member of
            $memberships = $this->ldap->getMemberships($dn, ['cn', 'ou', 'mailinglistId'])->toArray();
            // and maybe the owned groups
            $ownerships = $this->ldap->getOwnedGroups($dn)->toArray();
            // assign the user's roles
            $roles = $this->getRoles($dn, $ownerships);
            return new LdapUser($username, null, $attributes, $roles, $memberships, $ownerships);
        } catch (LdapException $ex) {
          throw new UsernameNotFoundException($ex->getMessage().'Der Login war nicht erfolgreich, bitte überprüfe deinen Benutzernamen und Passwort.');
          $logger = $this->get('logger');
          $logger->error(($ex->getMessage()));
        }
    }

    /**
     * Infer the rules of the given user by checking the LDAP resource. This can be extended to accommodate for
     * ROLE_ADMIN or other cases
     *
     * @param string $user_dn The user DN for which to infer the rules
     * @param array $ownerships The owned groups for the user DN
     * @return array The roles of the given user
     */
    private function getRoles($user_dn, $ownerships)
    {
        $roles = [];
        if (count($ownerships) > 0) {
            $roles[] = 'ROLE_GROUP_ADMIN';
        } else {
            $roles[] = 'ROLE_USER';
        }
        return $roles;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsClass($class)
    {
        return ($class === '\\SOG\\Dashboard\\Authentication\\LdapUser');
    }

}
