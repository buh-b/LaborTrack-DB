<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list exit records
if ($method === 'GET') {
    requireSupervisor(); // Gated for Supervisor or Admin
    $pdo = getDB();
    $level = currentAccessLevel();
    $where = [];
    $params = [];

    if ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[] = 'e.department_id = ?';
        $params[] = $deptId;
    }

    $sql = 'SELECT ex.*,
                   CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                   d.department_name,
                   a.username AS processed_by_username
            FROM   employee_exits ex
            JOIN   employees e ON e.employee_id = ex.employee_id
            LEFT   JOIN departments d ON d.department_id = e.department_id
            LEFT   JOIN accounts a ON a.account_id = ex.processed_by_account_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ex.exit_date DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    json_ok(array_map(fn($r) => [
        'exit_id'                 => (int)$r['exit_id'],
        'employee_id'             => (int)$r['employee_id'],
        'employee_name'           => $r['employee_name'],
        'department_name'         => $r['department_name'],
        'processed_by_account_id' => $r['processed_by_account_id'] !== null ? (int)$r['processed_by_account_id'] : null,
        'processed_by_username'   => $r['processed_by_username'],
        'exit_date'               => $r['exit_date'],
        'exit_reason'             => $r['exit_reason'],
        'is_voluntary'            => (bool)$r['is_voluntary'],
        'remarks'                 => $r['remarks'],
        'created_at'              => $r['created_at'],
    ], $stmt->fetchAll()));
}

// POST — record exit
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();
    
    $employeeId = intVal_($body, 'employee_id');
    $date       = str($body, 'exit_date');
    $reason     = str($body, 'exit_reason');
    $voluntary  = array_key_exists('is_voluntary', $body) ? (int)$body['is_voluntary'] : 1;
    $remarks    = str($body, 'remarks');
    $statusId   = intVal_($body, 'inactive_status_id', 2); // default: 2 (Resigned)

    if (!$employeeId) {
        json_err('employee_id is required.');
    }
    if ($date === '') {
        json_err('exit_date is required.');
    }
    if ($reason === '') {
        json_err('exit_reason is required.');
    }

    $pdo = getDB();
    try {
        $pdo->beginTransaction();

        // 1. Insert exit record
        $stmt = $pdo->prepare(
            'INSERT INTO employee_exits 
                (employee_id, processed_by_account_id, exit_date, exit_reason, is_voluntary, remarks)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $employeeId,
            currentAccountId(),
            $date,
            $reason,
            $voluntary,
            $remarks ?: null
        ]);
        $exitId = (int)$pdo->lastInsertId();

        // 2. Update employee status to inactive/resigned
        $empStmt = $pdo->prepare('UPDATE employees SET employment_status_id = ? WHERE employee_id = ?');
        $empStmt->execute([$statusId, $employeeId]);

        // 3. Log to employment history transition
        // Close current history
        $yesterday = date('Y-m-d', strtotime($date . ' - 1 day'));
        $closeStmt = $pdo->prepare('UPDATE employment_history SET effective_to = ? WHERE employee_id = ? AND effective_to IS NULL');
        $closeStmt->execute([$yesterday, $employeeId]);

        // Fetch current employee data for history cloning
        $empDataStmt = $pdo->prepare('SELECT department_id, role_id, employment_type_id FROM employees WHERE employee_id = ?');
        $empDataStmt->execute([$employeeId]);
        $emp = $empDataStmt->fetch();

        // Log exit transition in history
        $historyStmt = $pdo->prepare(
            'INSERT INTO employment_history 
                (employee_id, department_id, role_id, employment_status_id, employment_type_id, changed_by_account_id, effective_from, effective_to, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $historyStmt->execute([
            $employeeId,
            $emp['department_id'] !== null ? (int)$emp['department_id'] : null,
            $emp['role_id'] !== null ? (int)$emp['role_id'] : null,
            $statusId,
            $emp['employment_type_id'] !== null ? (int)$emp['employment_type_id'] : null,
            currentAccountId(),
            $date,
            'Auto-logged on employee exit: ' . $reason
        ]);

        $pdo->commit();
        json_ok(['exit_id' => $exitId, 'message' => 'Employee exit processed successfully.']);
    } catch (\Exception $e) {
        $pdo->rollBack();
        json_err('Transaction failed: ' . $e->getMessage());
    }
}

// PUT — update exit details
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();
    
    $id        = intVal_($body, 'exit_id');
    $date      = str($body, 'exit_date');
    $reason    = str($body, 'exit_reason');
    $voluntary = array_key_exists('is_voluntary', $body) ? (int)$body['is_voluntary'] : 1;
    $remarks   = str($body, 'remarks');

    if (!$id) {
        json_err('exit_id is required.');
    }
    if ($date === '') {
        json_err('exit_date is required.');
    }
    if ($reason === '') {
        json_err('exit_reason is required.');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE employee_exits 
         SET    exit_date = ?, exit_reason = ?, is_voluntary = ?, remarks = ?
         WHERE  exit_id = ?'
    );
    $stmt->execute([
        $date,
        $reason,
        $voluntary,
        $remarks ?: null,
        $id
    ]);

    json_ok(['message' => 'Exit record updated.']);
}

// DELETE — delete exit record
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $stmt = getDB()->prepare('DELETE FROM employee_exits WHERE exit_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Exit record not found.', 404);
    }

    json_ok(['message' => 'Exit record deleted.']);
}

json_err('Method not allowed.', 405);

