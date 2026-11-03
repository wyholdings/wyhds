<?php

// Read-only voice lookup. No text synthesis, OpenAI requests or character-generation charges.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
try { Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load(); }
catch (Throwable $error) { fwrite(STDERR, "Unable to load .env. Check syntax and permissions.\n"); exit(1); }
try {
    $voice = App\Services\GoogleSpeechClient::configuration()['voice'];
    (new App\Services\GoogleSpeechClient())->checkVoice($voice);
    echo '[OK] Google Cloud Text-to-Speech: ' . $voice . PHP_EOL;
    echo 'Only the voice list was checked. No audio was generated.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
