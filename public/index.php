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

$app->match('/members/Mitglieder-verwalten', function (Request $request) use ($app) {
    /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
    $token = $app['security.token_storage']->getToken();
    
    if (null !== $token) {
        $user = $token->getUser();
        $ownedGroups = $app['ldap']->getOwnedGroups($user->getAttributes()['dn'])->toArray();
        
        $selGroup = $request->query->get('ou');
        if (!isset($selGroup)) $selGroup = $ownedGroups[0]['ou'][0];
        $selGroupDN = sprintf('ou=%s,ou=groups,o=sog-de,dc=sog', $selGroup);
        
        $action = $request->request->get('action');
        
        if (isset($action)) {
            $ownerPermission = false;
            $selGroupName = '';
            foreach ($ownedGroups as $og) {
                if ($og['dn'] == $selGroupDN)
                    $ownerPermission = true;
                    $selGroupName = $og['cn'][0];
                break;
            }

            if ($ownerPermission) {
                $userID = $request->request->get('uid');
                $userDN = $app['ldap']->findUserDN($userID);
                $userAttr = $app['ldap']->getEntry($userDN, ['cn']);
                
                $groupAttr = $app['ldap']->getEntry($selGroupDN, ['owner']);
                
                switch ($action){
                    case 'add':
                        try {
                            $app['ldap']->addToGroup($userDN, $selGroupDN);
                            $app['ldap']->dropMembershipRequest($userID, $selGroup);
                            $app['session']->getFlashBag()->add('success', $userAttr['cn'][0] . ' wurde zu der Gruppe "' . $selGroupName . '" hinzugefügt!');
                        } catch (LdapException $ex) {
                            $app['session']->getFlashBag()->add('error', 'Fehler beim Hinzufügen von ' . $userAttr['cn'][0] . ' zu der Gruppe "' . $selGroupName . '": ' . $ex->getMessage());
                        }
                        break;

                    case 'rm':
                        try {
                            if (in_array($userDN, $groupAttr['owner'])) {
                                $app['session']->getFlashBag()->add('error', 'Nicht möglich! "' . $userAttr['cn'][0] . '" ist Koordinator der Gruppe "' . $selGroupName . '". Zum Beenden deiner Mitgliedschaft wende dich bitte an das Ressort IT.');
                            } else {
                            $app['ldap']->removeFromGroup($userDN, $selGroupDN);
                            $app['session']->getFlashBag()->add('success', $userAttr['cn'][0] . ' wurde von der Gruppe "' . $selGroupName . '" entfernt!');
                            }
                        } catch (LdapException $ex) {
                            $app['session']->getFlashBag()->add('error', 'Fehler beim Entfernen von ' . $userAttr['cn'][0] . ' aus der Gruppe "' . $selGroupName . '": ' . $ex->getMessage());
                        }
                        break;
                    case 'rm-request':
                        try {
                            $app['ldap']->dropMembershipRequest($userID, $selGroup);
                            $app['session']->getFlashBag()->add('success', 'Die Mitgliedschaftsanfrage von ' . $userAttr['cn'][0] . ' für die Gruppe "' . $selGroupName . '" wurde gelöscht!');
                        } catch (LdapException $ex) {
                            $app['session']->getFlashBag()->add('error', 'Fehler beim Löschen der Mitgliedschaftsanfrage von ' . $userAttr['cn'][0] . ' für die Gruppe "' . $selGroupName . '": ' . $ex->getMessage());
                        }
                        break;
                    default:
                        $app['session']->getFlashBag()->add('error', 'Fehler: Der gesendete Befehl wird nicht unterstützt.');
                    }
                    
            } else {
                $app['session']->getFlashBag()->add('error', 'Keine Berechtigung für die gewählte Gruppe');
            }
        }

        $allUsers = $app['ldap']->getAllUsers()->toArray();
        $groupAttr = $app['ldap']->getEntry($selGroupDN, ['owner', 'member', 'pending']);
        
        $memberList = [];
        foreach ($allUsers as $u) {
            $roles = [];
            if (isset($groupAttr['owner']) && in_array($u['dn'] , $groupAttr['owner'])) $roles[] = 'owner';
            if (isset($groupAttr['member']) && in_array($u['dn'] , $groupAttr['member'])) $roles[] = 'member';
            if (isset($groupAttr['pending']) && in_array($u['dn'] , $groupAttr['pending'])) $roles[] = 'pending';
            
            $listentry = array(
                'name' => $u['cn'][0],
                'uid' => $u['uid'][0],
                'email' => $u['mail'][0],
                'userRoles' => $roles
            );
            
            $memberList[] = $listentry;
        }
        
        return $app['twig']->render('manage_members.twig', [
            'memberList' => $memberList,
            'ownedGroups' => $ownedGroups,
            'selectedGroup' => $selGroup
        ]);
    }
})
    ->method('GET|POST')
    ->bind('/members/manage-members');

$app->get('/members/Hilfe', function () use ($app) {
    return $app['twig']->render('help.twig');
})
    ->bind('/members/help');

$app->get('/login', function (Request $request) use ($app) {
    return $app['twig']->render('login.twig', [
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username')
    ]);
});

$app->run();
