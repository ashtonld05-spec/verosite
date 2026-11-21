<?php
// Cal.com webhook: keeps the dashboard's Meetings list in sync with Cal.com
// (new bookings, cancellations, reschedules), including bookings made on the Cal.com page.
// Every request must be signed with the secret shown in the dashboard (HMAC-SHA256).
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
require_method('POST');
if (!vero_config()) json_out(['error' => 'not_configured'], 503);
vero_schema_ready();

$secret = setting('cal_webhook_secret', '');
$raw = file_get_contents('php://input', false, null, 0, 200000) ?: '';
$sig = strtolower(trim((string)($_SERVER['HTTP_X_CAL_SIGNATURE_256'] ?? '')));
if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    if (!rate_limited('calhook_bad', 20, 3600)) log_event('webhook_rejected', null, 'Cal.com update with a wrong or missing secret was ignored');
    json_out(['error' => 'bad_signature'], 401);
}
$d = json_decode($raw, true);
if (!is_array($d)) json_out(['error' => 'bad_json'], 400);
$trigger = strtoupper((string)($d['triggerEvent'] ?? ''));
$p = is_array($d['payload'] ?? null) ? $d['payload'] : [];
set_setting('cal_webhook_last', now_utc());
if ($trigger === 'PING' || $trigger === '') json_out(['ok' => true]);

$db = vero_db();
$now = now_utc();
$uid = (string)($p['uid'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $uid)) json_out(['ok' => true, 'ignored' => 'no_uid']);

function resp($p, string $k): string {
    $v = $p['responses'][$k] ?? null;
    if (is_array($v)) $v = $v['value'] ?? '';
    return is_scalar($v) ? (string)$v : '';
}
function find_meeting(PDO $db, string $uid): ?array {
    $st = $db->prepare('SELECT * FROM meetings WHERE uid = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}
function upsert_meeting(PDO $db, string $uid, array $p, string $status): int {
    $att = is_array($p['attendees'][0] ?? null) ? $p['attendees'][0] : [];
    $name = clean_text(resp($p, 'name') ?: ($att['name'] ?? ''), 120);
    $email = strtolower(clean_text(resp($p, 'email') ?: ($att['email'] ?? ''), 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $phone = substr(preg_replace('/[^0-9+]/', '', resp($p, 'attendeePhoneNumber') ?: (string)($att['phoneNumber'] ?? '')), 0, 40);
    $notes = clean_text(resp($p, 'notes') ?: (string)($p['additionalNotes'] ?? ''), 1500);
    $biz = '';
    if (preg_match('/^Business:\s*(.+?)\.\s*(.*)$/s', $notes, $m)) { $biz = clean_text($m[1], 160); $notes = clean_text($m[2], 1500); }
    $start = to_utc_dt($p['startTime'] ?? '');
    $end = to_utc_dt($p['endTime'] ?? '');
    $url = (string)($p['metadata']['videoCallUrl'] ?? ($p['videoCallData']['url'] ?? ''));
    if (!preg_match('#^https://[^\s"<>]{4,490}$#', $url)) $url = '';
    $now = now_utc();
    $row = find_meeting($db, $uid);
    if ($row) {
        $db->prepare('UPDATE meetings SET name = ?, email = ?, phone = ?, business = ?, notes = ?, start_at = ?, end_at = ?, meet_url = ?, status = ?, source = source, verified = 1, updated_at = ? WHERE id = ?')
           ->execute([$name ?: $row['name'], $email ?: $row['email'], $phone ?: $row['phone'], $biz ?: $row['business'], $notes !== '' ? $notes : $row['notes'],
                      $start ?: $row['start_at'], $end ?: $row['end_at'], $url ?: $row['meet_url'], in_array($row['status'], ['completed', 'no_show'], true) && $status === 'booked' ? $row['status'] : $status, $now, $row['id']]);
        return (int)$row['id'];
    }
    if (!$start) return 0;
    $db->prepare('INSERT INTO meetings (uid, name, email, phone, business, notes, start_at, end_at, meet_url, status, source, verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
       ->execute([$uid, $name, $email, $phone, $biz, $notes, $start, $end, $url, $status, 'cal', $now, $now]);
    return (int)$db->lastInsertId();
}

switch ($trigger) {
    case 'BOOKING_CREATED':
    case 'BOOKING_REQUESTED':
    case 'BOOKING_PAID':
        $known = find_meeting($db, $uid);
        $id = upsert_meeting($db, $uid, $p, 'booked');
        if ($id && !$known) log_event('booking_completed', $id, clean_text(resp($p, 'name') ?: ($p['attendees'][0]['name'] ?? 'Someone'), 120) . ' booked on Cal.com');
        break;
    case 'BOOKING_RESCHEDULED':
        $old = (string)($p['rescheduleUid'] ?? ($p['fromReschedule'] ?? ''));
        if ($old !== '' && $old !== $uid && ($o = find_meeting($db, $old))) {
            $db->prepare("UPDATE meetings SET status = 'rescheduled', updated_at = ? WHERE id = ?")->execute([$now, $o['id']]);
        }
        $id = upsert_meeting($db, $uid, $p, 'booked');
        if ($id) log_event('meeting_rescheduled', $id, clean_text(resp($p, 'name') ?: 'A client', 120) . ' moved their consultation');
        break;
    case 'BOOKING_CANCELLED':
    case 'BOOKING_REJECTED':
        $row = find_meeting($db, $uid);
        if ($row) {
            $db->prepare("UPDATE meetings SET status = 'cancelled', verified = 1, updated_at = ? WHERE id = ?")->execute([$now, $row['id']]);
            $id = (int)$row['id'];
        } else {
            $id = upsert_meeting($db, $uid, $p, 'cancelled');
        }
        if ($id) log_event('meeting_cancelled', $id, clean_text(($row['name'] ?? '') ?: resp($p, 'name') ?: 'A client', 120) . ' cancelled their consultation');
        break;
    case 'BOOKING_NO_SHOW_UPDATED':
        if ($row = find_meeting($db, $uid)) $db->prepare("UPDATE meetings SET status = 'no_show', updated_at = ? WHERE id = ?")->execute([$now, $row['id']]);
        break;
}
json_out(['ok' => true]);
