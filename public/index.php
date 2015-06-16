<?php
require_once __DIR__ . '/../vendor/autoload.php';

$app = new Silex\Application();

// should be disabled on production
$app['debug'] = true;

require_once __DIR__ . '/../app/services.php';

// now, let's set up the routes

$app->get('/', function () use ($app) {
    return $app['twig']->render('home.twig', [
        'message' => 'Hello World'
    ]);
});

$app->run();