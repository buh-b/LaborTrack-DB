<?php
// routes/incident_reports.php — Employee attendance incident reports
// (buddy punching, no-show, unauthorized attendance, system error, fraud, etc.)
//
// Not to be confused with reports.php, which serves admin payroll/labor-cost
// analytics. This file implements the `reports` table workflow described in
// the business logic spec:
//   Employee files a report on a time log -> Supervisor/HR/Admin investigates
//   -> Confirmed / Dismissed. Reports are immutable after submission except
//   by the validator.

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

const REPORT_SELECT =
    'SELECT r.*,
            tl.employee_id, tl.work_date, tl.clock_in, tl.clock_out,
            CONCAT(e.first_name, " ", e.last_name) AS employee_name,
            e.department_id,
            vs.status_name AS validation_status,
            rb.username AS reported_by_username,
            vb.username AS validated_by_username
     FROM      reports r
     JOIN      time_logs tl ON tl.log_id = r.log_id
     JOIN      employees e  ON e.employee_id = tl.employee_id
     LEFT JOIN validation_status vs ON vs.validation_status_id = r.validation_status_id
     LEFT JOIN accounts rb ON rb.account_id = r.reported_by_account_id
     LEFT JOIN accounts vb ON vb.account_id = r.validated_by_account_id';

function castReport(array $r): array {
    return [
        'report_id'                => (int)$r['report_id'],
        'log_id'                   => (int)$r['log_id'],
        'reported_by_account_id'   => (int)$r['reported_by_account_id'],
        'reported_by_username'     => $r['reported_by_username'] ?? null,
        'report_reason'            => $r['report_reason'],
        'description'              => $r['description'],
        'validation_status_id'     => (int)$r['validation_status_id'],
        'validation_status'        => $r['validation_status'] ?? null,
        'validated_by_account_id'  => $r['validated_by_account_id'] !== null ? (int)$r['validated_by_account_id'] : null,
        'validated_by_username'    => $r['validated_by_username'] ?? null,
        'remarks'                  => $r['remarks'],
        'validated_at'             => $r['validated_at'],
        'created_at'               => $r['created_at'],
        'employee_id'               => (int)$r['employee_id'],
        'employee_name'             => trim($r['employee_name'] ?? '') ?: null,
        'work_date'                 => $r['work_date'],
        'clock_in'                  => $r['clock_in'],
        'clock_out'                 => $r['clock_out'],
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

function canViewReport(PDO $pdo, array $report, ?string $level): bool {
    // System Admin / Payroll Admin (HR) can see everything.
    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        return true;
    }
    if ($level === 'supervisor') {
        return (int)$report['department_id'] === currentDepartmentId();
    }
    // Employees can see reports they filed and reports filed about their own logs.
    return (int)$report['reported_by_account_id'] === currentAccountId()
        || (int)$report['employee_id'] === currentEmployeeId();
}

// GET — list / view reports
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id   = (int)$_GET['id'];
        $stmt = $pdo->prepare(REPORT_SELECT . ' WHERE r.report_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_err('Report not found.', 404);
        if (!canViewReport($pdo, $row, currentAccessLevel())) json_err('Forbidden.', 403);
        json_ok(castReport($row));
    }

    $level  = currentAccessLevel();
    $where  = [];
    $params = [];

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        if (!empty($_GET['validation_status_id'])) {
            $where[]  = 'r.validation_status_id = ?';
            $params[] = (int)$_GET['validation_status_id'];
        }
        if (!empty($_GET['department_id'])) {
            $where[]  = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) json_ok([]);
        $where[]  = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['validation_status_id'])) {
            $where[]  = 'r.validation_status_id = ?';
            $params[] = (int)$_GET['validation_status_id'];
        }
    } else {
        // Employees only ever see reports they filed themselves.
        $where[]  = 'r.reported_by_account_id = ?';
        $params[] = currentAccountId();
    }

    $sql = REPORT_SELECT
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY r.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castReport', $stmt->fetchAll()));
}

// POST — file a report against a time log
if ($method === 'POST') {
    $body   = bodyJson();
    $logId  = intVal_($body, 'log_id');
    $reason = str($body, 'report_reason');
    $desc   = str($body, 'description');

    if (!$logId) json_err('log_id is required.');
    if ($reason === '') json_err('report_reason is required.');
    if ($desc === '') json_err('description is required.');

    $chk = $pdo->prepare('SELECT log_id FROM time_logs WHERE log_id = ? LIMIT 1');
    $chk->execute([$logId]);
    if (!$chk->fetch()) json_err('Time log not found.', 404);

    // Employees cannot validate their own reports, but anyone authenticated
    // may file one (e.g. reporting buddy punching on a colleague's log).
    $stmt = $pdo->prepare(
        'INSERT INTO reports (log_id, reported_by_account_id, report_reason, description, validation_status_id)
         VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([$logId, currentAccountId(), $reason, $desc]);
    $reportId = (int)$pdo->lastInsertId();

    $sel = $pdo->prepare(REPORT_SELECT . ' WHERE r.report_id = ?');
    $sel->execute([$reportId]);
    json_ok(castReport($sel->fetch()), 201);
}

// PUT — validate (confirm/dismiss) a report. Reports are immutable after
// submission except by the validator (Supervisor / HR / Admin).
if ($method === 'PUT') {
    requireRole(['supervisor', 'human_resources', 'system_admin']);

    $body     = bodyJson();
    $reportId = intVal_($body, 'report_id');
    if (!$reportId) json_err('report_id is required.');

    $stmt = $pdo->prepare(REPORT_SELECT . ' WHERE r.report_id = ? LIMIT 1');
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report) json_err('Report not found.', 404);

    $level = currentAccessLevel();
    if ($level === 'supervisor') {
        if ((int)$report['department_id'] !== currentDepartmentId()) {
            json_err('Forbidden.', 403);
        }
        if ((int)$report['reported_by_account_id'] === currentAccountId()) {
            json_err('You cannot validate your own report.');
        }
    }

    // Employees can never validate reports (enforced above by requireRole).
    $newStatusId = resolveValidationStatusId($pdo, $body, (int)$report['validation_status_id']);
    if ($newStatusId === null || !in_array($newStatusId, [1, 2, 3], true)) {
        json_err('validation_status_id must be Pending (1), Confirmed (2), or Dismissed (3).');
    }

    $validatedAt = in_array($newStatusId, [2, 3], true) ? (new DateTime())->format('Y-m-d H:i:s') : null;
    $validatedBy = in_array($newStatusId, [2, 3], true) ? currentAccountId() : null;

    $pdo->prepare(
        'UPDATE reports
         SET validation_status_id = ?, validated_by_account_id = ?, remarks = ?, validated_at = ?
         WHERE report_id = ?'
    )->execute([
        $newStatusId,
        $validatedBy,
        str($body, 'remarks') ?: null,
        $validatedAt,
        $reportId,
    ]);

    logAudit($pdo, 'report_validation', 'report', $reportId, [
        'from_status_id' => (int)$report['validation_status_id'],
        'to_status_id'   => $newStatusId,
    ]);

    $sel = $pdo->prepare(REPORT_SELECT . ' WHERE r.report_id = ?');
    $sel->execute([$reportId]);
    json_ok(castReport($sel->fetch()));
}

json_err('Method not allowed.', 405);

