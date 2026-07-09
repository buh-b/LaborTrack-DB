<?php
// GET    /backend/routes/departments.php         list all (auth required)
// POST   /backend/routes/departments.php         create (admin only)
// PUT    /backend/routes/departments.php         update (admin only)
// DELETE /backend/routes/departments.php?id=X    delete (admin only)


declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireAuth();
    $pdo  = getDB();
    $rows = $pdo->query(
        'SELECT d.*, COUNT(e.employee_id) AS employee_count
         FROM   departments d
         LEFT   JOIN employees e ON e.department_id = d.department_id
         GROUP  BY d.department_id
         ORDER  BY d.department_name'
    )->fetchAll();

    json_ok(array_map(fn($r) => [
        'department_id'         => (int)$r['department_id'],
        'department_name'       => $r['department_name'],
        'department_code'       => $r['department_code'],
        'labor_cost_allocation' => (float)($r['labor_cost_allocation'] ?? 0),
        'employee_count'        => (int)$r['employee_count'],
    ], $rows));
}

if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $name = str($body, 'department_name');
    $code = str($body, 'department_code');
    if ($name === '') json_err('department_name is required.');
    if ($code === '') json_err('department_code is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO departments (department_name, department_code, labor_cost_allocation) VALUES (?, ?, ?)'
    );
    $stmt->execute([$name, strtoupper($code), floatVal_($body, 'labor_cost_allocation')]);
    json_ok(['department_id' => (int)$pdo->lastInsertId(), 'message' => 'Department created.']);
}

if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'department_id');
    if (!$id) json_err('department_id is required.');

    $pdo = getDB();
    $chk = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('Department not found.', 404);

    $pdo->prepare(
        'UPDATE departments SET department_name = ?, department_code = ?, labor_cost_allocation = ? WHERE department_id = ?'
    )->execute([
        str($body, 'department_name'),
        strtoupper(str($body, 'department_code')),
        floatVal_($body, 'labor_cost_allocation'),
        $id,
    ]);
    json_ok(['message' => 'Department updated.']);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');

    $pdo = getDB();
    $emp = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
    $emp->execute([$id]);
    if ((int)$emp->fetchColumn() > 0) {
        json_err('Cannot delete a department that still has employees assigned.');
    }

    $stmt = $pdo->prepare('DELETE FROM departments WHERE department_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Department not found.', 404);
    json_ok(['message' => 'Department deleted.']);
}

json_err('Method not allowed.', 405);
