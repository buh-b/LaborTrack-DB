<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed.', 405);
}

requireAuth();

$pdo   = getDB();
$today = (new DateTime())->format('Y-m-d');

// Builds the admin-style dashboard payload. When $deptId is null the figures
// are company-wide (System Admin / Payroll Admin); when set, every query is
// scoped to that one department (Supervisor).
function buildAdminDashboard(PDO $pdo, string $today, ?int $deptId): array {
    $deptFilterEmp = $deptId !== null ? ' AND department_id = ' . $deptId : '';
    $deptFilterE   = $deptId !== null ? ' AND e.department_id = ' . $deptId : '';

    $totalEmployees = (int)$pdo->query(
        "SELECT COUNT(*) FROM employees WHERE 1=1{$deptFilterEmp}"
    )->fetchColumn();

    $activeEmployees = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM   employees emp
         JOIN   employment_status es ON es.employment_status_id = emp.employment_status_id
         WHERE  es.status_name = 'Active'{$deptFilterEmp}"
    )->fetchColumn();

    $presentToday = (int)$pdo->query(
        "SELECT COUNT(DISTINCT tl.employee_id)
         FROM   time_logs tl
         JOIN   employees e ON e.employee_id = tl.employee_id
         WHERE  tl.work_date = '$today'{$deptFilterE}"
    )->fetchColumn();

    $lateToday = (int)$pdo->query(
        "SELECT COUNT(DISTINCT tl.employee_id)
         FROM   time_logs tl
         JOIN   employees e             ON e.employee_id  = tl.employee_id
         JOIN   attendance_status ast   ON ast.status_id  = tl.status_id
         WHERE  tl.work_date = '$today'
         AND    LOWER(ast.status_label) LIKE '%late%'{$deptFilterE}"
    )->fetchColumn();

    $onLeaveToday = (int)$pdo->query(
        "SELECT COUNT(DISTINCT lr.employee_id)
         FROM   leave_records lr
         JOIN   employees e ON e.employee_id = lr.employee_id
         JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
         WHERE  ls.status_name = 'Approved'
         AND    lr.date_from <= '$today'
         AND    lr.date_to   >= '$today'{$deptFilterE}"
    )->fetchColumn();

    $notClockedIn = max(0, $activeEmployees - $presentToday - $onLeaveToday);

    // Pending leave requests
    $pendingLeaves = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM   leave_records lr
         JOIN   employees e ON e.employee_id = lr.employee_id
         JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
         WHERE  ls.status_name = 'Pending'{$deptFilterE}"
    )->fetchColumn();

    // Pending time log claims
    $pendingClaims = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM   time_log_claims c
         JOIN   time_logs tl ON tl.log_id = c.log_id
         JOIN   employees e  ON e.employee_id = tl.employee_id
         JOIN   validation_status vs ON vs.validation_status_id = c.validation_status_id
         WHERE  vs.status_name = 'Pending'{$deptFilterE}"
    )->fetchColumn();

    // Pending attendance incident reports
    $pendingReports = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM   reports r
         JOIN   time_logs tl ON tl.log_id = r.log_id
         JOIN   employees e  ON e.employee_id = tl.employee_id
         JOIN   validation_status vs ON vs.validation_status_id = r.validation_status_id
         WHERE  vs.status_name = 'Pending'{$deptFilterE}"
    )->fetchColumn();

    // Department breakdown — a single row (their own dept) when scoped
    $deptWhere = $deptId !== null ? " WHERE d.department_id = {$deptId}" : '';
    $deptRows = $pdo->query(
        "SELECT d.department_id,
                d.department_name,
                COUNT(DISTINCT e.employee_id)                                    AS employee_count,
                COUNT(DISTINCT CASE WHEN tl.work_date = '$today'
                                    THEN tl.employee_id END)                     AS present_today,
                COUNT(DISTINCT CASE WHEN tl.work_date = '$today'
                                         AND LOWER(ast.status_label) LIKE '%late%'
                                    THEN tl.employee_id END)                     AS late_today
         FROM       departments d
         LEFT JOIN  employees e   ON e.department_id   = d.department_id
         LEFT JOIN  time_logs tl  ON tl.employee_id    = e.employee_id
         LEFT JOIN  attendance_status ast ON ast.status_id = tl.status_id
         {$deptWhere}
         GROUP BY   d.department_id, d.department_name
         ORDER BY   d.department_name"
    )->fetchAll();

    $departments = array_map(fn($r) => [
        'department_id'   => (int)$r['department_id'],
        'department_name' => $r['department_name'],
        'employee_count'  => (int)$r['employee_count'],
        'present_today'   => (int)$r['present_today'],
        'late_today'      => (int)$r['late_today'],
    ], $deptRows);

    // Recent clock-ins (last 10)
    $recentRows = $pdo->query(
        "SELECT tl.log_id, tl.employee_id,
                CONCAT(e.first_name, ' ', e.last_name) AS full_name,
                tl.clock_in, tl.clock_out, tl.total_hours,
                ast.status_label
         FROM   time_logs tl
         JOIN   employees e              ON e.employee_id           = tl.employee_id
         LEFT JOIN attendance_status ast ON ast.status_id           = tl.status_id
         WHERE  1=1{$deptFilterE}
         ORDER  BY tl.clock_in DESC
         LIMIT  10"
    )->fetchAll();

    $recentClockIns = array_map(fn($r) => [
        'log_id'        => (int)$r['log_id'],
        'employee_id'   => (int)$r['employee_id'],
        'full_name'     => $r['full_name'],
        'clock_in'      => $r['clock_in'],
        'clock_out'     => $r['clock_out'],
        'total_hours'   => $r['total_hours'] !== null ? (float)$r['total_hours'] : null,
        'status_label'  => $r['status_label'],
    ], $recentRows);

    // Weekly attendance
    // Build Mon–Sun for the current ISO week; count present/late/absent per day.
    $weekStart = (new DateTime())->modify('Monday this week')->setTime(0, 0, 0);
    $weeklyAttendance = [];

    for ($i = 0; $i < 7; $i++) {
        $day      = clone $weekStart;
        $day->modify("+{$i} days");
        $dayStr   = $day->format('Y-m-d');
        $dayLabel = $day->format('D');   // "Mon", "Tue", …

        // Present (clocked in and NOT late)
        $presentCnt = (int)$pdo->query(
            "SELECT COUNT(DISTINCT tl.employee_id)
             FROM   time_logs tl
             JOIN   employees e ON e.employee_id = tl.employee_id
             LEFT JOIN attendance_status ast ON ast.status_id = tl.status_id
             WHERE  tl.work_date = '$dayStr'
             AND    (ast.status_label IS NULL OR LOWER(ast.status_label) NOT LIKE '%late%'){$deptFilterE}"
        )->fetchColumn();

        // Late
        $lateCnt = (int)$pdo->query(
            "SELECT COUNT(DISTINCT tl.employee_id)
             FROM   time_logs tl
             JOIN   employees e ON e.employee_id = tl.employee_id
             JOIN   attendance_status ast ON ast.status_id = tl.status_id
             WHERE  tl.work_date = '$dayStr'
             AND    LOWER(ast.status_label) LIKE '%late%'{$deptFilterE}"
        )->fetchColumn();

        // Absent = active employees who didn't clock in and weren't on approved leave
        $approvedLeave = (int)$pdo->query(
            "SELECT COUNT(DISTINCT lr.employee_id)
             FROM   leave_records lr
             JOIN   employees e ON e.employee_id = lr.employee_id
             JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
             WHERE  ls.status_name = 'Approved'
             AND    lr.date_from <= '$dayStr'
             AND    lr.date_to   >= '$dayStr'{$deptFilterE}"
        )->fetchColumn();
        $absentCnt = max(0, $activeEmployees - ($presentCnt + $lateCnt) - $approvedLeave);

        $weeklyAttendance[] = [
            'day_label' => $dayLabel,
            'date'      => $dayStr,
            'present'   => $presentCnt,
            'late'      => $lateCnt,
            'absent'    => $absentCnt,
        ];
    }

    $absentToday = $weeklyAttendance[count($weeklyAttendance) - 1]['date'] === $today
        ? $weeklyAttendance[count($weeklyAttendance) - 1]['absent']
        : max(0, $activeEmployees - $presentToday - $lateToday - $onLeaveToday);

    return [
        'today'              => $today,
        'headcount'          => [
            'total_employees'  => $totalEmployees,
            'active_employees' => $activeEmployees,
            'present_today'    => $presentToday,
            'late_today'       => $lateToday,
            'absent_today'     => $absentToday,
            'on_leave_today'   => $onLeaveToday,
            'not_clocked_in'   => $notClockedIn,
        ],
        'pending_leaves'     => $pendingLeaves,
        'pending_claims'     => $pendingClaims,
        'pending_reports'    => $pendingReports,
        'departments'        => $departments,
        'recent_clock_ins'   => $recentClockIns,
        'weekly_attendance'  => $weeklyAttendance,
    ];
}

$level = currentAccessLevel();

// SYSTEM ADMIN / PAYROLL ADMIN — company-wide dashboard
if (in_array($level, ['system_admin', 'human_resources'], true)) {
    json_ok(buildAdminDashboard($pdo, $today, null));
}

// SUPERVISOR — same dashboard, scoped to their own department
if ($level === 'supervisor') {
    $deptId = currentDepartmentId();
    if ($deptId === null) {
        // Supervisor with no department on record — nothing to scope to.
        json_ok(buildAdminDashboard($pdo, $today, 0));
    }
    json_ok(buildAdminDashboard($pdo, $today, $deptId));
}

// EMPLOYEE DASHBOARD
$empId = currentEmployeeId();
if ($empId === null) {
    json_ok([
        'today'                       => $today,
        'clocked_in'                  => false,
        'clock_in'                    => null,
        'clock_out'                   => null,
        'hours_today'                 => null,
        'status_label'                => null,
        'assigned_schedule'           => null,
        'leave_balance'               => [],
        'recent_attendance'           => [],
        'pending_leaves'              => 0,
        'approved_leaves_this_month'  => 0,
        'pending_claims'              => 0,
        'pending_reports'             => 0,
    ]);
}

$logStmt = $pdo->prepare(
    "SELECT tl.log_id, tl.clock_in, tl.clock_out, tl.total_hours,
            ast.status_label
     FROM   time_logs tl
     LEFT JOIN attendance_status ast ON ast.status_id = tl.status_id
     WHERE  tl.employee_id = ?
     AND    tl.work_date = ?
     ORDER  BY tl.clock_in DESC
     LIMIT  1"
);
$logStmt->execute([$empId, $today]);
$log = $logStmt->fetch();

// Assigned work schedule
$schedStmt = $pdo->prepare(
    "SELECT ws.schedule_id, ws.schedule_name, ws.start_time, ws.end_time,
            ws.required_hours, ws.rest_day
     FROM   employees e
     LEFT JOIN work_schedules ws ON ws.schedule_id = e.schedule_id
     WHERE  e.employee_id = ?"
);
$schedStmt->execute([$empId]);
$sched = $schedStmt->fetch();
$assignedSchedule = ($sched && $sched['schedule_id'] !== null) ? [
    'schedule_id'     => (int)$sched['schedule_id'],
    'schedule_name'   => $sched['schedule_name'],
    'start_time'      => $sched['start_time'],
    'end_time'        => $sched['end_time'],
    'required_hours'  => (float)$sched['required_hours'],
    'rest_day'        => $sched['rest_day'],
] : null;

// Leave balance for the current year
$balStmt = $pdo->prepare(
    "SELECT lb.leave_type_id, lt.leave_name, lb.entitled_days, lb.carried_over_days,
            lb.used_days, lb.remaining_days
     FROM   leave_balances lb
     JOIN   leave_types lt ON lt.leave_type_id = lb.leave_type_id
     WHERE  lb.employee_id = ? AND lb.year = ?"
);
$balStmt->execute([$empId, (int)date('Y')]);
$leaveBalance = array_map(fn($r) => [
    'leave_type_id'      => (int)$r['leave_type_id'],
    'leave_name'         => $r['leave_name'],
    'entitled_days'      => (float)$r['entitled_days'],
    'carried_over_days'  => (float)$r['carried_over_days'],
    'used_days'          => (float)$r['used_days'],
    'remaining_days'     => (float)$r['remaining_days'],
], $balStmt->fetchAll());

// Recent attendance (last 5 logs)
$recentStmt = $pdo->prepare(
    "SELECT tl.log_id, tl.work_date, tl.clock_in, tl.clock_out, tl.total_hours,
            ast.status_label
     FROM   time_logs tl
     LEFT JOIN attendance_status ast ON ast.status_id = tl.status_id
     WHERE  tl.employee_id = ?
     ORDER  BY tl.work_date DESC
     LIMIT  5"
);
$recentStmt->execute([$empId]);
$recentAttendance = array_map(fn($r) => [
    'log_id'       => (int)$r['log_id'],
    'work_date'    => $r['work_date'],
    'clock_in'     => $r['clock_in'],
    'clock_out'    => $r['clock_out'],
    'total_hours'  => $r['total_hours'] !== null ? (float)$r['total_hours'] : null,
    'status_label' => $r['status_label'],
], $recentStmt->fetchAll());

$pLeaveStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM   leave_records lr
     JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
     WHERE  lr.employee_id = ? AND ls.status_name = 'Pending'"
);
$pLeaveStmt->execute([$empId]);
$pendingOwn = (int)$pLeaveStmt->fetchColumn();

$monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
$monthEnd   = (new DateTime('last day of this month'))->format('Y-m-d');
$aLeaveStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM   leave_records lr
     JOIN   leave_status ls ON ls.leave_status_id = lr.leave_status_id
     WHERE  lr.employee_id = ?
     AND    ls.status_name = 'Approved'
     AND    lr.date_from  <= ?
     AND    lr.date_to    >= ?"
);
$aLeaveStmt->execute([$empId, $monthEnd, $monthStart]);
$approvedThisMonth = (int)$aLeaveStmt->fetchColumn();

// Pending claims filed by this employee
$pClaimStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM   time_log_claims c
     JOIN   time_logs tl ON tl.log_id = c.log_id
     JOIN   validation_status vs ON vs.validation_status_id = c.validation_status_id
     WHERE  tl.employee_id = ? AND vs.status_name = 'Pending'"
);
$pClaimStmt->execute([$empId]);
$pendingClaims = (int)$pClaimStmt->fetchColumn();

// Pending incident reports filed by this employee
$pReportStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM   reports r
     JOIN   accounts a ON a.account_id = r.reported_by_account_id
     JOIN   validation_status vs ON vs.validation_status_id = r.validation_status_id
     WHERE  a.employee_id = ? AND vs.status_name = 'Pending'"
);
$pReportStmt->execute([$empId]);
$pendingReports = (int)$pReportStmt->fetchColumn();

json_ok([
    'today'                      => $today,
    'clocked_in'                 => $log && $log['clock_out'] === null,
    'clock_in'                   => $log['clock_in']    ?? null,
    'clock_out'                  => $log['clock_out']   ?? null,
    'hours_today'                => $log && $log['total_hours'] !== null ? (float)$log['total_hours'] : null,
    'status_label'               => $log['status_label'] ?? null,
    'assigned_schedule'          => $assignedSchedule,
    'leave_balance'              => $leaveBalance,
    'recent_attendance'          => $recentAttendance,
    'pending_leaves'             => $pendingOwn,
    'approved_leaves_this_month' => $approvedThisMonth,
    'pending_claims'             => $pendingClaims,
    'pending_reports'            => $pendingReports,
]);

