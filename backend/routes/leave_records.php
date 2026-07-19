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

// Returns true if the employee already has a Pending or Approved leave
// record whose date range overlaps [dateFrom, dateTo]. $excludeLeaveId lets
// an in-flight edit ignore its own row.
function hasOverlappingLeave(
    PDO $pdo,
    int $employeeId,
    string $dateFrom,
    string $dateTo,
    ?int $excludeLeaveId = null
): bool {
    $sql = 'SELECT lr.leave_id
            FROM   leave_records lr
            JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
            WHERE  lr.employee_id = ?
              AND  ls.status_name IN ("Pending", "Approved")
              AND  lr.date_from <= ?
              AND  lr.date_to   >= ?';
    $params = [$employeeId, $dateTo, $dateFrom];

    if ($excludeLeaveId !== null) {
        $sql .= ' AND lr.leave_id != ?';
        $params[] = $excludeLeaveId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch();
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

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
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
    $employeeId = in_array(currentAccessLevel(), ['system_admin', 'human_resources'], true)
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
    if (hasOverlappingLeave($pdo, $employeeId, $dateFrom, $dateTo)) {
        json_err('This overlaps an existing pending or approved leave request.');
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

    $newLeaveId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'leave_file', 'leave_record', $newLeaveId, [
        'employee_id'   => $employeeId,
        'leave_type_id' => $leaveTypeId,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
        'leave_hours'   => $leaveHours,
    ]);

    json_ok(['leave_id' => $newLeaveId, 'message' => 'Leave request filed.']);
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

    if (in_array($level, ['system_admin', 'human_resources', 'supervisor'], true)) {
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

        // Supervisors can only recommend (5) or reject (3) — not final approve (2)
        if ($level === 'supervisor' && $newStatusId !== null && !in_array($newStatusId, [3, 5], true)) {
            json_err('Supervisors can only recommend or reject leave requests. Final approval is by HR.');
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

        // Reject approval if the employee's remaining balance can't cover it.
        $oldStatusIdCheck = $leave['leave_status_id'] !== null ? (int)$leave['leave_status_id'] : 1;
        if ($oldStatusIdCheck !== 2 && $newStatusId === 2) {
            $checkYear    = (int)date('Y', strtotime($dateFrom));
            $checkDays    = $leaveHours / 8.0;
            $checkBalStmt = $pdo->prepare('SELECT remaining_days FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?');
            $checkBalStmt->execute([(int)$leave['employee_id'], $leaveTypeId, $checkYear]);
            $checkBal = $checkBalStmt->fetch();
            if ($checkBal && (float)$checkBal['remaining_days'] < $checkDays) {
                json_err('Insufficient leave balance for this request.');
            }
        }

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

        // Sync leave balances
        $oldStatusId = $leave['leave_status_id'] !== null ? (int)$leave['leave_status_id'] : 1;
        if ($oldStatusId !== 2 && $newStatusId === 2) {
            // Approving leave request! Increment used days
            $empId = (int)$leave['employee_id'];
            $leaveYear = (int)date('Y', strtotime($dateFrom));
            $daysUsed = $leaveHours / 8.0;

            $balStmt = $pdo->prepare('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?');
            $balStmt->execute([$empId, $leaveTypeId, $leaveYear]);
            $balance = $balStmt->fetch();

            if ($balance) {
                $newUsed = (float)$balance['used_days'] + $daysUsed;
                $newRem  = (float)$balance['entitled_days'] + (float)$balance['carried_over_days'] - $newUsed;
                $updStmt = $pdo->prepare('UPDATE leave_balances SET used_days = ?, remaining_days = ? WHERE balance_id = ?');
                $updStmt->execute([$newUsed, $newRem, $balance['balance_id']]);
            } else {
                $typeStmt = $pdo->prepare('SELECT max_days_per_year FROM leave_types WHERE leave_type_id = ?');
                $typeStmt->execute([$leaveTypeId]);
                $typeRow = $typeStmt->fetch();
                $entitled = $typeRow ? (float)($typeRow['max_days_per_year'] ?? 15.0) : 15.0;

                $newRem = $entitled - $daysUsed;
                $insStmt = $pdo->prepare(
                    'INSERT INTO leave_balances 
                        (employee_id, leave_type_id, year, entitled_days, carried_over_days, used_days, remaining_days)
                     VALUES (?, ?, ?, ?, 0.0, ?, ?)'
                );
                $insStmt->execute([$empId, $leaveTypeId, $leaveYear, $entitled, $daysUsed, $newRem]);
            }
        } elseif ($oldStatusId === 2 && $newStatusId !== 2) {
            // Reverting approval! Deduct used days
            $empId = (int)$leave['employee_id'];
            $leaveYear = (int)date('Y', strtotime($leave['date_from']));
            $daysUsed = ($leave['leave_hours'] !== null ? (float)$leave['leave_hours'] : 0.0) / 8.0;

            $balStmt = $pdo->prepare('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?');
            $balStmt->execute([$empId, (int)$leave['leave_type_id'], $leaveYear]);
            $balance = $balStmt->fetch();

            if ($balance) {
                $newUsed = max(0.0, (float)$balance['used_days'] - $daysUsed);
                $newRem  = (float)$balance['entitled_days'] + (float)$balance['carried_over_days'] - $newUsed;
                $updStmt = $pdo->prepare('UPDATE leave_balances SET used_days = ?, remaining_days = ? WHERE balance_id = ?');
                $updStmt->execute([$newUsed, $newRem, $balance['balance_id']]);
            }
        }

        logAudit($pdo, 'leave_approval', 'leave_record', $leaveId, [
            'from_status_id' => $oldStatusId,
            'to_status_id'   => $newStatusId,
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
    if (hasOverlappingLeave($pdo, (int)$leave['employee_id'], $dateFrom, $dateTo, $leaveId)) {
        json_err('This overlaps an existing pending or approved leave request.');
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

    logAudit($pdo, 'leave_edit', 'leave_record', $leaveId, [
        'leave_type_id' => $leaveTypeId,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
        'leave_hours'   => $leaveHours,
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

    $isAdmin = in_array(currentAccessLevel(), ['system_admin', 'human_resources'], true);

    if (!$isAdmin) {
        if ((int)$leave['employee_id'] !== currentEmployeeId()) {
            json_err('Forbidden.', 403);
        }
        if ((int)$leave['leave_status_id'] !== 1) {
            json_err('Only pending requests can be cancelled.');
        }
    }

    // If deleting an approved leave, reverse the balance deduction
    $wasApproved = (int)($leave['leave_status_id'] ?? 0) === 2;
    if ($wasApproved && $leave['leave_type_id'] !== null) {
        $empId     = (int)$leave['employee_id'];
        $leaveYear = (int)date('Y', strtotime($leave['date_from']));
        $daysUsed  = ($leave['leave_hours'] !== null ? (float)$leave['leave_hours'] : 0.0) / 8.0;

        $balStmt = $pdo->prepare(
            'SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?'
        );
        $balStmt->execute([$empId, (int)$leave['leave_type_id'], $leaveYear]);
        $balance = $balStmt->fetch();

        if ($balance) {
            $newUsed = max(0.0, (float)$balance['used_days'] - $daysUsed);
            $newRem  = (float)$balance['entitled_days'] + (float)$balance['carried_over_days'] - $newUsed;
            $pdo->prepare(
                'UPDATE leave_balances SET used_days = ?, remaining_days = ? WHERE balance_id = ?'
            )->execute([$newUsed, $newRem, $balance['balance_id']]);
        }
    }

    $pdo->prepare('DELETE FROM leave_records WHERE leave_id = ?')->execute([$id]);

    logAudit($pdo, $isAdmin ? 'leave_delete' : 'leave_cancel', 'leave_record', $id, [
        'employee_id'   => (int)$leave['employee_id'],
        'leave_type_id' => $leave['leave_type_id'] !== null ? (int)$leave['leave_type_id'] : null,
        'date_from'     => $leave['date_from'],
        'date_to'       => $leave['date_to'],
        'balance_reversed' => $wasApproved,
    ]);

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

