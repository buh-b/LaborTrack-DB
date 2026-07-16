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
        'holiday_id'      => (int)$r['holiday_id'],
        'holiday_date'    => $r['holiday_date'],
        'holiday_name'    => $r['holiday_name'],
        'holiday_type'    => $r['holiday_type'],
        'rate_multiplier' => (float)$r['rate_multiplier'],
    ], $stmt->fetchAll()));
}

// POST — create
if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $date = str($body, 'holiday_date');
    $name = str($body, 'holiday_name');
    $type = str($body, 'holiday_type');
    if ($date === '') json_err('holiday_date is required.');
    if ($name === '') json_err('holiday_name is required.');
    if ($type === '') json_err('holiday_type is required.');
    if (!in_array($type, ['Regular', 'Special'], true)) json_err('holiday_type must be Regular or Special.');
    $pdo = getDB();
    try {
        $pdo->prepare(
            'INSERT INTO holidays (holiday_date, holiday_name, holiday_type, rate_multiplier) VALUES (?, ?, ?, ?)'
        )->execute([
            $date,
            $name,
            $type,
            floatVal_($body, 'rate_multiplier', 2.0),
        ]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) json_err('A holiday on that date already exists.');
        throw $e;
    }
    json_ok(['holiday_id' => (int)$pdo->lastInsertId(), 'message' => 'Holiday created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'holiday_id');
    $date = str($body, 'holiday_date');
    $name = str($body, 'holiday_name');
    $type = str($body, 'holiday_type');
    if (!$id)       json_err('holiday_id is required.');
    if ($date === '') json_err('holiday_date is required.');
    if ($name === '') json_err('holiday_name is required.');
    if ($type === '') json_err('holiday_type is required.');
    if (!in_array($type, ['Regular', 'Special'], true)) json_err('holiday_type must be Regular or Special.');
    try {
        $stmt = getDB()->prepare(
            'UPDATE holidays SET holiday_date = ?, holiday_name = ?, holiday_type = ?, rate_multiplier = ? WHERE holiday_id = ?'
        );
        $stmt->execute([
            $date,
            $name,
            $type,
            floatVal_($body, 'rate_multiplier', 2.0),
            $id,
        ]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) json_err('A holiday on that date already exists.');
        throw $e;
    }
    if ($stmt->rowCount() === 0) json_err('Holiday not found.', 404);
    json_ok(['message' => 'Holiday updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireAdmin();
    $id   = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $stmt = getDB()->prepare('DELETE FROM holidays WHERE holiday_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Holiday not found.', 404);
    json_ok(['message' => 'Holiday deleted.']);
}

json_err('Method not allowed.', 405);