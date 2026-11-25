<?php
// Password reset for the admin dashboard.
//  1) Email link: enter your admin email; a one-time link (valid 30 minutes) is emailed to that address.
//  2) Backup: the private setup code from first-time setup.
declare(strict_types=1);
require __DIR__ . '/../api/_lib/bootstrap.php';
admin_headers();
if (!vero_config()) { header('Location: setup.php'); exit; }
vero_schema_ready();
$db = vero_db();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$err = ''; $ok = ''; $view = 'start';
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function pw_ok(string $pw): bool { return strlen($pw) >= 10 && preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw); }
function reset_record(): ?array { $r = json_decode((string)setting('pw_reset', ''), true); return is_array($r) ? $r : null; }
function reset_token_valid(string $t): ?array {
    $r = reset_record();
    if (!$r || $t === '' || !preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    if (($r['exp'] ?? 0) < time() || !hash_equals((string)$r['hash'], hash('sha256', $t))) return null;
    return $r;
}
function set_admin_password(PDO $db, int $id, string $pw, string $how): void {
    $db->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    // a fresh password also clears the sign-in lockout
    $db->exec('DELETE FROM login_attempts WHERE ok = 0');
    set_setting('pw_reset', '');
    log_event('password_changed', null, 'Password reset ' . $how);
}
function csrf_form_ok(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) admin_session_start();
    return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''));
}
admin_session_start();
$csrf = $_SESSION['csrf'];

if ($token !== '') {
    $view = reset_token_valid($token) ? 'newpw' : 'expired';
} elseif (($_GET['m'] ?? '') === 'code') {
    $view = 'code';
}

if ($method === 'POST') {
    same_origin_or_die();
    $act = (string)($_POST['act'] ?? '');
    if (!csrf_form_ok()) {
        $err = 'This page expired. Please try again.';
    } elseif ($act === 'email') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (rate_limited('pw_reset', 3, 3600)) {
            $err = 'Too many reset requests. Wait an hour, or use your setup code below.';
        } else {
            $st = $db->prepare('SELECT id, email FROM admins WHERE email = ?');
            $st->execute([$email]);
            if ($row = $st->fetch()) {
                $t = bin2hex(random_bytes(32));
                set_setting('pw_reset', json_encode(['hash' => hash('sha256', $t), 'id' => (int)$row['id'], 'exp' => time() + 1800]));
                $link = site_url() . '/admin/reset.php?t=' . $t;
                $sent = send_mail($row['email'], 'Reset your VERO dashboard password',
                    "Someone asked to reset the password for your VERO admin dashboard.\n\nTo choose a new password, open this link within 30 minutes:\n$link\n\nIf this wasn't you, ignore this email. Your password stays the same.\n");
                log_event($sent ? 'password_reset_sent' : 'notify_failed', null, $sent ? 'Password reset link emailed' : 'Password reset email could not be sent');
            }
            // Same answer either way, so this page never reveals which emails have accounts.
            $ok = 'If that email belongs to your dashboard, a reset link is on its way. Check your inbox and spam folder. The link works for 30 minutes.';
        }
    } elseif ($act === 'newpw') {
        $r = reset_token_valid($token);
        $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
        if (!$r) { $view = 'expired'; }
        elseif (!pw_ok($pw)) { $err = 'Password must be at least 10 characters and include letters and numbers.'; $view = 'newpw'; }
        elseif (!hash_equals($pw, $pw2)) { $err = 'The two passwords do not match.'; $view = 'newpw'; }
        else { set_admin_password($db, (int)$r['id'], $pw, 'by email link'); header('Location: ./?reset=1'); exit; }
    } elseif ($act === 'code') {
        $sealedFile = getenv('VERO_SEALED_FILE') ?: __DIR__ . '/../api/_lib/sealed.php';
        $sealed = is_file($sealedFile) ? (require $sealedFile) : null;
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
        $st = $db->prepare('SELECT id FROM admins WHERE email = ?');
        $st->execute([$email]);
        $id = $st->fetchColumn();
        if (rate_limited('pw_reset_code', 5, 900)) $err = 'Too many attempts. Wait 15 minutes and try again.';
        elseif (!$sealed || !password_verify($code, (string)$sealed['code_hash'])) { $err = 'That setup code is not correct.'; usleep(400000); }
        elseif ($id === false) $err = 'No dashboard account uses that email.';
        elseif (!pw_ok($pw)) $err = 'Password must be at least 10 characters and include letters and numbers.';
        elseif (!hash_equals($pw, $pw2)) $err = 'The two passwords do not match.';
        else { set_admin_password($db, (int)$id, $pw, 'with setup code'); header('Location: ./?reset=1'); exit; }
        $view = 'code';
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Reset password — VERO Admin</title>
<link rel="icon" href="/images/vero-mark.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at 50% 0%,#1A1240,#0A0A0C 60%);font-family:Inter,system-ui,sans-serif;color:#0E0E12;padding:20px}
.card{width:100%;max-width:420px;background:#fff;border-radius:24px;padding:32px;box-shadow:0 40px 80px -30px rgba(0,0,0,.8)}
h1{font-family:'Space Grotesk',sans-serif;font-size:26px;margin:18px 0 6px}p{color:#5B5B66;font-size:15px;line-height:1.55;margin:0 0 6px}
label{display:block;font-size:14px;font-weight:600;margin:14px 0 6px}input{width:100%;font:inherit;font-size:15px;padding:12px 14px;border:1px solid #D4D4DC;border-radius:12px;outline:none}input:focus{border-color:#7C5CFF;box-shadow:0 0 0 4px #ECE8FF}
button{width:100%;margin-top:22px;font:inherit;font-weight:600;font-size:16px;padding:14px;border:0;border-radius:999px;background:#7C5CFF;color:#fff;cursor:pointer}
.err{background:#FFF1F3;color:#B4234A;border:1px solid #FFD0DA;border-radius:12px;padding:12px 14px;font-size:14px;margin-top:14px}
.ok{background:#E8F7EE;color:#11733A;border:1px solid #BFE8CF;border-radius:12px;padding:12px 14px;font-size:14px;margin-top:14px}
.logo{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;letter-spacing:.2em}.logo img{height:28px;filter:brightness(0)}
.hint{font-size:12px;color:#8A8A96;margin-top:6px}.links{margin-top:18px;font-size:14px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.links a{color:#7C5CFF;text-decoration:none;font-weight:600}
</style></head><body>
<div class="card">
<div class="logo"><img src="/images/vero-mark.png" alt="">VERO</div>
<?php if ($view === 'newpw'): ?>
<form method="post" autocomplete="off">
<h1>Choose a new password</h1>
<p>Pick a password you haven't used here before.</p>
<?php if ($err): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="newpw"><input type="hidden" name="t" value="<?= $h($token) ?>">
<label for="p1">New password</label><input id="p1" name="password" type="password" required minlength="10" autocomplete="new-password">
<div class="hint">At least 10 characters, with letters and numbers.</div>
<label for="p2">Repeat new password</label><input id="p2" name="password2" type="password" required minlength="10" autocomplete="new-password">
<button type="submit">Save new password</button>
</form>
<?php elseif ($view === 'expired'): ?>
<h1>Link expired</h1>
<p>This reset link has expired or was already used. Request a new one.</p>
<div class="links"><a href="reset.php">Send a new link</a><a href="./">Back to sign in</a></div>
<?php elseif ($view === 'code'): ?>
<form method="post" autocomplete="off">
<h1>Reset with setup code</h1>
<p>Use the private setup code you entered when you first created this dashboard.</p>
<?php if ($err): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="code">
<label for="c">Setup code</label><input id="c" name="code" required placeholder="XXXXX-XXXXX-XXXXX-XXXXX" autocapitalize="characters" spellcheck="false">
<label for="e">Your dashboard email</label><input id="e" name="email" type="email" required autocomplete="username" value="<?= $h($_POST['email'] ?? '') ?>">
<label for="p1">New password</label><input id="p1" name="password" type="password" required minlength="10" autocomplete="new-password">
<div class="hint">At least 10 characters, with letters and numbers.</div>
<label for="p2">Repeat new password</label><input id="p2" name="password2" type="password" required minlength="10" autocomplete="new-password">
<button type="submit">Save new password</button>
<div class="links"><a href="reset.php">Use email instead</a><a href="./">Back to sign in</a></div>
</form>
<?php else: ?>
<form method="post" autocomplete="off">
<h1>Forgot your password?</h1>
<p>Enter your dashboard email and we'll send you a link to choose a new one.</p>
<?php if ($err): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="ok" role="status"><?= $h($ok) ?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="email">
<label for="e">Dashboard email</label><input id="e" name="email" type="email" required autocomplete="username">
<button type="submit">Email me a reset link</button>
<div class="links"><a href="reset.php?m=code" id="codeLink">Use my setup code instead</a><a href="./">Back to sign in</a></div>
</form>
<?php endif; ?>
</div>
</body></html>
