<?php

// Fake HTTP responses only. No network requests and no waits.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';

class RateFixturePixabay extends App\Services\PixabayVideoClient
{
    public static int $calls = 0;
    public static array $response = [];
    protected function sendSearchRequest(array $params): array
    {
        self::$calls++;
        return self::$response;
    }
}
function rateCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
function rateError(callable $callback): string
{
    try { $callback(); } catch (RuntimeException $error) { return $error->getMessage(); }
    throw new RuntimeException('Expected a rate or access error');
}
function rateResponse(int $status = 200, array $headers = [], string $body = '{"hits":[]}'): void
{
    RateFixturePixabay::$response = ['ok' => true, 'status' => $status, 'headers' => $headers, 'body' => $body];
}

$dir = sys_get_temp_dir() . '/pixabay-rate-' . bin2hex(random_bytes(6));
mkdir($dir);
$_ENV['PIXABAY_CACHE_ROOT'] = $dir;
$_ENV['PIXABAY_API_KEY'] = 'rate-fixture-key-not-real';
$stateFile = $dir . '/rate-' . hash('sha256', $_ENV['PIXABAY_API_KEY']) . '.json';
try {
    $client = new RateFixturePixabay();
    rateResponse();
    $client->search('cached bedroom');
    rateResponse(429, ['retry-after' => '120', 'x-ratelimit-limit' => '100', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '45'], 'API rate limit exceeded: ' . $_ENV['PIXABAY_API_KEY']);
    $error = rateError(static function () use ($client) { $client->search('blocked bedding'); });
    $diagnostics = $client->diagnostics();
    rateCheck($diagnostics['wait_seconds'] >= 119 && $diagnostics['wait_seconds'] <= 120
        && $diagnostics['last_response']['kind'] === 'api_rate_limit', '429 honors the longer Retry-After and provider reset');
    rateCheck(strpos($error . json_encode($diagnostics) . file_get_contents($stateFile), $_ENV['PIXABAY_API_KEY']) === false, 'errors and shared diagnostics never expose keys echoed by a provider');
    $calls = RateFixturePixabay::$calls;
    $second = new RateFixturePixabay();
    rateError(static function () use ($second) { $second->assertAvailable(); });
    rateError(static function () use ($second) { $second->search('another query'); });
    rateCheck(RateFixturePixabay::$calls === $calls, 'cooldown is shared across instances and queries without retrying HTTP');
    $second->assertAvailable('cached bedroom');
    rateCheck($second->search('cached bedroom') === [] && RateFixturePixabay::$calls === $calls, 'fresh 24-hour cache remains usable during provider cooldown');

    file_put_contents($stateFile, '{}');
    rateResponse(429, ['content-type' => 'text/html'], '<!DOCTYPE html><html>Access restricted</html>');
    $error = rateError(static function () use ($client) { $client->search('edge response'); });
    rateCheck(strpos($error, 'HTML') !== false && $client->diagnostics()['last_response']['kind'] === 'html_response', 'HTML 429 is identified separately from confirmed API quota exhaustion');
    rateCheck($client->diagnostics()['wait_seconds'] >= 59, '429 without timing headers gets a conservative 60-second cooldown');

    file_put_contents($stateFile, '{}');
    rateResponse(403, ['content-type' => 'text/html'], '<html>Forbidden</html>');
    $error = rateError(static function () use ($client) { $client->search('edge forbidden'); });
    rateCheck(strpos($error, 'HTML') !== false && strpos($error, 'PIXABAY_API_KEY') === false, 'HTML access restriction is not misreported as invalid credentials');

    file_put_contents($stateFile, '{}');
    rateResponse(429, ['retry-after' => gmdate('D, d M Y H:i:s', time() + 90) . ' GMT'], 'API rate limit exceeded');
    rateError(static function () use ($client) { $client->search('date retry'); });
    rateCheck($client->diagnostics()['wait_seconds'] >= 88, 'HTTP-date Retry-After is supported');

    file_put_contents($stateFile, '{}');
    rateResponse(200, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '30']);
    $client->search('last available request');
    $calls = RateFixturePixabay::$calls;
    rateError(static function () use ($second) { $second->assertAvailable(); });
    rateCheck(RateFixturePixabay::$calls === $calls && $second->diagnostics()['wait_seconds'] >= 29, 'successful final quota slot blocks further paid preparation until reset');
    $state = json_decode(file_get_contents($stateFile), true);
    $state['blocked_until'] = time() - 1;
    file_put_contents($stateFile, json_encode($state));
    rateResponse();
    $second->assertAvailable();
    $second->search('recovered search');
    rateCheck(RateFixturePixabay::$calls === $calls + 1, 'search can proceed once provider cooldown expires');

    file_put_contents($stateFile, '{}');
    $calls = RateFixturePixabay::$calls;
    for ($i = 0; $i < 30; $i++) { $client->search('local-budget-' . $i); }
    rateError(static function () use ($second) { $second->assertAvailable(); });
    rateError(static function () use ($second) { $second->search('over-budget'); });
    rateCheck(RateFixturePixabay::$calls === $calls + 30, 'different queries share a local request budget across PHP workers');
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
echo 'All Pixabay rate handling checks passed. No network calls were made.' . PHP_EOL;
