<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

const EMPLOYEE_SELECT =
    'SELECT e.*,
            d.department_name,
            r.role_name,
            es.status_name  AS employment_status,
            et.type_name    AS employment_type_name,
            ws.schedule_name
     FROM   employees e
     LEFT   JOIN departments       d  ON d.department_id         = e.department_id
     LEFT   JOIN roles             r  ON r.role_id               = e.role_id
     LEFT   JOIN employment_status es ON es.employment_status_id = e.employment_status_id
     LEFT   JOIN employment_types  et ON et.employment_type_id   = e.employment_type_id
     LEFT   JOIN work_schedules    ws ON ws.schedule_id          = e.schedule_id';

function resolveEmploymentStatusId(PDO $pdo, array $body, ?int $fallback = null): ?int {
    $id = intVal_($body, 'employment_status_id');
    if ($id !== null) {
        return $id;
    }

    $name = str($body, 'employment_status');
    if ($name === '') {
        return $fallback;
    }

    $stmt = $pdo->prepare('SELECT employment_status_id FROM employment_status WHERE status_name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    return $row ? (int)$row['employment_status_id'] : $fallback;
}

function parseEmployeeNames(array $body): array {
    $firstName = str($body, 'first_name');
    $lastName  = str($body, 'last_name');

    if ($firstName === '' && str($body, 'full_name') !== '') {
        $parts     = preg_split('/\s+/', trim(str($body, 'full_name')), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';
    }

    return [$firstName, $lastName];
}

// List employees
if ($method === 'GET') {
    requireAuth();
    $pdo = getDB();

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $level = currentAccessLevel();
        $isPrivileged = in_array($level, ['system_admin', 'human_resources'], true);
        if (!$isPrivileged && $id !== currentEmployeeId()) {
            if ($level === 'supervisor') {
                $chk = $pdo->prepare('SELECT department_id FROM employees WHERE employee_id = ?');
                $chk->execute([$id]);
                $row = $chk->fetch();
                if (!$row || (int)$row['department_id'] !== currentDepartmentId()) {
                    json_err('Forbidden.', 403);
                }
            } else {
                json_err('Forbidden.', 403);
            }
        }

        $stmt = $pdo->prepare(EMPLOYEE_SELECT . ' WHERE e.employee_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_err('Employee not found.', 404);
        }

        json_ok(castEmployee($row));
    }

    $level = currentAccessLevel();

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        $where  = [];
        $params = [];
        if (!empty($_GET['search'])) {
            $where[]  = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?
                          OR CONCAT(e.first_name, " ", e.last_name) LIKE ?)';
            $term     = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if (!empty($_GET['department_id'])) {
            $where[]  = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
        $sql = EMPLOYEE_SELECT
             . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY e.last_name, e.first_name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $stmt = $pdo->prepare(EMPLOYEE_SELECT . ' WHERE e.department_id = ? ORDER BY e.last_name, e.first_name');
        $stmt->execute([$deptId]);
        $rows = $stmt->fetchAll();
    } elseif (currentEmployeeId() === null) {
        json_ok([]);
    } else {
        $stmt = $pdo->prepare(EMPLOYEE_SELECT . ' WHERE e.employee_id = ?');
        $stmt->execute([currentEmployeeId()]);
        $rows = $stmt->fetchAll();
    }

    json_ok(array_map('castEmployee', $rows));
}

// POST: create
if ($method === 'POST') {
    requireHumanResources();

    $body = bodyJson();
    [$firstName, $lastName] = parseEmployeeNames($body);
    $hireDate = str($body, 'hire_date');

    if ($firstName === '') {
        json_err('first_name is required.');
    }
    if ($hireDate === '') {
        json_err('hire_date is required.');
    }

    $pdo = getDB();
    $statusId = resolveEmploymentStatusId($pdo, $body, 1);

    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (department_id, role_id, first_name, last_name, email, contact_no, hire_date,
             employment_status_id, employment_type_id, schedule_id, added_by_account_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        intVal_($body, 'department_id') ?: null,
        intVal_($body, 'role_id')       ?: null,
        $firstName,
        $lastName,
        str($body, 'email')      ?: null,
        str($body, 'contact_no') ?: null,
        $hireDate,
        $statusId,
        intVal_($body, 'employment_type_id') ?: null,
        intVal_($body, 'schedule_id')        ?: null,
        currentAccountId(),
    ]);

    $newEmpId = (int)$pdo->lastInsertId();

    $historyStmt = $pdo->prepare(
        'INSERT INTO employment_history 
            (employee_id, department_id, role_id, employment_status_id, employment_type_id, changed_by_account_id, effective_from, effective_to, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
    );
    $historyStmt->execute([
        $newEmpId,
        intVal_($body, 'department_id') ?: null,
        intVal_($body, 'role_id')       ?: null,
        $statusId,
        intVal_($body, 'employment_type_id') ?: null,
        currentAccountId(),
        $hireDate,
        'Initial employment history record'
    ]);

    logAudit($pdo, 'employee_create', 'employee', $newEmpId, [
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'department_id' => intVal_($body, 'department_id') ?: null,
        'role_id'       => intVal_($body, 'role_id') ?: null,
    ]);

    json_ok(['employee_id' => $newEmpId, 'message' => 'Employee created.']);
}

// PUT: update
if ($method === 'PUT') {
    requireHumanResources();

    $body = bodyJson();
    $id   = intVal_($body, 'employee_id');
    if (!$id) {
        json_err('employee_id is required.');
    }

    $pdo = getDB();
    $chk = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
    $chk->execute([$id]);
    $existing = $chk->fetch();
    if (!$existing) {
        json_err('Employee not found.', 404);
    }

    [$firstName, $lastName] = parseEmployeeNames($body);
    if ($firstName === '' && str($body, 'full_name') === '') {
        $firstName = $existing['first_name'];
        $lastName  = $existing['last_name'];
    } elseif ($firstName === '') {
        json_err('first_name is required.');
    }

    $statusId = resolveEmploymentStatusId(
        $pdo,
        $body,
        $existing['employment_status_id'] !== null ? (int)$existing['employment_status_id'] : null
    );

    $newDeptId   = intVal_($body, 'department_id') ?: null;
    $newRoleId   = intVal_($body, 'role_id')       ?: null;
    $newStatusId = $statusId;
    $newTypeId   = array_key_exists('employment_type_id', $body)
        ? (intVal_($body, 'employment_type_id') ?: null)
        : ($existing['employment_type_id'] !== null ? (int)$existing['employment_type_id'] : null);

    $oldDeptId   = $existing['department_id'] !== null ? (int)$existing['department_id'] : null;
    $oldRoleId   = $existing['role_id'] !== null ? (int)$existing['role_id'] : null;
    $oldStatusId = $existing['employment_status_id'] !== null ? (int)$existing['employment_status_id'] : null;
    $oldTypeId   = $existing['employment_type_id'] !== null ? (int)$existing['employment_type_id'] : null;
    $oldScheduleId = $existing['schedule_id'] !== null ? (int)$existing['schedule_id'] : null;
    $newScheduleId = array_key_exists('schedule_id', $body)
        ? (intVal_($body, 'schedule_id') ?: null)
        : $oldScheduleId;

    $pdo->prepare(
        'UPDATE employees
         SET department_id = ?, role_id = ?, first_name = ?, last_name = ?, email = ?,
             contact_no = ?, hire_date = ?, employment_status_id = ?,
             employment_type_id = ?, schedule_id = ?
         WHERE employee_id = ?'
    )->execute([
        $newDeptId,
        $newRoleId,
        $firstName,
        $lastName,
        str($body, 'email')      ?: null,
        str($body, 'contact_no') ?: null,
        str($body, 'hire_date', $existing['hire_date']),
        $newStatusId,
        $newTypeId,
        $newScheduleId,
        $id,
    ]);

    logAudit($pdo, 'employee_update', 'employee', $id, [
        'department_id'        => ['from' => $oldDeptId, 'to' => $newDeptId],
        'role_id'               => ['from' => $oldRoleId, 'to' => $newRoleId],
        'employment_status_id'  => ['from' => $oldStatusId, 'to' => $newStatusId],
        'employment_type_id'    => ['from' => $oldTypeId, 'to' => $newTypeId],
    ]);

    if ($newScheduleId !== $oldScheduleId) {
        logAudit($pdo, 'schedule_assignment', 'employee', $id, [
            'schedule_id' => ['from' => $oldScheduleId, 'to' => $newScheduleId],
        ]);
    }

    // Check if critical parameters changed to log history
    if ($newDeptId !== $oldDeptId || $newRoleId !== $oldRoleId || $newStatusId !== $oldStatusId || $newTypeId !== $oldTypeId) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $closeStmt = $pdo->prepare('UPDATE employment_history SET effective_to = ? WHERE employee_id = ? AND effective_to IS NULL');
        $closeStmt->execute([$yesterday, $id]);

        $today = date('Y-m-d');
        $historyStmt = $pdo->prepare(
            'INSERT INTO employment_history 
                (employee_id, department_id, role_id, employment_status_id, employment_type_id, changed_by_account_id, effective_from, effective_to, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $historyStmt->execute([
            $id,
            $newDeptId,
            $newRoleId,
            $newStatusId,
            $newTypeId,
            currentAccountId(),
            $today,
            'Auto-logged on employee details update'
        ]);
    }

    json_ok(['message' => 'Employee updated.']);
}

// DELETE — disabled. Employee records must never be hard-deleted; process
// separations through POST /employee_exits.php, which sets employment_status_id
// and preserves full history instead.
if ($method === 'DELETE') {
    json_err('Employees cannot be deleted. Use the employee exit process instead.', 405);
}

json_err('Method not allowed.', 405);

function castEmployee(array $r): array {
    $firstName = $r['first_name'] ?? '';
    $lastName  = $r['last_name'] ?? '';
    $fullName  = trim($firstName . ' ' . $lastName);

    return [
        'employee_id'           => (int)$r['employee_id'],
        'department_id'         => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'role_id'               => $r['role_id']       !== null ? (int)$r['role_id']       : null,
        'first_name'            => $firstName,
        'last_name'             => $lastName,
        'full_name'             => $fullName !== '' ? $fullName : null,
        'email'                 => $r['email'],
        'contact_no'            => $r['contact_no'],
        'hire_date'             => $r['hire_date'],
        'employment_status_id'  => $r['employment_status_id'] !== null ? (int)$r['employment_status_id'] : null,
        'employment_type_id'    => $r['employment_type_id']   !== null ? (int)$r['employment_type_id']   : null,
        'schedule_id'           => $r['schedule_id']          !== null ? (int)$r['schedule_id']          : null,
        'added_by_account_id'   => $r['added_by_account_id']  !== null ? (int)$r['added_by_account_id']  : null,
        'created_at'            => $r['created_at'] ?? null,
        'employment_status'     => $r['employment_status']     ?? null,
        'employment_type_name'  => $r['employment_type_name']  ?? null,
        'schedule_name'         => $r['schedule_name']         ?? null,
        'department_name'       => $r['department_name']       ?? null,
        'role_name'             => $r['role_name']             ?? null,
    ];
}

