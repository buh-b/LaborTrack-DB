<?php
// GET    /backend/routes/employees.php        → list all (auth required)
// GET    /backend/routes/employees.php?id=X   → single employee
// POST   /backend/routes/employees.php        → create (admin only)
// PUT    /backend/routes/employees.php        → update (admin only)
// DELETE /backend/routes/employees.php?id=X   → delete (admin only)

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// List all employees
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if (currentAccessLevel() !== 'admin' && $id !== currentEmployeeId()) {
            json_err('Forbidden.', 403);
        }
        $stmt = $pdo->prepare(
            'SELECT e.*, d.department_name, r.role_name
             FROM   employees e
             LEFT   JOIN departments d ON d.department_id = e.department_id
             LEFT   JOIN roles       r ON r.role_id       = e.role_id
             WHERE  e.employee_id = ?'
        );
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if (!$emp) json_err('Employee not found.', 404);
        json_ok(castEmployee($emp));
    }

    if (currentAccessLevel() === 'admin') {
        $rows = $pdo->query(
            'SELECT e.*, d.department_name, r.role_name
             FROM   employees e
             LEFT   JOIN departments d ON d.department_id = e.department_id
             LEFT   JOIN roles       r ON r.role_id       = e.role_id
             ORDER  BY e.full_name'
        )->fetchAll();
    } elseif (currentEmployeeId() === null) {
        json_ok([]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT e.*, d.department_name, r.role_name
             FROM   employees e
             LEFT   JOIN departments d ON d.department_id = e.department_id
             LEFT   JOIN roles       r ON r.role_id       = e.role_id
             WHERE  e.employee_id = ?'
        );
        $stmt->execute([currentEmployeeId()]);
        $rows = $stmt->fetchAll();
    }

    json_ok(array_map('castEmployee', $rows));
}

// POST: create 
if ($method === 'POST') {
    requireAdmin();

    $body     = bodyJson();
    $fullName = str($body, 'full_name');
    $hireDate = str($body, 'hire_date');

    if ($fullName === '') json_err('full_name is required.');
    if ($hireDate === '') json_err('hire_date is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (department_id, role_id, full_name, email, contact_no, hire_date, current_hourly_rate, employment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        intVal_($body, 'department_id') ?: null,
        intVal_($body, 'role_id')       ?: null,
        $fullName,
        str($body, 'email')      ?: null,
        str($body, 'contact_no') ?: null,
        $hireDate,
        floatVal_($body, 'current_hourly_rate'),
        str($body, 'employment_status', 'Active'),
    ]);

    json_ok(['employee_id' => (int)$pdo->lastInsertId(), 'message' => 'Employee created.']);
}

// PUT: update 
if ($method === 'PUT') {
    requireAdmin();

    $body = bodyJson();
    $id   = intVal_($body, 'employee_id');
    if (!$id) json_err('employee_id is required.');

    $pdo = getDB();
    $chk = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('Employee not found.', 404);

    $pdo->prepare(
        'UPDATE employees
         SET department_id = ?, role_id = ?, full_name = ?, email = ?,
             contact_no = ?, hire_date = ?, current_hourly_rate = ?, employment_status = ?
         WHERE employee_id = ?'
    )->execute([
        intVal_($body, 'department_id') ?: null,
        intVal_($body, 'role_id')       ?: null,
        str($body, 'full_name'),
        str($body, 'email')      ?: null,
        str($body, 'contact_no') ?: null,
        str($body, 'hire_date'),
        floatVal_($body, 'current_hourly_rate'),
        str($body, 'employment_status', 'Active'),
        $id,
    ]);

    json_ok(['message' => 'Employee updated.']);
}

// DELETE 
if ($method === 'DELETE') {
    requireAdmin();

    $id   = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare('DELETE FROM employees WHERE employee_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Employee not found.', 404);

    json_ok(['message' => 'Employee deleted.']);
}

json_err('Method not allowed.', 405);

function castEmployee(array $r): array {
    return [
        'employee_id'         => (int)$r['employee_id'],
        'department_id'       => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'role_id'             => $r['role_id']       !== null ? (int)$r['role_id']       : null,
        'full_name'           => $r['full_name'],
        'email'               => $r['email'],
        'contact_no'          => $r['contact_no'],
        'hire_date'           => $r['hire_date'],
        'current_hourly_rate' => (float)$r['current_hourly_rate'],
        'employment_status'   => $r['employment_status'],
        'department_name'     => $r['department_name'] ?? null,
        'role_name'           => $r['role_name']       ?? null,
    ];
}
