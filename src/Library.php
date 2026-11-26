<?php
declare(strict_types=1);

/**
 * Media on disk: storage/media/<id>/manifest.json plus 000000.bin, 000001.bin, ...
 * Every chunk is AES-GCM encrypted at rest with the master key, so a copied
 * storage folder is useless without storage/master.key (or CHUNKVAULT_MASTER_KEY).
 */
final class Library
{
    public static function dir(): string
    {
        return CV_STORAGE . '/media';
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) === 1;
    }

    public static function slug(string $text): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '');
        $slug = trim(substr(trim($slug, '-'), 0, 48), '-');
        return $slug !== '' ? $slug : 'media';
    }

    public static function get(string $id): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        $file = self::dir() . "/$id/manifest.json";
        if (!is_file($file)) {
            return null;
        }
        $m = json_decode((string)file_get_contents($file), true);
        return is_array($m) && ($m['id'] ?? null) === $id ? $m : null;
    }

    /** @return list<array> newest first */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*/manifest.json') ?: [] as $file) {
            $m = json_decode((string)file_get_contents($file), true);
            if (is_array($m) && is_string($m['id'] ?? null) && self::validId($m['id'])) {
                $out[] = $m;
            }
        }
        usort($out, fn ($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
        return $out;
    }

    /** Plain bytes of chunk $i, or null if missing or tampered with. */
    public static function chunk(array $media, int $i): ?string
    {
        if ($i < 0 || $i >= (int)$media['chunks']) {
            return null;
        }
        $sealed = @file_get_contents(sprintf('%s/%s/%06d.bin', self::dir(), $media['id'], $i));
        return $sealed === false ? null : Crypto::open(self::masterKey(), $sealed, $media['id'] . '|' . $i);
    }

    public static function masterKey(bool $create = false): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $env = getenv('CHUNKVAULT_MASTER_KEY');
        if (is_string($env) && $env !== '') {
            $k = base64_decode($env, true);
            if ($k === false || strlen($k) !== 32) {
                throw new RuntimeException('CHUNKVAULT_MASTER_KEY must be 32 random bytes, base64 encoded');
            }
            return $key = $k;
        }
        $file = CV_STORAGE . '/master.key';
        if (is_file($file)) {
            $k = (string)file_get_contents($file);
            if (strlen($k) !== 32) {
                throw new RuntimeException("$file is damaged");
            }
            return $key = $k;
        }
        if (!$create) {
            throw new RuntimeException('No master key yet. Add a file with bin/pack.php first.');
        }
        if (!is_dir(CV_STORAGE) && !mkdir(CV_STORAGE, 0700, true) && !is_dir(CV_STORAGE)) {
            throw new RuntimeException('Cannot create ' . CV_STORAGE);
        }
        $k = random_bytes(32);
        $fh = fopen($file, 'x');
        if ($fh === false) {
            throw new RuntimeException("Cannot write $file");
        }
        fwrite($fh, $k);
        fclose($fh);
        chmod($file, 0600);
        return $key = $k;
    }
}
