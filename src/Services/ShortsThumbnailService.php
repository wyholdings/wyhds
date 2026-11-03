<?php

namespace App\Services;

use RuntimeException;

/** Deterministic title rendering: real font bounds, balanced words, and a fixed safe area. */
class ShortsThumbnailService
{
    private string $font;
    private array $metrics = [];

    public function __construct(string $font)
    {
        $bold = str_ireplace(['NanumGothic.ttf', 'malgun.ttf'], ['NanumGothicBold.ttf', 'malgunbd.ttf'], $font);
        $this->font = is_file($bold) ? $bold : $font;
    }

    public function render(string $backgroundPath, string $targetPath, string $title, string $subtitle = ''): array
    {
        if (!function_exists('imagettftext') || !is_readable($this->font)) {
            throw new RuntimeException('썸네일 생성에 GD FreeType과 읽을 수 있는 한글 폰트가 필요합니다.');
        }
        $source = @imagecreatefromstring((string)file_get_contents($backgroundPath));
        if ($source === false) { throw new RuntimeException('썸네일 배경을 읽을 수 없습니다.'); }
        $canvas = imagecreatetruecolor(1080, 1920);
        $scale = max(1080 / imagesx($source), 1920 / imagesy($source));
        $w = (int)round(1080 / $scale);
        $h = (int)round(1920 / $scale);
        imagecopyresampled($canvas, $source, 0, 0, (int)((imagesx($source) - $w) / 2), (int)((imagesy($source) - $h) / 2), 1080, 1920, $w, $h);
        imagedestroy($source);

        // Readable upper title zone with a gradual transition; retain the photograph below it.
        for ($y = 0; $y < 1920; $y++) {
            $opacity = $y < 1050 ? 0.78 - 0.16 * ($y / 1050) : 0.62 * (1 - min(1, ($y - 1050) / 550));
            if ($y > 1700) { $opacity = max($opacity, ($y - 1700) / 220 * 0.5); }
            imageline($canvas, 0, $y, 1079, $y, imagecolorallocatealpha($canvas, 7, 16, 30, (int)(127 * (1 - $opacity))));
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $accent = imagecolorallocate($canvas, 224, 255, 92);
        $muted = imagecolorallocate($canvas, 223, 230, 239);
        imagefilledrectangle($canvas, 80, 238, 88, 283, $accent);
        $this->text($canvas, 27, 108, 240, '오늘의 이야기', $white, false);
        imagefilledrectangle($canvas, 80, 321, 190, 327, $accent);

        $layout = $this->headlineLayout($title);
        $top = 385;
        $boxes = [];
        foreach ($layout['lines'] as $index => $line) {
            $metrics = $this->bounds($line, $layout['size']);
            $color = $index === count($layout['lines']) - 1 ? $accent : $white;
            $this->text($canvas, $layout['size'], 78, $top, $line, $color);
            $boxes[] = ['text' => $line, 'x' => 78, 'y' => $top, 'width' => $metrics['width'], 'height' => $metrics['height']];
            $top += $layout['line_height'];
        }
        $subtitle = $this->clean($subtitle, 48);
        if ($subtitle !== '') {
            $sub = $this->fitSingleLine($subtitle, 38, 880);
            $this->text($canvas, $sub['size'], 84, $top + 45, $sub['text'], $muted);
        }
        imagefilledrectangle($canvas, 80, 1780, 148, 1785, $accent);
        $this->text($canvas, 23, 169, 1767, 'SHORTS  /  핵심만 간단히', $white, false);
        $ok = imagepng($canvas, $targetPath);
        imagedestroy($canvas);
        if (!$ok) { throw new RuntimeException('썸네일 저장에 실패했습니다.'); }
        return ['lines' => $boxes, 'font_size' => $layout['size']];
    }

    public function headlineLayout(string $title): array
    {
        $title = $this->clean($title, 36);
        if ($title === '') { $title = '오늘의 이야기'; }
        $words = preg_split('/\s+/u', $title) ?: [$title];
        // Prefer whole words even if a slightly smaller font is necessary.
        foreach ([false, true] as $allowCharacterBreak) {
            for ($size = 152; $size >= 56; $size -= 2) {
                $units = $allowCharacterBreak ? preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY) : $words;
                $separator = $allowCharacterBreak ? '' : ' ';
                $best = null;
                $count = count($units);
                // Enumerate up to three balanced lines, rather than greedily overflowing the final syllable.
                $partitions = [[$count]];
                for ($a = 1; $a < $count; $a++) {
                    $partitions[] = [$a, $count];
                    for ($b = $a + 1; $b < $count; $b++) { $partitions[] = [$a, $b, $count]; }
                }
                foreach ($partitions as $cuts) {
                    $lines = [];
                    $widths = [];
                    $start = 0;
                    foreach ($cuts as $end) {
                        $line = trim(implode($separator, array_slice($units, $start, $end - $start)));
                        $start = $end;
                        if ($line === '') { continue 2; }
                        $width = $this->bounds($line, $size)['width'];
                        if ($width > 916) { continue 2; }
                        $lines[] = $line;
                        $widths[] = $width;
                    }
                    // Avoid a single orphan syllable when the title has enough content to balance.
                    if (count($lines) > 1 && mb_strlen(str_replace(' ', '', $title)) > 4
                        && min(array_map('mb_strlen', $lines)) < 2) { continue; }
                    $score = (max($widths) - min($widths)) / max($widths) + abs(count($lines) - 2) * 0.28;
                    if ($best === null || $score < $best['score']) { $best = ['lines' => $lines, 'score' => $score]; }
                }
                if ($best !== null) {
                    $heights = array_map(function ($line) use ($size) { return $this->bounds($line, $size)['height']; }, $best['lines']);
                    return ['lines' => $best['lines'], 'size' => $size, 'line_height' => max($heights) + (int)round($size * 0.28)];
                }
            }
        }
        throw new RuntimeException('썸네일 제목을 배치하지 못했습니다. 제목을 줄여주세요.');
    }

    private function clean(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private function fitSingleLine(string $text, int $size, int $width): array
    {
        while ($size > 25 && $this->bounds($text, $size)['width'] > $width) { $size--; }
        while ($this->bounds($text, $size)['width'] > $width) { $text = mb_substr($text, 0, -2) . '…'; }
        return ['text' => $text, 'size' => $size];
    }

    private function bounds(string $text, int $size): array
    {
        $key = $size . ':' . $text;
        if (isset($this->metrics[$key])) { return $this->metrics[$key]; }
        $b = imagettfbbox($size, 0, $this->font, $text);
        return $this->metrics[$key] = ['left' => min($b[0], $b[2], $b[4], $b[6]), 'top' => min($b[1], $b[3], $b[5], $b[7]),
            'width' => max($b[0], $b[2], $b[4], $b[6]) - min($b[0], $b[2], $b[4], $b[6]),
            'height' => max($b[1], $b[3], $b[5], $b[7]) - min($b[1], $b[3], $b[5], $b[7])];
    }

    private function text($canvas, int $size, int $x, int $top, string $text, int $color, bool $shadow = true): void
    {
        $box = $this->bounds($text, $size);
        $baseline = $top - $box['top'];
        $left = $x - $box['left'];
        if ($shadow) {
            $dark = imagecolorallocatealpha($canvas, 1, 6, 13, 25);
            for ($dx = -2; $dx <= 2; $dx += 2) {
                for ($dy = -2; $dy <= 2; $dy += 2) { imagettftext($canvas, $size, 0, $left + $dx + 2, $baseline + $dy + 5, $dark, $this->font, $text); }
            }
        }
        imagettftext($canvas, $size, 0, $left, $baseline, $color, $this->font, $text);
    }
}
