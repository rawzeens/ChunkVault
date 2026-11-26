<?php
declare(strict_types=1);

/**
 * Adds a photo or video to the vault: splits it into chunks and encrypts each
 * chunk at rest. Run from the command line only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

const USAGE = <<<'TXT'
Add a photo or video to the vault.

  php bin/pack.php <file> [--title "Name"] [--id slug] [--chunk-kb 512] [--mime '...'] [--replace]
  php bin/pack.php --list
  php bin/pack.php --remove <id>

Photos: JPEG, PNG, GIF, WebP, AVIF.   Videos: fragmented MP4 or WebM.
Turn a normal MP4 into a streamable one (no re-encode) with:
  ffmpeg -i input.mp4 -c copy -movflags frag_keyframe+empty_moov+default_base_moof output.mp4

TXT;

try {
    [$files, $opt] = parse_args(array_slice($argv, 1));
    if (isset($opt['help'])) {
        fwrite(STDOUT, USAGE);
        exit(0);
    }
    if (isset($opt['list'])) {
        foreach (Library::all() as $m) {
            printf("%-32s %-6s %4d chunks  %s\n", $m['id'], $m['kind'], $m['chunks'], $m['title']);
        }
        exit(0);
    }
    if (isset($opt['remove'])) {
        if (Library::get($opt['remove']) === null) {
            throw new RuntimeException("No item called {$opt['remove']}");
        }
        remove_dir(Library::dir() . '/' . $opt['remove']);
        echo "Removed {$opt['remove']}\n";
        exit(0);
    }
    if (count($files) !== 1) {
        fwrite(STDERR, USAGE);
        exit(1);
    }
    pack_file($files[0], $opt);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

function pack_file(string $path, array $opt): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Cannot read $path");
    }
    $probe = MediaProbe::detect($path);
    if (isset($opt['mime'])) {
        $probe['mime'] = $opt['mime'];
    }
    $kb = (int)($opt['chunk-kb'] ?? ($probe['kind'] === 'image' ? 64 : 512));
    if ($kb < 16 || $kb > 8192) {
        throw new RuntimeException('--chunk-kb must be between 16 and 8192');
    }
    $title = trim((string)($opt['title'] ?? pathinfo($path, PATHINFO_FILENAME)));
    $title = $title !== '' ? $title : 'Untitled';
    $id = (string)($opt['id'] ?? Library::slug($title));
    if (!Library::validId($id)) {
        throw new RuntimeException('--id may only use a-z, 0-9 and dashes (max 64)');
    }

    $base = Library::dir();
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException("Cannot create $base");
    }
    if (is_dir("$base/$id") && !isset($opt['replace'])) {
        $id = substr($id, 0, 58) . '-' . bin2hex(random_bytes(2));
    }
    $key = Library::masterKey(true);
    $size = $kb * 1024;
    $tmp = "$base/.tmp-" . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);

    try {
        $fh = fopen($path, 'rb');
        $count = 0;
        $bytes = 0;
        while (($buf = read_full($fh, $size)) !== '') {
            file_put_contents(sprintf('%s/%06d.bin', $tmp, $count), Crypto::seal($key, $buf, "$id|$count"));
            $bytes += strlen($buf);
            $count++;
            if (strlen($buf) < $size) {
                break;
            }
        }
        fclose($fh);
        if ($count === 0) {
            throw new RuntimeException('The file is empty');
        }
        $manifest = [
            'id' => $id,
            'title' => $title,
            'kind' => $probe['kind'],
            'mime' => $probe['mime'],
            'chunks' => $count,
            'chunkSize' => $size,
            'bytes' => $bytes,
            'created' => gmdate('c'),
        ];
        file_put_contents("$tmp/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        if (is_dir("$base/$id")) {
            remove_dir("$base/$id");
        }
        rename($tmp, "$base/$id");
    } catch (Throwable $e) {
        remove_dir($tmp);
        throw $e;
    }
    printf("Added \"%s\" as %s: %d chunks, %s\n", $title, $id, $count, $probe['mime']);
}

function read_full($fh, int $n): string
{
    $buf = '';
    while (strlen($buf) < $n && !feof($fh)) {
        $part = fread($fh, $n - strlen($buf));
        if ($part === false || $part === '') {
            break;
        }
        $buf .= $part;
    }
    return $buf;
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? remove_dir("$dir/$f") : unlink("$dir/$f");
        }
    }
    rmdir($dir);
}

/** @return array{0:list<string>,1:array<string,string|bool>} */
function parse_args(array $args): array
{
    $flags = ['list', 'replace', 'help'];
    $valued = ['title', 'id', 'chunk-kb', 'mime', 'remove'];
    $files = [];
    $opt = [];
    for ($k = 0; $k < count($args); $k++) {
        $a = $args[$k];
        if (!str_starts_with($a, '--')) {
            $files[] = $a;
            continue;
        }
        $name = substr($a, 2);
        $value = null;
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        }
        if (in_array($name, $flags, true)) {
            $opt[$name] = true;
            continue;
        }
        if (!in_array($name, $valued, true)) {
            throw new RuntimeException("Unknown option --$name (see --help)");
        }
        if ($value === null) {
            if (!isset($args[$k + 1])) {
                throw new RuntimeException("--$name needs a value");
            }
            $value = $args[++$k];
        }
        $opt[$name] = $value;
    }
    return [$files, $opt];
}
