<?php
namespace SOG\Dashboard;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Zend\Ldap\Exception\LdapException;

/**
 * This controller provider implements several group related functionality, such as adding owners, members and so on.
 *
 * Class GroupControllerProvider
 * @package SOG\Dashboard
 */
class GroupControllerProvider implements ControllerProviderInterface
{
    /**
     * @var Application Reference to the application container
     */
    private $app;

    /**
     * @var string The DN of the group we deal with in this request
     */
    private $group_dn;

    /**
     * @var string The DN of the user we deal with in this request
     */
    private $user_dn;

    /**
     * @var string The ou value of the group
     */
    private $ou;

    /**
     * @var string The uid value of the user
     */
    private $uid;

    /**
     * {@inheritdoc}
     */
    public function connect(Application $app)
    {
        $this->app = $app;

        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        $app['notify_owners'] = $app->protect(function ($group_ou, $subject, $text) use ($app) {
            $owners = $app['ldap']->getOwnerDetails($group_ou, ['mail', 'cn']);
            if (empty($owners)) {
                // provide a fallback email
                $to = ['it@studieren-ohne-grenzen.org' => 'IT Support'];
            } else {
                $to = [];
                foreach ($owners as $owner) {
                    $to[$owner['mail'][0]] = $owner['cn'][0];
                }
            }

            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$app['mailer.from']])
                ->setTo($to)
                ->setBody($text, 'text/html');
            return $app['mailer']->send($message);
        });

        $controllers->post('/owner/add', [$this, 'ownerAdd'])
            ->before([$this, 'setDNs'])
            ->before([$this, 'ensureNotOwn'])
            ->before([$this, 'ensureGroupAdmin']);
        $controllers->post('/owner/remove', [$this, 'ownerRemove'])
            ->before([$this, 'setDNs'])
            ->before([$this, 'ensureNotOwn'])
            ->before([$this, 'ensureGroupAdmin']);

        // TODO: implement request/accept/drop membership things and the manage-members route here

        return $controllers;
    }

    /**
     * Ensure you're not editing your own position in the group, such as demoting yourself to regular user.
     * To be used as before middleware.
     *
     * @param Request $request
     * @return null|RefererRedirectResponse
     */
    public function ensureNotOwn(Request $request)
    {
        /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
        $token = $this->app['security.token_storage']->getToken();
        $user = $token->getUser();
        if ($user->getAttributes()['uid'][0] === $this->uid) {
            $this->app['session']->getFlashBag()
                ->add('error', 'Du kannst dich nicht selbst bearbeiten.');
            return new RefererRedirectResponse($request);
        }
        return null;
    }

    /**
     * Ensure you are indeed an admin of the group you are about to modify.
     * To be used as before middleware.
     *
     * @param Request $request
     * @return null|RefererRedirectResponse
     */
    public function ensureGroupAdmin(Request $request)
    {
        /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
        $token = $this->app['security.token_storage']->getToken();
        $user = $token->getUser();
        if (in_array($this->ou, $user->getOwnerships()) === false) {
            $this->app['session']->getFlashBag()
                ->add('error', 'Du kannst nur Gruppen bearbeiten, von denen du Koordinator bist.');
            return new RefererRedirectResponse($request);
        }
        return null;
    }

    /**
     * Adds the current user to the current group as owner.
     *
     * @param Request $request
     * @return RefererRedirectResponse
     */
    public function ownerAdd(Request $request)
    {
        $this->app['ldap']->addToGroup($this->user_dn, $this->group_dn, 'owner');
        $this->app['session']->getFlashBag()
            ->add('success', 'Das Mitglied wurde erfolgreich als zusätzlicher Koordinator hinzugefügt.');
        return new RefererRedirectResponse($request);
    }

    /**
     * Removes the current user from the current group as owner.
     *
     * @param Request $request
     * @return RefererRedirectResponse
     */
    public function ownerRemove(Request $request)
    {
        $this->app['ldap']->removeFromGroup($this->user_dn, $this->group_dn, 'owner');
        $this->app['session']->getFlashBag()
            ->add('success', 'Das Mitglied wurde erfolgreich als Koordinator ausgetragen.');
        return new RefererRedirectResponse($request);
    }

    /**
     * Sets the full DNs from the given Request object on the controller instance.
     *
     * @param Request $request
     * @return array Full DNs for the owner and group
     */
    public function setDNs(Request $request)
    {
        $this->uid = $request->request->get('uid');
        $this->ou = $request->request->get('ou');
        if (is_null($this->uid) || is_null($this->ou)) {
            $this->app['session']->getFlashBag()
                ->add('error', 'Ein Fehler ist aufgetreten.');
            return new RefererRedirectResponse($request);
        }
        $groupDN = sprintf('ou=%s,ou=groups,o=sog-de,dc=sog', $this->ou);

        try {
            $userDN = $this->app['ldap']->findUserDN($this->uid);
        } catch (LdapException $ex) {
            return new RefererRedirectResponse($request);
        }

        $this->group_dn = $groupDN;
        $this->user_dn = $userDN;
        return null;
    }

}