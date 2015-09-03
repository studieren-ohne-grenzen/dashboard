<?php
namespace SOG\Dashboard;


use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Easy access to random strings of any length, can be used for tokens, passwords etc.
 *
 * Class RandomStringServiceProvider
 * @package SOG\Dashboard
 */
class RandomStringServiceProvider implements ServiceProviderInterface
{

    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        $app['random'] = $app->protect(function ($length = 8) {
            $string = (new UriSafeTokenGenerator())->generateToken();
            return substr($string, 0, $length);
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