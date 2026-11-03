<?php

// One bounded stock search; never calls OpenAI or downloads video. Does not print credentials or raw provider bodies.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
$client = null;
$failed = false;
try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] Unable to load .env. Check its syntax and file permissions.' . PHP_EOL);
    exit(1);
}
try {
    $client = new App\Services\PixabayVideoClient();
    $hits = $client->search($argv[1] ?? 'bedroom');
    echo '[OK] Pixabay search: ' . count($hits) . ' results' . PHP_EOL;
} catch (Throwable $error) {
    $failed = true;
    echo '[ERROR] ' . $error->getMessage() . PHP_EOL;
}
if ($client !== null) {
    try { echo json_encode($client->diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; }
    catch (Throwable $error) { echo '[ERROR] ' . $error->getMessage() . PHP_EOL; $failed = true; }
}
echo 'No OpenAI requests or video downloads were made. Cached results and cooldowns are respected.' . PHP_EOL;
exit($failed ? 1 : 0);
