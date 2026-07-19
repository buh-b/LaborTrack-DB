<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list history records
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();
    $level = currentAccessLevel();
    $where = [];
    $params = [];

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[] = 'eh.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[] = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['employee_id'])) {
            $where[] = 'eh.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
    } else {
        $where[] = 'eh.employee_id = ?';
        $params[] = currentEmployeeId();
    }

    $sql = 'SELECT eh.*,
                   CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                   d.department_name,
                   r.role_name,
                   es.status_name AS employment_status,
                   et.type_name AS employment_type_name,
                   a.username AS changed_by_username
            FROM   employment_history eh
            JOIN   employees e ON e.employee_id = eh.employee_id
            LEFT   JOIN departments d ON d.department_id = eh.department_id
            LEFT   JOIN roles r ON r.role_id = eh.role_id
            LEFT   JOIN employment_status es ON es.employment_status_id = eh.employment_status_id
            LEFT   JOIN employment_types et ON et.employment_type_id = eh.employment_type_id
            LEFT   JOIN accounts a ON a.account_id = eh.changed_by_account_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY eh.effective_from DESC, eh.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    json_ok(array_map(fn($r) => [
        'history_id'            => (int)$r['history_id'],
        'employee_id'           => (int)$r['employee_id'],
        'employee_name'         => $r['employee_name'],
        'department_id'         => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'department_name'       => $r['department_name'],
        'role_id'               => $r['role_id'] !== null ? (int)$r['role_id'] : null,
        'role_name'             => $r['role_name'],
        'employment_status_id'  => $r['employment_status_id'] !== null ? (int)$r['employment_status_id'] : null,
        'employment_status'     => $r['employment_status'],
        'employment_type_id'    => $r['employment_type_id'] !== null ? (int)$r['employment_type_id'] : null,
        'employment_type_name'  => $r['employment_type_name'],
        'changed_by_account_id' => $r['changed_by_account_id'] !== null ? (int)$r['changed_by_account_id'] : null,
        'changed_by_username'   => $r['changed_by_username'],
        'effective_from'        => $r['effective_from'],
        'effective_to'          => $r['effective_to'],
        'remarks'               => $r['remarks'],
        'created_at'            => $r['created_at'],
    ], $stmt->fetchAll()));
}

// POST — create manually
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();
    
    $employeeId = intVal_($body, 'employee_id');
    $deptId     = intVal_($body, 'department_id');
    $roleId     = intVal_($body, 'role_id');
    $statusId   = intVal_($body, 'employment_status_id');
    $typeId     = intVal_($body, 'employment_type_id');
    $from       = str($body, 'effective_from');
    $to         = str($body, 'effective_to') ?: null;
    $remarks    = str($body, 'remarks');

    if (!$employeeId) {
        json_err('employee_id is required.');
    }
    if ($from === '') {
        json_err('effective_from is required.');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO employment_history 
            (employee_id, department_id, role_id, employment_status_id, employment_type_id, changed_by_account_id, effective_from, effective_to, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $employeeId,
        $deptId,
        $roleId,
        $statusId,
        $typeId,
        currentAccountId(),
        $from,
        $to,
        $remarks ?: null
    ]);

    json_ok(['history_id' => (int)$pdo->lastInsertId(), 'message' => 'History record created.']);
}

// PUT — update manually
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();
    
    $id         = intVal_($body, 'history_id');
    $deptId     = intVal_($body, 'department_id');
    $roleId     = intVal_($body, 'role_id');
    $statusId   = intVal_($body, 'employment_status_id');
    $typeId     = intVal_($body, 'employment_type_id');
    $from       = str($body, 'effective_from');
    $to         = str($body, 'effective_to') ?: null;
    $remarks    = str($body, 'remarks');

    if (!$id) {
        json_err('history_id is required.');
    }
    if ($from === '') {
        json_err('effective_from is required.');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE employment_history 
         SET    department_id = ?, role_id = ?, employment_status_id = ?, employment_type_id = ?, effective_from = ?, effective_to = ?, remarks = ?
         WHERE  history_id = ?'
    );
    $stmt->execute([
        $deptId,
        $roleId,
        $statusId,
        $typeId,
        $from,
        $to,
        $remarks ?: null,
        $id
    ]);

    json_ok(['message' => 'History record updated.']);
}

// DELETE — delete history record
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $stmt = getDB()->prepare('DELETE FROM employment_history WHERE history_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('History record not found.', 404);
    }

    json_ok(['message' => 'History record deleted.']);
}

json_err('Method not allowed.', 405);

