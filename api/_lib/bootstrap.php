<?php
// VERO Digital backend bootstrap. Not web-accessible (see .htaccess in this folder).
declare(strict_types=1);

const VERO_SITE_NAME = 'VERO Digital';

function vero_private_dir(): string {
    // public_html/api/_lib  ->  domain folder (one level ABOVE public_html, never served to the web)
    $env = getenv('VERO_PRIVATE_DIR');
    return $env ? rtrim($env, '/') : dirname(__DIR__, 3) . '/vero_private';
}

function vero_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $f = vero_private_dir() . '/config.php';
    $cfg = is_file($f) ? (require $f) : null;
    return $cfg;
}

function vero_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = vero_config();
    if (!$c) { json_out(['error' => 'not_configured'], 503); }
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    } else {
        $pdo = new PDO('mysql:host=' . $c['db_host'] . ';port=' . ($c['db_port'] ?? 3306) . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], $opts);
    }
    return $pdo;
}

function vero_is_sqlite(): bool { return (vero_config()['driver'] ?? 'mysql') === 'sqlite'; }

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input', false, null, 0, 20000);
    $d = json_decode($raw ?: '[]', true);
    return is_array($d) ? $d : [];
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function ip_hash(): string {
    $c = vero_config();
    // salted + rotated daily: we can rate-limit and count unique visitors without storing anyone's IP address
    return hash('sha256', client_ip() . '|' . ($c['secret'] ?? 'x') . '|' . gmdate('Y-m-d'));
}

function rate_limited(string $kind, int $max, int $windowSec): bool {
    $db = vero_db();
    $since = gmdate('Y-m-d H:i:s', time() - $windowSec);
    $st = $db->prepare('SELECT COUNT(*) FROM rate_hits WHERE ip_hash = ? AND kind = ? AND created_at > ?');
    $st->execute([ip_hash(), $kind, $since]);
    if ((int)$st->fetchColumn() >= $max) return true;
    $db->prepare('INSERT INTO rate_hits (ip_hash, kind, created_at) VALUES (?, ?, ?)')->execute([ip_hash(), $kind, now_utc()]);
    if (random_int(1, 50) === 1) { // occasional cleanup
        $db->prepare('DELETE FROM rate_hits WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400 * 2)]);
    }
    return false;
}

function log_event(string $type, ?int $ref = null, string $detail = ''): void {
    vero_db()->prepare('INSERT INTO events (type, ref_id, detail, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$type, $ref, mb_substr($detail, 0, 255), now_utc()]);
}

function setting(string $k, ?string $default = null): ?string {
    $st = vero_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $k, string $v): void {
    $db = vero_db();
    $db->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function clean_text($s, int $max): string {
    $s = is_string($s) ? $s : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim(mb_substr($s, 0, $max));
}

function same_origin_or_die(): void {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $o = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($o === '') return; // non-browser clients send none; protected by other checks
    $oh = parse_url($o, PHP_URL_HOST) . (parse_url($o, PHP_URL_PORT) ? ':' . parse_url($o, PHP_URL_PORT) : '');
    if (strcasecmp($oh, $host) !== 0) json_out(['error' => 'bad_origin'], 403);
}

function require_method(string $m): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $m) json_out(['error' => 'method_not_allowed'], 405);
}

function site_url(): string {
    $c = vero_config();
    if (!empty($c['site_url'])) return rtrim($c['site_url'], '/');
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function send_mail(string $to, string $subject, string $text, ?string $replyTo = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $from = 'noreply@' . preg_replace('/:\d+$/', '', $host);
    $headers = "From: VERO Digital <$from>\r\n" . ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? "Reply-To: $replyTo\r\n" : '') . "Content-Type: text/plain; charset=UTF-8\r\nX-Mailer: VERO";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (getenv('VERO_MAIL_LOG')) { file_put_contents(getenv('VERO_MAIL_LOG'), "TO: $to\nSUBJ: $subject\n$text\n---\n", FILE_APPEND); return true; }
    return @mail($to, $subject, $text, $headers, '-f' . $from);
}

// ---------------- admin session ----------------
function admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('vero_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    $now = time();
    if (!empty($_SESSION['uid'])) {
        $idle = $now - ($_SESSION['seen'] ?? 0);
        $age = $now - ($_SESSION['born'] ?? 0);
        if ($idle > 7200 || $age > 43200 || ($_SESSION['ua'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['seen'] = $now;
        }
    }
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function admin_logged_in(): bool { admin_session_start(); return !empty($_SESSION['uid']); }

function admin_require(): void {
    if (!admin_logged_in()) json_out(['error' => 'unauthorized'], 401);
}

function csrf_check(): void {
    admin_session_start();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) json_out(['error' => 'bad_csrf'], 403);
    same_origin_or_die();
}

function admin_headers(): void {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}
