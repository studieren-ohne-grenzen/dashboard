<?php
namespace SOG\Dashboard\Authentication;

use Silex\Application;
use Silex\ServiceProviderInterface;

/**
 * This class sets up the required hooks for providing LDAP-based authentication.
 * The implementation follows https://github.com/DerManoMann/ldap-auth-service-provider - thank you!
 *
 * Class LdapAuthenticationServiceProvider
 * @package SOG\Dashboard\Authentication
 */
class LdapAuthenticationServiceProvider implements ServiceProviderInterface
{
    /**
     * @var string The entry point for the authentication, this can be e.g. `form` or `http`, we're interested in `form`
     */
    private $entry_point = 'form';

    /** 
     * {@inheritdoc} 
     */
    public function register(Application $app)
    {
        // let's first setup the user provider, we'll hand over the LDAP instance
        if (isset($app['security.ldap.user_provider']) === false) {
            $app['security.ldap.user_provider'] = $app->protect(function () use ($app) {
                return new LdapUserProvider($app['ldap']);
            });
        }

        $entry_point = $this->entry_point;
        // now setup the authentication listener
        $app['security.authentication_listener.factory.ldap'] = $app->protect(function ($name, $options = []) use ($app, $entry_point) {

            if ($entry_point && isset($app['security.entry_point.' . $name . '.' . $entry_point]) === false) {
                $app['security.entry_point.' . $name . '.' . $entry_point] = $app['security.entry_point.' . $entry_point . '._proto']($name, $options);
            }

            // the authentication provider handles the actual authentication and is called automatically
            $app['security.authentication_provider.' . $name . '.ldap'] = function () use ($app, $name) {
                return new LdapAuthenticationProvider(
                    $app['ldap'],
                    $app['security.user_provider.' . $name]
                );
            };

            if ($entry_point) {
                $app['security.authentication_listener.' . $name . '.ldap'] = $app['security.authentication_listener.' . $entry_point . '._proto']($name, $options);
            }

            return array(
                'security.authentication_provider.' . $name . '.ldap',
                'security.authentication_listener.' . $name . '.ldap',
                $entry_point ? 'security.entry_point.' . $name . '.' . $entry_point : null,
                'pre_auth',
            );
        });
    }

    /** 
     * {@inheritdoc} 
     */
    public function boot(Application $app)
    {
        // nothing to do here.
    }
}