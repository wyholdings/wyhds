<?php

namespace App\Services;

use RuntimeException;

class ShortsCaptionService
{
    /** Paragraph boundaries come from generated PCM lengths, not estimated video duration. */
    public function buildCues(array $segments): array
    {
        $cues = [];
        $previousEnd = 0.0;
        foreach ($segments as $segment) {
            $start = (float)($segment['start'] ?? -1);
            $end = (float)($segment['end'] ?? -1);
            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '' || $start < $previousEnd - 0.001 || $end <= $start) {
                throw new RuntimeException('자막에 사용할 발화 문장과 시간이 올바르지 않습니다.');
            }
            $blocks = [];
            foreach (preg_split('/(?<=[,，.!?。！？])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $phrase) {
                foreach (array_chunk($this->wrapLines($phrase), 2) as $block) { $blocks[] = $block; }
            }
            $weights = array_map(static fn (array $lines): int => max(1, mb_strlen(preg_replace('/\s+/u', '', implode('', $lines)))), $blocks);
            $totalWeight = array_sum($weights);
            $cursor = $start;
            foreach ($blocks as $index => $lines) {
                // Only within a paragraph do we divide the measured duration by text length.
                $next = $index === count($blocks) - 1 ? $end : $cursor + ($end - $start) * $weights[$index] / $totalWeight;
                $cues[] = ['start' => $cursor, 'end' => $next, 'text' => implode("\n", $lines)];
                $cursor = $next;
            }
            $previousEnd = $end;
        }
        if ($cues === []) {
            throw new RuntimeException('표시할 자막이 없습니다.');
        }
        return $cues;
    }

    public function wrapLines(string $text): array
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (mb_strwidth($text) <= 28) { return [$text]; }
        $words = explode(' ', $text);
        $balanced = null;
        $bestScore = PHP_INT_MAX;
        foreach (range(1, max(1, count($words) - 1)) as $cut) {
            $left = implode(' ', array_slice($words, 0, $cut));
            $right = implode(' ', array_slice($words, $cut));
            if ($right === '' || mb_strwidth($left) > 28 || mb_strwidth($right) > 28) { continue; }
            $score = abs(mb_strwidth($left) - mb_strwidth($right));
            if ($score < $bestScore) { $balanced = [$left, $right]; $bestScore = $score; }
        }
        if ($balanced !== null) { return $balanced; }
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strwidth($candidate) <= 28) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') { $lines[] = $line; $line = ''; }
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                if ($line !== '' && mb_strwidth($line . $char) > 28) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $char;
            }
        }
        if ($line !== '') { $lines[] = $line; }
        return $lines;
    }

    public function writeAss(string $path, array $cues, string $fontName, float $offset): void
    {
        $body = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\nWrapStyle: 2\nScaledBorderAndShadow: yes\n\n";
        $body .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $body .= "Style: Main,{$fontName},64,&H00FFFFFF,&H00FFFFFF,&H00101010,&H80000000,-1,0,0,0,100,100,0,0,1,3.5,1,2,90,130,390,1\n\n";
        $body .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($cues as $cue) {
            $text = str_replace(['\\', '{', '}'], ['＼', '｛', '｝'], $cue['text']);
            $text = str_replace(["\r", "\n"], ['', '\\N'], $text);
            $body .= 'Dialogue: 0,' . $this->assTime($cue['start'] + $offset) . ',' . $this->assTime($cue['end'] + $offset)
                . ',Main,,0,0,0,,{\an2\pos(520,1500)\fad(50,70)}' . $text . "\n";
        }
        $this->save($path, $body);
    }

    public function writeSrt(string $path, array $cues, float $offset): void
    {
        $body = '';
        foreach ($cues as $index => $cue) {
            $body .= ($index + 1) . "\n" . $this->srtTime($cue['start'] + $offset) . ' --> ' . $this->srtTime($cue['end'] + $offset)
                . "\n" . $cue['text'] . "\n\n";
        }
        $this->save($path, $body);
    }

    private function assTime(float $seconds): string
    {
        $ticks = (int)round($seconds * 100);
        return sprintf('%d:%02d:%02d.%02d', intdiv($ticks, 360000), intdiv($ticks % 360000, 6000), intdiv($ticks % 6000, 100), $ticks % 100);
    }

    private function srtTime(float $seconds): string
    {
        $ticks = (int)round($seconds * 1000);
        return sprintf('%02d:%02d:%02d,%03d', intdiv($ticks, 3600000), intdiv($ticks % 3600000, 60000), intdiv($ticks % 60000, 1000), $ticks % 1000);
    }

    private function save(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('자막 파일 저장에 실패했습니다: ' . $path);
        }
    }
}
