<?php
declare(strict_types=1);

define('CV_STORAGE', rtrim((string)(getenv('CHUNKVAULT_STORAGE') ?: dirname(__DIR__) . '/storage'), '/'));

require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Library.php';
require_once __DIR__ . '/MediaProbe.php';
require_once __DIR__ . '/Streams.php';

function cv_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function cv_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('cv_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => cv_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** The signed-in viewer's display name. Swap this for your real login. */
function cv_viewer(): ?string
{
    $v = $_SESSION['viewer'] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

function cv_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/**
 * Rejects requests sent from other sites. Modern browsers label every request with
 * Sec-Fetch-Site, which pages cannot forge; older ones get the Origin check instead.
 * (Origin alone is not enough: Chrome sends "Origin: null" on some same-site form posts.)
 */
function cv_same_origin(): bool
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($site !== null) {
        return $site === 'same-origin' || $site === 'none';
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null) {
        return true;
    }
    $p = parse_url((string)$origin);
    if (!is_array($p) || empty($p['host'])) {
        return false;
    }
    $host = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    return hash_equals(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), $host);
}

/** Headers for HTML pages: no framing by other sites, scripts only from here, media only from MediaSource. */
function cv_page_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "media-src blob:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin'); // never leak page URLs to other sites
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store');
}

function cv_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function cv_deny(int $status, string $code): never
{
    cv_json($status, ['error' => $code]);
}

/** Common gate for the JSON API: POST, same site, JSON body, signed in. */
function cv_api(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cv_deny(405, 'method');
    }
    if (!cv_same_origin()) {
        cv_deny(403, 'origin');
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        cv_deny(415, 'type');
    }
    cv_session();
    if (cv_viewer() === null) {
        cv_deny(401, 'login');
    }
}

function cv_input(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 4096);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
