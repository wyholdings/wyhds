<?php

// Quantitative local audio check: real FFmpeg, synthetic voice/music, no APIs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
if (count($argv) < 2) { fwrite(STDERR, "Usage: php tests/shorts_audio_integration.php /path/to/ffmpeg\n"); exit(1); }
$ffmpeg = $argv[1];
$dir = sys_get_temp_dir() . '/shorts-audio-' . bin2hex(random_bytes(6));
mkdir($dir);
function audioRun(string $command): void
{
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) { throw new RuntimeException(implode("\n", $output)); }
}
function audioCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
function rms(string $pcm, float $start, float $end): float
{
    if ($end > strlen($pcm) / 96000) { throw new RuntimeException('Audio ended before the measurement window.'); }
    $samples = unpack('v*', substr($pcm, (int)round($start * 48000) * 2, (int)round(($end - $start) * 48000) * 2));
    $sum = 0.0;
    foreach ($samples as $sample) { $value = ($sample > 32767 ? $sample - 65536 : $sample) / 32768; $sum += $value * $value; }
    return sqrt($sum / max(1, count($samples)));
}
try {
    $source = "aevalsrc=exprs='0.23*sin(2*PI*220*t)*(between(t,1,2.3)+between(t,4,5.3))':s=24000:d=6";
    audioRun(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg($source) . ' ' . escapeshellarg($dir . '/voice.wav'));
    audioRun(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg('sine=frequency=880:sample_rate=48000:duration=0.7') . ' ' . escapeshellarg($dir . '/music.wav'));
    $mixer = new App\Services\ShortsAudioService();
    $mixer->mix($ffmpeg, $dir . '/voice.wav', $dir . '/mix.aac', 6, 0.6, $dir . '/music.wav', 0.25);
    $mixer->mix($ffmpeg, $dir . '/voice.wav', $dir . '/plain.aac', 6, 0.6);
    $bands = [];
    foreach (['mix' => [220, 880], 'plain' => [220]] as $name => $frequencies) {
        foreach ($frequencies as $frequency) {
            $target = $dir . '/' . $name . '-' . $frequency . '.pcm';
            audioRun(escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($dir . '/' . $name . '.aac')
                . ' -af ' . escapeshellarg('bandpass=f=' . $frequency . ':width_type=h:w=30') . ' -ac 1 -ar 48000 -f s16le ' . escapeshellarg($target));
            $bands[$name][$frequency] = file_get_contents($target);
        }
    }
    $music = $bands['mix'][880];
    $quiet = rms($music, 4.0, 4.3);
    $ducked = rms($music, 2.0, 2.3);
    audioCheck($quiet > 0.001 && $ducked < $quiet * 0.85, 'music drops automatically under speech and recovers during a gap');
    audioCheck(rms($music, 0.3, 0.5) > 0.001 && rms($music, 4.0, 4.3) > 0.001, 'short music loops across the intro and full narration');
    audioCheck(rms($music, 6.5, 6.58) < $quiet * 0.25, 'music fades down at the end instead of cutting abruptly');
    $ratio = rms($bands['mix'][220], 2.0, 2.3) / rms($bands['plain'][220], 2.0, 2.3);
    audioCheck($ratio > 0.94 && $ratio < 1.06, 'mixing music preserves narration level');
    audioCheck(rms($bands['plain'][220], 0.1, 0.4) < 0.0001, 'voice-only output retains the silent thumbnail lead-in');
    audioCheck(rms($bands['plain'][220], 1.0, 1.4) < 0.0001 && rms($bands['plain'][220], 1.8, 2.0) > 0.001, 'speech starts after the full thumbnail offset, without timestamp gaps shortening the delay');
    $decodedDuration = strlen($music) / 96000;
    audioCheck(abs($decodedDuration - 6.6) < 0.08, 'audio ends at narration duration with no outro padding (decoded ' . $decodedDuration . ' seconds)');
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
echo 'Audio integration passed. No API requests were made.' . PHP_EOL;
