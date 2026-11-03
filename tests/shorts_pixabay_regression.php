<?php

// Offline only. GD/FreeType is required to verify actual Korean font bounds.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';

use App\Services\PixabayVideoClient;
use App\Services\ShortsThumbnailService;

function verifyPixabay(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}

class FixturePixabay extends PixabayVideoClient
{
    public int $calls = 0;
    public bool $fail = false;
    protected function fetchSearch(array $params): array
    {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('fixture transport failure'); }
        verifyPixabay($params['safesearch'] === 'true' && $params['video_type'] === 'film', 'safe, bounded footage search');
        return ['hits' => [['id' => 123, 'query' => $params['q']]]];
    }
}

$dir = sys_get_temp_dir() . '/shorts-pixabay-' . bin2hex(random_bytes(6));
mkdir($dir);
$_ENV['PIXABAY_CACHE_ROOT'] = $dir;
$_ENV['PIXABAY_API_KEY'] = '';
$rejected = false;
try { new FixturePixabay(); } catch (RuntimeException $e) { $rejected = true; }
verifyPixabay($rejected, 'missing key fails without contacting a provider');
$_ENV['PIXABAY_API_KEY'] = 'test-secret-never-a-real-key';

try {
    $client = new FixturePixabay();
    $first = $client->search('bedroom');
    $second = $client->search('bedroom');
    verifyPixabay($first === $second && $client->calls === 1, 'identical searches use the persisted 24-hour cache');
    $cache = glob($dir . '/*.json')[0];
    verifyPixabay(strpos(file_get_contents($cache), $_ENV['PIXABAY_API_KEY']) === false, 'cache does not store API credentials');
    touch($cache, time() - 86401);
    $client->search('bedroom');
    verifyPixabay($client->calls === 2, 'expired cache performs one new search');
    $client->fail = true;
    try { $client->search('failed-query'); } catch (RuntimeException $e) { /* expected */ }
    $client->fail = false;
    $client->search('failed-query');
    verifyPixabay($client->calls === 4, 'failed search releases the lock and is never cached');
    $before = $client->calls;
    foreach (['', str_repeat('a', 101)] as $query) {
        $failed = false;
        try { $client->search($query); } catch (RuntimeException $e) { $failed = true; }
        verifyPixabay($failed && $client->calls === $before, 'invalid queries fail locally');
    }

    $hit = static function (int $id, int $width, int $height): array {
        return ['id' => $id, 'duration' => 8, 'pageURL' => 'https://pixabay.com/videos/id-' . $id . '/', 'user' => 'fixture',
            'videos' => ['medium' => ['url' => 'https://cdn.pixabay.com/video/' . $id . '.mp4', 'width' => $width, 'height' => $height, 'size' => 1000]]];
    };
    $landscape = $hit(1, 1920, 1080);
    $portrait = $hit(2, 1080, 1920);
    verifyPixabay($client->select([$landscape, $portrait])['id'] === 2, 'portrait footage is preferred');
    verifyPixabay($client->select([$landscape, $portrait], [2])['id'] === 1, 'unused footage is preferred to repeated clips');
    $portrait['videos']['large'] = ['url' => 'https://cdn.pixabay.com/video/2-4k.mp4', 'width' => 2160, 'height' => 3840, 'size' => 70000000];
    verifyPixabay($client->select([$portrait])['download_url'] === $portrait['videos']['medium']['url'], 'practical HD rendition is preferred over 4K');
    $bad = $hit(3, 1080, 1920);
    foreach (['http://cdn.pixabay.com/a.mp4', 'https://cdn.pixabay.com.evil.test/a.mp4', 'https://127.0.0.1/a.mp4', 'https://user@cdn.pixabay.com/a.mp4'] as $url) {
        $bad['videos']['medium']['url'] = $url;
        verifyPixabay($client->select([$bad]) === null, 'unsafe video URL is rejected');
        $failed = false;
        try { $client->download(['download_url' => $url], $dir . '/unsafe.mp4'); } catch (RuntimeException $e) { $failed = true; }
        verifyPixabay($failed && !is_file($dir . '/unsafe.mp4.part'), 'unsafe download fails before creating files or network requests');
    }
    $bad = $hit(4, 1080, 1920);
    $bad['videos']['medium']['size'] = 83886081;
    verifyPixabay($client->select([$bad]) === null, 'oversized footage is rejected before download');

    $font = PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/malgun.ttf' : '/usr/share/fonts/truetype/nanum/NanumGothic.ttf';
    $thumb = new ShortsThumbnailService($_ENV['SHORTS_FONT_PATH'] ?? $font);
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 44, 76, 105));
    imagepng($image, $dir . '/background.png');
    imagedestroy($image);
    foreach (['침대 속 진드기', '고양이가 보내는 다섯 가지 신호', str_repeat('긴제목테스트', 10), '2026 AI Video Workflow', '잠', ''] as $title) {
        $layout = $thumb->render($dir . '/background.png', $dir . '/cover.png', $title, '매일 쓰는 침대, 알고 계셨나요?');
        $bottom = 0;
        foreach ($layout['lines'] as $line) {
            verifyPixabay($line['x'] >= 70 && $line['x'] + $line['width'] <= 1000
                && $line['y'] > $bottom && $line['y'] + $line['height'] < 1100, 'headline fits its safe area and never overlaps');
            $bottom = $line['y'] + $line['height'];
        }
        if ($title === '침대 속 진드기') {
            verifyPixabay(array_column($layout['lines'], 'text') === ['침대 속', '진드기'], 'bedroom title uses whole words with no orphan syllable');
        }
        verifyPixabay(getimagesize($dir . '/cover.png')[0] === 1080 && getimagesize($dir . '/cover.png')[1] === 1920, 'thumbnail exports at 1080x1920');
    }
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
echo 'All Pixabay and thumbnail checks passed. No API calls were made.' . PHP_EOL;
