<?php
namespace SOG\Dashboard\Authentication;

use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Zend\Ldap\Exception\LdapException;
use Zend\Ldap\Ldap;

/**
 * This class attempts the authentication of a given token using the LDAP resource.
 * The implementation follows https://github.com/DerManoMann/ldap-auth-service-provider - thank you!
 *
 * Class LdapAuthenticationProvider
 * @package SOG\Dashboard\Authentication
 */
class LdapAuthenticationProvider implements AuthenticationProviderInterface
{

    /**
     * @var Ldap
     */
    private $ldap;
    /**
     * @var UserProviderInterface
     */
    private $user_provider;

    /**
     * @param Ldap $ldap
     * @param UserProviderInterface $user_provider
     */
    function __construct(Ldap $ldap, UserProviderInterface $user_provider)
    {
        $this->ldap = $ldap;
        $this->user_provider = $user_provider;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(TokenInterface $token)
    {
        $user = $this->user_provider->loadUserByUsername($token->getUsername());
        if ($user && $this->checkLogin($token->getUsername(), $token->getCredentials())) {
            $roles = array_unique(array_merge($token->getRoles(), $user->getRoles()));
            return new UsernamePasswordToken($user, null, 'ldap', $roles);
        }

        throw new AuthenticationException('Der Login war nicht erfolgreich, bitte überprüfe deinen Benutzernamen und Passwort.');
    }

    /**
     * This method tries to authenticate the given user, the LDAP resource is then bound to this account.
     * By calling `$ldap->bind()` (without parameters) afterwards, the resource is again bound to the privileged account.
     *
     * @param string $user The username to use for binding
     * @param string $password The password to use for binding
     * @return bool Returns true if the bind was successful, false otherwise.
     */
    private function checkLogin($user, $password)
    {
        $success = false;
        try {
            $this->ldap->bind($user, $password);
            $success = true;
        } catch (LdapException $ex) {
            $success = false;
        } finally {
            // rebind to privileged user
            $this->ldap->bind();
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(TokenInterface $token)
    {
        return ($token instanceof UsernamePasswordToken);
    }
}