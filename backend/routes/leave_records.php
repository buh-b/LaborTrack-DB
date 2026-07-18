<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

const LEAVE_SELECT =
    'SELECT lr.*,
            CONCAT(e.first_name, " ", e.last_name) AS full_name,
            lt.leave_name AS leave_type,
            ls.status_name AS leave_status
     FROM   leave_records lr
     LEFT   JOIN employees    e  ON e.employee_id    = lr.employee_id
     LEFT   JOIN leave_types  lt ON lt.leave_type_id = lr.leave_type_id
     LEFT   JOIN leave_status ls ON ls.leave_status_id = lr.leave_status_id';

function resolveLeaveTypeId(PDO $pdo, array $body): ?int {
    $id = intVal_($body, 'leave_type_id');
    if ($id !== null) {
        return $id;
    }

    $name = str($body, 'leave_type');
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    return $row ? (int)$row['leave_type_id'] : null;
}

function resolveLeaveStatusId(PDO $pdo, array $body, ?int $fallback = null): ?int {
    $id = intVal_($body, 'leave_status_id');
    if ($id !== null) {
        return $id;
    }

    $name = str($body, 'leave_status');
    if ($name === '') {
        return $fallback;
    }

    $stmt = $pdo->prepare('SELECT leave_status_id FROM leave_status WHERE status_name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    return $row ? (int)$row['leave_status_id'] : $fallback;
}

function resolveLeaveStatusFilter(PDO $pdo, string $status): ?int {
    $stmt = $pdo->prepare('SELECT leave_status_id FROM leave_status WHERE status_name = ? LIMIT 1');
    $stmt->execute([$status]);
    $row = $stmt->fetch();

    return $row ? (int)$row['leave_status_id'] : null;
}

function computeLeaveHours(string $dateFrom, string $dateTo, ?float $provided = null): ?float {
    if ($provided !== null) {
        return $provided;
    }

    $from = new DateTime($dateFrom);
    $to   = new DateTime($dateTo);
    $days = (int)$from->diff($to)->days + 1;

    return $days > 0 ? round($days * 8.0, 2) : 8.0;
}

// list all leave records
if ($method === 'GET') {
    requireAuth();
    $pdo    = getDB();
    $where  = [];
    $params = [];
    $level  = currentAccessLevel();

    if (in_array($level, ['system_admin', 'payroll_admin'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[]  = 'lr.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['status'])) {
            $statusId = resolveLeaveStatusFilter($pdo, $_GET['status']);
            if ($statusId !== null) {
                $where[]  = 'lr.leave_status_id = ?';
                $params[] = $statusId;
            }
        }
        if (!empty($_GET['search'])) {
            $where[]  = '(CONCAT(e.first_name, " ", e.last_name) LIKE ? OR lt.leave_name LIKE ?)';
            $term     = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[]  = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['status'])) {
            $statusId = resolveLeaveStatusFilter($pdo, $_GET['status']);
            if ($statusId !== null) {
                $where[]  = 'lr.leave_status_id = ?';
                $params[] = $statusId;
            }
        }
        if (!empty($_GET['search'])) {
            $where[]  = '(CONCAT(e.first_name, " ", e.last_name) LIKE ? OR lt.leave_name LIKE ?)';
            $term     = '%' . $_GET['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }
    } else {
        $where[]  = 'lr.employee_id = ?';
        $params[] = currentEmployeeId();
        if (!empty($_GET['search'])) {
            $where[]  = 'lt.leave_name LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['status'])) {
            $statusId = resolveLeaveStatusFilter($pdo, $_GET['status']);
            if ($statusId !== null) {
                $where[]  = 'lr.leave_status_id = ?';
                $params[] = $statusId;
            }
        }
    }

    $sql = LEAVE_SELECT
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY lr.date_from DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castLeave', $stmt->fetchAll()));
}

// POST: file a leave request
if ($method === 'POST') {
    requireAuth();
    $body       = bodyJson();
    $employeeId = in_array(currentAccessLevel(), ['system_admin', 'payroll_admin'], true)
                  ? intVal_($body, 'employee_id', currentEmployeeId())
                  : currentEmployeeId();
    $leaveTypeId = resolveLeaveTypeId($pdo = getDB(), $body);
    $dateFrom    = str($body, 'date_from');
    $dateTo      = str($body, 'date_to');

    if ($leaveTypeId === null) {
        json_err('leave_type_id is required.');
    }
    if ($dateFrom === '') {
        json_err('date_from is required.');
    }
    if ($dateTo === '') {
        json_err('date_to is required.');
    }
    if ($dateTo < $dateFrom) {
        json_err('date_to must be on or after date_from.');
    }

    $leaveHours = computeLeaveHours(
        $dateFrom,
        $dateTo,
        array_key_exists('leave_hours', $body) ? floatVal_($body, 'leave_hours') : null
    );

    $pdo->prepare(
        'INSERT INTO leave_records
            (employee_id, leave_type_id, leave_status_id, date_from, date_to, leave_hours, remarks)
         VALUES (?, ?, 1, ?, ?, ?, ?)'
    )->execute([
        $employeeId,
        $leaveTypeId,
        $dateFrom,
        $dateTo,
        $leaveHours,
        str($body, 'remarks') ?: null,
    ]);

    json_ok(['leave_id' => (int)$pdo->lastInsertId(), 'message' => 'Leave request filed.']);
}

// approve/reject (admin) - edit (employee)
if ($method === 'PUT') {
    requireAuth();
    $body    = bodyJson();
    $leaveId = intVal_($body, 'leave_id');
    if (!$leaveId) {
        json_err('leave_id is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT lr.*, e.department_id
         FROM   leave_records lr
         JOIN   employees e ON e.employee_id = lr.employee_id
         WHERE  lr.leave_id = ? LIMIT 1'
    );
    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch();
    if (!$leave) {
        json_err('Leave record not found.', 404);
    }

    $level = currentAccessLevel();

    if (in_array($level, ['system_admin', 'payroll_admin', 'supervisor'], true)) {
        if ($level === 'supervisor') {
            if ((int)$leave['department_id'] !== currentDepartmentId()) {
                json_err('Forbidden.', 403);
            }
            if ((int)$leave['employee_id'] === currentEmployeeId()) {
                json_err('You cannot approve or reject your own leave request.');
            }
        }

        $newStatusId = resolveLeaveStatusId(
            $pdo,
            $body,
            $leave['leave_status_id'] !== null ? (int)$leave['leave_status_id'] : 1
        );

        $leaveTypeId = resolveLeaveTypeId($pdo, $body)
            ?? ($leave['leave_type_id'] !== null ? (int)$leave['leave_type_id'] : null);
        if ($leaveTypeId === null) {
            json_err('leave_type_id is required.');
        }

        $dateFrom = str($body, 'date_from', $leave['date_from']);
        $dateTo   = str($body, 'date_to', $leave['date_to']);
        if ($dateTo < $dateFrom) {
            json_err('date_to must be on or after date_from.');
        }

        $approvedBy = null;
        if ($newStatusId === 2) {
            $approvedBy = currentAccountId();
        }

        $leaveHours = computeLeaveHours(
            $dateFrom,
            $dateTo,
            array_key_exists('leave_hours', $body)
                ? floatVal_($body, 'leave_hours')
                : ($leave['leave_hours'] !== null ? (float)$leave['leave_hours'] : null)
        );

        $pdo->prepare(
            'UPDATE leave_records
             SET leave_type_id = ?, leave_status_id = ?, date_from = ?, date_to = ?,
                 leave_hours = ?, remarks = ?, approved_by_account_id = ?
             WHERE leave_id = ?'
        )->execute([
            $leaveTypeId,
            $newStatusId,
            $dateFrom,
            $dateTo,
            $leaveHours,
            str($body, 'remarks', $leave['remarks'] ?? ''),
            $approvedBy,
            $leaveId,
        ]);

        json_ok(['message' => 'Leave record updated.']);
    }

    if ((int)$leave['employee_id'] !== currentEmployeeId()) {
        json_err('Forbidden.', 403);
    }
    if ((int)$leave['leave_status_id'] !== 1) {
        json_err('Only pending requests can be edited.');
    }

    $leaveTypeId = resolveLeaveTypeId($pdo, $body)
        ?? ($leave['leave_type_id'] !== null ? (int)$leave['leave_type_id'] : null);
    if ($leaveTypeId === null) {
        json_err('leave_type_id is required.');
    }

    $dateFrom = str($body, 'date_from', $leave['date_from']);
    $dateTo   = str($body, 'date_to', $leave['date_to']);
    if ($dateTo < $dateFrom) {
        json_err('date_to must be on or after date_from.');
    }

    $leaveHours = computeLeaveHours(
        $dateFrom,
        $dateTo,
        array_key_exists('leave_hours', $body)
            ? floatVal_($body, 'leave_hours')
            : ($leave['leave_hours'] !== null ? (float)$leave['leave_hours'] : null)
    );

    $pdo->prepare(
        'UPDATE leave_records
         SET leave_type_id = ?, date_from = ?, date_to = ?, leave_hours = ?, remarks = ?
         WHERE leave_id = ?'
    )->execute([
        $leaveTypeId,
        $dateFrom,
        $dateTo,
        $leaveHours,
        str($body, 'remarks', $leave['remarks'] ?? ''),
        $leaveId,
    ]);

    json_ok(['message' => 'Leave request updated.']);
}

// DELETE (admin) / cancel (employee)
if ($method === 'DELETE') {
    requireAuth();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM leave_records WHERE leave_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    if (!$leave) {
        json_err('Leave record not found.', 404);
    }

    if (!in_array(currentAccessLevel(), ['system_admin', 'payroll_admin'], true)) {
        if ((int)$leave['employee_id'] !== currentEmployeeId()) {
            json_err('Forbidden.', 403);
        }
        if ((int)$leave['leave_status_id'] !== 1) {
            json_err('Only pending requests can be cancelled.');
        }
    }

    $pdo->prepare('DELETE FROM leave_records WHERE leave_id = ?')->execute([$id]);
    json_ok(['message' => 'Leave record deleted.']);
}

json_err('Method not allowed.', 405);

function castLeave(array $r): array {
    return [
        'leave_id'               => (int)$r['leave_id'],
        'employee_id'            => (int)$r['employee_id'],
        'leave_type_id'          => $r['leave_type_id']   !== null ? (int)$r['leave_type_id']   : null,
        'leave_status_id'        => $r['leave_status_id'] !== null ? (int)$r['leave_status_id'] : null,
        'approved_by_account_id' => $r['approved_by_account_id'] !== null ? (int)$r['approved_by_account_id'] : null,
        'leave_type'             => $r['leave_type']   ?? null,
        'leave_status'           => $r['leave_status'] ?? null,
        'date_from'              => $r['date_from'],
        'date_to'                => $r['date_to'],
        'leave_hours'            => $r['leave_hours'] !== null ? (float)$r['leave_hours'] : null,
        'remarks'                => $r['remarks'],
        'created_at'             => $r['created_at'] ?? null,
        'updated_at'             => $r['updated_at'] ?? null,
        'full_name'              => trim($r['full_name'] ?? '') ?: null,
    ];
}
