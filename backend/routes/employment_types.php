<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all employment types
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT * FROM employment_types ORDER BY type_name'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'employment_type_id' => (int)$r['employment_type_id'],
        'type_name'          => $r['type_name'],
    ], $rows));
}

// POST — create
if ($method === 'POST') {
    requireSystemAdmin();
    $body = bodyJson();
    $name = str($body, 'type_name');
    if ($name === '') {
        json_err('type_name is required.');
    }
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO employment_types (type_name) VALUES (?)'
    )->execute([$name]);
    json_ok(['employment_type_id' => (int)$pdo->lastInsertId(), 'message' => 'Employment type created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireSystemAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'employment_type_id');
    $name = str($body, 'type_name');
    if (!$id) {
        json_err('employment_type_id is required.');
    }
    if ($name === '') {
        json_err('type_name is required.');
    }
    $stmt = getDB()->prepare(
        'UPDATE employment_types SET type_name = ? WHERE employment_type_id = ?'
    );
    $stmt->execute([$name, $id]);
    if ($stmt->rowCount() === 0) {
        json_err('Employment type not found.', 404);
    }
    json_ok(['message' => 'Employment type updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireSystemAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }
    $pdo  = getDB();
    $stmt = $pdo->prepare('DELETE FROM employment_types WHERE employment_type_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Employment type not found.', 404);
    }
    json_ok(['message' => 'Employment type deleted.']);
}

json_err('Method not allowed.', 405);

