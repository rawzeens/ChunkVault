<?php
declare(strict_types=1);

/**
 * A "stream" is one viewing of one file in one signed-in browser.
 * It owns two keys that both sides derive from ECDH + HKDF:
 *   enc - AES-256-GCM key that chunks are encrypted with on the way out
 *   mac - HMAC key the page signs every chunk request with
 * Neither key is ever sent over the network.
 */
final class Streams
{
    public const IDLE = 600;       // seconds without a request before a stream dies
    public const MAX_AGE = 7200;   // hard limit for one stream
    public const SKEW_MS = 30000;  // how old a signed request may be
    public const MAX_OPEN = 6;     // streams kept per signed-in browser

    /** @return array{sid:string,pub:string,salt:string} */
    public static function open(array $media, string $clientPub): array
    {
        [$serverPub, $secret] = Crypto::ecdh($clientPub);
        $sid = Crypto::b64uEnc(random_bytes(18));
        $salt = random_bytes(16);
        $now = time();
        self::prune($now, true);
        $_SESSION['streams'][$sid] = [
            'media' => $media['id'],
            'chunks' => (int)$media['chunks'],
            'enc' => hash_hkdf('sha256', $secret, 32, "chunkvault enc $sid", $salt),
            'mac' => hash_hkdf('sha256', $secret, 32, "chunkvault mac $sid", $salt),
            'born' => $now,
            'seen' => $now,
            'nonces' => [],
        ];
        return ['sid' => $sid, 'pub' => Crypto::b64uEnc($serverPub), 'salt' => Crypto::b64uEnc($salt)];
    }

    /**
     * Checks a signed chunk request {s, i, t, n, g}.
     * @return array{0:?array,1:?string,2:int} [stream (+sid, i) | null, error code, HTTP status]
     */
    public static function authorize(array $in): array
    {
        $sid = $in['s'] ?? null;
        $i = $in['i'] ?? null;
        $t = $in['t'] ?? null;
        $n = $in['n'] ?? null;
        $g = $in['g'] ?? null;
        if (!is_string($sid) || !preg_match('/^[A-Za-z0-9_-]{24}$/', $sid)
            || !is_int($i) || !is_int($t)
            || !is_string($n) || !preg_match('/^[A-Za-z0-9_-]{16}$/', $n)
            || !is_string($g) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $g)) {
            return [null, 'bad-request', 400];
        }

        $now = time();
        self::prune($now, false);
        $s = $_SESSION['streams'][$sid] ?? null;
        if ($s === null) {
            return [null, 'expired', 403];
        }
        $sig = Crypto::b64uDec($g);
        if ($sig === null || !hash_equals(hash_hmac('sha256', "$sid.$i.$t.$n", $s['mac'], true), $sig)) {
            return [null, 'signature', 403];
        }
        $ms = (int)floor(microtime(true) * 1000);
        if (abs($ms - $t) > self::SKEW_MS) {
            return [null, 'clock', 403];
        }
        if (isset($s['nonces'][$n])) {
            return [null, 'replay', 403];
        }
        if ($i < 0 || $i >= $s['chunks']) {
            return [null, 'range', 404];
        }

        foreach ($s['nonces'] as $old => $when) {
            if ($when < $ms - 2 * self::SKEW_MS) {
                unset($s['nonces'][$old]); // older than any timestamp we would still accept
            }
        }
        $s['nonces'][$n] = $t;
        $s['seen'] = $now;
        $_SESSION['streams'][$sid] = $s;
        return [$s + ['sid' => $sid, 'i' => $i], null, 200];
    }

    private static function prune(int $now, bool $makeRoom): void
    {
        $all = $_SESSION['streams'] ?? [];
        foreach ($all as $sid => $s) {
            if ($now - $s['seen'] > self::IDLE || $now - $s['born'] > self::MAX_AGE) {
                unset($all[$sid]);
            }
        }
        if ($makeRoom && count($all) >= self::MAX_OPEN) {
            uasort($all, fn ($a, $b) => $a['seen'] <=> $b['seen']);
            $all = array_slice($all, -(self::MAX_OPEN - 1), null, true);
        }
        $_SESSION['streams'] = $all;
    }
}
