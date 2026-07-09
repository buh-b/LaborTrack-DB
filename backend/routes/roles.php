<?php
// =============================================================================
// routes/roles.php — Role CRUD
//
// GET    /backend/routes/roles.php        → list all (auth required)
// POST   /backend/routes/roles.php        → create (admin only)
// PUT    /backend/routes/roles.php        → update (admin only)
// DELETE /backend/routes/roles.php?id=X   → delete (admin only)
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT r.*, COUNT(e.employee_id) AS employee_count
         FROM   roles r
         LEFT   JOIN employees e ON e.role_id = r.role_id
         GROUP  BY r.role_id
         ORDER  BY r.role_name'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'role_id'        => (int)$r['role_id'],
        'role_name'      => $r['role_name'],
        'employee_count' => (int)$r['employee_count'],
    ], $rows));
}

if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $name = str($body, 'role_name');
    if ($name === '') json_err('role_name is required.');
    $pdo = getDB();
    $pdo->prepare('INSERT INTO roles (role_name) VALUES (?)')->execute([$name]);
    json_ok(['role_id' => (int)$pdo->lastInsertId(), 'message' => 'Role created.']);
}

if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'role_id');
    $name = str($body, 'role_name');
    if (!$id)       json_err('role_id is required.');
    if ($name === '') json_err('role_name is required.');
    $stmt = getDB()->prepare('UPDATE roles SET role_name = ? WHERE role_id = ?');
    $stmt->execute([$name, $id]);
    if ($stmt->rowCount() === 0) json_err('Role not found.', 404);
    json_ok(['message' => 'Role updated.']);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $pdo = getDB();
    $emp = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE role_id = ?');
    $emp->execute([$id]);
    if ((int)$emp->fetchColumn() > 0) json_err('Cannot delete a role that still has employees assigned.');
    $stmt = $pdo->prepare('DELETE FROM roles WHERE role_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Role not found.', 404);
    json_ok(['message' => 'Role deleted.']);
}

json_err('Method not allowed.', 405);
