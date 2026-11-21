<?php
// Public health check: reports only whether the backend is set up and the database answers. No secrets.
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';
$out = ['ok' => true, 'php8' => PHP_MAJOR_VERSION >= 8, 'configured' => (bool)vero_config(), 'database' => false];
if ($out['configured']) { try { vero_db()->query('SELECT 1'); $out['database'] = true; } catch (Throwable $e) { $out['ok'] = false; } }
json_out($out);
