<?php
// Public: the approved + published reviews shown on the website. Never returns emails or pending reviews.
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
require_method('GET');
if (!vero_config()) json_out(['reviews' => [], 'count' => 0, 'average' => null]);
$rows = vero_db()->query("SELECT name, business, rating, body, created_at FROM reviews WHERE status = 'approved' AND published = 1 ORDER BY created_at DESC LIMIT 24")->fetchAll();
$out = [];
$sum = 0;
foreach ($rows as $r) {
    $parts = preg_split('/\s+/', trim($r['name']));
    $short = $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[count($parts) - 1], 0, 1) . '.' : ''); // "Jane S." for privacy
    $out[] = ['name' => $short, 'business' => $r['business'], 'rating' => (int)$r['rating'], 'body' => $r['body'], 'date' => substr($r['created_at'], 0, 10)];
    $sum += (int)$r['rating'];
}
header('Cache-Control: public, max-age=60');
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['reviews' => $out, 'count' => count($out), 'average' => $out ? round($sum / count($out), 1) : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
