<?php
use SOG\Dashboard\Authentication\LdapUserProvider;
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
    return $app->redirect($app["url_generator"]->generate("/members/manage-account"));
});

$app->get('/Benutzerdaten', function () use ($app) {
    $userp = new LdapUserProvider($app['ldap']);
    $user = $userp->loadUserByUsername('leonhard.melzer');
    return $app['twig']->render('manage_account.twig', [
        'user' => $user
    ]);
})->bind('/members/manage-account');

$app->get('/Gruppen', function (Request $request) use ($app) {
    $userp = new LdapUserProvider($app['ldap']);
    $user = $userp->loadUserByUsername('dennis.keck');
    $group = $request->get('group');
    $ownedGroups = $app['ldap']->getOwnedGroups($user->getAttributes()['dn'])->toArray();
    if(!isset($group)) $group = $ownedGroups[0]['ou'][0];
    $result = $app['ldap']->search('objectClass=inetOrgPerson', 'ou=people,o=sog-de,dc=sog')->toArray();
    $members = $app['ldap']->getMembers($group)->toArray();
    
    return $app['twig']->render('manage_groups.twig', [
        'user' => $user,
    	'result' => $result,
    	'members' => $members,
    	'group' => $group,
    	'ownedGroups' => $ownedGroups
    ]);
})->bind('/members/manage-groups');

$app->get('/Hilfe', function () use ($app) {
    return $app['twig']->render('help.twig');
})->bind('help');

// some LDAP test calls
$app->get('/ldaptests', function () use ($app) {
    $dn = 'uid=leonhard.melzer,ou=active,ou=people,o=sog-de,dc=sog';
    $content = '';
    $content .= print_r($app['ldap']->search('objectClass=person', 'dc=sog')->getFirst(), true);
    $content .= print_r($app['ldap']->getGroups()->toArray(), true);
    $content .= print_r($app['ldap']->getMemberships($dn)->toArray(), true);
    $content .= print_r($app['ldap']->getMembers('ressort_it')->toArray(), true);
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
    return $app['twig']->render('text.twig', [
        'content' => 'Test, should be protected'
    ]);
});

$app->get('/login', function (Request $request) use ($app) {
    return $app['twig']->render('login.twig', [
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ]);
});

$app->run();
