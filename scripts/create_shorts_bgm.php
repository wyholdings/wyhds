<?php

// Reproducible original ambient pad; no external recordings or generation APIs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
$ffmpeg = $argv[1] ?? 'ffmpeg';
$target = __DIR__ . '/../public/assets/bgm/shorts-ambient.m4a';
if (!is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); }
$chords = [[130.8128, 164.8138, 195.9977, 246.9417], [110, 130.8128, 164.8138, 195.9977],
    [87.3071, 130.8128, 164.8138, 220], [97.9989, 146.8324, 195.9977, 246.9417]];
$channels = [];
foreach ([0, 0.13] as $phase) {
    $bars = [];
    foreach ($chords as $bar => $notes) {
        $start = $bar * 8;
        $tone = [];
        foreach ($notes as $frequency) {
            $tone[] = '0.045*(sin(2*PI*' . $frequency . '*t+' . $phase . ')+0.12*sin(4*PI*' . $frequency . '*t))';
        }
        $bars[] = 'if(between(t,' . $start . ',' . ($start + 8) . '),pow(max(0,sin(PI*(t-' . $start . ')/8)),0.45)*(' . implode('+', $tone) . '),0)';
    }
    $channels[] = '(' . implode('+', $bars) . ')*(0.93+0.07*sin(2*PI*0.13*t))';
}
$source = "aevalsrc=exprs='" . implode('|', $channels) . "':s=48000:d=32";
$command = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg($source)
    . ' -af ' . escapeshellarg('lowpass=f=1800,afade=t=in:d=1,afade=t=out:st=31:d=1')
    . ' -c:a aac -b:a 128k -movflags +faststart ' . escapeshellarg($target) . ' 2>&1';
exec($command, $output, $code);
if ($code !== 0) { fwrite(STDERR, implode("\n", $output)); exit(1); }
echo $target . PHP_EOL;
