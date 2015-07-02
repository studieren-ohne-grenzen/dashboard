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
$app->get('/', function () use($app) {
    return $app->redirect($app["url_generator"]->generate("/members/manage-account"));
});

$app->match('/members/Benutzerdaten', function (Request $request) use($app) {
    $user = null;
    $token = $app['security.token_storage']->getToken();
    if (null !== $token) {
        $user = $token->getUser();
        
        if ($request->request->has('change-email-address')) {
            try {
                $app['ldap']->updateEmail($user->getAttributes()['dn'], $request->request->get('email-address'));
                $app['session']->getFlashBag()
                    ->add('success', 'E-Mail-Adresse erfolgreich geändert: ' . $request->request->get('email-address'));
            } catch (LdapException $ex) {
                $app['session']->getFlashBag()
                    ->add('error', 'Fehler beim Ändern der E-Mail-Adresse (zu ' . $request->request->get('email-address') . '): ' . $ex->getMessage());
            }
        } else 
            if ($request->request->has('change-password')) {
                $newpwd = $request->request->get('new-password');
                if (preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $newpwd) && strlen($newpwd) >= 8) {
                    try {
                        $app['ldap']->updatePassword($user->getAttributes()['dn'], $request->request->get('old-password'), $request->request->get('new-password'));
                        $app['session']->getFlashBag()
                            ->add('success', 'Passwort erfolgreich geändert!');
                    } catch (LdapException $ex) {
                        $app['session']->getFlashBag()
                            ->add('error', 'Fehler beim Ändern des Passworts: ' . $ex->getMessage());
                    }
                } else {
                    $app['session']->getFlashBag()
                        ->add('warning', 'Das Passwort muss 8 Zeichen lang sein und mindestens eine Zahl und einen Buchstaben enthalten');
                }
            }
    }
    
    return $app['twig']->render('manage_account.twig', []);
})
    ->method('GET|POST')
    ->bind('/members/manage-account');

$app->match('/members/Gruppen', function (Request $request) use($app) {
    $user = null;
    $token = $app['security.token_storage']->getToken();
    
    $user = $token->getUser();
    $ownedGroups = $app['ldap']->getOwnedGroups($user->getAttributes()['dn'])
        ->toArray();
    $group = $request->query->get('group');
    if (! isset($group))
        $group = $ownedGroups[0]['ou'][0];
    
    if (null !== $token) {
        
        if ($request->request->has('action')) {
            $groupDn = $app['ldap']->getGroupDnByOu($request->request->get('selected-group'));
            $permission = false;
            foreach ($ownedGroups as $og) {
                if ($og['dn'] == $groupDn)
                    $permission = true;
                break;
            }
            if (permission) {
                if ($request->request->get('action') === 'add') {
                    try {
                        $app['ldap']->addToGroup($request->request->get('selected-member'), $groupDn);
                        $app['session']->getFlashBag()
                            ->add('success', $request->request->get('member-name') . " wurde zu der Gruppe " . $request->request->get('group-name') . ' hinzugefügt!');
                    } catch (LdapException $ex) {
                        $app['session']->getFlashBag()
                            ->add('error', 'Fehler beim Hinzufügen von ' . $request->request->get('member-name') . " zu der Gruppe " . $request->request->get('group-name') . ": " . $ex->getMessage());
                    }
                } else 
                    if ($request->request->get('action') === 'rm') {
                        try {
                            $app['ldap']->removeFromGroup($request->request->get('selected-member'), $groupDn);
                            $app['session']->getFlashBag()
                                ->add('success', $request->request->get('member-name') . " wurde von der Gruppe " . $request->request->get('group-name') . ' entfernt!');
                        } catch (LdapException $ex) {
                            $app['session']->getFlashBag()
                                ->add('error', 'Fehler beim Entfernen von ' . $request->request->get('member-name') . " aus der Gruppe " . $request->request->get('group-name') . ": " . $ex->getMessage());
                        }
                    }
            } else {
                $app['session']->getFlashBag()
                    ->add('error', 'Keine Berechtigung für die gewählte Gruppe');
            }
        }
        
        $result = $app['ldap']->search('objectClass=inetOrgPerson', 'ou=people,o=sog-de,dc=sog')
            ->toArray();
        $members = $app['ldap']->getMembers($group)
            ->toArray();
    }
    
    return $app['twig']->render('manage_groups.twig', [
        'result' => $result,
        'members' => $members,
        'group' => $group,
        'ownedGroups' => $ownedGroups,
        'm' => $m
    ]);
})
    ->method('GET|POST')
    ->bind('/members/manage-groups');

$app->get('/Hilfe', function () use($app) {
    return $app['twig']->render('help.twig');
})
    ->bind('help');

// some LDAP test calls
$app->get('/ldaptests', function () use($app) {
    $dn = 'uid=leonhard.melzer,ou=active,ou=people,o=sog-de,dc=sog';
    $content = "search('objectClass=person', 'dc=sog')->getFirst(): ";
    $content .= print_r($app['ldap']->search('objectClass=person', 'dc=sog')
        ->getFirst(), true);
    $content .= "getGroups()->toArray(): ";
    $content .= print_r($app['ldap']->getGroups()
        ->toArray(), true);
    $content .= "getMemberships(dn)->toArray(): ";
    $content .= print_r($app['ldap']->getMemberships($dn)
        ->toArray(), true);
    $content .= "getMembers('ressort_it')->toArray(): ";
    $content .= print_r($app['ldap']->getMembers('ressort_it')
        ->toArray(), true);
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
$app->get('/members/test', function () use($app) {
    return $app['twig']->render('text.twig', [
        'content' => 'Test, should be protected'
    ]);
});

$app->get('/login', function (Request $request) use($app) {
    return $app['twig']->render('login.twig', [
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username')
    ]);
});

$app->run();
