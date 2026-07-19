<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET — list all schedules with employee count
if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query(
        'SELECT ws.*, COUNT(e.employee_id) AS employee_count
         FROM   work_schedules ws
         LEFT   JOIN employees e ON e.schedule_id = ws.schedule_id
         GROUP  BY ws.schedule_id
         ORDER  BY ws.schedule_name'
    )->fetchAll();
    json_ok(array_map(fn($r) => [
        'schedule_id'        => (int)$r['schedule_id'],
        'schedule_name'      => $r['schedule_name'],
        'start_time'         => $r['start_time'],
        'end_time'           => $r['end_time'],
        'break_minutes'      => (int)$r['break_minutes'],
        'required_hours'     => (float)$r['required_hours'],
        'grace_minutes'      => (int)$r['grace_minutes'],
        'late_after_minutes' => (int)$r['late_after_minutes'],
        'rest_day'           => $r['rest_day'],
        'employee_count'     => (int)$r['employee_count'],
    ], $rows));
}

// POST — create
if ($method === 'POST') {
    requireSystemAdmin();
    $body  = bodyJson();
    $name  = str($body, 'schedule_name');
    $start = str($body, 'start_time');
    $end   = str($body, 'end_time');
    if ($name  === '') json_err('schedule_name is required.');
    if ($start === '') json_err('start_time is required.');
    if ($end   === '') json_err('end_time is required.');

    $breakMin = intVal_($body, 'break_minutes', 60);
    $reqHours = floatVal_($body, 'required_hours', 8.00);
    $graceMin = intVal_($body, 'grace_minutes', 15);
    $lateMin  = intVal_($body, 'late_after_minutes', 15);
    $restDay  = str($body, 'rest_day', 'Sunday');
    if ($restDay === '') $restDay = 'Sunday';

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO work_schedules (schedule_name, start_time, end_time, break_minutes, required_hours, grace_minutes, late_after_minutes, rest_day) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$name, $start, $end, $breakMin, $reqHours, $graceMin, $lateMin, $restDay]);
    json_ok(['schedule_id' => (int)$pdo->lastInsertId(), 'message' => 'Work schedule created.']);
}

// PUT — update
if ($method === 'PUT') {
    requireSystemAdmin();
    $body  = bodyJson();
    $id    = intVal_($body, 'schedule_id');
    $name  = str($body, 'schedule_name');
    $start = str($body, 'start_time');
    $end   = str($body, 'end_time');
    if (!$id)       json_err('schedule_id is required.');
    if ($name  === '') json_err('schedule_name is required.');
    if ($start === '') json_err('start_time is required.');
    if ($end   === '') json_err('end_time is required.');

    $breakMin = intVal_($body, 'break_minutes', 60);
    $reqHours = floatVal_($body, 'required_hours', 8.00);
    $graceMin = intVal_($body, 'grace_minutes', 15);
    $lateMin  = intVal_($body, 'late_after_minutes', 15);
    $restDay  = str($body, 'rest_day', 'Sunday');
    if ($restDay === '') $restDay = 'Sunday';

    $stmt = getDB()->prepare(
        'UPDATE work_schedules SET schedule_name = ?, start_time = ?, end_time = ?, break_minutes = ?, required_hours = ?, grace_minutes = ?, late_after_minutes = ?, rest_day = ? WHERE schedule_id = ?'
    );
    $stmt->execute([$name, $start, $end, $breakMin, $reqHours, $graceMin, $lateMin, $restDay, $id]);
    json_ok(['message' => 'Work schedule updated.']);
}

// DELETE
if ($method === 'DELETE') {
    requireSystemAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $pdo  = getDB();
    $stmt = $pdo->prepare('DELETE FROM work_schedules WHERE schedule_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Work schedule not found.', 404);
    json_ok(['message' => 'Work schedule deleted.']);
}

json_err('Method not allowed.', 405);
