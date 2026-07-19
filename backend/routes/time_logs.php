<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const LOG_SELECT =
    'SELECT tl.log_id, tl.employee_id,
            CONCAT(e.first_name, " ", e.last_name) AS full_name,
            tl.status_id, ast.status_label,
            tl.work_date, tl.clock_in, tl.clock_out,
            tl.break_minutes, tl.total_hours, tl.hours_valid,
            tl.created_at, tl.updated_at
     FROM      time_logs tl
     JOIN      employees         e   ON e.employee_id = tl.employee_id
     LEFT JOIN attendance_status ast ON ast.status_id = tl.status_id';

function fetchEmployeeSchedule(PDO $pdo, int $employeeId): ?array {
    $stmt = $pdo->prepare(
        'SELECT ws.*
         FROM   employees e
         LEFT   JOIN work_schedules ws ON ws.schedule_id = e.schedule_id
         WHERE  e.employee_id = ?'
    );
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch();

    return ($row && !empty($row['schedule_id'])) ? $row : null;
}

function computeTotalHours(string $clockIn, string $clockOut, int $breakMinutes = 0): float {
    $ci  = new DateTime($clockIn);
    $co  = new DateTime($clockOut);
    $raw = ($co->getTimestamp() - $ci->getTimestamp()) / 3600;

    return round(max(0.0, $raw - ($breakMinutes / 60)), 2);
}

function isLateForSchedule(string $clockInDatetime, ?array $schedule): bool {
    if (!$schedule || empty($schedule['start_time'])) {
        $dt = new DateTime($clockInDatetime);
        $h  = (int)$dt->format('H');
        $m  = (int)$dt->format('i');

        return ($h > 9) || ($h === 9 && $m > 15);
    }

    $workDate  = (new DateTime($clockInDatetime))->format('Y-m-d');
    $deadline  = new DateTime($workDate . ' ' . $schedule['start_time']);
    $lateAfter = (int)($schedule['late_after_minutes'] ?? 15);
    $deadline->modify('+' . $lateAfter . ' minutes');

    return new DateTime($clockInDatetime) > $deadline;
}

function castLog(array $r): array {
    return [
        'log_id'        => (int)$r['log_id'],
        'employee_id'   => (int)$r['employee_id'],
        'full_name'     => trim($r['full_name'] ?? '') ?: null,
        'status_id'     => $r['status_id']     !== null ? (int)$r['status_id']     : null,
        'status_label'  => $r['status_label']  ?? null,
        'work_date'     => $r['work_date']     ?? null,
        'clock_in'      => $r['clock_in'],
        'clock_out'     => $r['clock_out'],
        'break_minutes' => $r['break_minutes'] !== null ? (int)$r['break_minutes'] : 0,
        'total_hours'   => $r['total_hours']   !== null ? (float)$r['total_hours']   : null,
        'hours_valid'   => (bool)($r['hours_valid'] ?? true),
        'created_at'    => $r['created_at'] ?? null,
        'updated_at'    => $r['updated_at'] ?? null,
    ];
}

// list all logs
if ($method === 'GET' && $action === '') {
    $where  = [];
    $params = [];
    $level  = currentAccessLevel();

    if (in_array($level, ['system_admin', 'human_resources'], true)) {
        if (!empty($_GET['employee_id'])) {
            $where[]  = 'tl.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['department_id'])) {
            $where[]  = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = 'CONCAT(e.first_name, " ", e.last_name) LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['year']) && !empty($_GET['month'])) {
            $where[]  = 'YEAR(tl.work_date) = ? AND MONTH(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
            $params[] = (int)$_GET['month'];
        } elseif (!empty($_GET['year'])) {
            $where[]  = 'YEAR(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
        }
        if (!empty($_GET['from'])) {
            $where[]  = 'tl.work_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[]  = 'tl.work_date <= ?';
            $params[] = $_GET['to'];
        }
    } elseif ($level === 'supervisor') {
        $deptId = currentDepartmentId();
        if ($deptId === null) {
            json_ok([]);
        }
        $where[]  = 'e.department_id = ?';
        $params[] = $deptId;
        if (!empty($_GET['employee_id'])) {
            $where[]  = 'tl.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = 'CONCAT(e.first_name, " ", e.last_name) LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['year']) && !empty($_GET['month'])) {
            $where[]  = 'YEAR(tl.work_date) = ? AND MONTH(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
            $params[] = (int)$_GET['month'];
        } elseif (!empty($_GET['year'])) {
            $where[]  = 'YEAR(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
        }
        if (!empty($_GET['from'])) {
            $where[]  = 'tl.work_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[]  = 'tl.work_date <= ?';
            $params[] = $_GET['to'];
        }
    } else {
        $empId = currentEmployeeId();
        if ($empId === null) {
            json_err('No employee record linked to this account.', 403);
        }
        $where[]  = 'tl.employee_id = ?';
        $params[] = $empId;
        if (!empty($_GET['year']) && !empty($_GET['month'])) {
            $where[]  = 'YEAR(tl.work_date) = ? AND MONTH(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
            $params[] = (int)$_GET['month'];
        } elseif (!empty($_GET['year'])) {
            $where[]  = 'YEAR(tl.work_date) = ?';
            $params[] = (int)$_GET['year'];
        }
    }

    $sql  = LOG_SELECT
          . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
          . ' ORDER BY tl.work_date DESC, tl.clock_in DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castLog', $stmt->fetchAll()));
}

// status if employee is clocked in right now
if ($method === 'GET' && $action === 'status') {
    $empId = currentEmployeeId();
    if ($empId === null) {
        json_err('No employee record linked to this account.', 403);
    }

    $today = (new DateTime())->format('Y-m-d');
    $stmt  = $pdo->prepare(
        LOG_SELECT . ' WHERE tl.employee_id = ? AND tl.work_date = ? AND tl.clock_out IS NULL LIMIT 1'
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
    if ($empId === null) {
        json_err('No employee record linked to this account.', 403);
    }

    $today = (new DateTime())->format('Y-m-d');
    $check = $pdo->prepare(
        'SELECT log_id FROM time_logs
         WHERE employee_id = ? AND work_date = ? LIMIT 1'
    );
    $check->execute([$empId, $today]);
    if ($check->fetch()) {
        json_err('You have already clocked in today. Only one clock-in is allowed per day.');
    }

    $schedule     = fetchEmployeeSchedule($pdo, $empId);
    $breakMinutes = $schedule ? (int)($schedule['break_minutes'] ?? 0) : 0;
    $now          = (new DateTime())->format('Y-m-d H:i:s');
    $statusId     = isLateForSchedule($now, $schedule) ? 2 : 1;

    $pdo->prepare(
        'INSERT INTO time_logs (employee_id, status_id, work_date, clock_in, break_minutes, hours_valid)
         VALUES (?, ?, ?, ?, ?, 1)'
    )->execute([$empId, $statusId, $today, $now, $breakMinutes]);

    $logId = (int)$pdo->lastInsertId();
    $sel   = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    json_ok(castLog($sel->fetch()), 201);
}

// POST: clock out
if ($method === 'POST' && $action === 'clock_out') {
    $empId = currentEmployeeId();
    if ($empId === null) {
        json_err('No employee record linked to this account.', 403);
    }

    $today = (new DateTime())->format('Y-m-d');
    $sel   = $pdo->prepare(
        'SELECT log_id, clock_in, break_minutes
         FROM   time_logs
         WHERE  employee_id = ? AND work_date = ? AND clock_out IS NULL
         LIMIT  1'
    );
    $sel->execute([$empId, $today]);
    $log = $sel->fetch();
    if (!$log) {
        json_err('No open clock-in found for today.');
    }

    $clockOut     = (new DateTime())->format('Y-m-d H:i:s');
    $breakMinutes = (int)($log['break_minutes'] ?? 0);
    $totalHours   = computeTotalHours($log['clock_in'], $clockOut, $breakMinutes);

    $pdo->prepare(
        'UPDATE time_logs
         SET clock_out = ?, total_hours = ?
         WHERE log_id = ?'
    )->execute([$clockOut, $totalHours, (int)$log['log_id']]);

    $sel2 = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel2->execute([(int)$log['log_id']]);
    json_ok(castLog($sel2->fetch()));
}

// PUT: admin edit
if ($method === 'PUT') {
    requireHumanResources();

    $body  = bodyJson();
    $logId = intVal_($body, 'log_id');
    if (!$logId) {
        json_err('log_id is required.');
    }

    $existing = $pdo->prepare('SELECT * FROM time_logs WHERE log_id = ? LIMIT 1');
    $existing->execute([$logId]);
    $log = $existing->fetch();
    if (!$log) {
        json_err('Time log not found.', 404);
    }

    $fields = [];
    $params = [];

    if (array_key_exists('status_id', $body)) {
        $fields[] = 'status_id = ?';
        $params[] = intVal_($body, 'status_id');
    }

    if (array_key_exists('work_date', $body)) {
        $workDate = str($body, 'work_date');
        if ($workDate === '') {
            json_err('work_date cannot be empty.');
        }
        $fields[] = 'work_date = ?';
        $params[] = $workDate;
    }

    if (array_key_exists('break_minutes', $body)) {
        $breakMinutes = intVal_($body, 'break_minutes', 0);
        if ($breakMinutes < 0) {
            json_err('break_minutes cannot be negative.');
        }
        $fields[] = 'break_minutes = ?';
        $params[] = $breakMinutes;
    }

    if (array_key_exists('hours_valid', $body)) {
        $fields[] = 'hours_valid = ?';
        $params[] = (int)(bool)$body['hours_valid'];
    }

    $newClockIn  = array_key_exists('clock_in', $body) ? str($body, 'clock_in') : null;
    $newClockOut = array_key_exists('clock_out', $body) ? str($body, 'clock_out') : null;

    if ($newClockIn !== null || $newClockOut !== null) {
        $ciStr = $newClockIn ?? $log['clock_in'];
        $coStr = $newClockOut ?? $log['clock_out'];

        if ($ciStr === '') {
            json_err('clock_in cannot be empty.');
        }

        $fields[] = 'clock_in = ?';
        $params[] = $ciStr;

        $breakMinutes = array_key_exists('break_minutes', $body)
            ? (int)intVal_($body, 'break_minutes', 0)
            : (int)($log['break_minutes'] ?? 0);

        if ($coStr !== null && $coStr !== '') {
            $totalHours = computeTotalHours($ciStr, $coStr, $breakMinutes);
            if ($totalHours <= 0 && (new DateTime($coStr)) <= (new DateTime($ciStr))) {
                json_err('clock_out must be after clock_in.');
            }

            $fields[] = 'clock_out = ?';
            $params[] = $coStr;
            $fields[] = 'total_hours = ?';
            $params[] = $totalHours;
        } else {
            $fields[] = 'clock_out = NULL';
            $fields[] = 'total_hours = NULL';
        }
    }

    if (empty($fields)) {
        json_err('Nothing to update.');
    }

    $params[] = $logId;
    $pdo->prepare(
        'UPDATE time_logs SET ' . implode(', ', $fields) . ' WHERE log_id = ?'
    )->execute($params);

    $sel = $pdo->prepare(LOG_SELECT . ' WHERE tl.log_id = ?');
    $sel->execute([$logId]);
    json_ok(castLog($sel->fetch()));
}

json_err('Not found.', 404);

