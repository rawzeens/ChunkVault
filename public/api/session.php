<?php
declare(strict_types=1);

/**
 * Handshake. The page sends its ECDH public key, we answer with ours plus a salt.
 * Both sides now hold the same AES + HMAC keys; nothing secret was sent.
 */
require __DIR__ . '/../../src/bootstrap.php';

cv_api();
$in = cv_input();

$id = $in['media'] ?? null;
$media = is_string($id) ? Library::get($id) : null;
if ($media === null) {
    cv_deny(404, 'media');
}
$pub = is_string($in['pub'] ?? null) ? Crypto::b64uDec($in['pub']) : null;
if ($pub === null) {
    cv_deny(400, 'key');
}
try {
    $stream = Streams::open($media, $pub);
} catch (InvalidArgumentException) {
    cv_deny(400, 'key');
}
session_write_close();

cv_json(200, $stream + [
    'now' => (int)floor(microtime(true) * 1000), // lets the page correct for a wrong device clock
    'kind' => $media['kind'],
    'mime' => $media['mime'],
    'chunks' => (int)$media['chunks'],
]);
