<?php
// Public: privacy-friendly visit and click counting. No cookies, no IP addresses stored.
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
require_method('POST');
if (!vero_config()) { http_response_code(204); exit; }
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor/i', $ua) || ($_SERVER['HTTP_DNT'] ?? '') === '1') { http_response_code(204); exit; }
same_origin_or_die();
if (rate_limited('track', 120, 3600)) { http_response_code(204); exit; }

$d = body_json();
$e = (string)($d['e'] ?? '');
$path = substr(preg_replace('#[^a-zA-Z0-9/_\-.]#', '', (string)($d['p'] ?? '/')), 0, 120) ?: '/';
$db = vero_db();
$now = now_utc();
switch ($e) {
    case 'pageview':
        $ref = (string)($d['r'] ?? '');
        $rh = $ref ? (parse_url($ref, PHP_URL_HOST) ?: '') : '';
        if ($rh === ($_SERVER['HTTP_HOST'] ?? '')) $rh = '';
        $device = preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : (preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : 'desktop');
        $db->prepare('INSERT INTO pageviews (path, device, ref, visitor, day, created_at) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$path, $device, substr($rh, 0, 120), ip_hash(), gmdate('Y-m-d'), $now]);
        break;
    case 'book_click':
        log_event('book_click', null, 'Clicked a Book button on ' . $path);
        break;
    case 'booking_done':
        log_event('booking_completed', null, 'Booked a consultation on ' . $path);
        break;
    case 'review_open':
        $t = preg_replace('/[^a-f0-9]/', '', (string)($d['t'] ?? ''));
        if (strlen($t) === 32) {
            $st = $db->prepare("UPDATE review_requests SET status = 'opened', opened_at = ? WHERE token = ? AND status = 'sent'");
            $st->execute([$now, $t]);
            if ($st->rowCount()) log_event('review_link_opened', null, 'A customer opened their review link');
        }
        break;
}
http_response_code(204);
