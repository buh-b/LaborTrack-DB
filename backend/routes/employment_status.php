<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all statuses with employee count
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT es.*, COUNT(e.employee_id) AS employee_count
         FROM   employment_status es
         LEFT   JOIN employees e ON e.employment_status_id = es.employment_status_id
         GROUP  BY es.employment_status_id
         ORDER  BY es.status_name'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'employment_status_id' => (int)$r['employment_status_id'],
        'status_name'          => $r['status_name'],
        'employee_count'       => (int)$r['employee_count'],
    ], $rows));
}

// POST — create
if ($method === 'POST') {
    requireSystemAdmin();
    $body = bodyJson();
    $name = str($body, 'status_name');
    if ($name === '') json_err('status_name is required.');
    $pdo = getDB();
    $pdo->prepare('INSERT INTO employment_status (status_name) VALUES (?)')->execute([$name]);
    json_ok(['employment_status_id' => (int)$pdo->lastInsertId(), 'message' => 'Status created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireSystemAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'employment_status_id');
    $name = str($body, 'status_name');
    if (!$id)       json_err('employment_status_id is required.');
    if ($name === '') json_err('status_name is required.');
    $stmt = getDB()->prepare('UPDATE employment_status SET status_name = ? WHERE employment_status_id = ?');
    $stmt->execute([$name, $id]);
    if ($stmt->rowCount() === 0) json_err('Status not found.', 404);
    json_ok(['message' => 'Status updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireSystemAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $pdo = getDB();
    $emp = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employment_status_id = ?');
    $emp->execute([$id]);
    if ((int)$emp->fetchColumn() > 0) json_err('Cannot delete a status that still has employees assigned.');
    $stmt = $pdo->prepare('DELETE FROM employment_status WHERE employment_status_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Status not found.', 404);
    json_ok(['message' => 'Status deleted.']);
}

json_err('Method not allowed.', 405);