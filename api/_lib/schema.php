<?php
declare(strict_types=1);
// Creates the tables if they don't exist. Safe to run more than once.
function vero_install_schema(PDO $db, bool $sqlite): void {
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $q = [
        "CREATE TABLE IF NOT EXISTS admins (id $pk, email VARCHAR(190) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, last_login DATETIME NULL)$tail",
        "CREATE TABLE IF NOT EXISTS reviews (id $pk, name VARCHAR(80) NOT NULL, business VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', rating SMALLINT NOT NULL, body TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', published SMALLINT NOT NULL DEFAULT 0, request_id INT NULL, ip_hash CHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS review_requests (id $pk, token CHAR(32) NOT NULL UNIQUE, name VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', channel VARCHAR(12) NOT NULL DEFAULT 'sms', status VARCHAR(12) NOT NULL DEFAULT 'sent', created_at DATETIME NOT NULL, opened_at DATETIME NULL, submitted_at DATETIME NULL)$tail",
        "CREATE TABLE IF NOT EXISTS events (id $pk, type VARCHAR(32) NOT NULL, ref_id INT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS pageviews (id $pk, path VARCHAR(120) NOT NULL, device VARCHAR(10) NOT NULL DEFAULT '', ref VARCHAR(120) NOT NULL DEFAULT '', visitor CHAR(64) NOT NULL DEFAULT '', day DATE NOT NULL, created_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS login_attempts (id $pk, ip_hash CHAR(64) NOT NULL, ok SMALLINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS rate_hits (id $pk, ip_hash CHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS meetings (id $pk, uid VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', business VARCHAR(160) NOT NULL DEFAULT '', notes TEXT NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NULL, meet_url VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'booked', source VARCHAR(10) NOT NULL DEFAULT 'site', verified SMALLINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",
    ];
    foreach ($q as $sql) $db->exec($sql);
    $idx = [
        'CREATE INDEX idx_reviews_status ON reviews (status, published)',
        'CREATE INDEX idx_events_created ON events (created_at)',
        'CREATE INDEX idx_pv_day ON pageviews (day)',
        'CREATE INDEX idx_rate ON rate_hits (ip_hash, kind, created_at)',
        'CREATE INDEX idx_login ON login_attempts (ip_hash, created_at)',
        'CREATE INDEX idx_meet_start ON meetings (start_at)',
    ];
    foreach ($idx as $sql) { try { $db->exec($sql); } catch (Throwable $e) { /* already exists */ } }
}
