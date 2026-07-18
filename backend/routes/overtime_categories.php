<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// list all ot cats
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT * FROM overtime_categories ORDER BY overtime_category_id'
    )->fetchAll();

    json_ok(array_map(fn($r) => [
        'overtime_category_id' => (int)$r['overtime_category_id'],
        'category_name'        => $r['category_name'],
    ], $rows));
}

// create cat
if ($method === 'POST') {
    requirePayrollAdmin();
    $body = bodyJson();
    $name = str($body, 'category_name');
    if ($name === '') {
        json_err('category_name is required.');
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO overtime_categories (category_name) VALUES (?)'
    )->execute([$name]);

    json_ok([
        'overtime_category_id' => (int)$pdo->lastInsertId(),
        'message'              => 'Overtime category created.',
    ]);
}

// update cat
if ($method === 'PUT') {
    requirePayrollAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'overtime_category_id');
    $name = str($body, 'category_name');
    if (!$id) {
        json_err('overtime_category_id is required.');
    }
    if ($name === '') {
        json_err('category_name is required.');
    }

    $stmt = getDB()->prepare(
        'UPDATE overtime_categories SET category_name = ? WHERE overtime_category_id = ?'
    );
    $stmt->execute([$name, $id]);
    if ($stmt->rowCount() === 0) {
        json_err('Overtime category not found.', 404);
    }

    json_ok(['message' => 'Overtime category updated.']);
}

// delete cat
if ($method === 'DELETE') {
    requirePayrollAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo = getDB();

    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM time_log_claims WHERE overtime_category_id = ?'
    );
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() > 0) {
        json_err('Cannot delete an overtime category that is assigned to time log claims.');
    }

    $stmt = $pdo->prepare('DELETE FROM overtime_categories WHERE overtime_category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Overtime category not found.', 404);
    }

    json_ok(['message' => 'Overtime category deleted.']);
}

json_err('Method not allowed.', 405);
