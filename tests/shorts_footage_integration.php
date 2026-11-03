<?php

// Real local media integration. Creates synthetic footage; never contacts an API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php tests/shorts_footage_integration.php /path/to/ffmpeg /path/to/ffprobe\n");
    exit(1);
}
$ffmpeg = $argv[1];
$ffprobe = $argv[2];
$dir = sys_get_temp_dir() . '/shorts-footage-' . bin2hex(random_bytes(6));
mkdir($dir);
function footageCommand(string $command): string
{
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) { throw new RuntimeException(implode("\n", $lines)); }
    return implode("\n", $lines);
}
function footageCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
try {
    $reflection = new ReflectionClass(App\Services\ShortsAutomationService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $font = PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/malgun.ttf' : '/usr/share/fonts/truetype/nanum/NanumGothic.ttf';
    foreach (['ffmpegPath' => $ffmpeg, 'ffprobePath' => $ffprobe, 'fontPath' => $font, 'bgmPath' => ''] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($service, $value);
    }
    $invoke = static function (string $name, ...$args) use ($reflection, $service) {
        $method = $reflection->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($service, $args);
    };
    $clips = [];
    foreach (['testsrc2=size=1280x720:rate=25', 'testsrc2=size=720x1280:rate=24'] as $index => $source) {
        $path = $dir . '/source-' . $index . '.mp4';
        footageCommand(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg($source)
            . ' -f lavfi -i ' . escapeshellarg('sine=frequency=440:sample_rate=48000')
            . ' -t 0.8 -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac ' . escapeshellarg($path));
        $clips[] = ['path' => $path, 'media' => $invoke('probeFootage', $path)];
    }
    $invoke('renderFootageVideo', $clips, $dir . '/scenes.mp4', [2.17, 3.73]);
    $data = json_decode(footageCommand(escapeshellarg($ffprobe) . ' -v error -count_frames -show_streams -show_format -of json ' . escapeshellarg($dir . '/scenes.mp4')), true);
    $stream = $data['streams'][0];
    footageCheck(count($data['streams']) === 1 && $stream['codec_type'] === 'video', 'stock footage original audio is removed');
    footageCheck((int)$stream['width'] === 1080 && (int)$stream['height'] === 1920 && $stream['r_frame_rate'] === '30/1', 'mixed landscape/portrait and variable frame rates normalize to 1080x1920 at 30 fps');
    footageCheck((int)$stream['nb_read_frames'] === 177 && abs((float)$data['format']['duration'] - 5.9) < 0.04, 'short clips loop to exact narration length without cumulative frame rounding drift');
    footageCommand(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($clips[0]['path']) . ' -frames:v 1 ' . escapeshellarg($dir . '/background.png'));
    (new App\Services\ShortsThumbnailService($font))->render($dir . '/background.png', $dir . '/cover.png', '침대 속 진드기', '매일 쓰는 침대, 알고 계셨나요?');
    $invoke('renderStillClip', $dir . '/cover.png', $dir . '/intro.mp4', 0.6);
    $invoke('concatVideoClips', [$dir . '/intro.mp4', $dir . '/scenes.mp4'], $dir . '/timeline.txt', $dir . '/timeline.mp4');
    footageCommand(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg('sine=frequency=220:sample_rate=24000') . ' -t 5.9 ' . escapeshellarg($dir . '/voice.wav'));
    $audio = $dir . '/timed.aac';
    (new App\Services\ShortsAudioService())->mix($ffmpeg, $dir . '/voice.wav', $audio, 5.9, 0.6, __DIR__ . '/../public/assets/bgm/shorts-ambient.m4a');
    (new App\Services\ShortsCaptionService())->writeAss($dir . '/captions.ass', [['text' => '음성과 함께 움직이는 영상이에요.', 'start' => 0, 'end' => 5.9]], PHP_OS_FAMILY === 'Windows' ? 'Malgun Gothic' : 'NanumGothic', 0.6);
    $invoke('renderVideo', $dir . '/timeline.mp4', $audio, $dir . '/captions.ass', $dir . '/final.mp4', 6.5);
    $data = json_decode(footageCommand(escapeshellarg($ffprobe) . ' -v error -count_frames -show_streams -show_format -of json ' . escapeshellarg($dir . '/final.mp4')), true);
    footageCheck(count($data['streams']) === 2 && $data['streams'][0]['codec_name'] === 'h264' && $data['streams'][1]['codec_name'] === 'aac', 'thumbnail/footage/subtitle/voice/music pipeline exports H.264 and AAC');
    footageCheck((int)$data['streams'][0]['nb_read_frames'] === 195 && abs((float)$data['format']['duration'] - 6.5) < 0.06, 'timeline ends with narration and has no thank-you outro or padded ending');
    footageCheck(!$reflection->hasMethod('createOutroImage'), 'thank-you card renderer has been removed');
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
echo 'Real footage integration passed. No API calls were made.' . PHP_EOL;
