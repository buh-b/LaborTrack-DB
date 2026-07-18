<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

const CLAIM_SELECT =
    'SELECT c.*,
            tl.employee_id, tl.work_date, tl.clock_in, tl.clock_out, tl.total_hours,
            CONCAT(e.first_name, " ", e.last_name) AS employee_name,
            oc.category_name AS overtime_category_name,
            h.holiday_name,
            vs.status_name AS validation_status,
            cb.username AS claimed_by_username,
            vb.username AS validated_by_username
     FROM      time_log_claims c
     JOIN      time_logs tl ON tl.log_id = c.log_id
     JOIN      employees e  ON e.employee_id = tl.employee_id
     LEFT JOIN overtime_categories oc ON oc.overtime_category_id = c.overtime_category_id
     LEFT JOIN holidays h ON h.holiday_id = c.holiday_id
     LEFT JOIN validation_status vs ON vs.validation_status_id = c.validation_status_id
     LEFT JOIN accounts cb ON cb.account_id = c.claimed_by_account_id
     LEFT JOIN accounts vb ON vb.account_id = c.validated_by_account_id';

function castClaim(array $r): array {
    return [
        'claim_id'                 => (int)$r['claim_id'],
        'log_id'                   => (int)$r['log_id'],
        'claimed_by_account_id'    => (int)$r['claimed_by_account_id'],
        'overtime_category_id'     => $r['overtime_category_id'] !== null ? (int)$r['overtime_category_id'] : null,
        'holiday_id'               => $r['holiday_id']           !== null ? (int)$r['holiday_id']           : null,
        'holiday_hours'            => $r['holiday_hours']  !== null ? (float)$r['holiday_hours']  : null,
        'overtime_hours'           => $r['overtime_hours'] !== null ? (float)$r['overtime_hours'] : null,
        'remarks'                  => $r['remarks'],
        'validation_status_id'     => (int)$r['validation_status_id'],
        'validated_by_account_id'  => $r['validated_by_account_id'] !== null ? (int)$r['validated_by_account_id'] : null,
        'resolution_remarks'       => $r['resolution_remarks'],
        'validated_at'             => $r['validated_at'],
        'created_at'               => $r['created_at'],
        'employee_id'              => (int)$r['employee_id'],
        'employee_name'            => trim($r['employee_name'] ?? '') ?: null,
        'work_date'                => $r['work_date'],
        'clock_in'                 => $r['clock_in'],
        'clock_out'                => $r['clock_out'],
        'total_hours'              => $r['total_hours'] !== null ? (float)$r['total_hours'] : null,
        'overtime_category_name'   => $r['overtime_category_name'] ?? null,
        'holiday_name'             => $r['holiday_name'] ?? null,
        'validation_status'        => $r['validation_status'] ?? null,
        'claimed_by_username'      => $r['claimed_by_username'] ?? null,
        'validated_by_username'    => $r['validated_by_username'] ?? null,
    ];
}

function resolveValidationStatusId(PDO $pdo, array $body, ?int $fallback = null): ?int {
    $id = intVal_($body, 'validation_status_id');
    if ($id !== null) {
        return $id;
    }

    $name = str($body, 'validation_status');
    if ($name === '') {
        return $fallback;
    }

    $stmt = $pdo->prepare('SELECT validation_status_id FROM validation_status WHERE status_name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    return $row ? (int)$row['validation_status_id'] : $fallback;
}

// GET — list claims
if ($method === 'GET') {
    requireAuth();
    $pdo    = getDB();
    $where  = [];
    $params = [];
    $level  = currentAccessLevel();

    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_err('Claim not found.', 404);
        }

        if (!canViewClaim($pdo, $row, $level)) {
            json_err('Forbidden.', 403);
        }

        json_ok(castClaim($row));
    }

    if (in_array($level, ['system_admin', 'payroll_admin'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[]  = 'tl.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['department_id'])) {
            $where[]  = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
        if (!empty($_GET['validation_status_id'])) {
            $where[]  = 'c.validation_status_id = ?';
            $params[] = (int)$_GET['validation_status_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = 'CONCAT(e.first_name, " ", e.last_name) LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[]  = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['validation_status_id'])) {
            $where[]  = 'c.validation_status_id = ?';
            $params[] = (int)$_GET['validation_status_id'];
        }
    } else {
        $empId = currentEmployeeId();
        if ($empId === null) {
            json_err('No employee record linked to this account.', 403);
        }
        $where[]  = 'tl.employee_id = ?';
        $params[] = $empId;
    }

    $sql  = CLAIM_SELECT
          . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
          . ' ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castClaim', $stmt->fetchAll()));
}

// POST — file a claim against a time log
if ($method === 'POST') {
    requireAuth();

    $body  = bodyJson();
    $logId = intVal_($body, 'log_id');
    if (!$logId) {
        json_err('log_id is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT tl.*, e.employee_id
         FROM   time_logs tl
         JOIN   employees e ON e.employee_id = tl.employee_id
         WHERE  tl.log_id = ? LIMIT 1'
    );
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) {
        json_err('Time log not found.', 404);
    }

    $level = currentAccessLevel();
    if (!in_array($level, ['system_admin', 'payroll_admin'], true)) {
        if ($level === 'supervisor') {
            json_err('Supervisors cannot file claims on behalf of employees.', 403);
        }
        if ((int)$log['employee_id'] !== currentEmployeeId()) {
            json_err('You can only file claims for your own time logs.', 403);
        }
    }

    $overtimeHours = array_key_exists('overtime_hours', $body)
        ? floatVal_($body, 'overtime_hours')
        : null;
    $holidayHours = array_key_exists('holiday_hours', $body)
        ? floatVal_($body, 'holiday_hours')
        : null;

    if (($overtimeHours === null || $overtimeHours <= 0) && ($holidayHours === null || $holidayHours <= 0)) {
        json_err('At least one of overtime_hours or holiday_hours must be greater than 0.');
    }
    if ($overtimeHours !== null && $overtimeHours < 0) {
        json_err('overtime_hours cannot be negative.');
    }
    if ($holidayHours !== null && $holidayHours < 0) {
        json_err('holiday_hours cannot be negative.');
    }

    $pendingCheck = $pdo->prepare(
        'SELECT claim_id FROM time_log_claims
         WHERE log_id = ? AND validation_status_id = 1 LIMIT 1'
    );
    $pendingCheck->execute([$logId]);
    if ($pendingCheck->fetch()) {
        json_err('A pending claim already exists for this time log.');
    }

    $pdo->prepare(
        'INSERT INTO time_log_claims
            (log_id, claimed_by_account_id, overtime_category_id, holiday_id,
             holiday_hours, overtime_hours, remarks, validation_status_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $logId,
        currentAccountId(),
        intVal_($body, 'overtime_category_id') ?: null,
        intVal_($body, 'holiday_id')           ?: null,
        ($holidayHours !== null && $holidayHours > 0) ? $holidayHours : null,
        ($overtimeHours !== null && $overtimeHours > 0) ? $overtimeHours : null,
        str($body, 'remarks') ?: null,
    ]);

    $claimId = (int)$pdo->lastInsertId();
    $sel = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ?');
    $sel->execute([$claimId]);
    json_ok(castClaim($sel->fetch()), 201);
}

// PUT — validate (approve/reject) or employee edit pending claim
if ($method === 'PUT') {
    requireAuth();

    $body    = bodyJson();
    $claimId = intVal_($body, 'claim_id');
    if (!$claimId) {
        json_err('claim_id is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ? LIMIT 1');
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();
    if (!$claim) {
        json_err('Claim not found.', 404);
    }

    $level = currentAccessLevel();

    if (in_array($level, ['system_admin', 'payroll_admin', 'supervisor'], true)) {
        if ($level === 'supervisor') {
            $deptStmt = $pdo->prepare('SELECT department_id FROM employees WHERE employee_id = ?');
            $deptStmt->execute([(int)$claim['employee_id']]);
            $deptRow = $deptStmt->fetch();
            if (!$deptRow || (int)$deptRow['department_id'] !== currentDepartmentId()) {
                json_err('Forbidden.', 403);
            }
            if ((int)$claim['employee_id'] === currentEmployeeId()) {
                json_err('You cannot validate your own claim.');
            }
        }

        $newStatusId = resolveValidationStatusId(
            $pdo,
            $body,
            (int)$claim['validation_status_id']
        );
        if ($newStatusId === null || !in_array($newStatusId, [1, 2, 3], true)) {
            json_err('validation_status_id must be Pending (1), Approved (2), or Rejected (3).');
        }

        $validatedAt = in_array($newStatusId, [2, 3], true)
            ? (new DateTime())->format('Y-m-d H:i:s')
            : null;
        $validatedBy = in_array($newStatusId, [2, 3], true)
            ? currentAccountId()
            : null;

        $pdo->prepare(
            'UPDATE time_log_claims
             SET validation_status_id = ?, validated_by_account_id = ?,
                 resolution_remarks = ?, validated_at = ?
             WHERE claim_id = ?'
        )->execute([
            $newStatusId,
            $validatedBy,
            str($body, 'resolution_remarks') ?: null,
            $validatedAt,
            $claimId,
        ]);

        $sel = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ?');
        $sel->execute([$claimId]);
        json_ok(castClaim($sel->fetch()));
    }

    if ((int)$claim['employee_id'] !== currentEmployeeId()) {
        json_err('Forbidden.', 403);
    }
    if ((int)$claim['validation_status_id'] !== 1) {
        json_err('Only pending claims can be edited.');
    }

    $overtimeHours = array_key_exists('overtime_hours', $body)
        ? floatVal_($body, 'overtime_hours')
        : ($claim['overtime_hours'] !== null ? (float)$claim['overtime_hours'] : null);
    $holidayHours = array_key_exists('holiday_hours', $body)
        ? floatVal_($body, 'holiday_hours')
        : ($claim['holiday_hours'] !== null ? (float)$claim['holiday_hours'] : null);

    $pdo->prepare(
        'UPDATE time_log_claims
         SET overtime_category_id = ?, holiday_id = ?, holiday_hours = ?,
             overtime_hours = ?, remarks = ?
         WHERE claim_id = ?'
    )->execute([
        intVal_($body, 'overtime_category_id') ?: ($claim['overtime_category_id'] ?: null),
        intVal_($body, 'holiday_id')           ?: ($claim['holiday_id'] ?: null),
        ($holidayHours !== null && $holidayHours > 0) ? $holidayHours : null,
        ($overtimeHours !== null && $overtimeHours > 0) ? $overtimeHours : null,
        str($body, 'remarks', $claim['remarks'] ?? ''),
        $claimId,
    ]);

    $sel = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ?');
    $sel->execute([$claimId]);
    json_ok(castClaim($sel->fetch()));
}

// DELETE — cancel pending claim (employee) or admin delete
if ($method === 'DELETE') {
    requireAuth();
    $id = intVal_($_GET, 'id');
    if (!$id) {
        json_err('id query param is required.');
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(CLAIM_SELECT . ' WHERE c.claim_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $claim = $stmt->fetch();
    if (!$claim) {
        json_err('Claim not found.', 404);
    }

    $level = currentAccessLevel();
    if (!in_array($level, ['system_admin', 'payroll_admin'], true)) {
        if ((int)$claim['employee_id'] !== currentEmployeeId()) {
            json_err('Forbidden.', 403);
        }
        if ((int)$claim['validation_status_id'] !== 1) {
            json_err('Only pending claims can be cancelled.');
        }
    }

    $pdo->prepare('DELETE FROM time_log_claims WHERE claim_id = ?')->execute([$id]);
    json_ok(['message' => 'Claim deleted.']);
}

json_err('Method not allowed.', 405);

function canViewClaim(PDO $pdo, array $claim, ?string $level): bool {
    if (in_array($level, ['system_admin', 'payroll_admin'], true)) {
        return true;
    }
    if ($level === 'supervisor') {
        $deptStmt = $pdo->prepare('SELECT department_id FROM employees WHERE employee_id = ?');
        $deptStmt->execute([(int)$claim['employee_id']]);
        $deptRow = $deptStmt->fetch();

        return $deptRow && (int)$deptRow['department_id'] === currentDepartmentId();
    }

    return (int)$claim['employee_id'] === currentEmployeeId();
}
