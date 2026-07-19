<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all holidays, optional year filter
if ($method === 'GET') {
    requireAuth();
    $where  = [];
    $params = [];

    if (!empty($_GET['year'])) {
        $where[]  = 'YEAR(holiday_date) = ?';
        $params[] = (int)$_GET['year'];
    }

    $sql  = 'SELECT * FROM holidays'
          . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
          . ' ORDER BY holiday_date';
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map(fn($r) => [
        'holiday_id'   => (int)$r['holiday_id'],
        'holiday_date' => $r['holiday_date'],
        'holiday_name' => $r['holiday_name'],
        'holiday_type' => $r['holiday_type'],
    ], $stmt->fetchAll()));
}

// POST — create
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();
    $date = str($body, 'holiday_date');
    $name = str($body, 'holiday_name');
    $type = str($body, 'holiday_type');
    if ($date === '') {
        json_err('holiday_date is required.');
    }
    if ($name === '') {
        json_err('holiday_name is required.');
    }
    if ($type === '') {
        json_err('holiday_type is required.');
    }
    if (!in_array($type, ['Regular', 'Special'], true)) {
        json_err('holiday_type must be Regular or Special.');
    }

    $pdo = getDB();
    try {
        $pdo->prepare(
            'INSERT INTO holidays (holiday_date, holiday_name, holiday_type) VALUES (?, ?, ?)'
        )->execute([$date, $name, $type]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            json_err('A holiday on that date already exists.');
        }
        throw $e;
    }
    $holidayId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'holiday_create', 'holiday', $holidayId, [
        'holiday_date' => $date,
        'holiday_name' => $name,
        'holiday_type' => $type,
    ]);

    json_ok(['holiday_id' => $holidayId, 'message' => 'Holiday created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();
    $id   = intVal_($body, 'holiday_id');
    $date = str($body, 'holiday_date');
    $name = str($body, 'holiday_name');
    $type = str($body, 'holiday_type');
    if (!$id) {
        json_err('holiday_id is required.');
    }
    if ($date === '') {
        json_err('holiday_date is required.');
    }
    if ($name === '') {
        json_err('holiday_name is required.');
    }
    if ($type === '') {
        json_err('holiday_type is required.');
    }
    if (!in_array($type, ['Regular', 'Special'], true)) {
        json_err('holiday_type must be Regular or Special.');
    }

    $pdo = getDB();
    $existsStmt = $pdo->prepare('SELECT holiday_id FROM holidays WHERE holiday_id = ? LIMIT 1');
    $existsStmt->execute([$id]);
    if (!$existsStmt->fetch()) {
        json_err('Holiday not found.', 404);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE holidays SET holiday_date = ?, holiday_name = ?, holiday_type = ? WHERE holiday_id = ?'
        );
        $stmt->execute([$date, $name, $type, $id]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            json_err('A holiday on that date already exists.');
        }
        throw $e;
    }

    logAudit($pdo, 'holiday_update', 'holiday', $id, [
        'holiday_date' => $date,
        'holiday_name' => $name,
        'holiday_type' => $type,
    ]);

    json_ok(['message' => 'Holiday updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }
    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM holidays WHERE holiday_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Holiday not found.', 404);
    }

    logAudit($pdo, 'holiday_delete', 'holiday', $id, null);

    json_ok(['message' => 'Holiday deleted.']);
}

json_err('Method not allowed.', 405);

