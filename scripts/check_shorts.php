<?php

// Run as the same OS user as PHP-FPM/Apache to verify actual file permissions.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
    echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . PHP_EOL;
    echo 'php.ini: ' . (php_ini_loaded_file() ?: '(none)') . PHP_EOL;
    $failed = false;
    foreach ((new App\Services\ShortsEnvironment())->inspect() as $check) {
        echo '[' . strtoupper($check['status']) . '] ' . $check['name'] . ': ' . $check['message'] . PHP_EOL;
        $failed = $failed || $check['status'] === 'error';
    }

    try {
        $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost')
            . ';port=' . ($_ENV['DB_PORT'] ?? 3306)
            . ';dbname=' . ($_ENV['DB_NAME'] ?? 'test') . ';charset=utf8mb4';
        $db = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $db->query('SELECT 1')->fetchColumn();
        echo '[OK] Database: connection successful' . PHP_EOL;
    } catch (Throwable $error) {
        echo '[ERROR] Database: check DB settings (code ' . $error->getCode() . ')' . PHP_EOL;
        $failed = true;
    }

    echo 'API authentication, model access, and credit balance are not checked. No generation requests were made.' . PHP_EOL;
    echo 'CLI and PHP-FPM/Apache may use different php.ini files; check the web runtime too.' . PHP_EOL;
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Unable to run checks. Verify Composer dependencies and .env configuration.' . PHP_EOL);
    exit(1);
}
