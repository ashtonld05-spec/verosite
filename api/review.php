<?php
// Public: a customer submits a review. It is saved as "pending" until approved in the dashboard.
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
require_method('POST');
same_origin_or_die();

$d = body_json();
// bot traps: hidden field must be empty, and the form must have been open for at least 3 seconds
if (!empty($d['website']) || (isset($d['elapsed']) && (int)$d['elapsed'] < 3000)) json_out(['ok' => true]);
if (rate_limited('review', 3, 3600)) json_out(['error' => 'Too many reviews from this connection. Please try again later.'], 429);

$name = clean_text($d['name'] ?? '', 80);
$business = clean_text($d['business'] ?? '', 120);
$email = strtolower(clean_text($d['email'] ?? '', 190));
$rating = (int)($d['rating'] ?? 0);
$body = clean_text($d['body'] ?? '', 2000);
$token = preg_replace('/[^a-f0-9]/', '', (string)($d['token'] ?? ''));

$errors = [];
if (mb_strlen($name) < 2) $errors[] = 'Please enter your name.';
if ($rating < 1 || $rating > 5) $errors[] = 'Please choose a star rating.';
if (mb_strlen($body) < 10) $errors[] = 'Please write at least a sentence about your experience.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address does not look right.';
if (preg_match_all('#https?://#i', $body) > 1) $errors[] = 'Please remove links from your review.';
if ($errors) json_out(['error' => implode(' ', $errors)], 422);

$db = vero_db();
$reqId = null;
if (strlen($token) === 32) {
    $st = $db->prepare('SELECT id FROM review_requests WHERE token = ?');
    $st->execute([$token]);
    $reqId = $st->fetchColumn() ?: null;
    if ($reqId) $db->prepare("UPDATE review_requests SET status = 'submitted', submitted_at = ? WHERE id = ?")->execute([now_utc(), $reqId]);
}
$now = now_utc();
$db->prepare('INSERT INTO reviews (name, business, email, rating, body, status, published, request_id, ip_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)')
   ->execute([$name, $business, $email, $rating, $body, 'pending', $reqId, ip_hash(), $now, $now]);
$id = (int)$db->lastInsertId();
log_event('review_submitted', $id, $name . ' · ' . $rating . '★');

// notify the owner
$to = setting('notify_email', 'clients.verodigitalweb@gmail.com');
if ($to && setting('notify_on', '1') === '1') {
    $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    $text = "New review waiting for approval\n\n$stars ($rating/5)\nFrom: $name" . ($business ? " — $business" : '') . ($email ? "\nEmail: $email" : '') . "\n\n\"$body\"\n\nApprove or reject it in your dashboard:\n" . site_url() . "/admin/\n";
    $ok = send_mail($to, "New $rating-star review from $name", $text, $email ?: null);
    if (!$ok) log_event('notify_failed', $id, 'Email notification could not be sent');
}
json_out(['ok' => true]);
