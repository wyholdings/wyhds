<?php

// Offline preview; no API calls. Arguments: input image, output PNG, title, optional subtitle.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
if (count($argv) < 4) {
    fwrite(STDERR, "Usage: php scripts/preview_shorts_thumbnail.php input.jpg output.png 'title' ['subtitle']\n");
    exit(1);
}
try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    $config = App\Services\ShortsEnvironment::configuration();
    // Allow a portable preview on a developer machine with Linux deployment settings in .env.
    $font = is_file($config['font_path']) ? $config['font_path'] : (PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/malgun.ttf' : $config['font_path']);
    $layout = (new App\Services\ShortsThumbnailService($font))->render($argv[1], $argv[2], $argv[3], $argv[4] ?? '');
    echo json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
