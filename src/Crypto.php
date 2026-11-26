<?php
declare(strict_types=1);

/**
 * Small wrappers around OpenSSL.
 *
 *  - ecdh():  P-256 key agreement with the browser. Only public keys cross the network.
 *  - seal() / open(): AES-256-GCM. Output is iv(12) || ciphertext || tag(16),
 *    which is exactly what WebCrypto's AES-GCM decrypt expects after the iv.
 */
final class Crypto
{
    /** DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point. */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64uEnc(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64uDec(string $text): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $text) || strlen($text) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return $bytes === false ? null : $bytes;
    }

    /**
     * @param string $clientRaw 65-byte uncompressed P-256 point from WebCrypto exportKey('raw')
     * @return array{0:string,1:string} [server public point (65 bytes), shared secret (32 bytes)]
     */
    public static function ecdh(string $clientRaw): array
    {
        if (strlen($clientRaw) !== 65 || $clientRaw[0] !== "\x04") {
            throw new InvalidArgumentException('Not an uncompressed P-256 point');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $clientRaw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $peer = openssl_pkey_get_public($pem); // OpenSSL rejects points that are not on the curve
        if ($peer === false) {
            throw new InvalidArgumentException('Invalid P-256 point');
        }
        $mine = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($mine === false) {
            throw new RuntimeException('Could not create an EC key');
        }
        $secret = openssl_pkey_derive($peer, $mine);
        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException('ECDH failed');
        }
        $ec = openssl_pkey_get_details($mine)['ec'];
        $raw = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        return [$raw, $secret];
    }

    public static function seal(string $key, string $plain, string $aad): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }
        return $iv . $ct . $tag;
    }

    public static function open(string $key, string $sealed, string $aad): ?string
    {
        if (strlen($sealed) < 28) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($sealed, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($sealed, 0, 12), substr($sealed, -16), $aad
        );
        return $plain === false ? null : $plain;
    }
}
