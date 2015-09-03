<?php
namespace SOG\Dashboard;


use Silex\Application;
use Silex\ServiceProviderInterface;

/**
 * Provides simple static methods which work on strings.
 *
 * Class DataUtilityServiceProvider
 * @package SOG\Dashboard
 */
class DataUtilityServiceProvider implements ServiceProviderInterface
{

    /**
     * Check if the given password complies with our password policy.
     *
     * @param string $password
     * @param int $minLength The minimum length for the password
     * @param string $regex The regex for checking the password character classes
     * @return bool True if the given password complies with the password policy, false otherwise.
     */
    private static function checkPasswordPolicy($password, $minLength = 8, $regex = '/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/')
    {
        if (strlen($password) < $minLength) {
            return false;
        }
        if (preg_match($regex, $password) === 0) {
            return false;
        }
        return true;
    }

    /**
     * Replaces certain prefixes of an OU to retrieve the mailbox name.
     *
     * @param string $ou
     * @return string The modified string
     */
    private static function getMailFromOu($ou)
    {
        $ou = strtolower($ou);
        $prefixes = [
            'ak_', 'lg_', 'ressort_'
        ];
        return str_replace($prefixes, '', $ou);
    }

    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        $app['check_password_policy'] = $app->protect(function () {
            return call_user_func_array('self::checkPasswordPolicy', func_get_args());
        });
        $app['get_mail_from_ou'] = $app->protect(function () {
            return call_user_func_array('self::getMailFromOu', func_get_args());
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