<?php
// index.php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');
$twig = new \Twig\Environment($loader);

$router = new App\Router($twig);

require_once __DIR__ . '/../routes/web.php';

$router->dispatch($_SERVER['REQUEST_URI']);


?>