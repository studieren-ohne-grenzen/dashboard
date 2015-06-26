<?php
// templating
$app->register(new Silex\Provider\TwigServiceProvider(), [
    'twig.path' => __DIR__ . '/../views'
]);

// logging
$app->register(new Silex\Provider\MonologServiceProvider(), [
    'monolog.logfile' => __DIR__ . '/../logs/development.log'
]);

// ldap
$app->register(new SOG\Dashboard\ZendLdapServiceProvider(), [
    'ldap.options' => $config['ldap.options']
]);

$app->register(new Silex\Provider\SessionServiceProvider());

// used for handy `path()` calls inside Twig templates
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

// security / login
$app->register(new SOG\Dashboard\Authentication\LdapAuthenticationServiceProvider());
$app->register(new Silex\Provider\SecurityServiceProvider(), [
    'security.firewalls' => [
        'members' => [
            'pattern' => '^/members/',
            'users' => function () use ($app) {
                return $app['security.ldap.user_provider']($app['ldap']);
            },
            'form' => ['login_path' => '/login', 'check_path' => '/members/login_check'],
            'logout' => ['logout_path' => '/members/logout']
        ]
    ]
]);

// TODO: setup? checkbox?
$app->register(new Silex\Provider\RememberMeServiceProvider());