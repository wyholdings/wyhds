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

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');
$twig = new \Twig\Environment($loader);

echo $twig->render('index.html.twig', [
    // 전달할 데이터가 있다면 여기에 작성
]);

?>