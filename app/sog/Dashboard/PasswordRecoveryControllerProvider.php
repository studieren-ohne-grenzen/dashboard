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
                            ->add('error', 'Fehler beim Zurücksetzen des Passworts. Bitte versuche es noch einmal, oder benachrichtige das IT-Team.');
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
            if ($this->mailIsKnown($email)) {
                $token = $this->registerRequest($email);
                $this->sendRecoveryMail($email, $token);
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
        if (count($result) === 1) {
            return true;
        } else {
            // TODO: log error?
            // $this->app['monolog']->error(??);
            return false;
        }
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
     * Checks if the given $email is known in the system. Also only returns true
     * iff the mail is unique (e.g. count > 1 fails).
     *
     * @param string $email
     * @return bool True if the email is known, false otherwise.
     */
    private function mailIsKnown($email)
    {
        return (1 === $this->app['ldap']->count(
                sprintf('(&(objectClass=inetOrgPerson)(mail-alternative=%s))', $email),
                'ou=active,ou=people,o=sog-de,dc=sog'
            ));
    }

    /**
     * Registers the given $email by storing it in the database. The method
     * also generates and returns a random token associated with this request.
     *
     * @param string $email
     * @return string The token associated with this request.
     */
    private function registerRequest($email)
    {
        // delete all existing requests first
        $this->app['db']->delete('password_requests', ['email' => $email]);
        $token = $this->app['random']($this->token_length);
        // TODO: use real UID - do we need it?
        // TODO: DECIDE: work on uid or email? Which makes more sense for the reset process
        $this->app['db']->insert('password_requests',
            ['email' => $email, 'token' => $token, 'uid' => 'leonhard.melzer', 'created' => time()]
        );
        return $token;
    }

    /**
     * Sends the recovery email containing a link with the $token to $email.
     *
     * @param string $email
     * @param string $token
     * @return int The number of successful mail deliveries.
     */
    private function sendRecoveryMail($email, $token)
    {
        $text = sprintf('Passwort hier zurücksetzen: %s',
            $this->app['url_generator']->generate($this->reset_route, ['token' => $token], UrlGenerator::ABSOLUTE_URL)
        );
        $message = \Swift_Message::newInstance()
            ->addTo($email)
            ->addFrom($this->app['mailer.from'])
            ->setSubject('[SOG Dashboard] Passwort zurücksetzen')
            ->setBody($text);
        return $this->app['mailer']->send($message);
    }
}