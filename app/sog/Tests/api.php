<?php
/*
 * This file demonstrates the API usage. Currently methods for creating a new (inactive) user and requesting group
 * membership are implemented.
 */

// load necessary files:
// 1) auto-loading through composer
require_once __DIR__ . '/../../../vendor/autoload.php';
// 2) config file
require_once '../../config.php';

// create API instance
$api = new SOG\Api\SogDashboardApi($dashboard_config);

// create a new user, will return the new username
$username = $api->createUser('Peter', 'Lustig', 'leonhard.melzer@studieren-ohne-grenzen.org', 'lg_karlsruhe');

// add newly created user to additional group
$api->requestGroupMembership($username, 'ressort_it');

printf('Created user %s', $username);