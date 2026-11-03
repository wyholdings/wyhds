<?php

namespace App\Services;

use RuntimeException;

/** Narration format: mono signed 16-bit PCM at 24 kHz. */
class NarrationPcm
{
    public static function encodeWav(string $pcm): string
    {
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    public static function decodeWav(string $wav): string
    {
        if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new RuntimeException('Google TTS 응답이 WAV 형식이 아닙니다.');
        }
        $format = null;
        $pcm = '';
        for ($offset = 12; $offset + 8 <= strlen($wav);) {
            $id = substr($wav, $offset, 4);
            $size = unpack('Vsize', substr($wav, $offset + 4, 4))['size'];
            $offset += 8;
            if ($size > strlen($wav) - $offset) { throw new RuntimeException('WAV 데이터가 잘렸습니다.'); }
            if ($id === 'fmt ' && $size >= 16) { $format = unpack('vencoding/vchannels/Vrate/Vbytes/vblock/vbits', substr($wav, $offset, 16)); }
            if ($id === 'data') { $pcm .= substr($wav, $offset, $size); }
            $offset += $size + ($size % 2);
        }
        if ($format === null || $format['encoding'] !== 1 || $format['channels'] !== 1 || $format['rate'] !== 24000
            || $format['bits'] !== 16 || $pcm === '' || strlen($pcm) % 2 !== 0) {
            throw new RuntimeException('음성은 24kHz, 16비트, 모노 PCM WAV여야 합니다.');
        }
        return $pcm;
    }

    public static function trimSilence(string $pcm): string
    {
        $length = strlen($pcm);
        $window = 480;
        $first = 0;
        $last = $length;
        $audible = static function (string $chunk): bool {
            foreach (unpack('v*', $chunk) ?: [] as $sample) {
                if (abs($sample > 32767 ? $sample - 65536 : $sample) > 120) { return true; }
            }
            return false;
        };
        while ($first + $window < $length && !$audible(substr($pcm, $first, $window))) { $first += $window; }
        if (!$audible(substr($pcm, $first, min($window, $length - $first)))) { throw new RuntimeException('생성된 음성에 발화가 없습니다.'); }
        while ($last - $window > $first && !$audible(substr($pcm, $last - $window, $window))) { $last -= $window; }
        $start = max(0, $first - 2880);
        return substr($pcm, $start, min($length, $last + 4800) - $start);
    }
}
