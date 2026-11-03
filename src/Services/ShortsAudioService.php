<?php

namespace App\Services;

use RuntimeException;

class ShortsAudioService
{
    public function mix(string $ffmpeg, string $voicePath, string $target, float $voiceDuration, float $introDuration, string $bgmPath = '', float $volume = 0.12): void
    {
        if (!is_readable($voicePath) || $voiceDuration <= 0) { throw new RuntimeException('내레이션 파일 또는 길이가 올바르지 않습니다.'); }
        $total = $voiceDuration + $introDuration;
        $duration = number_format($total, 6, '.', '');
        $inputs = '-i ' . escapeshellarg($voicePath);
        $filter = '[0:a]loudnorm=I=-16:TP=-2:LRA=11,aresample=48000,aformat=channel_layouts=stereo,asetpts=N/SR/TB[normalized]';
        // Explicit silence avoids discontinuous timestamps from adelay with large loudnorm frames.
        if ($introDuration > 0) {
            $filter .= ';anullsrc=r=48000:cl=stereo,atrim=duration=' . number_format($introDuration, 6, '.', '') . '[lead]'
                . ';[lead][normalized]concat=n=2:v=0:a=1,asetpts=N/SR/TB,apad,atrim=duration=' . $duration . '[voice]';
        } else { $filter .= ';[normalized]apad,atrim=duration=' . $duration . '[voice]'; }
        if ($bgmPath !== '' && $volume > 0) {
            if (!is_readable($bgmPath)) { throw new RuntimeException('선택한 배경음악 파일을 읽을 수 없습니다.'); }
            $inputs .= ' -stream_loop -1 -t ' . escapeshellarg($duration) . ' -i ' . escapeshellarg($bgmPath);
            $gain = number_format(max(0, min(0.3, $volume)), 4, '.', '');
            $fade = min(0.8, $total / 4);
            $fadeStart = number_format($total - $fade, 6, '.', '');
            $filter .= ';[voice]asplit=2[narration][sidechain];[1:a]loudnorm=I=-14:TP=-2:LRA=7,aresample=48000,aformat=channel_layouts=stereo,asetpts=N/SR/TB,volume=' . $gain
                . ',apad,atrim=duration=' . $duration . ',asetpts=PTS-STARTPTS,afade=t=in:d=0.35,afade=t=out:st=' . $fadeStart . ':d=' . $fade . '[music]'
                . ';[music][sidechain]sidechaincompress=threshold=0.06:ratio=2:attack=20:release=350[ducked]'
                // Both streams span the full timeline. Restore amix's fixed 1/2 gain; works on FFmpeg 4.2 too.
                . ';[narration][ducked]amix=inputs=2:duration=first:dropout_transition=0,volume=2,alimiter=limit=0.95:level=false[out]';
        } else { $filter .= ';[voice]anull[out]'; }
        $command = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y ' . $inputs . ' -filter_complex ' . escapeshellarg($filter)
            . ' -map ' . escapeshellarg('[out]') . ' -t ' . escapeshellarg($duration) . ' -c:a aac -b:a 192k -ar 48000 ' . escapeshellarg($target) . ' 2>&1';
        exec($command, $output, $code);
        if ($code !== 0 || !is_file($target) || filesize($target) === 0) { throw new RuntimeException("음성·배경음악 믹싱 실패.\n" . implode("\n", array_slice($output, -10))); }
    }
}
