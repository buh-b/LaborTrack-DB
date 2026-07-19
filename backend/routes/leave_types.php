<?php
// leave_types.php
// GET    → fetch all leave types (admin + employee)
// POST   → create new leave type (admin only)
// PUT    → update leave type (admin only)
// DELETE → delete leave type (admin only)

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

requireAuth();

// GET — return all leave types
if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM leave_types ORDER BY leave_type_id')
                ->fetchAll(PDO::FETCH_ASSOC);
    json_ok(array_map('castLeaveType', $rows));
}

// POST — create new leave type (admin only)
if ($method === 'POST') {
    requireSystemAdmin();
    $body      = bodyJson();
    $name      = str($body, 'leave_name');
    $isPaid    = isset($body['is_paid']) ? (int)(bool)$body['is_paid'] : 1;
    $maxDays   = array_key_exists('max_days_per_year', $body)
                 ? floatVal_($body, 'max_days_per_year', 0.0)
                 : 15.0;

    if ($name === '') json_err('leave_name is required.');
    if ($maxDays < 0) json_err('max_days_per_year cannot be negative.');

    // Prevent duplicate leave type names
    $chk = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_name = ? LIMIT 1');
    $chk->execute([$name]);
    if ($chk->fetch()) {
        json_err('A leave type with that name already exists.');
    }

    $stmt = $pdo->prepare('INSERT INTO leave_types (leave_name, is_paid, max_days_per_year) VALUES (?, ?, ?)');
    $stmt->execute([$name, $isPaid, $maxDays]);
    $id = (int)$pdo->lastInsertId();

    logAudit($pdo, 'leave_type_create', 'leave_type', $id, [
        'leave_name'         => $name,
        'is_paid'            => $isPaid,
        'max_days_per_year'  => $maxDays,
    ]);

    json_ok(['leave_type_id' => $id, 'leave_name' => $name, 'is_paid' => $isPaid, 'max_days_per_year' => $maxDays], 201);
}

// PUT — update leave type (admin only)
if ($method === 'PUT') {
    requireSystemAdmin();
    $body    = bodyJson();
    $id      = intVal_($body, 'leave_type_id');
    $name    = str($body, 'leave_name');
    $isPaid  = isset($body['is_paid']) ? (int)(bool)$body['is_paid'] : 1;
    $maxDays = array_key_exists('max_days_per_year', $body)
               ? floatVal_($body, 'max_days_per_year', 0.0)
               : 15.0;

    if (!$id)   json_err('leave_type_id is required.');
    if (!$name) json_err('leave_name is required.');
    if ($maxDays < 0) json_err('max_days_per_year cannot be negative.');

    $chk = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_name = ? AND leave_type_id != ? LIMIT 1');
    $chk->execute([$name, $id]);
    if ($chk->fetch()) {
        json_err('A leave type with that name already exists.');
    }

    $existsStmt = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_type_id = ? LIMIT 1');
    $existsStmt->execute([$id]);
    if (!$existsStmt->fetch()) {
        json_err('Leave type not found.', 404);
    }

    $stmt = $pdo->prepare('UPDATE leave_types SET leave_name = ?, is_paid = ?, max_days_per_year = ? WHERE leave_type_id = ?');
    $stmt->execute([$name, $isPaid, $maxDays, $id]);

    logAudit($pdo, 'leave_type_update', 'leave_type', $id, [
        'leave_name'         => $name,
        'is_paid'            => $isPaid,
        'max_days_per_year'  => $maxDays,
    ]);

    json_ok(['leave_type_id' => $id, 'leave_name' => $name, 'is_paid' => $isPaid, 'max_days_per_year' => $maxDays]);
}

// DELETE — delete leave type (admin only)
if ($method === 'DELETE') {
    requireSystemAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'leave_type_id');

    if (!$id) json_err('leave_type_id is required.');

    // Block deletion if leave records or balances reference this type
    $inUseStmt = $pdo->prepare(
        '(SELECT leave_id AS ref_id FROM leave_records WHERE leave_type_id = ? LIMIT 1)
         UNION ALL
         (SELECT balance_id AS ref_id FROM leave_balances WHERE leave_type_id = ? LIMIT 1)
         LIMIT 1'
    );
    $inUseStmt->execute([$id, $id]);
    if ($inUseStmt->fetch()) {
        json_err('This leave type is in use by existing leave records or balances and cannot be deleted.');
    }

    $stmt = $pdo->prepare('DELETE FROM leave_types WHERE leave_type_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Leave type not found.', 404);
    }

    logAudit($pdo, 'leave_type_delete', 'leave_type', $id, null);

    json_ok(['deleted' => $id]);
}

function castLeaveType(array $r): array {
    return [
        'leave_type_id'      => (int)$r['leave_type_id'],
        'leave_name'         => $r['leave_name'],
        'is_paid'            => (bool)$r['is_paid'],
        'max_days_per_year'  => array_key_exists('max_days_per_year', $r) && $r['max_days_per_year'] !== null
                                 ? (float)$r['max_days_per_year']
                                 : 15.0,
    ];
}

