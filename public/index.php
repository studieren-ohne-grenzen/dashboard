<?php
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Silex\Application();

// should be disabled on production
$app['debug'] = true;

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/services.php';

// now, let's set up the routes
// TODO: organize in controllers?

// (unprotected) index route
$app->get('/', function () use ($app) {
    return $app['twig']->render('home.twig', [
        'message' => 'Hello World'
    ]);
});

// some LDAP test calls
$app->get('/ldaptests', function () use ($app) {
    $dn = 'uid=leonhard.melzer,ou=active,ou=people,o=sog-de,dc=sog';
    $content = '';
    // fails due to insufficient permission:
    // $content = print_r($app['ldap']->updatePassword($dn, 'test'), true);
    $content .= print_r($app['ldap']->getGroups()->toArray(), true);
    $content .= print_r($app['ldap']->getMemberships($dn)->toArray(), true);
    try {
        $content .= print_r($app['ldap']->bind($dn, 'test'), true);
    } catch (\Zend\Ldap\Exception\LdapException $ex) {
        if ($ex->getCode() == \Zend\Ldap\Exception\LdapException::LDAP_INVALID_CREDENTIALS) {
            $content .= "Der Login war nicht erfolgreich, bitte überprüfe deinen Benutzernamen und Passwort.";
        } else {
            $content .= print_r($ex, true);
        }
    } finally {
        // rebind to privileged user
        $app['ldap']->bind();
    }
    return $app['twig']->render('text.twig', [
        'content' => $content
    ]);
});

// this route should be protected
$app->get('/members/test', function () use ($app) {
    return $app['twig']->render('home.twig', [
        'message' => 'Test, should be protected'
    ]);
});

$app->get('/login', function (Request $request) use ($app) {
    return $app['twig']->render('login.twig', [
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ]);
});

$app->run();