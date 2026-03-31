<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

// Only technicians should be able to look up receiver staff details
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized',
    ]);
    exit;
}

require_once '../config/database.php';

$employee_no = isset($_GET['employee_no']) ? trim((string)$_GET['employee_no']) : '';
if ($employee_no === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Missing employee_no',
    ]);
    exit;
}

try {
    $stmt = db()->prepare("
        SELECT
            employee_no,
            full_name,
            department,
            email,
            phone
        FROM staff
        WHERE employee_no = ?
        LIMIT 1
    ");
    $stmt->execute([$employee_no]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'staff' => $staff ?: null,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error',
    ]);
}

