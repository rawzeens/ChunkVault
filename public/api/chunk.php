<?php
declare(strict_types=1);

/**
 * One chunk, freshly encrypted for this viewer's stream key.
 * The request must be signed by the page, recent, and never seen before.
 */
require __DIR__ . '/../../src/bootstrap.php';

cv_api();
[$stream, $error, $status] = Streams::authorize(cv_input());
session_write_close(); // release the session lock before the (slower) crypto work
if ($stream === null) {
    cv_deny($status, (string)$error);
}

$media = Library::get($stream['media']);
$plain = $media === null ? null : Library::chunk($media, $stream['i']);
if ($plain === null) {
    cv_deny(410, 'gone');
}

$sealed = Crypto::seal($stream['enc'], $plain, $stream['sid'] . '|' . $stream['i']);
header('Content-Type: application/octet-stream');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($sealed));
echo $sealed;
