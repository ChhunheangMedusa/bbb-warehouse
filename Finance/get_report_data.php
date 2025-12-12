<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if we have report data in session
if (isset($_SESSION['report_data']) && isset($_SESSION['report_criteria'])) {
    $response = [
        'report_type' => $_SESSION['report_type'],
        'start_date' => $_SESSION['report_criteria']['start_date'],
        'end_date' => $_SESSION['report_criteria']['end_date'],
        'location_name' => $_SESSION['report_criteria']['location_name'] ?? 'All Locations',
        'data' => $_SESSION['report_data']
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'No report data found']);
}
exit();