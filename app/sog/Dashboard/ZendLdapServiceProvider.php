<?php
namespace SOG\Dashboard;

use Silex\Application;
use Silex\ServiceProviderInterface;

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
        // setup the ldap adapter which extends the LDAP abstraction layer
        $app['ldap'] = $app->share(function ($app) {
            return new LdapAdapter($app['ldap.options'], $app['config']['ldap.subtrees']);
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