<?php
declare(strict_types=1);

/**
 * Works out what a file is and, for video, the exact MediaSource type string
 * (e.g. video/mp4; codecs="avc1.64001F,mp4a.40.2") by reading the MP4/WebM header.
 */
final class MediaProbe
{
    public const FRAG_HELP = "This MP4 is not streamable yet. Convert it once (no quality loss) with:\n"
        . "  ffmpeg -i input.mp4 -c copy -movflags frag_keyframe+empty_moov+default_base_moof output.mp4";

    /** @return array{kind:string,mime:string} */
    public static function detect(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open $path");
        }
        try {
            $head = (string)fread($fh, 64);
            if (str_starts_with($head, "\xFF\xD8\xFF")) {
                return ['kind' => 'image', 'mime' => 'image/jpeg'];
            }
            if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
                return ['kind' => 'image', 'mime' => 'image/png'];
            }
            if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
                return ['kind' => 'image', 'mime' => 'image/gif'];
            }
            if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
                return ['kind' => 'image', 'mime' => 'image/webp'];
            }
            if (substr($head, 4, 4) === 'ftyp') {
                $brand = substr($head, 8, 4);
                if ($brand === 'avif' || $brand === 'avis') {
                    return ['kind' => 'image', 'mime' => 'image/avif'];
                }
                if (in_array($brand, ['heic', 'heix', 'mif1', 'msf1'], true)) {
                    throw new RuntimeException('HEIC photos do not open in most browsers. Convert to JPEG first.');
                }
                return ['kind' => 'video', 'mime' => self::mp4($fh)];
            }
            if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
                return ['kind' => 'video', 'mime' => self::webm($fh)];
            }
        } finally {
            fclose($fh);
        }
        throw new RuntimeException('Unsupported file. Use JPEG, PNG, GIF, WebP, AVIF, fragmented MP4 or WebM.');
    }

    private static function mp4($fh): string
    {
        $size = fstat($fh)['size'];
        $pos = 0;
        $moov = null;
        $moof = false;
        while ($pos + 8 <= $size && ($moov === null || !$moof)) {
            fseek($fh, $pos);
            $h = (string)fread($fh, 16);
            if (strlen($h) < 8) {
                break;
            }
            $len = unpack('N', $h)[1];
            $type = substr($h, 4, 4);
            $hdr = 8;
            if ($len === 1) {
                if (strlen($h) < 16) {
                    break;
                }
                $len = unpack('J', $h, 8)[1];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $size - $pos;
            }
            if ($len < $hdr || $pos + $len > $size) {
                break;
            }
            if ($type === 'moov') {
                if ($len > 64 << 20) {
                    throw new RuntimeException('MP4 header is unusually large');
                }
                fseek($fh, $pos + $hdr);
                $moov = (string)fread($fh, $len - $hdr);
            } elseif ($type === 'moof') {
                $moof = true;
            }
            $pos += $len;
        }
        if ($moov === null) {
            throw new RuntimeException('This MP4 has no header (moov box).');
        }
        if (!$moof || self::find($moov, 0, strlen($moov), 'mvex') === null) {
            throw new RuntimeException(self::FRAG_HELP);
        }

        $codecs = [];
        foreach (self::children($moov, 0, strlen($moov)) as [$type, $s, $e]) {
            if ($type !== 'trak' || ($stsd = self::path($moov, $s, $e, ['mdia', 'minf', 'stbl', 'stsd'])) === null) {
                continue;
            }
            $entries = self::children($moov, $stsd[0] + 8, $stsd[1]); // skip version/flags + entry count
            if ($entries && ($c = self::codec($moov, ...$entries[0])) !== null) {
                $codecs[] = $c;
            }
        }
        if (!$codecs) {
            throw new RuntimeException("Could not read the video codec. Pass it yourself with --mime 'video/mp4; codecs=\"...\"'");
        }
        return 'video/mp4; codecs="' . implode(',', $codecs) . '"';
    }

    private static function codec(string $b, string $type, int $s, int $e): ?string
    {
        switch ($type) {
            case 'avc1':
            case 'avc3':
                $c = self::find($b, $s + 78, $e, 'avcC');
                return $c && $c[1] - $c[0] >= 4
                    ? sprintf('%s.%02X%02X%02X', $type, ord($b[$c[0] + 1]), ord($b[$c[0] + 2]), ord($b[$c[0] + 3]))
                    : $type;
            case 'hvc1':
            case 'hev1':
                $c = self::find($b, $s + 78, $e, 'hvcC');
                return $c && $c[1] - $c[0] >= 13 ? self::hevc($type, substr($b, $c[0], 13)) : $type;
            case 'av01':
                $c = self::find($b, $s + 78, $e, 'av1C');
                if (!$c || $c[1] - $c[0] < 3) {
                    return 'av01.0.08M.08';
                }
                $b1 = ord($b[$c[0] + 1]);
                $b2 = ord($b[$c[0] + 2]);
                $profile = $b1 >> 5;
                $depth = ($b2 & 0x40) ? (($profile === 2 && ($b2 & 0x20)) ? 12 : 10) : 8;
                return sprintf('av01.%d.%02d%s.%02d', $profile, $b1 & 0x1F, ($b2 & 0x80) ? 'H' : 'M', $depth);
            case 'vp09':
                $c = self::find($b, $s + 78, $e, 'vpcC');
                if (!$c || $c[1] - $c[0] < 7) {
                    return 'vp09.00.10.08';
                }
                $p = $c[0] + 4; // full box
                return sprintf('vp09.%02d.%02d.%02d', ord($b[$p]), ord($b[$p + 1]), ord($b[$p + 2]) >> 4);
            case 'mp4a':
                foreach ([28, 44, 64] as $skip) { // ISO v0, QuickTime v1, QuickTime v2 sound entries
                    if ($c = self::find($b, $s + $skip, $e, 'esds')) {
                        return self::aac(substr($b, $c[0] + 4, $c[1] - $c[0] - 4));
                    }
                }
                return 'mp4a.40.2';
            case 'Opus':
                return 'opus';
            case 'fLaC':
                return 'flac';
            case 'ac-3':
            case 'ec-3':
                return $type;
            case 'encv':
            case 'enca':
                throw new RuntimeException('This file is already DRM-encrypted.');
        }
        return null;
    }

    private static function aac(string $d): string
    {
        $n = strlen($d);
        $p = 0;
        $descriptor = function () use ($d, $n, &$p): ?int {
            if ($p >= $n) {
                return null;
            }
            $tag = ord($d[$p++]);
            for ($k = 0; $k < 4 && $p < $n; $k++) {
                if (!(ord($d[$p++]) & 0x80)) {
                    break;
                }
            }
            return $tag;
        };
        if ($descriptor() !== 0x03 || $p + 3 > $n) {
            return 'mp4a.40.2';
        }
        $p += 2; // ES_ID
        $flags = ord($d[$p++]);
        if ($flags & 0x80) {
            $p += 2;
        }
        if ($flags & 0x40) {
            $p += 1 + ($p < $n ? ord($d[$p]) : 0);
        }
        if ($flags & 0x20) {
            $p += 2;
        }
        if ($descriptor() !== 0x04 || $p + 13 > $n) {
            return 'mp4a.40.2';
        }
        $oti = ord($d[$p]);
        $p += 13;
        if ($oti !== 0x40) {
            return sprintf('mp4a.%02x', $oti);
        }
        if ($descriptor() !== 0x05 || $p >= $n) {
            return 'mp4a.40.2';
        }
        $aot = ord($d[$p]) >> 3;
        if ($aot === 31 && $p + 1 < $n) {
            $aot = 32 + (((ord($d[$p]) & 0x07) << 3) | (ord($d[$p + 1]) >> 5));
        }
        return 'mp4a.40.' . $aot;
    }

    private static function hevc(string $type, string $c): string
    {
        $b1 = ord($c[1]);
        $compat = unpack('N', $c, 2)[1];
        $reversed = 0;
        for ($k = 0; $k < 32; $k++) {
            $reversed = ($reversed << 1) | ($compat & 1);
            $compat >>= 1;
        }
        $constraints = array_map('ord', str_split(substr($c, 6, 6)));
        while ($constraints && end($constraints) === 0) {
            array_pop($constraints);
        }
        $out = sprintf('%s.%s%d.%X.%s%d', $type, ['', 'A', 'B', 'C'][$b1 >> 6], $b1 & 0x1F, $reversed,
            ($b1 & 0x20) ? 'H' : 'L', ord($c[12]));
        foreach ($constraints as $x) {
            $out .= sprintf('.%X', $x);
        }
        return $out;
    }

    private static function webm($fh): string
    {
        fseek($fh, 0);
        $head = (string)fread($fh, 1 << 20);
        $cluster = strpos($head, "\x1F\x43\xB6\x75");
        if ($cluster !== false) {
            $head = substr($head, 0, $cluster); // codec ids live in Tracks, before the first Cluster
        }
        $known = ['V_VP9' => 'vp9', 'V_VP8' => 'vp8', 'V_AV1' => 'av01.0.08M.08', 'A_OPUS' => 'opus', 'A_VORBIS' => 'vorbis'];
        $found = [];
        foreach ($known as $id => $codec) {
            if (($at = strpos($head, $id)) !== false) {
                $found[$at] = $codec;
            }
        }
        if (!$found) {
            throw new RuntimeException("Could not read the WebM codec. Pass it yourself with --mime 'video/webm; codecs=\"vp9,opus\"'");
        }
        ksort($found);
        return 'video/webm; codecs="' . implode(',', $found) . '"';
    }

    /** @return list<array{0:string,1:int,2:int}> [type, payload start, box end] */
    private static function children(string $b, int $start, int $end): array
    {
        $out = [];
        $pos = $start;
        while ($pos >= 0 && $pos + 8 <= $end) {
            $len = unpack('N', $b, $pos)[1];
            $type = substr($b, $pos + 4, 4);
            $hdr = 8;
            if ($len === 1) {
                if ($pos + 16 > $end) {
                    break;
                }
                $len = unpack('J', $b, $pos + 8)[1];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $end - $pos;
            }
            if ($len < $hdr || $pos + $len > $end) {
                break;
            }
            $out[] = [$type, $pos + $hdr, $pos + $len];
            $pos += $len;
        }
        return $out;
    }

    private static function find(string $b, int $start, int $end, string $type): ?array
    {
        foreach (self::children($b, $start, $end) as [$t, $s, $e]) {
            if ($t === $type) {
                return [$s, $e];
            }
        }
        return null;
    }

    private static function path(string $b, int $start, int $end, array $types): ?array
    {
        foreach ($types as $type) {
            if (($hit = self::find($b, $start, $end, $type)) === null) {
                return null;
            }
            [$start, $end] = $hit;
        }
        return [$start, $end];
    }
}
