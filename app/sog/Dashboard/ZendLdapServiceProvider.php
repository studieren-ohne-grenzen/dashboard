<?php
namespace SOG\Dashboard;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Zend\Ldap\Ldap;

/**
 * Setup the LDAP resource using Zend\Ldap and share it with the application.
 *
 * Class ZendLdapServiceProvider
 * @package SOG\Dashboard
 */
class ZendLdapServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        // provide an alias, independent of the implementation
        $app['ldap'] = function () use ($app) {
            return $app['zendldap'];
        };

        // setup the actual LDAP resource
        $app['zendldap'] = $app->share(function ($app) {
            return new Ldap($app['ldap.options']);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
        // nothing to do here
    }
}