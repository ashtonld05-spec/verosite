<?php
declare(strict_types=1);
require __DIR__ . '/../api/_lib/bootstrap.php';
admin_headers();
if (!vero_config()) { header('Location: setup.php'); exit; }
$authed = admin_logged_in();
$csrf = $_SESSION['csrf'] ?? '';
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#0A0A0C">
<title><?= $authed ? 'Dashboard' : 'Sign in' ?> — VERO Admin</title>
<link rel="icon" href="/images/vero-mark.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
<style>
:root{--v:#7C5CFF;--v2:#A99BFF;--vl:#ECE8FF;--ink:#0E0E12;--mut:#5B5B66;--line:#E7E7EC;--bg:#F4F4F6;--ok:#16A34A;--bad:#E1325A;--warn:#D97706}
*{box-sizing:border-box}html,body{margin:0}body{font-family:Inter,system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
button,input,textarea,select{font:inherit}button{cursor:pointer}a{color:var(--v)}
.h{font-family:'Space Grotesk',sans-serif}
/* login */
.login{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(ellipse at 50% 0%,#1A1240,#0A0A0C 60%)}
.lcard{width:100%;max-width:400px;background:#fff;border-radius:24px;padding:32px;box-shadow:0 40px 80px -30px rgba(0,0,0,.8)}
.brand{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;letter-spacing:.22em}
.brand img{height:26px}
.lcard .brand img{filter:brightness(0)}
.field{display:block;font-size:14px;font-weight:600;margin-top:14px}
.field input,.field textarea,.field select{display:block;width:100%;margin-top:6px;font-weight:400;font-size:15px;padding:12px 14px;border:1px solid #D4D4DC;border-radius:12px;background:#fff;color:var(--ink);outline:none}
.field input:focus,.field textarea:focus{border-color:var(--v);box-shadow:0 0 0 4px var(--vl)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;border-radius:999px;padding:11px 18px;font-weight:600;font-size:14px;text-decoration:none;white-space:nowrap;transition:transform .15s,background .15s}
.btn:active{transform:scale(.97)}
.bp{background:var(--v);color:#fff}.bp:hover{background:#6B4BEF}
.bo{background:#fff;color:var(--ink);border-color:#D4D4DC}.bo:hover{border-color:var(--v);color:var(--v)}
.bd{background:#0E0E12;color:#fff}
.bg{background:#E8F7EE;color:#11733A}.br{background:#FFF1F3;color:#B4234A}
.bsm{padding:8px 13px;font-size:13px}
.err{background:#FFF1F3;color:#B4234A;border:1px solid #FFD0DA;border-radius:12px;padding:11px 14px;font-size:14px;margin-top:14px}
.okmsg{background:#E8F7EE;color:#11733A;border:1px solid #BFE8CF;border-radius:12px;padding:11px 14px;font-size:14px;margin-top:14px}
/* app shell */
.app{display:grid;grid-template-columns:248px 1fr;min-height:100vh}
.side{background:#0A0A0C;color:#fff;padding:22px 16px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column}
.side .brand{padding:4px 8px 22px;font-size:15px}
.nav{display:flex;flex-direction:column;gap:4px}
.nav button{display:flex;align-items:center;gap:12px;width:100%;background:none;border:0;color:#C9C9D1;padding:11px 12px;border-radius:12px;font-size:15px;font-weight:500;text-align:left}
.nav button:hover{background:rgba(255,255,255,.06);color:#fff}
.nav button.on{background:rgba(124,92,255,.22);color:#fff}
.nav svg{width:19px;height:19px;flex-shrink:0}
.pill{margin-left:auto;background:var(--v);color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:2px 8px;min-width:22px;text-align:center}
.side-foot{margin-top:auto;font-size:13px;color:#8A8A96;padding:12px;border-top:1px solid rgba(255,255,255,.08)}
.side-foot a{color:#C9BFFF;text-decoration:none}
.main{min-width:0;padding:28px 32px 90px}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}
.top h1{font-family:'Space Grotesk',sans-serif;font-size:28px;margin:0;letter-spacing:-.02em}
.top .sub{color:var(--mut);font-size:14px;margin-top:2px}
.bell{position:relative;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center}
.bell .dot{position:absolute;top:6px;right:7px;width:10px;height:10px;border-radius:50%;background:var(--bad);border:2px solid #fff}
.grid{display:grid;gap:16px}
.k4{grid-template-columns:repeat(4,1fr)}
.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:20px}
.kpi .l{font-size:13px;color:var(--mut);font-weight:500;display:flex;align-items:center;gap:8px}
.kpi .n{font-family:'Space Grotesk',sans-serif;font-size:34px;font-weight:700;margin-top:10px;letter-spacing:-.02em}
.kpi .s{font-size:12.5px;color:var(--mut);margin-top:4px}
.ic{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:var(--vl);color:var(--v)}
.ic svg{width:16px;height:16px}
.sec-t{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
.sec-t h2{font-family:'Space Grotesk',sans-serif;font-size:18px;margin:0}
.two{grid-template-columns:1.4fr 1fr;margin-top:16px}
.bars{display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:10px}
.bars div{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
.bars i{display:block;width:100%;background:linear-gradient(180deg,var(--v2),var(--v));border-radius:6px 6px 2px 2px;min-height:3px}
.bars span{font-size:10px;color:#8A8A96}
.dist div{display:grid;grid-template-columns:28px 1fr 30px;gap:10px;align-items:center;font-size:13px;margin:7px 0}
.dist b{display:block;height:8px;border-radius:999px;background:#EEEEF2;overflow:hidden}
.dist b i{display:block;height:100%;background:#F5B83D}
.mini{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.mini div{background:var(--bg);border-radius:14px;padding:12px}
.mini b{display:block;font-family:'Space Grotesk',sans-serif;font-size:20px}
.mini span{font-size:12px;color:var(--mut)}
.list{display:flex;flex-direction:column}
.row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid var(--line);font-size:14px}
.row:first-child{border-top:0}
.feed{display:flex;flex-direction:column}
.ev{display:flex;gap:12px;padding:12px 0;border-top:1px solid var(--line)}
.ev:first-child{border-top:0}
.ev .ic{flex-shrink:0}
.ev .t{font-size:14px;font-weight:600}.ev .d{font-size:13px;color:var(--mut);margin-top:2px;word-break:break-word}.ev .w{margin-left:auto;font-size:12px;color:#8A8A96;white-space:nowrap}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.tabs button{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 14px;font-size:14px;font-weight:600;color:var(--mut)}
.tabs button.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.tabs button em{font-style:normal;opacity:.7;margin-left:4px}
.rv{background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px 20px;margin-bottom:12px}
.rv-h{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}
.stars{color:#F5B83D;letter-spacing:1px;font-size:16px}
.rv-n{font-weight:700}.rv-b{color:var(--mut);font-size:13px}
.tag{font-size:12px;font-weight:700;border-radius:999px;padding:3px 9px;text-transform:uppercase;letter-spacing:.04em}
.t-pending{background:#FFF4DE;color:#9A5B00}.t-approved{background:#E8F7EE;color:#11733A}.t-rejected{background:#FFF1F3;color:#B4234A}.t-pub{background:var(--vl);color:#3A2A9E}
.rv p{margin:12px 0 0;font-size:15px;line-height:1.6;white-space:pre-wrap;word-break:break-word}
.rv-a{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.empty{text-align:center;color:var(--mut);padding:40px 20px;font-size:15px}
.req{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.req .actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.preview{background:var(--bg);border-radius:14px;padding:14px;font-size:14px;line-height:1.55;margin-top:14px;white-space:pre-wrap;word-break:break-word}
.toggle{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:600;margin-top:14px}
.toggle input{width:18px;height:18px;accent-color:var(--v)}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#0E0E12;color:#fff;padding:12px 18px;border-radius:999px;font-size:14px;font-weight:600;box-shadow:0 20px 40px -12px rgba(0,0,0,.5);z-index:100;opacity:0;pointer-events:none;transition:opacity .2s}
.toast.show{opacity:1}
.confirm{display:inline-flex;gap:6px;align-items:center;font-size:13px;font-weight:600;color:#B4234A}
.page{display:none}.page.on{display:block;animation:fade .25s ease}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.mob{display:none}
.day-h{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;margin:22px 0 10px;display:flex;align-items:center;gap:8px}
.day-h:first-child{margin-top:0}.day-h em{font-style:normal;font-family:Inter;font-weight:600;font-size:12px;color:var(--mut);background:#fff;border:1px solid var(--line);border-radius:999px;padding:2px 9px}
.mt{display:flex;gap:16px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px 18px;margin-bottom:10px}
.mt.soon{border-color:var(--v);box-shadow:0 0 0 3px var(--vl)}
.mt-time{flex-shrink:0;width:86px;border-right:1px solid var(--line);padding-right:14px}
.mt-time b{display:block;font-family:'Space Grotesk',sans-serif;font-size:18px;letter-spacing:-.01em}
.mt-time span{font-size:12px;color:var(--mut)}
.mt-b{min-width:0;flex:1}
.mt-h{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}
.mt-n{font-weight:700;font-size:16px}
.mt-c{display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:8px;font-size:13.5px}
.mt-c a{color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;gap:5px}.mt-c a:hover{color:var(--v)}
.mt-c svg{width:14px;height:14px;color:var(--mut)}
.mt-notes{margin-top:10px;background:var(--bg);border-radius:12px;padding:10px 12px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.t-booked{background:var(--vl);color:#3A2A9E}.t-completed{background:#E8F7EE;color:#11733A}.t-no_show{background:#FFF4DE;color:#9A5B00}.t-cancelled,.t-rescheduled{background:#F1F1F4;color:#5B5B66}
.nm{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--line)}.nm:first-child{border-top:0}
.nm .dt{flex-shrink:0;width:54px;text-align:center;background:var(--vl);color:#3A2A9E;border-radius:12px;padding:6px 0;line-height:1.1}
.nm .dt b{display:block;font-family:'Space Grotesk',sans-serif;font-size:19px}.nm .dt span{font-size:11px;font-weight:700;text-transform:uppercase}
.nm .who{min-width:0;flex:1}.nm .who div:first-child{font-weight:600}.nm .who div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hook{background:linear-gradient(135deg,#F3F0FF,#fff);border:1px solid #DCD4FF}
.hook ol{margin:12px 0 0;padding-left:20px;font-size:14px;line-height:1.75}
.code{display:flex;gap:8px;align-items:center;margin-top:8px}
.code code{flex:1;min-width:0;background:#fff;border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,Menlo,monospace}
@media (max-width:1100px){.k4{grid-template-columns:repeat(2,1fr)}.two{grid-template-columns:1fr}}
@media (max-width:760px){
.app{grid-template-columns:1fr}.side{display:none}
.main{padding:18px 16px 96px}
.top h1{font-size:23px}
.mob{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:50;background:#0A0A0C;padding:8px 6px calc(8px + env(safe-area-inset-bottom));justify-content:space-around}
.mob button{background:none;border:0;color:#A1A1AA;display:flex;flex-direction:column;align-items:center;gap:3px;font-size:10.5px;font-weight:600;padding:4px 2px;position:relative;flex:1;min-width:0}
.mob button.on{color:#fff}.mob svg{width:22px;height:22px}
.mob .pill{position:absolute;top:-2px;right:6px;margin:0;font-size:10px;padding:1px 6px;min-width:0}
.kpi .n{font-size:28px}.card{padding:16px;border-radius:16px}
.req{grid-template-columns:1fr}
.mini{grid-template-columns:repeat(3,1fr)}
.rv{padding:16px}
.ev .w{display:none}
.mt{gap:12px;padding:14px}.mt-time{width:68px;padding-right:10px}.mt-time b{font-size:16px}
}
</style></head><body>
<?php if (!$authed): ?>
<div class="login">
<form class="lcard" id="loginForm" autocomplete="on">
<div class="brand"><img src="/images/vero-mark.png" alt="">VERO</div>
<h1 class="h" style="font-size:26px;margin:18px 0 6px">Admin sign in</h1>
<p style="color:var(--mut);font-size:15px;margin:0 0 6px">Private dashboard for VERO Digital.</p>
<?php if (isset($_GET['setup'])): ?><div class="okmsg">Your account is ready. Sign in with the email and password you just chose.</div><?php endif; ?>
<label class="field">Email<input name="email" type="email" required autocomplete="username"></label>
<label class="field">Password<input name="password" type="password" required autocomplete="current-password"></label>
<div id="loginErr" class="err" role="alert" style="display:none"></div>
<button class="btn bp" type="submit" style="width:100%;margin-top:22px;padding:14px;font-size:16px">Sign in</button>
</form>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  var f = e.target, err = document.getElementById('loginErr'), b = f.querySelector('button');
  err.style.display = 'none'; b.disabled = true; b.textContent = 'Signing in…';
  try {
    var r = await fetch('api.php?a=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ email: f.email.value, password: f.password.value }) });
    var j = await r.json();
    if (r.ok && j.ok) { location.replace('./'); return; }
    err.textContent = j.error || 'Sign in failed.'; err.style.display = 'block';
  } catch (x) { err.textContent = 'Could not reach the server. Check your connection.'; err.style.display = 'block'; }
  b.disabled = false; b.textContent = 'Sign in';
});
</script>
<?php else: ?>
<div class="app">
<aside class="side">
<div class="brand"><img src="/images/vero-mark.png" alt="">VERO <span style="font-weight:500;letter-spacing:0;color:#8A8A96;font-family:Inter;font-size:12px;margin-left:4px">Admin</span></div>
<nav class="nav" id="nav"></nav>
<div class="side-foot"><div id="meEmail" style="color:#C9C9D1;font-weight:600;word-break:break-all"></div><a href="/" target="_blank" rel="noopener">View website ↗</a></div>
</aside>
<main class="main">
<div class="top"><div><h1 id="title">Overview</h1><div class="sub" id="subtitle"></div></div>
<button class="bell" id="bell" title="Pending reviews" aria-label="Pending reviews"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><span class="dot" id="bellDot" style="display:none"></span></button></div>

<section class="page on" id="p-overview">
<div class="grid k4" id="kpis"></div>
<div class="card" style="margin-top:16px"><div class="sec-t"><h2>Upcoming meetings</h2><button class="btn bo bsm" data-go="meetings">All meetings</button></div><div id="meetMini"></div></div>
<div class="grid two">
<div class="card"><div class="sec-t"><h2>Website visits · last 14 days</h2><span class="rv-b" id="viewsTotal"></span></div><div class="bars" id="chart"></div>
<div class="mini" id="webMini"></div></div>
<div class="card"><div class="sec-t"><h2>Rating breakdown</h2></div><div class="dist" id="dist"></div>
<div class="sec-t" style="margin-top:18px"><h2>Top pages · 30 days</h2></div><div class="list" id="pages"></div></div>
</div>
<div class="grid two">
<div class="card"><div class="sec-t"><h2>Recent activity</h2><button class="btn bo bsm" data-go="activity">See all</button></div><div class="feed" id="feedMini"></div></div>
<div class="card"><div class="sec-t"><h2>Needs your review</h2><button class="btn bo bsm" data-go="reviews">Open reviews</button></div><div id="pendingMini"></div></div>
</div>
</section>

<section class="page" id="p-meetings">
<div class="tabs" id="mtabs"></div>
<div id="mlist"></div>
<div id="mhook" style="margin-top:18px"></div>
</section>

<section class="page" id="p-reviews">
<div class="tabs" id="rtabs"></div>
<div id="rlist"></div>
</section>

<section class="page" id="p-requests">
<div class="req">
<form class="card" id="reqForm">
<div class="sec-t"><h2>Ask a customer for a review</h2></div>
<p class="rv-b" style="font-size:14px;margin:0">Creates a personal review link and opens your Messages app (or Gmail) with the text ready to send.</p>
<label class="field">Customer name<input name="name" maxlength="80" placeholder="Jordan Smith"></label>
<label class="field">Mobile number<input name="phone" type="tel" maxlength="30" placeholder="(555) 123-4567"></label>
<label class="field">Email <span style="font-weight:400;color:var(--mut)">(optional)</span><input name="email" type="email" maxlength="190" placeholder="jordan@business.com"></label>
<div class="actions">
<button class="btn bp" type="submit" data-ch="sms"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Send by text</button>
<button class="btn bo" type="submit" data-ch="email">Send by Gmail</button>
<button class="btn bo" type="submit" data-ch="copy">Copy link</button>
</div>
<div id="reqErr" class="err" style="display:none" role="alert"></div>
<div id="reqOut" style="display:none"><div class="preview" id="reqMsg"></div><div class="actions" id="reqAgain"></div></div>
</form>
<div class="card"><div class="sec-t"><h2>Sent requests</h2></div><div class="list" id="reqList"></div></div>
</div>
</section>

<section class="page" id="p-activity"><div class="card"><div class="feed" id="feed"></div></div></section>

<section class="page" id="p-settings">
<div class="req">
<form class="card" id="setForm">
<div class="sec-t"><h2>Review notifications</h2></div>
<label class="field">Send new-review emails to<input name="notify_email" type="email" required></label>
<label class="toggle"><input type="checkbox" name="notify_on"> Email me when a new review is submitted</label>
<div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px"><button class="btn bp" type="submit">Save</button><button class="btn bo" type="button" id="testMail">Send test email</button><button class="btn bo" type="button" id="pushBtn">Turn on browser alerts</button></div>
<div id="setMsg" style="display:none"></div>
</form>
<form class="card" id="pwForm">
<div class="sec-t"><h2>Change password</h2></div>
<label class="field">Current password<input name="current" type="password" required autocomplete="current-password"></label>
<label class="field">New password<input name="new" type="password" required minlength="10" autocomplete="new-password"></label>
<div class="actions" style="display:flex;gap:8px;margin-top:18px"><button class="btn bp" type="submit">Update password</button></div>
<div id="pwMsg" style="display:none"></div>
</form>
<div class="card"><div class="sec-t"><h2>Your public review link</h2></div>
<p class="rv-b" style="font-size:14px;margin:0">Anyone with this link can leave a review. Reviews stay hidden until you approve them.</p>
<div class="preview" id="pubLink"></div><div style="display:flex;gap:8px;margin-top:12px"><button class="btn bo bsm" id="copyPub" type="button">Copy link</button><a class="btn bo bsm" href="/review.html" target="_blank" rel="noopener">Open</a></div></div>
<div class="card"><div class="sec-t"><h2>Account</h2></div><p class="rv-b" style="font-size:14px;margin:0">You're signed in as <b id="meEmail2"></b>. You'll be signed out automatically after 2 hours of inactivity.</p>
<button class="btn bd" id="logout" type="button" style="margin-top:16px">Sign out</button></div>
</div>
</section>
</main>
<nav class="mob" id="mob"></nav>
</div>
<div class="toast" id="toast"></div>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  var SITE = location.origin;
  var I = {
    meetings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8 14h2v2H8z"/></svg>',
    overview: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    reviews: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/></svg>',
    requests: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>',
    activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
    settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
    lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
    globe: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
    mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>',
    video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>'
  };
  var PAGES = [['overview', 'Overview'], ['meetings', 'Meetings'], ['reviews', 'Reviews'], ['requests', 'Review requests'], ['activity', 'Activity'], ['settings', 'Settings']];
  var state = { page: 'overview', mview: 'upcoming', meetUp: 0, filter: 'pending', pending: 0, lastPending: null, me: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(id) { return document.getElementById(id); }
  function toast(t) { var x = $('toast'); x.textContent = t; x.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(function () { x.classList.remove('show'); }, 2600); }
  function when(utc) {
    if (!utc) return '';
    var d = new Date(utc.replace(' ', 'T') + 'Z'), s = (Date.now() - d.getTime()) / 1000;
    if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 604800) return Math.floor(s / 86400) + 'd ago';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function full(utc) { return utc ? new Date(utc.replace(' ', 'T') + 'Z').toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; }
  function stars(n) { return '★★★★★'.slice(0, n) + '<span style="color:#E2E2E8">' + '★★★★★'.slice(0, 5 - n) + '</span>'; }

  async function api(a, body, qs) {
    var opt = { credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } };
    if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    var r = await fetch('api.php?a=' + a + (qs || ''), opt);
    if (r.status === 401) { location.replace('./'); throw new Error('signed out'); }
    var j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok) throw new Error(j.error || 'Something went wrong (' + r.status + ').');
    return j;
  }

  // ---------- navigation ----------
  function renderNav() {
    var h = PAGES.map(function (p) {
      var badge = p[0] === 'reviews' && state.pending ? '<span class="pill">' + state.pending + '</span>' : (p[0] === 'meetings' && state.meetUp ? '<span class="pill" style="background:#2A2A33">' + state.meetUp + '</span>' : '');
      return '<button data-go="' + p[0] + '" class="' + (state.page === p[0] ? 'on' : '') + '">' + I[p[0]] + '<span>' + p[1] + '</span>' + badge + '</button>';
    }).join('');
    $('nav').innerHTML = h;
    $('mob').innerHTML = PAGES.map(function (p) {
      var badge = p[0] === 'reviews' && state.pending ? '<span class="pill">' + state.pending + '</span>' : '';
      return '<button data-go="' + p[0] + '" class="' + (state.page === p[0] ? 'on' : '') + '">' + I[p[0]] + (p[0] === 'requests' ? 'Requests' : p[1]) + badge + '</button>';
    }).join('');
    $('bellDot').style.display = state.pending ? 'block' : 'none';
  }
  function go(p) {
    state.page = p;
    PAGES.forEach(function (x) { $('p-' + x[0]).classList.toggle('on', x[0] === p); });
    var t = PAGES.filter(function (x) { return x[0] === p; })[0][1];
    $('title').textContent = t;
    $('subtitle').textContent = { overview: 'Your meetings, reviews and website at a glance', meetings: 'Who you are meeting with, and how to reach them', reviews: 'Approve, reject and publish customer reviews', requests: 'Text or email customers a link to review you', activity: 'Everything that has happened recently', settings: 'Notifications, password and account' }[p];
    renderNav(); window.scrollTo(0, 0);
    load(p);
    try { history.replaceState(null, '', '#' + p); } catch (e) {}
  }
  document.addEventListener('click', function (e) { var b = e.target.closest('[data-go]'); if (b) { e.preventDefault(); go(b.getAttribute('data-go')); } });
  $('bell').addEventListener('click', function () { state.filter = 'pending'; go('reviews'); });

  function load(p) {
    if (p === 'overview') return loadOverview();
    if (p === 'meetings') return loadMeetings();
    if (p === 'reviews') return loadReviews();
    if (p === 'requests') return loadRequests();
    if (p === 'activity') return loadActivity($('feed'), 80);
    if (p === 'settings') return loadSettings();
  }

  // ---------- overview ----------
  async function loadOverview() {
    try {
      var j = await api('overview'), s = j.stats, w = j.web;
      renderMeetMini(j.meetings);
      setPending(s.pending);
      var k = [
        ['reviews', 'Total reviews', s.total, s.approved + ' approved · ' + s.rejected + ' rejected'],
        ['clock', 'Pending reviews', s.pending, s.pending ? 'Waiting for your approval' : 'All caught up'],
        ['globe', 'Published reviews', s.published, 'Showing on your website'],
        ['reviews', 'Average rating', s.avg_published != null ? s.avg_published.toFixed(1) + ' ★' : '—', s.avg_all != null ? 'All reviews: ' + s.avg_all.toFixed(1) + ' ★' : 'No reviews yet']
      ];
      $('kpis').innerHTML = k.map(function (x) { return '<div class="card kpi"><div class="l"><span class="ic">' + I[x[0]] + '</span>' + x[1] + '</div><div class="n">' + esc(x[2]) + '</div><div class="s">' + esc(x[3]) + '</div></div>'; }).join('');
      var max = Math.max.apply(null, w.days.map(function (d) { return d.views; }).concat([1]));
      $('chart').innerHTML = w.days.map(function (d) { var dt = new Date(d.day + 'T12:00:00Z'); return '<div title="' + d.views + ' views · ' + d.visitors + ' visitors"><i style="height:' + Math.max(2, Math.round(d.views / max * 100)) + '%"></i><span>' + dt.getUTCDate() + '</span></div>'; }).join('');
      $('viewsTotal').textContent = w.views_30 + ' views in 30 days';
      $('webMini').innerHTML = [[w.views_today, 'Views today'], [w.visitors_7, 'Visitors · 7 days'], [w.book_clicks_30, 'Book clicks · 30d'], [w.bookings_30, 'Bookings · 30d'], [w.views_7, 'Views · 7 days'], [w.requests_30, 'Review requests · 30d']]
        .map(function (x) { return '<div><b>' + esc(x[0]) + '</b><span>' + x[1] + '</span></div>'; }).join('');
      var tot = s.total || 1;
      $('dist').innerHTML = [5, 4, 3, 2, 1].map(function (n) { var c = s.distribution[n] || 0; return '<div><span>' + n + ' ★</span><b><i style="width:' + Math.round(c / tot * 100) + '%"></i></b><span style="text-align:right">' + c + '</span></div>'; }).join('');
      $('pages').innerHTML = w.pages.length ? w.pages.map(function (p) { return '<div class="row"><span>' + esc(p.path === '/' ? '/ (home)' : p.path) + '</span><b>' + p.n + '</b></div>'; }).join('') : '<div class="empty" style="padding:16px">Visits will show here as people browse your site.</div>';
      loadActivity($('feedMini'), 6);
      var r = await api('reviews', null, '&status=pending');
      $('pendingMini').innerHTML = r.reviews.length ? r.reviews.slice(0, 3).map(function (v) { return '<div class="row" style="align-items:flex-start"><div><div class="stars">' + stars(v.rating) + '</div><div style="font-weight:600;margin-top:2px">' + esc(v.name) + '</div><div class="rv-b" style="margin-top:2px">' + esc(v.body.slice(0, 90)) + (v.body.length > 90 ? '…' : '') + '</div></div><span class="rv-b">' + when(v.created_at) + '</span></div>'; }).join('') : '<div class="empty" style="padding:20px">No reviews waiting. Send a review request to get your first one.</div>';
    } catch (e) { toast(e.message); }
  }

  // ---------- reviews ----------
  async function loadReviews() {
    $('rlist').innerHTML = '<div class="empty">Loading…</div>';
    try {
      var j = await api('reviews', null, '&status=' + state.filter), s = j.stats;
      setPending(s.pending);
      var tabs = [['pending', 'Pending', s.pending], ['approved', 'Approved', s.approved], ['published', 'Published', s.published], ['rejected', 'Rejected', s.rejected], ['all', 'All', s.total]];
      $('rtabs').innerHTML = tabs.map(function (t) { return '<button data-f="' + t[0] + '" class="' + (state.filter === t[0] ? 'on' : '') + '">' + t[1] + '<em>' + t[2] + '</em></button>'; }).join('');
      if (!j.reviews.length) { $('rlist').innerHTML = '<div class="card empty">' + (state.filter === 'pending' ? 'No reviews waiting for approval.' : 'No reviews here yet.') + '</div>'; return; }
      $('rlist').innerHTML = j.reviews.map(function (v) {
        var tag = '<span class="tag t-' + v.status + '">' + v.status + '</span>' + (v.published ? ' <span class="tag t-pub">On website</span>' : '');
        var a = [];
        if (v.status === 'pending') { a.push('<button class="btn bp bsm" data-op="approve_publish" data-id="' + v.id + '">' + I.check.replace('<svg', '<svg width="15" height="15"') + 'Approve & publish</button>'); a.push('<button class="btn bg bsm" data-op="approve" data-id="' + v.id + '">Approve only</button>'); a.push('<button class="btn br bsm" data-op="reject" data-id="' + v.id + '">Reject</button>'); }
        if (v.status === 'approved' && !v.published) a.push('<button class="btn bp bsm" data-op="publish" data-id="' + v.id + '">Publish to website</button>');
        if (v.status === 'approved' && v.published) a.push('<button class="btn bo bsm" data-op="unpublish" data-id="' + v.id + '">Remove from website</button>');
        if (v.status === 'approved') a.push('<button class="btn br bsm" data-op="reject" data-id="' + v.id + '">Reject</button>');
        if (v.status === 'rejected') a.push('<button class="btn bg bsm" data-op="approve" data-id="' + v.id + '">Approve</button>');
        a.push('<button class="btn bo bsm" data-del="' + v.id + '">' + I.trash.replace('<svg', '<svg width="14" height="14"') + 'Delete</button>');
        return '<article class="rv" id="rv' + v.id + '"><div class="rv-h"><span class="stars">' + stars(v.rating) + '</span><span class="rv-n">' + esc(v.name) + '</span>' + (v.business ? '<span class="rv-b">' + esc(v.business) + '</span>' : '') + tag + '<span class="rv-b" style="margin-left:auto" title="' + esc(full(v.created_at)) + '">' + when(v.created_at) + '</span></div>' +
          (v.email ? '<div class="rv-b" style="margin-top:6px">' + esc(v.email) + '</div>' : '') + '<p>' + esc(v.body) + '</p><div class="rv-a">' + a.join('') + '</div></article>';
      }).join('');
    } catch (e) { $('rlist').innerHTML = '<div class="card err">' + esc(e.message) + '</div>'; }
  }
  $('rtabs').addEventListener('click', function (e) { var b = e.target.closest('[data-f]'); if (b) { state.filter = b.getAttribute('data-f'); loadReviews(); } });
  $('rlist').addEventListener('click', async function (e) {
    var b = e.target.closest('[data-op]'), d = e.target.closest('[data-del]'), yes = e.target.closest('[data-yes]'), no = e.target.closest('[data-no]');
    if (d) { var wrap = d.parentNode; d.outerHTML = '<span class="confirm">Delete for good? <button class="btn br bsm" data-yes="' + d.getAttribute('data-del') + '">Delete</button><button class="btn bo bsm" data-no="1">Keep</button></span>'; return; }
    if (no) { loadReviews(); return; }
    var id = b ? b.getAttribute('data-id') : yes ? yes.getAttribute('data-yes') : null, op = b ? b.getAttribute('data-op') : yes ? 'delete' : null;
    if (!id) return;
    var btn = b || yes; btn.disabled = true;
    try {
      await api('review_update', { id: +id, op: op });
      toast({ approve: 'Review approved', approve_publish: 'Approved and published to your website', reject: 'Review rejected', publish: 'Published to your website', unpublish: 'Removed from your website', delete: 'Review deleted' }[op]);
      loadReviews();
    } catch (x) { toast(x.message); btn.disabled = false; }
  });

  // ---------- activity ----------
  var EV = {
    review_submitted: ['reviews', 'New review submitted'], review_approved: ['check', 'Review approved'], review_rejected: ['x', 'Review rejected'],
    review_published: ['globe', 'Review published'], review_unpublished: ['eye', 'Review removed from website'], review_deleted: ['trash', 'Review deleted'],
    review_request_sent: ['requests', 'Review request sent'], review_link_opened: ['eye', 'Review link opened'],
    book_click: ['cal', 'Book button clicked'], booking_completed: ['meetings', 'Consultation booked'], meeting_cancelled: ['x', 'Meeting cancelled'], meeting_rescheduled: ['clock', 'Meeting rescheduled'],
    meeting_completed: ['check', 'Meeting marked done'], meeting_no_show: ['x', 'Marked as no-show'], meeting_restored: ['meetings', 'Meeting restored'], meeting_deleted: ['trash', 'Meeting deleted'], webhook_rejected: ['lock', 'Blocked a fake Cal.com update'],
    login: ['lock', 'Signed in'], login_failed: ['lock', 'Failed sign-in attempt'], logout: ['lock', 'Signed out'], password_changed: ['lock', 'Password changed'],
    settings_changed: ['settings', 'Settings updated'], test_email_sent: ['check', 'Test email sent'], notify_failed: ['x', 'Email could not be sent'], setup: ['check', 'Dashboard created']
  };
  async function loadActivity(el, n) {
    try {
      var j = await api('activity'), ev = j.events.slice(0, n);
      el.innerHTML = ev.length ? ev.map(function (x) { var m = EV[x.type] || ['activity', x.type]; var bad = x.type === 'login_failed' || x.type === 'notify_failed' || x.type === 'webhook_rejected';
        return '<div class="ev"><span class="ic" style="' + (bad ? 'background:#FFF1F3;color:#B4234A' : '') + '">' + I[m[0]] + '</span><div><div class="t">' + m[1] + '</div><div class="d">' + esc(x.detail) + '</div></div><span class="w" title="' + esc(full(x.created_at)) + '">' + when(x.created_at) + '</span></div>'; }).join('') : '<div class="empty">No activity yet.</div>';
    } catch (e) { el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
  }

  // ---------- meetings ----------
  function dt(utc) { return new Date(String(utc).replace(' ', 'T') + 'Z'); }
  function tme(d) { return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }); }
  function dayKey(d) { return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
  function dayLabel(d) {
    var t = new Date(), tm = new Date(); tm.setDate(t.getDate() + 1); var y = new Date(); y.setDate(t.getDate() - 1);
    if (dayKey(d) === dayKey(t)) return 'Today'; if (dayKey(d) === dayKey(tm)) return 'Tomorrow'; if (dayKey(d) === dayKey(y)) return 'Yesterday';
    return d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: d.getFullYear() !== t.getFullYear() ? 'numeric' : undefined });
  }
  function telHref(p) { return 'tel:' + String(p).replace(/[^0-9+]/g, ''); }
  function prettyPhone(p) { var d = String(p).replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') d = d.slice(1); return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : p; }
  function joinHref(m) { return m.meet_url || ('https://cal.com/booking/' + encodeURIComponent(m.uid)); }
  var ML = { booked: 'Booked', completed: 'Done', no_show: 'No-show', cancelled: 'Cancelled', rescheduled: 'Moved' };
  function renderMeetMini(mt) {
    state.meetUp = mt.counts.upcoming; renderNav();
    $('meetMini').innerHTML = mt.next.length ? mt.next.map(function (m) { var d = dt(m.start_at);
      return '<div class="nm"><div class="dt"><span>' + d.toLocaleDateString('en-US', { month: 'short' }) + '</span><b>' + d.getDate() + '</b></div><div class="who"><div>' + esc(m.name) + (m.business ? ' <span class="rv-b">· ' + esc(m.business) + '</span>' : '') + '</div><div class="rv-b">' + dayLabel(d) + ' at ' + tme(d) + (m.phone ? ' · ' + esc(prettyPhone(m.phone)) : '') + '</div></div>' + (m.phone ? '<a class="btn bo bsm" href="' + esc(telHref(m.phone)) + '">Call</a>' : '') + '</div>'; }).join('')
      : '<div class="empty" style="padding:18px">No upcoming meetings yet. New consultations show up here as soon as someone books.</div>';
  }
  async function loadMeetings() {
    $('mlist').innerHTML = '<div class="empty">Loading…</div>';
    try {
      var j = await api('meetings', null, '&view=' + state.mview), c = j.counts;
      state.meetUp = c.upcoming; renderNav();
      $('mtabs').innerHTML = [['upcoming', 'Upcoming', c.upcoming], ['past', 'Past', c.past], ['cancelled', 'Cancelled', c.cancelled], ['all', 'All', c.all]]
        .map(function (t) { return '<button data-mv="' + t[0] + '" class="' + (state.mview === t[0] ? 'on' : '') + '">' + t[1] + '<em>' + t[2] + '</em></button>'; }).join('');
      if (!j.meetings.length) {
        $('mlist').innerHTML = '<div class="card empty">' + ({ upcoming: 'No upcoming meetings. When someone books a consultation, they show up here with their phone, email and notes.', past: 'No past meetings yet.', cancelled: 'No cancelled meetings.', all: 'No meetings yet.' }[state.mview]) + '</div>';
      } else {
        var out = [], last = '', now = Date.now();
        j.meetings.forEach(function (m) {
          var d = dt(m.start_at), k = dayKey(d), end = m.end_at ? dt(m.end_at) : new Date(d.getTime() + 15 * 60000);
          if (k !== last) { last = k; var n = j.meetings.filter(function (x) { return dayKey(dt(x.start_at)) === k; }).length; out.push('<div class="day-h">' + dayLabel(d) + '<em>' + n + (n === 1 ? ' meeting' : ' meetings') + '</em></div>'); }
          var mins = Math.max(5, Math.round((end - d) / 60000)), upcoming = end.getTime() >= now && m.status === 'booked', soon = upcoming && d.getTime() - now < 3600000;
          var tags = '<span class="tag t-' + esc(m.status) + '">' + (soon && d.getTime() <= now ? 'Happening now' : soon ? 'Starts soon' : (ML[m.status] || esc(m.status))) + '</span>';
          var c2 = [];
          if (m.phone) c2.push('<a href="' + esc(telHref(m.phone)) + '">' + I.phone + esc(prettyPhone(m.phone)) + '</a>');
          if (m.email) c2.push('<a href="' + esc(gmailHref(m.email, 'Hi ' + (m.name || '').split(' ')[0] + ',\n\n')) + '" target="_blank" rel="noopener">' + I.mail + esc(m.email) + '</a>');
          var a = [];
          if (upcoming) {
            a.push('<a class="btn bp bsm" href="' + esc(joinHref(m)) + '" target="_blank" rel="noopener">' + I.video.replace('<svg', '<svg width="15" height="15"') + (m.meet_url ? 'Join call' : 'Open in Cal.com') + '</a>');
            if (m.phone) a.push('<a class="btn bo bsm" href="' + esc(smsHref(m.phone, 'Hi ' + (m.name || '').split(' ')[0] + ', this is VERO Digital. Just confirming our call ' + (/^(Today|Tomorrow)$/.test(dayLabel(d)) ? dayLabel(d).toLowerCase() : 'on ' + dayLabel(d)) + ' at ' + tme(d) + '. Talk soon!')) + '">Text</a>');
            if (m.phone) a.push('<a class="btn bo bsm" href="' + esc(telHref(m.phone)) + '">Call</a>');
          }
          if (m.status === 'booked' && !upcoming) { a.push('<button class="btn bg bsm" data-mop="completed" data-id="' + m.id + '">' + I.check.replace('<svg', '<svg width="14" height="14"') + 'Mark done</button>'); a.push('<button class="btn bo bsm" data-mop="no_show" data-id="' + m.id + '">No-show</button>'); }
          if (m.status === 'completed') a.push('<button class="btn bp bsm" data-ask="' + m.id + '">Ask for a review</button>');
          if (m.status === 'completed' || m.status === 'no_show') a.push('<button class="btn bo bsm" data-mop="restore" data-id="' + m.id + '">Undo</button>');
          if (upcoming) a.push('<button class="btn bo bsm" data-mop="completed" data-id="' + m.id + '">Mark done</button>');
          a.push('<button class="btn bo bsm" data-mdel="' + m.id + '" title="Delete from dashboard">' + I.trash.replace('<svg', '<svg width="14" height="14"') + '</button>');
          out.push('<div class="mt' + (soon ? ' soon' : '') + '" data-mid="' + m.id + '"><div class="mt-time"><b>' + tme(d) + '</b><span>' + mins + ' min</span></div><div class="mt-b"><div class="mt-h"><span class="mt-n">' + esc(m.name || 'Client') + '</span>' + tags + '</div>'
            + (m.business ? '<div class="rv-b" style="margin-top:3px">' + esc(m.business) + '</div>' : '')
            + (c2.length ? '<div class="mt-c">' + c2.join('') + '</div>' : '')
            + (m.notes ? '<div class="mt-notes">' + esc(m.notes) + '</div>' : '')
            + '<div class="rv-a">' + a.join('') + '</div></div></div>');
        });
        $('mlist').innerHTML = out.join('');
        state.mrows = j.meetings;
      }
      var h = j.hook;
      $('mhook').innerHTML = h.last
        ? '<div class="card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><span class="ic" style="background:#E8F7EE;color:#11733A">' + I.check + '</span><div style="flex:1;min-width:200px"><b>Connected to Cal.com</b><div class="rv-b">Cancellations, reschedules and bookings made on Cal.com update here automatically. Last update ' + esc(when(h.last)) + '.</div></div><button class="btn bo bsm" id="hookShow">Connection details</button></div><div id="hookBox" style="display:none;margin-top:12px"></div>'
        : '<div id="hookBox"></div>';
      var box = $('hookBox');
      var steps = '<div class="card hook"><div class="sec-t"><h2>' + (h.last ? 'Cal.com connection' : 'Connect Cal.com (2 minutes, optional)') + '</h2></div>'
        + '<p class="rv-b" style="font-size:14px;margin:0">Bookings made on your website already show up here. Connect Cal.com so <b>cancellations, reschedules</b> and bookings made on your Cal.com page show up too.</p>'
        + '<ol><li>In Cal.com, go to <b>Settings → Developer → Webhooks</b> and click <b>New</b>.</li><li>Paste this into <b>Subscriber URL</b>:<div class="code"><code>' + esc(h.url) + '</code><button class="btn bo bsm" type="button" data-copy="' + esc(h.url) + '">Copy</button></div></li>'
        + '<li>Paste this into <b>Secret</b>:<div class="code"><code>' + esc(h.secret) + '</code><button class="btn bo bsm" type="button" data-copy="' + esc(h.secret) + '">Copy</button></div></li>'
        + '<li>Leave all event triggers on, then click <b>Create</b>. Use <b>Ping test</b> if you see it: this card will turn green.</li></ol>'
        + '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn bo bsm" type="button" id="hookCheck">Check connection</button><button class="btn bo bsm" type="button" id="hookReset">Make a new secret</button></div>'
        + '<p class="rv-b" style="margin:10px 0 0">Keep the secret private. It proves updates really come from your Cal.com account.</p></div>';
      box.innerHTML = steps;
    } catch (e) { $('mlist').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
  }
  $('p-meetings').addEventListener('click', async function (e) {
    var t = e.target.closest('button,a'); if (!t) return;
    if (t.hasAttribute('data-mv')) { state.mview = t.getAttribute('data-mv'); return loadMeetings(); }
    if (t.hasAttribute('data-copy')) { try { await navigator.clipboard.writeText(t.getAttribute('data-copy')); toast('Copied'); } catch (x) { toast('Select the text and copy it'); } return; }
    if (t.id === 'hookShow') { var b = $('hookBox'); b.style.display = b.style.display === 'none' ? 'block' : 'none'; return; }
    if (t.id === 'hookCheck') { await loadMeetings(); toast('Checked'); return; }
    if (t.id === 'hookReset') { if (t.dataset.sure !== '1') { t.dataset.sure = '1'; t.textContent = 'Sure? Paste the new one into Cal.com after'; return; } try { await api('cal_secret_reset', {}); toast('New secret made. Paste it into your Cal.com webhook.'); loadMeetings(); } catch (x) { toast(x.message); } return; }
    if (t.hasAttribute('data-ask')) {
      var m = (state.mrows || []).filter(function (x) { return String(x.id) === t.getAttribute('data-ask'); })[0]; if (!m) return;
      var f = $('reqForm'); f.name.value = m.name || ''; f.phone.value = m.phone ? prettyPhone(m.phone) : ''; f.email.value = m.email || '';
      go('requests'); toast('Filled in ' + (m.name || 'the client') + '. Tap Send by text.'); return;
    }
    if (t.hasAttribute('data-mdel')) {
      var id = t.getAttribute('data-mdel');
      t.outerHTML = '<span class="confirm">Delete this meeting from your dashboard? <button class="btn br bsm" data-mop="delete" data-id="' + id + '">Delete</button><button class="btn bo bsm" data-mv="' + state.mview + '">Keep</button></span>';
      return;
    }
    if (t.hasAttribute('data-mop')) {
      t.disabled = true;
      try { await api('meeting_update', { id: +t.getAttribute('data-id'), op: t.getAttribute('data-mop') }); toast({ completed: 'Marked as done', no_show: 'Marked as no-show', restore: 'Updated', delete: 'Deleted' }[t.getAttribute('data-mop')] || 'Updated'); loadMeetings(); }
      catch (x) { t.disabled = false; toast(x.message); }
    }
  });

  // ---------- review requests ----------
  var lastReq = null;
  function smsHref(phone, msg) { return 'sms:' + encodeURIComponent(phone) + '?&body=' + encodeURIComponent(msg); }
  function gmailHref(email, msg) { return 'https://mail.google.com/mail/?view=cm&fs=1&to=' + encodeURIComponent(email) + '&su=' + encodeURIComponent('How did we do? Quick review for VERO Digital') + '&body=' + encodeURIComponent(msg); }
  $('reqForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var f = e.target, ch = (e.submitter && e.submitter.getAttribute('data-ch')) || 'sms', err = $('reqErr');
    err.style.display = 'none';
    // open a blank window synchronously for Gmail so pop-up blockers allow it
    var win = ch === 'email' ? window.open('about:blank', '_blank') : null;
    try {
      var j = await api('request_create', { name: f.name.value, phone: f.phone.value, email: f.email.value, channel: ch });
      lastReq = j;
      $('reqMsg').textContent = j.message;
      $('reqOut').style.display = 'block';
      var again = [];
      if (j.phone) again.push('<a class="btn bp bsm" href="' + esc(smsHref(j.phone, j.message)) + '">Open Messages again</a>');
      if (j.email) again.push('<a class="btn bo bsm" target="_blank" rel="noopener" href="' + esc(gmailHref(j.email, j.message)) + '">Open Gmail again</a>');
      again.push('<button class="btn bo bsm" type="button" id="copyMsg">Copy message</button>');
      $('reqAgain').innerHTML = again.join('');
      if (ch === 'sms') { location.href = smsHref(j.phone, j.message); toast('Opening Messages…'); }
      else if (ch === 'email') { if (win) win.location.href = gmailHref(j.email, j.message); else window.open(gmailHref(j.email, j.message), '_blank'); toast('Opening Gmail…'); }
      else { await navigator.clipboard.writeText(j.message); toast('Message with link copied'); }
      loadRequests();
    } catch (x) { if (win) win.close(); err.textContent = x.message; err.style.display = 'block'; }
  });
  $('reqAgain').addEventListener('click', async function (e) { if (e.target.id === 'copyMsg' && lastReq) { try { await navigator.clipboard.writeText(lastReq.message); toast('Copied'); } catch (x) { toast('Copy failed: select the text and copy it'); } } });
  async function loadRequests() {
    try {
      var j = await api('requests');
      var label = { sent: ['Sent', 't-pending'], opened: ['Opened', 't-pub'], submitted: ['Review received', 't-approved'] };
      $('reqList').innerHTML = j.requests.length ? j.requests.map(function (r) { var l = label[r.status] || [r.status, '']; return '<div class="row"><div><div style="font-weight:600">' + esc(r.name || 'Customer') + '</div><div class="rv-b">' + esc(r.phone || r.email || 'Link copied') + ' · ' + when(r.created_at) + '</div></div><span class="tag ' + l[1] + '">' + l[0] + '</span></div>'; }).join('') : '<div class="empty" style="padding:20px">No requests yet.</div>';
    } catch (e) { $('reqList').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
  }

  // ---------- settings ----------
  async function loadSettings() {
    var j = await api('me'); state.me = j;
    var f = $('setForm'); f.notify_email.value = j.notify_email || ''; f.notify_on.checked = !!j.notify_on;
    $('pubLink').textContent = SITE + '/review.html';
    $('meEmail2').textContent = j.me.email;
  }
  function msg(el, ok, t) { el.className = ok ? 'okmsg' : 'err'; el.textContent = t; el.style.display = 'block'; }
  $('setForm').addEventListener('submit', async function (e) { e.preventDefault(); var f = e.target; try { await api('settings', { notify_email: f.notify_email.value, notify_on: f.notify_on.checked }); msg($('setMsg'), true, 'Saved.'); } catch (x) { msg($('setMsg'), false, x.message); } });
  $('testMail').addEventListener('click', async function () { try { var j = await api('test_email', {}); msg($('setMsg'), true, 'Test email sent to ' + j.to + '. Check your inbox and spam folder.'); } catch (x) { msg($('setMsg'), false, x.message); } });
  $('pwForm').addEventListener('submit', async function (e) { e.preventDefault(); var f = e.target; try { await api('password', { current: f.current.value, new: f.new.value }); f.reset(); msg($('pwMsg'), true, 'Password updated.'); } catch (x) { msg($('pwMsg'), false, x.message); } });
  $('copyPub').addEventListener('click', async function () { try { await navigator.clipboard.writeText(SITE + '/review.html'); toast('Link copied'); } catch (x) { toast('Select the link and copy it'); } });
  $('logout').addEventListener('click', async function () { try { await api('logout', {}); } catch (x) {} location.replace('./'); });
  $('pushBtn').addEventListener('click', async function () {
    if (!('Notification' in window)) { toast('This browser does not support alerts'); return; }
    var p = await Notification.requestPermission();
    toast(p === 'granted' ? 'Browser alerts on while this dashboard is open' : 'Alerts were not allowed');
  });

  // ---------- new-review alerts while the dashboard is open ----------
  function setPending(n) {
    if (state.lastPending !== null && n > state.lastPending) {
      toast('New review waiting for approval');
      try { if (window.Notification && Notification.permission === 'granted') new Notification('New review for VERO', { body: 'A customer just left a review. Open the dashboard to approve it.', icon: '/images/vero-mark.png' }); } catch (e) {}
      if (state.page === 'reviews') loadReviews();
    }
    state.lastPending = n; state.pending = n; renderNav();
    document.title = (n ? '(' + n + ') ' : '') + 'Dashboard — VERO Admin';
  }
  setInterval(async function () { try { var j = await api('reviews', null, '&status=pending'); setPending(j.stats.pending); } catch (e) {} }, 45000);

  api('me').then(function (j) { state.me = j; $('meEmail').textContent = j.me.email; }).catch(function () {});
  var start = (location.hash || '').slice(1);
  go(PAGES.some(function (p) { return p[0] === start; }) ? start : 'overview');
})();
</script>
<?php endif; ?>
</body></html>
