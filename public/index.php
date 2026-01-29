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

function resolveClientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $ip = trim(explode(',', $candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

$clientIp = resolveClientIp();
$blockWindowMinutes = (int)($_ENV['BLOCK_404_WINDOW_MINUTES'] ?? 10);
$blockThreshold = (int)($_ENV['BLOCK_404_THRESHOLD'] ?? 10);
$blockMinutes = (int)($_ENV['BLOCK_404_DURATION_MINUTES'] ?? 60);

$blockModel = new App\Models\BlockedIpModel();
if ($blockModel->isBlocked($clientIp)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$visitLogId = null;
$path = parse_url($uri, PHP_URL_PATH);
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !preg_match('#^/admin#', $path)) {
    $shouldLog = $accept === '' || stripos($accept, 'text/html') !== false;

    if ($shouldLog) {
        $visitModel = new App\Models\VisitorLogModel();
        $visitLogId = $visitModel->createLog(
            $path ?? '/',
            (string)(parse_url($uri, PHP_URL_QUERY) ?? ''),
            (string)($_SERVER['HTTP_REFERER'] ?? ''),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            $clientIp,
            session_id()
        );
    }
}

if ($visitLogId) {
    register_shutdown_function(static function () use (
        $visitLogId,
        $clientIp,
        $blockWindowMinutes,
        $blockThreshold,
        $blockMinutes
    ) {
        $statusCode = http_response_code() ?: 200;
        $visitModel = new App\Models\VisitorLogModel();
        $visitModel->updateStatusCode(
            $visitLogId,
            $statusCode,
            $clientIp,
            $blockWindowMinutes,
            $blockThreshold,
            $blockMinutes
        );
    });
}

//twig globals
$twig->addGlobal('site_title', 'WY Holdings');
$twig->addGlobal('event_date', 'We make Your Holding dreams');
$twig->addGlobal('visit_log_id', $visitLogId);

$router = new App\Router($twig);

require_once __DIR__ . '/../routes/web.php';

$router->dispatch($uri);


?>
