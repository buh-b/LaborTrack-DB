<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list leave balances
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();
    $level = currentAccessLevel();
    $where = [];
    $params = [];

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[] = 'lb.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[] = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['employee_id'])) {
            $where[] = 'lb.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    } else {
        $where[] = 'lb.employee_id = ?';
        $params[] = currentEmployeeId();
        if (!empty($_GET['year'])) {
            $where[] = 'lb.year = ?';
            $params[] = (int)$_GET['year'];
        }
    }

    $sql = 'SELECT lb.*,
                   CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                   d.department_name,
                   lt.leave_name AS leave_type_name
            FROM   leave_balances lb
            JOIN   employees e ON e.employee_id = lb.employee_id
            LEFT   JOIN departments d ON d.department_id = e.department_id
            LEFT   JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY lb.year DESC, e.last_name, e.first_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    json_ok(array_map(fn($r) => [
        'balance_id'        => (int)$r['balance_id'],
        'employee_id'       => (int)$r['employee_id'],
        'employee_name'     => $r['employee_name'],
        'department_name'   => $r['department_name'],
        'leave_type_id'     => (int)$r['leave_type_id'],
        'leave_type_name'   => $r['leave_type_name'],
        'year'              => (int)$r['year'],
        'entitled_days'     => (float)$r['entitled_days'],
        'carried_over_days' => (float)$r['carried_over_days'],
        'used_days'         => (float)$r['used_days'],
        'remaining_days'    => (float)$r['remaining_days'],
        'last_updated'      => $r['last_updated'],
    ], $stmt->fetchAll()));
}

// POST ?action=rollover — bulk-create next year's balances from the
// previous year's remaining/unused entitlement, per employee + leave type.
// Skips any employee/type/year combo that already has a balance row.
if ($method === 'POST' && ($_GET['action'] ?? '') === 'rollover') {
    requireHumanResources();
    $body     = bodyJson();
    $fromYear = intVal_($body, 'from_year');
    $toYear   = intVal_($body, 'to_year');
    // Cap how many unused days can roll into the new year; null = unlimited.
    $capDays  = array_key_exists('carry_over_cap', $body) && $body['carry_over_cap'] !== null
                ? floatVal_($body, 'carry_over_cap')
                : null;

    if (!$fromYear) json_err('from_year is required.');
    if (!$toYear)   json_err('to_year is required.');
    if ($toYear <= $fromYear) json_err('to_year must be after from_year.');

    $pdo = getDB();

    $srcStmt = $pdo->prepare(
        'SELECT lb.*, lt.leave_name, lt.max_days_per_year
         FROM   leave_balances lb
         JOIN   leave_types lt ON lt.leave_type_id = lb.leave_type_id
         WHERE  lb.year = ?'
    );
    $srcStmt->execute([$fromYear]);
    $sources = $srcStmt->fetchAll();

    $created = 0;
    $skipped = 0;

    $chkStmt = $pdo->prepare(
        'SELECT balance_id FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?'
    );
    $insStmt = $pdo->prepare(
        'INSERT INTO leave_balances
            (employee_id, leave_type_id, year, entitled_days, carried_over_days, used_days, remaining_days)
         VALUES (?, ?, ?, ?, ?, 0.0, ?)'
    );

    foreach ($sources as $src) {
        $empId  = (int)$src['employee_id'];
        $typeId = (int)$src['leave_type_id'];

        $chkStmt->execute([$empId, $typeId, $toYear]);
        if ($chkStmt->fetch()) {
            $skipped++;
            continue;
        }

        $unused = max(0.0, (float)$src['remaining_days']);
        if ($capDays !== null) {
            $unused = min($unused, $capDays);
        }

        $entitled = $src['max_days_per_year'] !== null ? (float)$src['max_days_per_year'] : (float)$src['entitled_days'];
        $remaining = $entitled + $unused;

        $insStmt->execute([$empId, $typeId, $toYear, $entitled, $unused, $remaining]);
        $created++;
    }

    logAudit($pdo, 'leave_balance_create', 'leave_balance', null, [
        'action'    => 'rollover',
        'from_year' => $fromYear,
        'to_year'   => $toYear,
        'created'   => $created,
        'skipped'   => $skipped,
    ]);

    json_ok([
        'message' => "Rolled over {$created} balance(s) into {$toYear}" . ($skipped ? ", skipped {$skipped} already-existing." : "."),
        'created' => $created,
        'skipped' => $skipped,
    ]);
}

// POST — create
if ($method === 'POST') {
    requireHumanResources();
    $body = bodyJson();
    
    $employeeId  = intVal_($body, 'employee_id');
    $leaveTypeId = intVal_($body, 'leave_type_id');
    $year        = intVal_($body, 'year');
    $entitled    = floatVal_($body, 'entitled_days', 0.0);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$employeeId) {
        json_err('employee_id is required.');
    }
    if (!$leaveTypeId) {
        json_err('leave_type_id is required.');
    }
    if (!$year) {
        json_err('year is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();
    
    // Check duplication
    $chk = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?');
    $chk->execute([$employeeId, $leaveTypeId, $year]);
    if ($chk->fetch()) {
        json_err('A leave balance record already exists for this employee, type, and year.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leave_balances 
            (employee_id, leave_type_id, year, entitled_days, carried_over_days, used_days, remaining_days)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $employeeId,
        $leaveTypeId,
        $year,
        $entitled,
        $carriedOver,
        $used,
        $remaining
    ]);

    $balanceId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'leave_balance_create', 'leave_balance', $balanceId, [
        'employee_id'       => $employeeId,
        'leave_type_id'     => $leaveTypeId,
        'year'              => $year,
        'entitled_days'     => $entitled,
        'carried_over_days' => $carriedOver,
        'used_days'         => $used,
        'remaining_days'    => $remaining,
    ]);

    json_ok(['balance_id' => $balanceId, 'message' => 'Leave balance granted.']);
}

// PUT — update
if ($method === 'PUT') {
    requireHumanResources();
    $body = bodyJson();
    
    $id          = intVal_($body, 'balance_id');
    $entitled    = floatVal_($body, 'entitled_days', 0.0);
    $carriedOver = floatVal_($body, 'carried_over_days', 0.0);
    $used        = floatVal_($body, 'used_days', 0.0);

    if (!$id) {
        json_err('balance_id is required.');
    }

    $remaining = $entitled + $carriedOver - $used;

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE leave_balances 
         SET    entitled_days = ?, carried_over_days = ?, used_days = ?, remaining_days = ?
         WHERE  balance_id = ?'
    );
    $existsStmt = $pdo->prepare('SELECT balance_id FROM leave_balances WHERE balance_id = ? LIMIT 1');
    $existsStmt->execute([$id]);
    if (!$existsStmt->fetch()) {
        json_err('Leave balance not found.', 404);
    }

    $stmt->execute([
        $entitled,
        $carriedOver,
        $used,
        $remaining,
        $id
    ]);

    logAudit($pdo, 'leave_balance_update', 'leave_balance', $id, [
        'entitled_days'     => $entitled,
        'carried_over_days' => $carriedOver,
        'used_days'         => $used,
        'remaining_days'    => $remaining,
    ]);

    json_ok(['message' => 'Leave balance updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireHumanResources();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM leave_balances WHERE balance_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Leave balance not found.', 404);
    }

    logAudit($pdo, 'leave_balance_delete', 'leave_balance', $id, null);

    json_ok(['message' => 'Leave balance deleted.']);
}

json_err('Method not allowed.', 405);

