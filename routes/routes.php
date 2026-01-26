<?php

/** @var Bramus\Router\Router $router */

// Define routes here
$router->get('/test', App\Controllers\IndexController::class . '@test');
$router->get('/', App\Controllers\IndexController::class . '@test');


$router->get('/facilities', App\Controllers\FacilityController::class . '@index');

$router->get('/facilities/search', App\Controllers\FacilityController::class . '@search');

$router->get('/facilities/(\d+)', App\Controllers\FacilityController::class . '@show');
$router->post('/facilities', App\Controllers\FacilityController::class . '@store');
$router->put('/facilities/(\d+)', App\Controllers\FacilityController::class . '@update');
$router->delete('/facilities/(\d+)', App\Controllers\FacilityController::class . '@delete');

