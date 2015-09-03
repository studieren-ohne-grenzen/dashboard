<?php
namespace SOG\Dashboard;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;

/**
 * TODO: implement request/accept/drop membership things and the manage-members route here
 *
 * Class GroupControllerProvider
 * @package SOG\Dashboard
 */
class GroupControllerProvider implements ControllerProviderInterface
{

    /**
     * {@inheritdoc}
     */
    public function connect(Application $app)
    {
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

        return $controllers;
    }
}