<?php
use Symfony\Component\HttpFoundation\Request;
use Zend\Ldap\Exception\LdapException;

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

$app->match('/members/Benutzerdaten', function (Request $request) use ($app) {
    $user = null;
    /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
    $token = $app['security.token_storage']->getToken();
    if (null !== $token) {
        $user = $token->getUser();

        if ($request->request->has('change-password')) {
            $newpwd = $request->request->get('new-password');
            if (preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $newpwd) && strlen($newpwd) >= 8) {
                try {
                    $app['ldap']->updatePassword($user->getAttributes()['dn'], $request->request->get('old-password'), $request->request->get('new-password'));
                    $app['session']->getFlashBag()
                        ->add('success', 'Passwort erfolgreich geändert!');
                    return $app->redirect('/members/Benutzerdaten');
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

$app->match('/members/meine-Gruppen', function (Request $request) use ($app) {
    /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
    $token = $app['security.token_storage']->getToken();
    
    if (null !== $token) {
        $user = $token->getUser();
        $userDN = $user->getAttributes()['dn'];
        $action = $request->request->get('action');
        
        if (isset($action)) {
            $userUID = $user->getAttributes()['uid'][0];
            
            $groupOU = $request->request->get('ou');
            $groupDN = sprintf('ou=%s,ou=groups,o=sog-de,dc=sog', $groupOU);
            $groupAttr = $app['ldap']->getEntry($groupDN, ['cn', 'owner']);
            
                switch ($action){
                    case 'quit':
                            try {
                                if (in_array($userDN, $groupAttr['owner'])) {
                                    $app['session']->getFlashBag()->add('error', 'Nicht möglich! Du bist Koordinator der Gruppe "' . $groupAttr['cn'][0] . '". Zum Beenden deiner Mitgliedschaft wende dich bitte an das Ressort IT.');
                                } else {
                                    $app['ldap']->removeFromGroup($userDN , $groupDN);
                                    $app['session']->getFlashBag()->add('success', 'Deine Mitgliedschaft in der Gruppe "' . $groupAttr['cn'][0] . '" wurde beendet.');
                                }
                            } catch (LdapException $ex) {
                                $app['session']->getFlashBag()->add('error', 'Fehler beim Beenden der Mitgliedschaft in der Gruppe ' . $groupAttr['cn'][0] . '": ' . $ex->getMessage());
                            }
                            break;
                    case 'drop-request':
                            try {
                                $app['ldap']->dropMembershipRequest($userUID, $groupOU);
                                $app['session']->getFlashBag()->add('success', 'Deine Mitgliedschaftsanfrage für die Gruppe "' . $groupAttr['cn'][0] . '" wurde abgebrochen.');
                            } catch (LdapException $ex) {
                                $app['session']->getFlashBag()->add('error', 'Fehler beim Abbrechen der Mitgliedschaftsanfrage für die Gruppe "' . $groupAttr['cn'][0] . '": ' . $ex->getMessage());
                            }
                            break;
                    case 'start-request':
                            try {
                                $app['ldap']->requestGroupMembership($userUID, $groupOU);
                                $app['session']->getFlashBag()->add('success', 'Es wurde eine neue Mitgliedschaftsanfrage für die Gruppe "' . $groupAttr['cn'][0] . '" erstellt.');
                            } catch (LdapException $ex) {
                                $app['session']->getFlashBag()->add('error', 'Fehler beim Erstellen einer Mitgliedschaftsanfrage für die Gruppe "' . $groupAttr['cn'][0] . '": ' . $ex->getMessage());
                            }
                            break;
                    default:
                            $app['session']->getFlashBag()->add('error', 'Fehler: Der gesendete Befehl wird nicht unterstützt.');
                }
        }
        
        $groups = $app['ldap']->getGroups(['cn', 'ou', 'owner', 'member', 'pending'])->toArray();
        $groupList = [];
        foreach ($groups as $g) {
            $roles = [];
            if (isset($g['owner']) && in_array($userDN , $g['owner'])) $roles[] = 'owner';
            if (isset($g['member']) && in_array($userDN , $g['member'])) $roles[] = 'member';
            if (isset($g['pending']) && in_array($userDN , $g['pending'])) $roles[] = 'pending';
            
            $owners = [];
            if (isset($g['owner'])) {
                for ($j = 0; $j < count($g['owner']); $j++) {
                    $o = $app['ldap']->getEntry($g['owner'][$j], ['cn', 'mail']);
                    if(isset($o)) $owners[] = $o;
                }
            }
            
            $listentry = array(
                'name' => $g['cn'][0],
                'ou' => $g['ou'][0],
                'userRoles' => $roles,
                'owners' => $owners
            );
            
            $groupList[] = $listentry;
        }
        
        return $app['twig']->render('manage_groups.twig', ['groupList' => $groupList]);
    }
})
->method('GET|POST')
->bind('/members/manage-groups');

$app->match('/members/Gruppen', function (Request $request) use ($app) {
    $user = null;
    /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
    $token = $app['security.token_storage']->getToken();

    $user = $token->getUser();
    $ownedGroups = $app['ldap']->getOwnedGroups($user->getAttributes()['dn'])
        ->toArray();
    $group = $request->query->get('group');
    if (!isset($group))
        $group = $ownedGroups[0]['ou'][0];

    $result = null;
    $members = null;
    if (null !== $token) {

        if ($request->request->has('action')) {
            $groupDn = $app['ldap']->getGroupDnByOu($request->request->get('selected-group'));
            $permission = false;
            foreach ($ownedGroups as $og) {
                if ($og['dn'] == $groupDn)
                    $permission = true;
                break;
            }

            if ($permission) {

                if ($request->request->get('action') === 'add') {
                    try {
                        $app['ldap']->addToGroup($request->request->get('selected-member'), $groupDn);
                        $app['session']->getFlashBag()
                            ->add('success', $request->request->get('member-name') . " wurde zu der Gruppe " . $request->request->get('group-name') . ' hinzugefügt!');
                    } catch (LdapException $ex) {
                        $app['session']->getFlashBag()
                            ->add('error', 'Fehler beim Hinzufügen von ' . $request->request->get('member-name') . " zu der Gruppe " . $request->request->get('group-name') . ": " . $ex->getMessage());
                    }

                } else {
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
                }
            } else {
                $app['session']->getFlashBag()->add('error', 'Keine Berechtigung für die gewählte Gruppe');
            }
        }

        $result = $app['ldap']->getAllUsers()
            ->toArray();
        $members = $app['ldap']->getMembers($group)
            ->toArray();
    }

    return $app['twig']->render('manage_members.twig', [
        'result' => $result,
        'members' => $members,
        'group' => $group,
        'ownedGroups' => $ownedGroups
    ]);
})
    ->method('GET|POST')
    ->bind('/members/manage-members');

$app->get('/members/Hilfe', function () use ($app) {
    return $app['twig']->render('help.twig');
})
    ->bind('/members/help');

// some LDAP test calls
$app->get('/ldaptests', function () use ($app) {
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
    } catch (LdapException $ex) {
        if ($ex->getCode() == LdapException::LDAP_INVALID_CREDENTIALS) {
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
        'last_username' => $app['session']->get('_security.last_username')
    ]);
});

$app->run();
