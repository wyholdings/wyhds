<?php

$router->get('/', [App\Controllers\HomeController::class, 'index']);
$router->get('/login', [App\Controllers\AuthController::class, 'showLoginForm']);
$router->post('/login', [App\Controllers\AuthController::class, 'login']);
$router->get('/logout', [App\Controllers\AuthController::class, 'logout']);

//견적 문의
$router->post('/contact/submit', [App\Controllers\ContactController::class, 'submit']);

?>