<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all pay differentials
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT * FROM pay_differentials ORDER BY differential_name'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'differential_id'   => (int)$r['differential_id'],
        'differential_name' => $r['differential_name'],
        'time_start'        => $r['time_start'],
        'time_end'          => $r['time_end'],
        'rate_multiplier'   => (float)$r['rate_multiplier'],
    ], $rows));
}

// POST — create
if ($method === 'POST') {
    requireHumanResources();
    $body  = bodyJson();
    $name  = str($body, 'differential_name');
    $start = str($body, 'time_start');
    $end   = str($body, 'time_end');
    $mult  = floatVal_($body, 'rate_multiplier', 1.10);
    if ($name  === '') json_err('differential_name is required.');
    if ($start === '') json_err('time_start is required.');
    if ($end   === '') json_err('time_end is required.');
    if ($mult  <= 0)   json_err('rate_multiplier must be greater than 0.');
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO pay_differentials (differential_name, time_start, time_end, rate_multiplier)
         VALUES (?, ?, ?, ?)'
    )->execute([$name, $start, $end, $mult]);
    json_ok(['differential_id' => (int)$pdo->lastInsertId(), 'message' => 'Pay differential created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireHumanResources();
    $body  = bodyJson();
    $id    = intVal_($body, 'differential_id');
    $name  = str($body, 'differential_name');
    $start = str($body, 'time_start');
    $end   = str($body, 'time_end');
    $mult  = floatVal_($body, 'rate_multiplier', 1.10);
    if (!$id)        json_err('differential_id is required.');
    if ($name  === '') json_err('differential_name is required.');
    if ($start === '') json_err('time_start is required.');
    if ($end   === '') json_err('time_end is required.');
    if ($mult  <= 0)   json_err('rate_multiplier must be greater than 0.');
    $stmt = getDB()->prepare(
        'UPDATE pay_differentials
         SET differential_name = ?, time_start = ?, time_end = ?, rate_multiplier = ?
         WHERE differential_id = ?'
    );
    $stmt->execute([$name, $start, $end, $mult, $id]);
    if ($stmt->rowCount() === 0) json_err('Pay differential not found.', 404);
    json_ok(['message' => 'Pay differential updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $stmt = getDB()->prepare('DELETE FROM pay_differentials WHERE differential_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Pay differential not found.', 404);
    json_ok(['message' => 'Pay differential deleted.']);
}

json_err('Method not allowed.', 405);
