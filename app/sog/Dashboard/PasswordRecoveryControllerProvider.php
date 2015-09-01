<?php
namespace SOG\Dashboard;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;

/**
 * Class PasswordRecoveryControllerProvider
 * @package SOG\Dashboard
 */
class PasswordRecoveryControllerProvider implements ControllerProviderInterface
{
    /**
     * @var Application Reference to the Silex app for easy access to the services etc.
     */
    private $app;
    /**
     * @var string The timeout of a reset request, as DateInterval https://secure.php.net/manual/en/class.dateinterval.php
     */
    private $request_timeout = 'P1W';
    /**
     * @var int The length of the generated token for validating requests
     */
    private $token_length = 32;
    /**
     * @var int The minimum length for a new user password
     */
    private $password_min_length = 8;
    /**
     * @var string Name of the route for reset, used with the UrlGenerator
     */
    private $reset_route = 'POST_GET_password_reset_token';
    /**
     * @var string Name of the route for request, used with the UrlGenerator
     */
    private $request_route = 'POST_GET_password_request';

    /**
     * Returns routes to connect to the given application.
     *
     * @param Application $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     */
    public function connect(Application $app)
    {
        $this->app = $app;

        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        // handle the password reset form (display and saving)
        $controllers->match('/reset/{token}', function (Application $app, Request $request, $token) {
            $this->cleanupRequests();

            if ($this->validateRequest($token)) {
                if ($request->isMethod('GET')) {
                    return $app['twig']->render('reset_password.twig', [
                        'reset_route' => $this->reset_route,
                        'token' => $token
                    ]);
                } elseif ($request->isMethod('POST')) {
                    $password = $request->request->get('password');
                    $password_repeat = $request->request->get('password_repeat');
                    if ($this->validateNewPassword($password, $password_repeat) === false) {
                        $app['session']->getFlashBag()
                            ->add('error', sprintf('Fehler beim Zurücksetzen des Passworts. Das gewählte Passwort muss aus mindestens %s Zeichen (Buchstaben *und* Ziffern) bestehen.', $this->password_min_length));
                        return $app->redirect($app['url_generator']->generate($this->reset_route, ['token' => $token]));
                    } else {
                        $details = $this->getRecoveryRequest($token);
                        $this->updatePassword($details['uid'], $password);
                        $this->closeRequest($token);
                        $app['session']->getFlashBag()
                            ->add('success', 'Dein Passwort wurde erfolgreich zurückgesetzt, du kannst dich jetzt einloggen.');
                    }
                }
            } else {
                $app['session']->getFlashBag()
                    ->add('error', 'Fehler beim Zurücksetzen des Passworts. Bitte versuche es noch einmal, oder benachrichtige das IT-Team.');
            }
            return $app->redirect('/login');
        })->method('POST|GET');

        // handle the password request form to initiate a reset process
        $controllers->match('/request', function (Application $app, Request $request) {
            if ($request->isMethod('GET')) {
                return $app['twig']->render('request_reset_password.twig', [
                    'request_route' => $this->request_route
                ]);
            }
            $email = $request->request->get('email');
            $member = $app['ldap']->getMemberByMail($email);
            if ($member !== false) {
                $token = $this->registerRequest($member['uid'][0], $email);
                $this->sendRecoveryMail($member, $token);
                $app['session']->getFlashBag()
                    ->add('success', 'Wir haben dir einen Link zum Zurücksetzen deines Passworts zugeschickt. Bitte klicke auf diesen Link.');
            } else {
                $app['session']->getFlashBag()
                    ->add('error', 'Diese Email-Adresse ist nicht vergeben.');
            }
            return $app->redirect('/login');
        })->method('POST|GET');;

        return $controllers;
    }

    /**
     * Deletes all old requests from the database. A request is considered old,
     * if its registration happened before now - $timeout.
     */
    private function cleanupRequests()
    {
        // calculate the timeout, now minus a given interval
        $limit = (new \DateTime())->sub(new \DateInterval($this->request_timeout))->getTimestamp();
        // delete all entries which are older than the calculated limit, data type is UNIX timestamp
        $this->app['db']->executeUpdate('DELETE FROM `password_requests` WHERE `created` < ?', [$limit]);
    }

    /**
     * Validates the given $token by looking it up in the database.
     *
     * @param string $token
     * @return bool True if the token is valid, false otherwise.
     */
    private function validateRequest($token)
    {
        $result = $this->app['db']->fetchAll('SELECT * FROM `password_requests` WHERE `token` = ?', [$token]);
        return (count($result) === 1);
    }

    /**
     * Validates the given password.
     *
     * @param string $password
     * @param string $password_repeat
     * @return bool True on success, false otherwise.
     */
    private function validateNewPassword($password, $password_repeat)
    {
        if ($password !== $password_repeat) {
            return false;
        }
        if (strlen($password) < $this->password_min_length) {
            return false;
        }
        // see https://github.com/studieren-ohne-grenzen/dashboard/blob/develop/public/index.php#L30
        // TODO: could be refactored, maybe Symfony\Security supports some kind of `password policy checker`
        if (preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $password) === false) {
            return false;
        }
        return true;
    }

    /**
     * Returns the recovery request from the database.
     *
     * @param $token
     * @return array
     */
    private function getRecoveryRequest($token)
    {
        return $this->app['db']->fetchAssoc('SELECT * FROM `password_requests` WHERE `token` = ?', [$token]);
    }

    /**
     * Finally updates the users password after a successful recovery.
     *
     * @param $uid
     * @param $password
     */
    private function updatePassword($uid, $password)
    {
        $dn = sprintf('uid=%s,ou=active,ou=people,o=sog-de,dc=sog', $uid);
        // force the password update
        $this->app['ldap']->forceUpdatePassword($dn, $password);
    }

    /**
     * Deletes the request associated with the given $token from the database.
     *
     * @param string $token
     */
    private function closeRequest($token)
    {
        $this->app['db']->delete('password_requests', ['token' => $token]);
    }

    /**
     * Registers the given $email by storing it in the database. The method
     * also generates and returns a random token associated with this request.
     *
     * @param string $uid
     * @param string $email
     * @return string The token associated with this request.
     */
    private function registerRequest($uid, $email)
    {
        // delete all existing requests first
        $this->app['db']->delete('password_requests', ['email' => $email]);
        $token = $this->app['random']($this->token_length);
        $this->app['db']->insert('password_requests',
            ['email' => $email, 'token' => $token, 'uid' => $uid, 'created' => time()]
        );
        return $token;
    }

    /**
     * Sends the recovery email containing a link with the $token to $email.
     *
     * @param array $member Array of attributes for the member as returned by \Zend\Ldap
     * @param string $token The random token which is passed to the reset URL for validation
     * @return int The number of successful mail deliveries.
     */
    private function sendRecoveryMail($member, $token)
    {
        $text = "<p>Hallo %s!</p>\n
        <p>Du hast über das SOG Dashboard das Zurücksetzen des Passworts für deinen SOG Account angefordert. Falls du diesen Vorgang nicht von dir aus gestartet hast, musst du nichts unternehmen.</p>\n
        <p>Das Passwort kannst du hier zurücksetzen: %s</p>\n
        <p>Dein Benutzername lautet: %s</p>\n
        <p>Bei Problemen kannst du einfach auf diese Mail antworten.</p>\n
        <p>Mit freundlichen Grüßen, dein SOG IT-Ressort</p>";
        $text = sprintf($text,
            $member['displayname'][0],
            $this->app['url_generator']->generate($this->reset_route, ['token' => $token], UrlGenerator::ABSOLUTE_URL),
            $member['uid'][0]
        );
        $message = \Swift_Message::newInstance()
            ->addTo($member['mail-alternative'][0], $member['displayName'][0])
            ->addFrom($this->app['mailer.from'])
            ->setSubject('[SOG Dashboard] Passwort zurücksetzen')
            ->setBody($text, 'text/html');
        return $this->app['mailer']->send($message);
    }
}