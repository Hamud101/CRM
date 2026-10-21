<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Session tokens live in SQLite rather than PHP's session files, so logging out
 * everywhere is a DELETE and sessions survive any shared-host session GC.
 */

const SESSION_COOKIE = 'crm_sess';
const SESSION_TTL    = 60 * 60 * 24 * 365;  // 1 year, renewed on use; single trusted user

/** Local mode: the Docker install on someone's own computer. It is published
 *  on 127.0.0.1 only, so there is no one to lock out and no password. */
function crm_local_mode(): bool { return getenv('CRM_LOCAL') === '1'; }

/** In local mode the only thing standing between a web page in the same
 *  browser and the CRM is the Host header: a DNS-rebinding page arrives with
 *  its own host name. Anything but localhost is refused. */
function crm_local_host_ok(): bool {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return (bool)preg_match('~^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$~i', $host);
}

function auth_login(string $password): bool {
    if (crm_local_mode()) return true;
    $hash = cfg('login_password_hash', '');
    if ($hash === '' || !password_verify($password, $hash)) {
        // Constant-ish delay to blunt trivial brute forcing on a single-user box.
        usleep(random_int(150000, 350000));
        return false;
    }
    $token = bin2hex(random_bytes(32));
    $now = time();
    db()->prepare("INSERT INTO sessions (token,created_at,expires_at) VALUES (?,?,?)")
        ->execute([$token, $now, $now + SESSION_TTL]);

    auth_set_cookie($token, $now + SESSION_TTL);
    return true;
}

function auth_set_cookie(string $token, int $expires): void {
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => crm_base(),
        'secure'   => crm_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Secure cookies everywhere except a plain-HTTP local test server. */
function crm_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return !preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~', $host);
}

function auth_user(): bool {
    if (crm_local_mode()) return true;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '') return false;
    $row = db()->prepare("SELECT expires_at FROM sessions WHERE token=?");
    $row->execute([$token]);
    $exp = $row->fetchColumn();
    if ($exp === false) return false;
    if ((int)$exp < time()) {
        db()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
        return false;
    }
    /* The session slides forward on use, so a daily user never sees the
       sign-in screen again. At most one write a day per session, so ordinary
       requests stay read-only. The token itself never changes (CSRF uses it). */
    if ((int)$exp < time() + SESSION_TTL - 86400) {
        db()->prepare("UPDATE sessions SET expires_at=? WHERE token=?")
            ->execute([time() + SESSION_TTL, $token]);
        if (!headers_sent()) auth_set_cookie($token, time() + SESSION_TTL);
    }
    return true;
}

function auth_logout(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') {
        db()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
    }
    setcookie(SESSION_COOKIE, '', ['expires' => 1, 'path' => crm_base(), 'secure' => crm_https(), 'httponly' => true]);
}

function require_auth(): void {
    if (!auth_user()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api.php')) {
            json_out(['error' => 'unauthorized'], 401);
        }
        header('Location: ' . crm_base());
        exit;
    }
}

/** CSRF: token tied to the session cookie, checked on every mutating API call. */
function csrf_token(): string {
    return hash_hmac('sha256', 'csrf', ($_COOKIE[SESSION_COOKIE] ?? '') . cfg('login_password_hash', '') . cfg('local_secret', ''));
}
function csrf_check(): void {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) json_out(['error' => 'bad csrf'], 403);
}

/* Refuse a local install's pages to any other host name before anything runs. */
if (crm_local_mode() && PHP_SAPI !== 'cli' && !crm_local_host_ok()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This CRM only opens at http://localhost on the computer it is installed on.\n");
}
