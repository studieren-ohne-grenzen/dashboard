<?php
/** @var $app Silex\Application */
// templating
$app->register(new Silex\Provider\TwigServiceProvider(), [
    'twig.path' => __DIR__ . '/../views'
]);

// logging
$app->register(new Silex\Provider\MonologServiceProvider(), [
    'monolog.logfile' => __DIR__ . '/../logs/development.log',
    'monolog.name' => 'dashboard'
]);

// database
$app->register(new Silex\Provider\DoctrineServiceProvider(), [
    'db.options' => [
        'user' => null,
        'driver' => 'pdo_sqlite',
        'path' => __DIR__ . '/dashboard.db',
    ],
]);

// i18n
$app->register(new Silex\Provider\TranslationServiceProvider(), [
    'locale' => $dashboard_config['locale']
]);
use Symfony\Component\Translation\Loader\YamlFileLoader;

$app['translator'] = $app->share($app->extend('translator', function ($translator, $app) {
    /** @var $translator Symfony\Component\Translation\Translator */
    $translator->addLoader('yaml', new YamlFileLoader());
    $translator->addResource('yaml', __DIR__ . '/locales/de.yml', 'de');

    return $translator;
}));


// ldap
$app->register(new SOG\Dashboard\ZendLdapServiceProvider(), [
    'ldap.options' => $dashboard_config['ldap.options']
]);

// convenience: random strings for passwords, token etc.
$app->register(new SOG\Dashboard\RandomStringServiceProvider());

// mailing
$app->register(new Silex\Provider\SwiftmailerServiceProvider());
$app['mailer.from'] = $dashboard_config['mailer.from'];
$app['swiftmailer.options'] = $dashboard_config['swiftmailer.options'];

$app->register(new Silex\Provider\SessionServiceProvider());

// used for handy `path()` calls inside Twig templates
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

// security / login
$app->register(new SOG\Dashboard\Authentication\LdapAuthenticationServiceProvider());
$app->register(new Silex\Provider\SecurityServiceProvider(), [
    'security.firewalls' => [
        'members' => [
            'pattern' => '^/members/',
            'logout' => [
                'logout_path' => '/members/logout'
            ],
            'ldap' => [
                'check_path' => '/members/login_check',
                'require_previous_session' => false
            ],
            'users' => function () use ($app) {
                return $app['security.ldap.user_provider']($app['ldap']);
            },
            'remember_me' => $dashboard_config['remember_me']
        ]
    ],
    'security.role_hierarchy' => [
        // 'ROLE_ADMIN' => ['ROLE_GROUP_ADMIN', 'ROLE_USER'], // unused
        'ROLE_GROUP_ADMIN' => ['ROLE_USER']
    ]
]);

$app->register(new Silex\Provider\RememberMeServiceProvider());

$app->register(new \SOG\Dashboard\DataUtilityServiceProvider());

// password recovery
$app->mount('/password', new SOG\Dashboard\PasswordRecoveryControllerProvider());

// signing up guests for mailing lists
$app->mount('/members/guests', new SOG\Dashboard\GuestControllerProvider());

// group management functionality
$app->mount('/members/groups', new \SOG\Dashboard\GroupControllerProvider());

$app['theme'] = $dashboard_config['theme'];