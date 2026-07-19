<?php
// 

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed.', 405);
}

$rows = getDB()->query(
    'SELECT status_id, status_label, description FROM attendance_status ORDER BY status_id'
)->fetchAll();

foreach ($rows as &$r) {
    $r['status_id'] = (int)$r['status_id'];
}
unset($r);

json_ok($rows);

