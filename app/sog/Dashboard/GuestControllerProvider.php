<?php
namespace SOG\Dashboard;


use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Zend\Ldap\Attribute;

/**
 * Simple extension to the @see \RedirectResponse class to easily redirect to the request referer.
 *
 * Class RefererRedirectResponse
 * @package SOG\Dashboard
 */
class RefererRedirectResponse extends RedirectResponse
{
    /**
     * @var string The default redirect route if the referer is empty
     */
    private $default_route = '/members/Mitglieder-verwalten';

    /**
     * Call the parent constructor to redirect appropriately
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        // referer might be empty, let's be certain and provide a default
        parent::__construct($request->headers->get('referer', $this->default_route));
    }
}

/**
 * This controller provides two endpoints for subscribing and unsubscribing guests to a given group.
 * The given attributes for a guest are either used to retrieve him/her from the LDAP tree or create a new guest.
 * This happens transparently and isn't the concern of the application itself.
 *
 * Class GuestControllerProvider
 * @package SOG\Dashboard
 */
class GuestControllerProvider implements ControllerProviderInterface
{
    /**
     * @var Application Reference to the Silex app for easy access to the services etc.
     */
    private $app;

    /**
     * {@inheritdoc}
     */
    public function connect(Application $app)
    {
        $this->app = $app;

        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        // used to subscribe a guest to a group mailing list
        $controllers->post('/subscribe', [$this, 'subscribe'])->before([$this, 'isAllowed']);

        return $controllers;
    }

    /**
     * Checks if the value for `ou` is indeed one of the owned groups by the logged in user.
     *
     * @param Request $request
     * @param Application $app
     * @return null|RefererRedirectResponse Redirects to the previous page if the user doesn't have permission.
     */
    public function isAllowed(Request $request, Application $app)
    {
        // checks ahead of executing the route callback if the given request is valid as in the user is an owner
        /** @var \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token */
        $token = $this->app['security.token_storage']->getToken();

        $ownerPermission = false;

        if (null !== $token) {
            $user = $token->getUser();
            $ownedGroups = $app['ldap']->getOwnedGroups($user->getAttributes()['dn'])->toArray();

            $selGroup = $request->request->get('ou');
            if (!isset($selGroup)) $selGroup = $ownedGroups[0]['ou'][0];
            $selGroupDN = sprintf('ou=%s,%s', $selGroup, $app['config']['ldap.subtrees']['groups']);

            foreach ($ownedGroups as $og) {
                if ($og['dn'] == $selGroupDN) {
                    $ownerPermission = true;
                    break;
                }
            }
        }

        // fail, no permission granted!
        if ($ownerPermission === false) {
            return new RefererRedirectResponse($request);
        }
        return null;
    }

    /**
     * Tries to subscribe the specified guest to the mailing list.
     *
     * @param Application $app
     * @param Request $request
     * @return RefererRedirectResponse Redirects to the previously visited page.
     */
    public function subscribe(Application $app, Request $request)
    {
        $name = $request->request->get('name');
        $mail = $request->request->get('mail');
        $group = $request->request->get('ou');

        if (is_null($name) || is_null($mail) || is_null($group)) {
            $app['session']->getFlashBag()->add('error', 'Der Gast konnte nicht gefunden und daher nicht der Liste hinzugefügt werden.');
            return new RefererRedirectResponse($request);
        }

        if (strpos($mail, '@studieren-ohne-grenzen.org') !== false) {
            $app['session']->getFlashBag()->add('error', 'Bitte füge SOG-Mitglieder nicht als Gäste hinzu.');
            return new RefererRedirectResponse($request);
        }

        $user_dn = $this->retrieveGuestByMail($mail);
        if ($user_dn === false) {
            $info = $app['ldap']->createGuest($name, $mail);
            $user_dn = Attribute::getAttribute($info, 'dn', 0);
        }

        $group_dn = sprintf('ou=%s,%s', $group, $app['config']['ldap.subtrees']['groups']);
        if ($app['ldap']->isMemberOfGroup($user_dn, $group_dn)) {
            $app['session']->getFlashBag()->add('info', 'Der Gast ist bereits auf der Liste eingetragen.');
        } else {
            $app['ldap']->addToGroup($user_dn, $group_dn);
            $app['session']->getFlashBag()->add('success', 'Der Gast wurde der Liste hinzugefügt.');
        }
        return new RefererRedirectResponse($request);
    }

    /**
     * Retrieves a guest by the given mail address.
     *
     * @param string $mail
     * @return false|string The retrieved DN of the guest or false if not found.
     */
    private function retrieveGuestByMail($mail)
    {
        $info = $this->app['ldap']->getMemberByMail($mail, 'mail');
        if (is_array($info))
            return $info['dn'];
        else
            return false;
    }
}