<?php
// One-time setup. Works only while no configuration exists, and only with the private setup code.
declare(strict_types=1);
require __DIR__ . '/../api/_lib/bootstrap.php';
require __DIR__ . '/../api/_lib/schema.php';
admin_headers();

if (vero_config()) { header('Location: ./'); exit; }

$err = '';
$priv = vero_private_dir();

function setup_throttled(string $priv): bool {
    $f = $priv . '/.setup_attempts';
    $now = time();
    $list = is_file($f) ? array_filter(array_map('intval', explode("\n", (string)file_get_contents($f))), fn($t) => $t > $now - 900) : [];
    if (count($list) >= 5) return true;
    $list[] = $now;
    @file_put_contents($f, implode("\n", $list), LOCK_EX);
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!is_dir($priv)) { @mkdir($priv, 0700, true); }
    $sealed = require (getenv('VERO_SEALED_FILE') ?: __DIR__ . '/../api/_lib/sealed.php');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (setup_throttled($priv)) {
        $err = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif (!password_verify($code, $sealed['code_hash'])) {
        $err = 'That setup code is not correct.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } elseif (strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $err = 'Password must be at least 10 characters and include letters and numbers.';
    } elseif (!hash_equals($pw, $pw2)) {
        $err = 'The two passwords do not match.';
    } else {
        $key = hash_pbkdf2('sha256', $code, base64_decode($sealed['salt']), 200000, 32, true);
        $plain = openssl_decrypt(base64_decode($sealed['ct']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, base64_decode($sealed['iv']), base64_decode($sealed['tag']));
        $creds = $plain ? json_decode($plain, true) : null;
        if (!is_array($creds)) {
            $err = 'Could not unlock the database settings.';
        } else {
            $cfg = $creds + ['secret' => bin2hex(random_bytes(32)), 'created' => gmdate('c')];
            try {
                $export = "<?php\n// VERO private configuration. Keep this file private. Created " . gmdate('c') . "\nreturn " . var_export($cfg, true) . ";\n";
                $tmp = $priv . '/config.php.tmp';
                if (file_put_contents($tmp, $export, LOCK_EX) === false) throw new RuntimeException('write');
                @chmod($tmp, 0600);
                // connect and build tables before activating the config
                $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
                $db = ($cfg['driver'] ?? 'mysql') === 'sqlite'
                    ? new PDO('sqlite:' . $cfg['sqlite_path'], null, null, $opts)
                    : new PDO('mysql:host=' . $cfg['db_host'] . ';port=' . ($cfg['db_port'] ?? 3306) . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4', $cfg['db_user'], $cfg['db_pass'], $opts);
                vero_install_schema($db, ($cfg['driver'] ?? 'mysql') === 'sqlite');
                $n = (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
                if ($n === 0) {
                    $db->prepare('INSERT INTO admins (email, pass_hash, created_at) VALUES (?, ?, ?)')
                       ->execute([$email, password_hash($pw, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s')]);
                }
                $db->prepare('DELETE FROM settings WHERE k = ?')->execute(['notify_email']);
                $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute(['notify_email', 'clients.verodigitalweb@gmail.com']);
                $db->prepare('INSERT INTO events (type, ref_id, detail, created_at) VALUES (?, NULL, ?, ?)')->execute(['setup', 'Dashboard set up', gmdate('Y-m-d H:i:s')]);
                rename($tmp, $priv . '/config.php');
                @unlink($priv . '/.setup_attempts');
                header('Location: ./?setup=1');
                exit;
            } catch (Throwable $e) {
                @unlink($priv . '/config.php.tmp');
                error_log('VERO setup failed: ' . $e->getMessage());
                $err = 'Setup could not reach the database. Please try again in a minute.';
            }
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Set up your dashboard — VERO</title>
<link rel="icon" href="/images/vero-mark.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at 50% 0%,#1A1240,#0A0A0C 60%);font-family:Inter,system-ui,sans-serif;color:#0E0E12;padding:20px}
.card{width:100%;max-width:440px;background:#fff;border-radius:24px;padding:32px;box-shadow:0 40px 80px -30px rgba(0,0,0,.8)}
h1{font-family:'Space Grotesk',sans-serif;font-size:26px;margin:18px 0 6px}p{color:#5B5B66;font-size:15px;line-height:1.55;margin:0 0 20px}
label{display:block;font-size:14px;font-weight:600;margin:14px 0 6px}input{width:100%;font:inherit;font-size:15px;padding:12px 14px;border:1px solid #D4D4DC;border-radius:12px;outline:none}input:focus{border-color:#7C5CFF;box-shadow:0 0 0 4px #ECE8FF}
button{width:100%;margin-top:22px;font:inherit;font-weight:600;font-size:16px;padding:14px;border:0;border-radius:999px;background:#7C5CFF;color:#fff;cursor:pointer}
.err{background:#FFF1F3;color:#B4234A;border:1px solid #FFD0DA;border-radius:12px;padding:12px 14px;font-size:14px;margin-bottom:6px}
.logo{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;letter-spacing:.2em}.logo img{height:28px;filter:brightness(0)}
.hint{font-size:12px;color:#8A8A96;margin-top:6px}
</style></head><body>
<form class="card" method="post" autocomplete="off">
<div class="logo"><img src="/images/vero-mark.png" alt="">VERO</div>
<h1>Set up your dashboard</h1>
<p>This page works once. Enter the private setup code, then choose the email and password you'll use to sign in.</p>
<?php if ($err): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
<label for="code">Setup code</label><input id="code" name="code" required placeholder="XXXXX-XXXXX-XXXXX-XXXXX" autocapitalize="characters" spellcheck="false">
<label for="email">Your email</label><input id="email" name="email" type="email" required autocomplete="username" value="<?= $h($_POST['email'] ?? 'clients.verodigitalweb@gmail.com') ?>">
<label for="password">Choose a password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
<div class="hint">At least 10 characters, with letters and numbers.</div>
<label for="password2">Repeat password</label><input id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password">
<button type="submit">Create my admin account</button>
</form></body></html>
