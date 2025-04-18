<?php
// index.php
session_start();

require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'];

// admin 경로에 대해 로그인 확인 (예외 경로는 제외)
if (preg_match('#^/admin#', $uri) && !preg_match('#^/admin/login#', $uri)) {
    AuthMiddleware::checkAdminAuth();
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');
$twig = new \Twig\Environment($loader);

$router = new App\Router($twig);

require_once __DIR__ . '/../routes/web.php';

$router->dispatch($uri);


?>