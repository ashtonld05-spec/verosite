<?php
// Public: the website's booking form reports a booking that Cal.com just accepted,
// so it shows up in the admin dashboard's Meetings page right away.
// It can only ADD a new booking (never change or remove one); the Cal.com webhook
// (calhook.php) is the trusted source that confirms, cancels and reschedules.
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
require_method('POST');
same_origin_or_die();
if (!vero_config()) json_out(['ok' => true]);
vero_schema_ready();
if (rate_limited('booking', 6, 3600)) json_out(['error' => 'too_many'], 429);

$d = body_json();
$uid = (string)($d['uid'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $uid)) json_out(['error' => 'bad_uid'], 422);
$start = to_utc_dt($d['start'] ?? '');
$end = to_utc_dt($d['end'] ?? '');
// must be a real upcoming time (allow a little clock drift)
if (!$start || strtotime($start . ' UTC') < time() - 3600 || strtotime($start . ' UTC') > time() + 400 * 86400) json_out(['error' => 'bad_time'], 422);
$name = clean_text($d['name'] ?? '', 120);
$email = strtolower(clean_text($d['email'] ?? '', 190));
if (mb_strlen($name) < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'bad_contact'], 422);
$phone = substr(preg_replace('/[^0-9+]/', '', (string)($d['phone'] ?? '')), 0, 40);
$biz = clean_text($d['business'] ?? '', 160);
$notes = clean_text($d['notes'] ?? '', 1500);

$db = vero_db();
$st = $db->prepare('SELECT id FROM meetings WHERE uid = ?');
$st->execute([$uid]);
if ($st->fetchColumn()) json_out(['ok' => true]); // already known (e.g. the webhook got here first)
$now = now_utc();
$db->prepare('INSERT INTO meetings (uid, name, email, phone, business, notes, start_at, end_at, status, source, verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
   ->execute([$uid, $name, $email, $phone, $biz, $notes, $start, $end, 'booked', 'site', $now, $now]);
log_event('booking_completed', (int)$db->lastInsertId(), $name . ($biz ? ' · ' . $biz : '') . ' booked a consultation');
json_out(['ok' => true]);
