<?php
// 1. Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

// 2. .env 먼저 불러오기
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// 3. 라우터 및 라우트 등록
use App\Router;
$router = new Router();
require_once __DIR__ . '/../routes/web.php';

// 4. 라우팅 디스패치
$router->dispatch($_SERVER['REQUEST_URI']);

?>