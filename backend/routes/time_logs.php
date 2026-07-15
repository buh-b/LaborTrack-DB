<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const OVERTIME_THRESHOLD_HOURS = 8;
const LATE_HOUR                = 9;
const LATE_MINUTE              = 15;

function isLate(string $clockInDatetime): bool {
    $dt = new DateTime($clockInDatetime);
    $h  = (int)$dt->format('H');
    $m  = (int)$dt->format('i');
    return ($h > LATE_HOUR) || ($h === LATE_HOUR && $m > LATE_MINUTE);
}

function castLog(array $r): array {
    return [
        'log_id'               => (int)$r['log_id'],
        'employee_id'          => (int)$r['employee_id'],
        'full_name'            => $r['full_name'],
        'shift_category_id'    => $r['shift_category_id']    !== null ? (int)$r['shift_category_id']    : null,
        'category_name'        => $r['category_name']        ?? null,
        'overtime_category_id' => $r['overtime_category_id'] !== null ? (int)$r['overtime_category_id'] : null,
        'overtime_category_name' => $r['overtime_category_name'] ?? null,
        'status_id'            => $r['status_id']            !== null ? (int)$r['status_id']            : null,
        'status_label'         => $r['status_label']         ?? null,
        'clock_in'             => $r['clock_in'],
        'clock_out'            => $r['clock_out'],
        'total_hours'          => $r['total_hours']          !== null ? (float)$r['total_hours']         : null,
        'overtime_hours'       => $r['overtime_hours']       !== null ? (float)$r['overtime_hours']      : null,
    ];
}

const LOG_SELECT =
    'SELECT tl.log_id, tl.employee_id, e.full_name,
            tl.shift_category_id,    sc.category_name,
            tl.overtime_category_id, oc.category_name  AS overtime_category_name,
            tl.status_id,            ast.status_label,
            tl.clock_in, tl.clock_out, tl.total_hours, tl.overtime_hours
     FROM      time_logs tl
     JOIN      employees          e   ON e.employee_id           = tl.employee_id
     LEFT JOIN shift_categories   sc  ON sc.shift_category_id    = tl.shift_category_id
     LEFT JOIN overtime_categories oc ON oc.overtime_category_id = tl.overtime_category_id
     LEFT JOIN attendance_status  ast ON ast.status_id           = tl.status_id';

// list all logs
if ($method === 'GET' && $action === '') {
    // Optional filters (admin only for employee_id / date range)
    $where  = [];
    $params = [];

    if (currentAccessLevel() === 'admin') {
        if (!empty($_GET['employee_id'])) {
            $where[]  = 'tl.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['department_id'])) {
            $where[]  = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = 'e.full_name LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        // Year/month filtering
        if (!empty($_GET['year']) && !empty($_GET['month'])) {
            $where[]  = 'YEAR(tl.clock_in) = ? AND MONTH(tl.clock_in) = ?';
            $params[] = (int)$_GET['year'];
            $params[] = (int)$_GET['month'];
        } elseif (!empty($_GET['year'])) {
            $where[]  = 'YEAR(tl.clock_in) = ?';
            $params[] = (int)$_GET['year'];
        }
        if (!empty($_GET['from'])) {
            $where[]  = 'DATE(tl.clock_in) >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[]  = 'DATE(tl.clock_in) <= ?';
            $params[] = $_GET['to'];
        }
    } else {
        $empId = currentEmployeeId();
        if ($empId === null) json_err('No employee record linked to this account.', 403);
        $where[]  = 'tl.employee_id = ?';
        $params[] = $empId;
        // Employee can filter by year/month too
        if (!empty($_GET['year']) && !empty($_GET['month'])) {
            $where[]  = 'YEAR(tl.clock_in) = ? AND MONTH(tl.clock_in) = ?';
            $params[] = (int)$_GET['year'];
            $params[] = (int)$_GET['month'];
        } elseif (!empty($_GET['year'])) {
            $where[]  = 'YEAR(tl.clock_in) = ?';
            $params[] = (int)$_GET['year'];
        }
    }

    $sql  = LOG_SELECT
          . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
          . ' ORDER BY tl.clock_in DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castLog', $stmt->fetchAll()));
}

// status if employee is clock in rn
if ($method === 'GET' && $action === 'status') {
    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $today = (new DateTime())->format('Y-m-d');
    $stmt  = $pdo->prepare(
        LOG_SELECT . ' WHERE tl.employee_id = ? AND DATE(tl.clock_in) = ? AND tl.clock_out IS NULL LIMIT 1'
    );
    $stmt->execute([$empId, $today]);
    $log = $stmt->fetch();

    json_ok([
        'clocked_in' => (bool)$log,
        'log'        => $log ? castLog($log) : null,
    ]);
}

// POST: clock in
if ($method === 'POST' && $action === 'clock_in') {
    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $body            = bodyJson();
    $shiftCategoryId = intVal_($body, 'shift_category_id', 1);

    $today = (new DateTime())->format('Y-m-d');
    $check = $pdo->prepare(
        'SELECT log_id FROM time_logs
         WHERE  employee_id = ? AND DATE(clock_in) = ? LIMIT 1'
    );
    $check->execute([$empId, $today]);
    if ($check->fetch()) json_err('You have already clocked in today. Only one clock-in is allowed per day.');

    $now      = (new DateTime())->format('Y-m-d H:i:s');
    $statusId = isLate($now) ? 2 : 1;

    $pdo->prepare(
        'INSERT INTO time_logs (employee_id, shift_category_id, status_id, clock_in)
         VALUES (?, ?, ?, ?)'
    )->execute([$empId, $shiftCategoryId, $statusId, $now]);

    $logId = (int)$pdo->lastInsertId();
    $sel   = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    json_ok(castLog($sel->fetch()), 201);
}

// POST: clock out 
if ($method === 'POST' && $action === 'clock_out') {
    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $today = (new DateTime())->format('Y-m-d');
    $sel   = $pdo->prepare(
        'SELECT log_id, clock_in
         FROM   time_logs
         WHERE  employee_id = ? AND DATE(clock_in) = ? AND clock_out IS NULL LIMIT 1'
    );
    $sel->execute([$empId, $today]);
    $log = $sel->fetch();
    if (!$log) json_err('No open clock-in found for today.');

    $clockOut   = new DateTime();
    $clockIn    = new DateTime($log['clock_in']);
    $totalHours = round(
        ($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 3600,
        2
    );

    // Auto-compute overtime: anything beyond 8 hours in this shift
    $overtimeHours = round(max(0.0, $totalHours - OVERTIME_THRESHOLD_HOURS), 2);

    $pdo->prepare(
        'UPDATE time_logs
         SET    clock_out = ?, total_hours = ?, overtime_hours = ?
         WHERE  log_id = ?'
    )->execute([
        $clockOut->format('Y-m-d H:i:s'),
        $totalHours,
        $overtimeHours > 0 ? $overtimeHours : null,   // NULL if no overtime
        (int)$log['log_id'],
    ]);

    $sel2 = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel2->execute([(int)$log['log_id']]);
    json_ok(castLog($sel2->fetch()));
}

//  PUT: admin edit 
if ($method === 'PUT') {
    if (currentAccessLevel() !== 'admin') json_err('Admins only.', 403);

    $body  = bodyJson();
    $logId = intVal_($body, 'log_id');
    if (!$logId) json_err('log_id is required.');

    // Fetch existing log first
    $existing = $pdo->prepare('SELECT * FROM time_logs WHERE log_id = ? LIMIT 1');
    $existing->execute([$logId]);
    $log = $existing->fetch();
    if (!$log) json_err('Time log not found.', 404);

    $fields = [];
    $params = [];

    // shift_category_id
    if (array_key_exists('shift_category_id', $body)) {
        $fields[] = 'shift_category_id = ?';
        $params[] = intVal_($body, 'shift_category_id');
    }

    // status_id
    if (array_key_exists('status_id', $body)) {
        $fields[] = 'status_id = ?';
        $params[] = intVal_($body, 'status_id');
    }

    // overtime_category_id (can be set to null to unassign)
    if (array_key_exists('overtime_category_id', $body)) {
        $fields[] = 'overtime_category_id = ?';
        $params[] = intVal_($body, 'overtime_category_id');
    }

    // overtime_hours (manual correction)
    if (array_key_exists('overtime_hours', $body)) {
        $oh = floatVal_($body, 'overtime_hours', 0.0);
        if ($oh < 0) json_err('overtime_hours cannot be negative.');
        $fields[] = 'overtime_hours = ?';
        $params[] = $oh > 0 ? $oh : null;
    }

    // clock_in / clock_out correction — recompute total_hours and overtime_hours
    $newClockIn  = array_key_exists('clock_in',  $body) ? str($body, 'clock_in')  : null;
    $newClockOut = array_key_exists('clock_out', $body) ? str($body, 'clock_out') : null;

    if ($newClockIn !== null || $newClockOut !== null) {
        $ciStr = $newClockIn  ?? $log['clock_in'];
        $coStr = $newClockOut ?? $log['clock_out'];

        if ($ciStr === '') json_err('clock_in cannot be empty.');

        $fields[] = 'clock_in = ?';
        $params[] = $ciStr;

        if ($coStr !== null && $coStr !== '') {
            $ci         = new DateTime($ciStr);
            $co         = new DateTime($coStr);
            $totalHours = round(($co->getTimestamp() - $ci->getTimestamp()) / 3600, 2);
            if ($totalHours < 0) json_err('clock_out must be after clock_in.');

            $autoOT = round(max(0.0, $totalHours - OVERTIME_THRESHOLD_HOURS), 2);

            $fields[] = 'clock_out = ?';
            $params[] = $coStr;
            $fields[] = 'total_hours = ?';
            $params[] = $totalHours;

            // Only overwrite overtime_hours if admin didn't explicitly set it in this request
            if (!array_key_exists('overtime_hours', $body)) {
                $fields[] = 'overtime_hours = ?';
                $params[] = $autoOT > 0 ? $autoOT : null;
            }
        } else {
            // clock_out cleared (re-opened)
            $fields[] = 'clock_out = NULL';
            $fields[] = 'total_hours = NULL';
            $fields[] = 'overtime_hours = NULL';
        }
    }

    if (empty($fields)) json_err('Nothing to update.');

    $params[] = $logId;
    $pdo->prepare(
        'UPDATE time_logs SET ' . implode(', ', $fields) . ' WHERE log_id = ?'
    )->execute($params);

    $sel = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    json_ok(castLog($sel->fetch()));
}

json_err('Not found.', 404);