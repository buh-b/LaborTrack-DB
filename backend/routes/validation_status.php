<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all validation statuses
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT * FROM validation_status ORDER BY validation_status_id'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'validation_status_id' => (int)$r['validation_status_id'],
        'status_name'          => $r['status_name'],
    ], $rows));
}

// POST — create
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();
    $name = str($body, 'status_name');
    if ($name === '') json_err('status_name is required.');
    $pdo = getDB();
    $pdo->prepare('INSERT INTO validation_status (status_name) VALUES (?)')->execute([$name]);
    json_ok(['validation_status_id' => (int)$pdo->lastInsertId(), 'message' => 'Validation status created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();
    $id   = intVal_($body, 'validation_status_id');
    $name = str($body, 'status_name');
    if (!$id)       json_err('validation_status_id is required.');
    if ($name === '') json_err('status_name is required.');
    $stmt = getDB()->prepare('UPDATE validation_status SET status_name = ? WHERE validation_status_id = ?');
    $stmt->execute([$name, $id]);
    if ($stmt->rowCount() === 0) json_err('Validation status not found.', 404);
    json_ok(['message' => 'Validation status updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $stmt = getDB()->prepare('DELETE FROM validation_status WHERE validation_status_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Validation status not found.', 404);
    json_ok(['message' => 'Validation status deleted.']);
}

json_err('Method not allowed.', 405);
