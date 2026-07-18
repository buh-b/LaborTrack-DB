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
        $isPrivileged = in_array($level, ['system_admin', 'payroll_admin'], true);
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

    if (in_array($level, ['system_admin', 'payroll_admin'], true)) {
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
    requirePayrollAdmin();

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

    json_ok(['employee_id' => (int)$pdo->lastInsertId(), 'message' => 'Employee created.']);
}

// PUT: update
if ($method === 'PUT') {
    requirePayrollAdmin();

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

    $pdo->prepare(
        'UPDATE employees
         SET department_id = ?, role_id = ?, first_name = ?, last_name = ?, email = ?,
             contact_no = ?, hire_date = ?, employment_status_id = ?,
             employment_type_id = ?, schedule_id = ?
         WHERE employee_id = ?'
    )->execute([
        intVal_($body, 'department_id') ?: null,
        intVal_($body, 'role_id')       ?: null,
        $firstName,
        $lastName,
        str($body, 'email')      ?: null,
        str($body, 'contact_no') ?: null,
        str($body, 'hire_date', $existing['hire_date']),
        $statusId,
        array_key_exists('employment_type_id', $body)
            ? (intVal_($body, 'employment_type_id') ?: null)
            : ($existing['employment_type_id'] !== null ? (int)$existing['employment_type_id'] : null),
        array_key_exists('schedule_id', $body)
            ? (intVal_($body, 'schedule_id') ?: null)
            : ($existing['schedule_id'] !== null ? (int)$existing['schedule_id'] : null),
        $id,
    ]);

    json_ok(['message' => 'Employee updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireSystemAdmin();

    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare('DELETE FROM employees WHERE employee_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_err('Employee not found.', 404);
    }

    json_ok(['message' => 'Employee deleted.']);
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
