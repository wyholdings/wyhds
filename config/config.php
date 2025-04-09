<?php
require_once __DIR__ . '/../vendor/autoload.php'; // dotenv 사용

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

define('DB_HOST', $_ENV['DB_HOST']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);

// 에러 숨기기
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 세션 설정
require_once 'session.php';
?>
