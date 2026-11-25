<?php
// Admin JSON API. Every action except "login" requires a signed-in admin session + CSRF token.
declare(strict_types=1);
require __DIR__ . '/../api/_lib/bootstrap.php';
admin_headers();
// Lead Finder is loaded only for lead actions, so it can never affect sign-in or the rest of the dashboard.
if (str_starts_with((string)($_GET['a'] ?? ''), 'lead')) require_once __DIR__ . '/../api/_lib/leads.php';
if (!vero_config()) json_out(['error' => 'not_configured'], 503);
admin_session_start();

$action = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = vero_db();

// ---------------- login / logout ----------------
if ($action === 'login') {
    require_method('POST');
    same_origin_or_die();
    $d = body_json();
    $ih = hash('sha256', client_ip() . '|' . vero_config()['secret']);
    $since = gmdate('Y-m-d H:i:s', time() - 900);
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND ok = 0 AND created_at > ?');
    $st->execute([$ih, $since]);
    $ipFails = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND created_at > ?');
    $st->execute([gmdate('Y-m-d H:i:s', time() - 3600)]);
    $allFails = (int)$st->fetchColumn();
    if ($ipFails >= 5 || $allFails >= 30) {
        json_out(['error' => 'Too many failed sign-in attempts. Please wait 15 minutes.'], 429);
    }
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $pw = (string)($d['password'] ?? '');
    $st = $db->prepare('SELECT id, pass_hash FROM admins WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    // verify against a dummy hash when the email is unknown, so timing doesn't reveal valid emails
    $hash = $row['pass_hash'] ?? '$2y$10$8Z0UrZ5m9eIJLr3ltuwVGegv6o5ulq6SVLbU0eNgQRha0zpCUOABS';
    $ok = password_verify($pw, $hash) && $row;
    $db->prepare('INSERT INTO login_attempts (ip_hash, ok, created_at) VALUES (?, ?, ?)')->execute([$ih, $ok ? 1 : 0, now_utc()]);
    if (!$ok) {
        log_event('login_failed', null, 'Failed sign-in attempt');
        usleep(400000);
        json_out(['error' => 'Email or password is incorrect.'], 401);
    }
    if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $row['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['born'] = $_SESSION['seen'] = time();
    $_SESSION['ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $db->prepare('UPDATE admins SET last_login = ? WHERE id = ?')->execute([now_utc(), $row['id']]);
    log_event('login', null, 'Signed in');
    json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

admin_require();
if ($method !== 'GET') csrf_check();
vero_schema_ready();

function meeting_row(array $m): array {
    $m['id'] = (int)$m['id']; $m['verified'] = (int)$m['verified'] === 1;
    return $m;
}
function meeting_counts(PDO $db): array {
    $now = now_utc();
    $one = function (string $sql, array $p = []) use ($db) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $wk = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
    return [
        'upcoming' => $one("SELECT COUNT(*) FROM meetings WHERE status = 'booked' AND COALESCE(end_at, start_at) >= ?", [$now]),
        'week' => $one("SELECT COUNT(*) FROM meetings WHERE status = 'booked' AND start_at >= ? AND start_at <= ?", [$now, $wk]),
        'past' => $one("SELECT COUNT(*) FROM meetings WHERE status IN ('booked','completed','no_show') AND COALESCE(end_at, start_at) < ?", [$now]),
        'cancelled' => $one("SELECT COUNT(*) FROM meetings WHERE status IN ('cancelled','rescheduled')"),
        'all' => $one('SELECT COUNT(*) FROM meetings'),
    ];
}
function cal_hook_info(): array {
    $sec = setting('cal_webhook_secret', '');
    if ($sec === '') { $sec = bin2hex(random_bytes(24)); set_setting('cal_webhook_secret', $sec); }
    return ['url' => site_url() . '/api/calhook.php', 'secret' => $sec, 'last' => setting('cal_webhook_last', '')];
}

function stats(PDO $db): array {
    $r = $db->query("SELECT COUNT(*) total,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
        SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) approved,
        SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) rejected,
        SUM(CASE WHEN status='approved' AND published=1 THEN 1 ELSE 0 END) published,
        AVG(CASE WHEN status='approved' AND published=1 THEN rating END) avg_published,
        AVG(rating) avg_all FROM reviews")->fetch();
    foreach (['total', 'pending', 'approved', 'rejected', 'published'] as $k) $r[$k] = (int)$r[$k];
    $r['avg_published'] = $r['avg_published'] !== null ? round((float)$r['avg_published'], 2) : null;
    $r['avg_all'] = $r['avg_all'] !== null ? round((float)$r['avg_all'], 2) : null;
    $dist = $db->query('SELECT rating, COUNT(*) n FROM reviews GROUP BY rating')->fetchAll();
    $r['distribution'] = array_fill(1, 5, 0);
    foreach ($dist as $x) $r['distribution'][(int)$x['rating']] = (int)$x['n'];
    return $r;
}

function website(PDO $db): array {
    $d7 = gmdate('Y-m-d', time() - 6 * 86400);
    $d30 = gmdate('Y-m-d', time() - 29 * 86400);
    $one = function (string $sql, array $p) use ($db) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $since7 = $d7 . ' 00:00:00'; $since30 = $d30 . ' 00:00:00';
    $days = [];
    $st = $db->prepare('SELECT day, COUNT(*) views, COUNT(DISTINCT visitor) visitors FROM pageviews WHERE day >= ? GROUP BY day');
    $st->execute([gmdate('Y-m-d', time() - 13 * 86400)]);
    $map = [];
    foreach ($st->fetchAll() as $x) $map[$x['day']] = $x;
    for ($i = 13; $i >= 0; $i--) {
        $k = gmdate('Y-m-d', time() - $i * 86400);
        $days[] = ['day' => $k, 'views' => (int)($map[$k]['views'] ?? 0), 'visitors' => (int)($map[$k]['visitors'] ?? 0)];
    }
    $st = $db->prepare('SELECT path, COUNT(*) n FROM pageviews WHERE day >= ? GROUP BY path ORDER BY n DESC LIMIT 6');
    $st->execute([$d30]);
    $pages = $st->fetchAll();
    $st = $db->prepare('SELECT device, COUNT(*) n FROM pageviews WHERE day >= ? GROUP BY device');
    $st->execute([$d30]);
    $devices = $st->fetchAll();
    $st = $db->prepare("SELECT ref, COUNT(*) n FROM pageviews WHERE day >= ? AND ref <> '' GROUP BY ref ORDER BY n DESC LIMIT 5");
    $st->execute([$d30]);
    $refs = $st->fetchAll();
    return [
        'views_today' => $one('SELECT COUNT(*) FROM pageviews WHERE day = ?', [gmdate('Y-m-d')]),
        'views_7' => $one('SELECT COUNT(*) FROM pageviews WHERE day >= ?', [$d7]),
        'views_30' => $one('SELECT COUNT(*) FROM pageviews WHERE day >= ?', [$d30]),
        'visitors_7' => $one('SELECT COUNT(DISTINCT visitor) FROM pageviews WHERE day >= ?', [$d7]),
        'book_clicks_30' => $one("SELECT COUNT(*) FROM events WHERE type = 'book_click' AND created_at >= ?", [$since30]),
        'bookings_30' => $one("SELECT COUNT(*) FROM events WHERE type = 'booking_completed' AND created_at >= ?", [$since30]),
        'bookings_7' => $one("SELECT COUNT(*) FROM events WHERE type = 'booking_completed' AND created_at >= ?", [$since7]),
        'requests_30' => $one('SELECT COUNT(*) FROM review_requests WHERE created_at >= ?', [$since30]),
        'days' => $days, 'pages' => $pages, 'devices' => $devices, 'refs' => $refs,
    ];
}

function review_row(array $r): array {
    $r['id'] = (int)$r['id']; $r['rating'] = (int)$r['rating']; $r['published'] = (int)$r['published'] === 1;
    unset($r['ip_hash']);
    return $r;
}

switch ($action) {
    case 'me':
        $st = $db->prepare('SELECT email, last_login FROM admins WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        json_out(['me' => $st->fetch(), 'csrf' => $_SESSION['csrf'], 'notify_email' => setting('notify_email', ''), 'notify_on' => setting('notify_on', '1') === '1', 'site' => site_url()]);

    case 'overview':
        $st = $db->prepare("SELECT * FROM meetings WHERE status = 'booked' AND COALESCE(end_at, start_at) >= ? ORDER BY start_at ASC LIMIT 4");
        $st->execute([now_utc()]);
        $next = array_map('meeting_row', $st->fetchAll());
        $leadsCard = null;
        try {
            require_once __DIR__ . '/../api/_lib/leads.php';
            $leadsCard = ['summary' => lead_summary($db), 'top' => array_map('lead_slim', array_slice(lead_list($db, ['status' => 'New', 'sort' => 'score']), 0, 4))];
        } catch (Throwable $e) { $leadsCard = null; }
        json_out(['stats' => stats($db), 'web' => website($db), 'meetings' => ['next' => $next, 'counts' => meeting_counts($db)], 'leads' => $leadsCard]);

    case 'meetings':
        $view = (string)($_GET['view'] ?? 'upcoming');
        $now = now_utc();
        [$where, $order, $p] = match ($view) {
            'past' => ["status IN ('booked','completed','no_show') AND COALESCE(end_at, start_at) < ?", 'start_at DESC', [$now]],
            'cancelled' => ["status IN ('cancelled','rescheduled')", 'start_at DESC', []],
            'all' => ['1=1', 'start_at DESC', []],
            default => ["status = 'booked' AND COALESCE(end_at, start_at) >= ?", 'start_at ASC', [$now]],
        };
        $st = $db->prepare("SELECT * FROM meetings WHERE $where ORDER BY $order LIMIT 300");
        $st->execute($p);
        json_out(['meetings' => array_map('meeting_row', $st->fetchAll()), 'counts' => meeting_counts($db), 'hook' => cal_hook_info()]);

    case 'meeting_update':
        require_method('POST');
        $d = body_json();
        $id = (int)($d['id'] ?? 0);
        $op = (string)($d['op'] ?? '');
        $st = $db->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $m = $st->fetch();
        if (!$m) json_out(['error' => 'Meeting not found.'], 404);
        $label = $m['name'] . ($m['business'] ? ' · ' . $m['business'] : '');
        $set = ['completed' => 'completed', 'no_show' => 'no_show', 'restore' => 'booked', 'cancel' => 'cancelled'];
        if (isset($set[$op])) {
            $db->prepare('UPDATE meetings SET status = ?, updated_at = ? WHERE id = ?')->execute([$set[$op], now_utc(), $id]);
            $ev = ['completed' => 'meeting_completed', 'no_show' => 'meeting_no_show', 'restore' => 'meeting_restored', 'cancel' => 'meeting_cancelled'][$op];
            log_event($ev, $id, $label);
        } elseif ($op === 'delete') {
            $db->prepare('DELETE FROM meetings WHERE id = ?')->execute([$id]);
            log_event('meeting_deleted', $id, $label);
        } else {
            json_out(['error' => 'Unknown action.'], 400);
        }
        json_out(['ok' => true, 'counts' => meeting_counts($db)]);

    case 'pulse':
        $st = $db->query("SELECT id, name, business, start_at FROM meetings WHERE status = 'booked' ORDER BY id DESC LIMIT 1");
        $last = $st->fetch() ?: null;
        if ($last) $last['id'] = (int)$last['id'];
        json_out(['pending' => stats($db)['pending'], 'last_meeting' => $last, 'upcoming' => meeting_counts($db)['upcoming']]);

    case 'meeting_texted':
        require_method('POST');
        $d = body_json();
        $st = $db->prepare('SELECT name FROM meetings WHERE id = ?');
        $st->execute([(int)($d['id'] ?? 0)]);
        $n = $st->fetchColumn();
        if ($n === false) json_out(['error' => 'Meeting not found.'], 404);
        $kind = clean_text($d['kind'] ?? '', 40);
        log_event('meeting_texted', (int)$d['id'], $n . ($kind ? ' · ' . $kind : ''));
        json_out(['ok' => true]);

    case 'cal_secret_reset':
        require_method('POST');
        set_setting('cal_webhook_secret', bin2hex(random_bytes(24)));
        log_event('settings_changed', null, 'Cal.com connection secret replaced');
        json_out(['ok' => true, 'hook' => cal_hook_info()]);

    case 'reviews':
        $status = (string)($_GET['status'] ?? 'all');
        $where = match ($status) {
            'pending' => "status = 'pending'", 'approved' => "status = 'approved'", 'rejected' => "status = 'rejected'",
            'published' => "status = 'approved' AND published = 1", default => '1=1' };
        $rows = $db->query("SELECT * FROM reviews WHERE $where ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, created_at DESC LIMIT 300")->fetchAll();
        json_out(['reviews' => array_map('review_row', $rows), 'stats' => stats($db)]);

    case 'review_update':
        require_method('POST');
        $d = body_json();
        $id = (int)($d['id'] ?? 0);
        $op = (string)($d['op'] ?? '');
        $st = $db->prepare('SELECT * FROM reviews WHERE id = ?');
        $st->execute([$id]);
        $rev = $st->fetch();
        if (!$rev) json_out(['error' => 'Review not found.'], 404);
        $now = now_utc();
        $label = $rev['name'] . ' · ' . $rev['rating'] . '★';
        switch ($op) {
            case 'approve':
                $db->prepare("UPDATE reviews SET status='approved', updated_at=? WHERE id=?")->execute([$now, $id]);
                log_event('review_approved', $id, $label); break;
            case 'approve_publish':
                $db->prepare("UPDATE reviews SET status='approved', published=1, updated_at=? WHERE id=?")->execute([$now, $id]);
                log_event('review_approved', $id, $label); log_event('review_published', $id, $label); break;
            case 'reject':
                $db->prepare("UPDATE reviews SET status='rejected', published=0, updated_at=? WHERE id=?")->execute([$now, $id]);
                log_event('review_rejected', $id, $label); break;
            case 'publish':
                if ($rev['status'] !== 'approved') json_out(['error' => 'Approve the review before publishing it.'], 409);
                $db->prepare('UPDATE reviews SET published=1, updated_at=? WHERE id=?')->execute([$now, $id]);
                log_event('review_published', $id, $label); break;
            case 'unpublish':
                $db->prepare('UPDATE reviews SET published=0, updated_at=? WHERE id=?')->execute([$now, $id]);
                log_event('review_unpublished', $id, $label); break;
            case 'delete':
                $db->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);
                log_event('review_deleted', $id, $label); break;
            default:
                json_out(['error' => 'Unknown action.'], 400);
        }
        json_out(['ok' => true, 'stats' => stats($db)]);

    case 'activity':
        $rows = $db->query('SELECT id, type, ref_id, detail, created_at FROM events ORDER BY id DESC LIMIT 80')->fetchAll();
        json_out(['events' => $rows]);

    case 'requests':
        $rows = $db->query('SELECT id, name, phone, email, channel, status, created_at, opened_at, submitted_at FROM review_requests ORDER BY id DESC LIMIT 100')->fetchAll();
        json_out(['requests' => $rows]);

    case 'request_create':
        require_method('POST');
        $d = body_json();
        $name = clean_text($d['name'] ?? '', 80);
        $phone = preg_replace('/[^0-9+]/', '', (string)($d['phone'] ?? ''));
        $email = strtolower(clean_text($d['email'] ?? '', 190));
        $channel = in_array($d['channel'] ?? '', ['sms', 'email', 'copy'], true) ? $d['channel'] : 'sms';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'That email address does not look right.'], 422);
        if ($channel === 'sms' && strlen(preg_replace('/\D/', '', $phone)) < 7) json_out(['error' => 'Enter a phone number to send a text.'], 422);
        if ($channel === 'email' && $email === '') json_out(['error' => 'Enter an email address to send an email.'], 422);
        $token = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO review_requests (token, name, phone, email, channel, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$token, $name, $phone, $email, $channel, 'sent', now_utc()]);
        $rid = (int)$db->lastInsertId();
        $link = site_url() . '/review.html?r=' . $token . ($name !== '' ? '&n=' . rawurlencode(explode(' ', $name)[0]) : '');
        $first = $name !== '' ? explode(' ', $name)[0] : '';
        $msg = 'Hi' . ($first ? " $first" : '') . ", thanks for working with VERO Digital! How did we do? We'd really appreciate a quick review — it takes less than a minute: $link";
        log_event('review_request_sent', $rid, ($name ?: 'Customer') . ' · by ' . ($channel === 'sms' ? 'text' : $channel));
        json_out(['ok' => true, 'link' => $link, 'message' => $msg, 'phone' => $phone, 'email' => $email]);

    case 'settings':
        require_method('POST');
        $d = body_json();
        $e = strtolower(clean_text($d['notify_email'] ?? '', 190));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Enter a valid email address.'], 422);
        set_setting('notify_email', $e);
        set_setting('notify_on', !empty($d['notify_on']) ? '1' : '0');
        log_event('settings_changed', null, 'Notification settings updated');
        json_out(['ok' => true]);

    case 'test_email':
        require_method('POST');
        $to = setting('notify_email', '');
        $ok = $to && send_mail($to, 'VERO dashboard: test notification', "This is a test from your VERO admin dashboard.\n\nIf you received this, new-review notifications will reach this inbox.\n" . site_url() . "/admin/\n");
        log_event($ok ? 'test_email_sent' : 'notify_failed', null, $ok ? 'Test email sent to ' . $to : 'Test email could not be sent');
        json_out($ok ? ['ok' => true, 'to' => $to] : ['error' => 'The server could not send the email. Check the address, or contact Hostinger to enable PHP mail.'], $ok ? 200 : 502);

    case 'password':
        require_method('POST');
        $d = body_json();
        $st = $db->prepare('SELECT pass_hash FROM admins WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        if (!password_verify((string)($d['current'] ?? ''), (string)$st->fetchColumn())) json_out(['error' => 'Current password is incorrect.'], 403);
        $new = (string)($d['new'] ?? '');
        if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) json_out(['error' => 'New password must be at least 10 characters with letters and numbers.'], 422);
        $db->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['uid']]);
        session_regenerate_id(true);
        log_event('password_changed', null, 'Admin password changed');
        json_out(['ok' => true]);

    // ---------------- Lead Finder ----------------
    case 'leads':
        $q = ['q' => $_GET['q'] ?? '', 'category' => $_GET['category'] ?? '', 'city' => $_GET['city'] ?? '', 'status' => $_GET['status'] ?? '',
            'favorite' => ($_GET['favorite'] ?? '') === '1', 'min_score' => $_GET['min_score'] ?? '', 'website_state' => $_GET['website_state'] ?? '',
            'booked' => ($_GET['booked'] ?? '') === '1', 'sort' => $_GET['sort'] ?? 'score', 'dir' => $_GET['dir'] ?? 'desc'];
        json_out(['leads' => array_map('lead_slim', lead_list($db, $q)), 'summary' => lead_summary($db), 'services' => lead_services(),
            'categories' => array_keys(LEAD_CATEGORIES), 'statuses' => LEAD_STATUSES, 'website_states' => LEAD_WEBSITE_LABELS]);

    case 'lead':
        $l = lead_get($db, (int)($_GET['id'] ?? 0));
        if (!$l) json_out(['error' => 'Lead not found.'], 404);
        json_out(['lead' => $l, 'website_states' => LEAD_WEBSITE_LABELS]);

    case 'lead_update':
        require_method('POST');
        $d = body_json();
        $id = (int)($d['id'] ?? 0);
        $l = lead_get($db, $id);
        if (!$l) json_out(['error' => 'Lead not found.'], 404);
        $set = []; $vals = [];
        if (array_key_exists('status', $d)) {
            if (!in_array($d['status'], LEAD_STATUSES, true)) json_out(['error' => 'Unknown status.'], 422);
            $set[] = 'status = ?'; $vals[] = $d['status'];
            if ($d['status'] !== $l['status']) log_event($d['status'] === 'Client' ? 'lead_client' : 'lead_status', $id, $l['name'] . ' → ' . $d['status']);
        }
        if (array_key_exists('favorite', $d)) { $set[] = 'favorite = ?'; $vals[] = !empty($d['favorite']) ? 1 : 0; }
        if (array_key_exists('notes', $d)) { $set[] = 'notes = ?'; $vals[] = clean_text($d['notes'], 5000); }
        if ($set) { $vals[] = now_utc(); $vals[] = $id; $db->prepare('UPDATE leads SET ' . implode(', ', $set) . ', updated_at = ? WHERE id = ?')->execute($vals); }
        json_out(['ok' => true, 'lead' => lead_slim(lead_get($db, $id))]);

    case 'lead_delete':
        require_method('POST');
        $d = body_json();
        $l = lead_get($db, (int)($d['id'] ?? 0));
        if (!$l) json_out(['error' => 'Lead not found.'], 404);
        $db->prepare('DELETE FROM leads WHERE id = ?')->execute([$l['id']]);
        log_event('lead_deleted', $l['id'], $l['name']);
        json_out(['ok' => true]);

    case 'lead_reanalyze':
        require_method('POST');
        @set_time_limit(180);
        $d = body_json();
        $l = lead_get($db, (int)($d['id'] ?? 0));
        if (!$l) json_out(['error' => 'Lead not found.'], 404);
        $unlisted = $l['website_state'] === 'found_unlisted';
        $listed = (bool)preg_match('/^(Verified: Google|Google lists)/', (string)$l['website_source']);
        $place = ['place_id' => $l['place_id'], 'name' => $l['name'], 'address' => $l['address'], 'city' => $l['city'], 'state' => $l['state'], 'phone' => $l['phone'],
            'website' => ($listed || $unlisted) ? $l['website'] : null, 'google_maps_url' => $l['google_maps_url'], 'rating' => $l['rating'],
            'review_count' => $l['review_count'], 'business_status' => $l['business_status'], 'google_type' => $l['google_type']];
        try {
            $new = lead_build($place, (string)$l['category'], (string)$l['search_location'], !empty($d['pagespeed']), $unlisted ? $l['website_source'] : null);
        } catch (Throwable $e) { json_out(['error' => 'Could not re-check: ' . $e->getMessage()], 502); }
        if ($unlisted && $new['website_state'] === 'has_website') {
            $new['website_state'] = 'found_unlisted';
            [$new['score'], $new['score_tier'], $new['score_reasons']] = lead_score($new, $new['analysis']);
        }
        lead_update_analysis($db, $l['id'], $new);
        json_out(['ok' => true, 'lead' => lead_get($db, $l['id'])]);

    case 'lead_plan':
        require_method('POST');
        $d = body_json();
        $loc = clean_text($d['location'] ?? '', 200); $cat = clean_text($d['category'] ?? '', 80);
        if ($loc === '' || $cat === '') json_out(['error' => 'Enter a city or state and a business category.'], 422);
        if (lead_key('google_places') === '') json_out(['error' => 'Add your Google Places API key first: Settings → Lead Finder API keys.'], 422);
        json_out(['queries' => lead_queries($loc, $cat), 'web_search' => lead_search_provider()]);

    case 'lead_places':
        require_method('POST');
        $d = body_json();
        try { $r = lead_places_search(clean_text($d['query'] ?? '', 300), isset($d['pageToken']) ? clean_text($d['pageToken'], 2000) : null); }
        catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        $out = [];
        foreach ($r['places'] as $p) {
            if ($p['place_id'] === '' || $p['name'] === '') continue;
            $dom = $p['website'] && !lead_classify_url($p['website']) ? lead_reg_domain($p['website']) : null;
            $dup = lead_find_dup($db, $p['place_id'], lead_norm_phone($p['phone']), $dom);
            $closed = $p['business_status'] && $p['business_status'] !== 'OPERATIONAL' ? strtolower(str_replace('_', ' ', $p['business_status'])) : null;
            $out[] = ['place' => $p, 'sig' => lead_sign($p), 'duplicate' => $dup['why'] ?? null, 'closed' => $closed];
        }
        json_out(['places' => $out, 'nextPageToken' => $r['next']]);

    case 'lead_analyze':
        require_method('POST');
        @set_time_limit(180);
        $d = body_json();
        $p = is_array($d['place'] ?? null) ? $d['place'] : null;
        if (!$p || !hash_equals(lead_sign($p), (string)($d['sig'] ?? ''))) json_out(['error' => 'Invalid business data.'], 400);
        $dom = !empty($p['website']) && !lead_classify_url($p['website']) ? lead_reg_domain($p['website']) : null;
        if ($dup = lead_find_dup($db, $p['place_id'], lead_norm_phone($p['phone']), $dom)) json_out(['skipped' => 'duplicate', 'reason' => 'same ' . $dup['why'] . ' as a saved lead']);
        try { $l = lead_build($p, clean_text($d['category'] ?? '', 80), clean_text($d['location'] ?? '', 200), !empty($d['pagespeed'])); }
        catch (Throwable $e) { json_out(['error' => 'Analysis failed: ' . $e->getMessage()], 502); }
        $min = (int)($d['min_score'] ?? 0);
        if ($min && $l['score'] < $min) json_out(['skipped' => 'low_score', 'reason' => "score {$l['score']} is below your minimum of $min"]);
        if ($dup = lead_find_dup($db, $l['place_id'], $l['phone_norm'], $l['domain'])) json_out(['skipped' => 'duplicate', 'reason' => 'same ' . $dup['why'] . ' as a saved lead']);
        try { $id = lead_insert($db, $l); }
        catch (PDOException $e) { json_out(['skipped' => 'duplicate', 'reason' => 'already saved']); }
        json_out(['saved' => true, 'lead' => lead_slim(lead_get($db, $id))]);

    case 'lead_search_done':
        require_method('POST');
        $d = body_json();
        $loc = clean_text($d['location'] ?? '', 200); $cat = clean_text($d['category'] ?? '', 80);
        $status = in_array($d['status'] ?? '', ['done', 'cancelled', 'error'], true) ? $d['status'] : 'done';
        $db->prepare('INSERT INTO lead_searches (location, category, requested, saved, duplicates, skipped, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$loc, $cat, (int)($d['requested'] ?? 0), (int)($d['saved'] ?? 0), (int)($d['duplicates'] ?? 0), (int)($d['skipped'] ?? 0), $status, now_utc()]);
        log_event('lead_search', null, "$cat in $loc · " . (int)($d['saved'] ?? 0) . ' new lead' . ((int)($d['saved'] ?? 0) === 1 ? '' : 's'));
        json_out(['ok' => true]);

    case 'leads_export':
        $q = ['q' => $_GET['q'] ?? '', 'category' => $_GET['category'] ?? '', 'city' => $_GET['city'] ?? '', 'status' => $_GET['status'] ?? '',
            'favorite' => ($_GET['favorite'] ?? '') === '1', 'min_score' => $_GET['min_score'] ?? '', 'website_state' => $_GET['website_state'] ?? '',
            'booked' => ($_GET['booked'] ?? '') === '1', 'sort' => $_GET['sort'] ?? 'score', 'dir' => $_GET['dir'] ?? 'desc'];
        $rows = lead_export_rows(lead_list($db, $q));
        $stamp = gmdate('Y-m-d');
        if (($_GET['format'] ?? '') === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"vero-leads-$stamp.xlsx\"");
            echo lead_xlsx($rows); exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"vero-leads-$stamp.csv\"");
        echo lead_csv($rows); exit;

    case 'lead_keys':
        json_out(['keys' => lead_keys_status(), 'services' => lead_services(), 'history' => $db->query('SELECT * FROM lead_searches ORDER BY id DESC LIMIT 10')->fetchAll()]);

    case 'lead_keys_save':
        require_method('POST');
        $d = body_json();
        $changed = [];
        foreach (LEAD_KEY_NAMES as $k => $label) {
            if (!empty($d['clear']) && in_array($k, (array)$d['clear'], true)) { lead_set_key($k, ''); $changed[] = $label . ' removed'; continue; }
            $v = trim((string)($d['keys'][$k] ?? ''));
            if ($v === '') continue;
            if (strlen($v) > 300 || preg_match('/\s/', $v)) json_out(['error' => $label . ' key does not look right.'], 422);
            lead_set_key($k, $v); $changed[] = $label;
        }
        if ($changed) log_event('lead_keys', null, 'API keys updated: ' . implode(', ', $changed));
        json_out(['ok' => true, 'keys' => lead_keys_status(), 'services' => lead_services()]);

    case 'logout':
        require_method('POST');
        log_event('logout', null, 'Signed out');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        session_destroy();
        json_out(['ok' => true]);
}
json_out(['error' => 'Unknown action.'], 400);
