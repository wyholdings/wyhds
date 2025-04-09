<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;

$router = new Router();
require_once __DIR__ . '/../routes/web.php';

$router->dispatch($_SERVER['REQUEST_URI']);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

?>