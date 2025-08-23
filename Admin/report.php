<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once 'translate.php'; // Moved this line up

// Get all locations for filter dropdown at the BEGINNING
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$all_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get repair locations
$stmt = $pdo->query("SELECT * FROM locations WHERE type = 'repair' ORDER BY name");
$repair_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get non-repair locations
$stmt = $pdo->query("SELECT * FROM locations WHERE type != 'repair' ORDER BY name");
$non_repair_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!isAdmin()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard-staff.php');
  exit();
}
checkAuth();

// Prepare location IDs for JavaScript
$repair_location_ids = array_column($repair_locations, 'id');
$non_repair_location_ids = array_column($non_repair_locations, 'id');

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_report']) || isset($_POST['preview_report'])) {
        $report_type = sanitizeInput($_POST['report_type']);
        $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $period = sanitizeInput($_POST['period']);
        $start_date = sanitizeInput($_POST['start_date']);
        $end_date = sanitizeInput($_POST['end_date']);
        
        // Determine date range based on period
        if ($period === 'monthly') {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
        } elseif ($period === 'yearly') {
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
        } elseif ($period === 'custom' && (empty($start_date) || empty($end_date))) {
            $_SESSION['error'] = "Please select both start and end dates for custom range";
            header('Location: report.php');
            exit();
        }
        
        // Store report criteria in session for preview/download
        $_SESSION['report_criteria'] = [
            'report_type' => $report_type,
            'location_id' => $location_id,
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
        
        // Generate report data based on type
        $report_data = generateReportData($pdo, $report_type, $location_id, $start_date, $end_date);
        
        if (empty($report_data)) {
            $_SESSION['error'] = "No records found for the selected criteria";
            header('Location: report.php');
            exit();
        }
        
        // If preview is requested, show preview page
      // If preview is requested, show preview page
// If preview is requested, show preview page
if (isset($_POST['preview_report'])) {
    // Store report data in session for preview
    $_SESSION['report_data'] = $report_data;
    $_SESSION['report_type'] = $report_type;
    
    // Get location name safely
    $location_name = 'All Locations';
    if ($location_id) {
        if (isset($report_data[0]['location_name'])) {
            $location_name = $report_data[0]['location_name'];
        } elseif (isset($report_data[0]['from_location_name'])) {
            $location_name = $report_data[0]['from_location_name'];
        }
    }
    
    $_SESSION['report_criteria']['location_name'] = $location_name;
    
    // Redirect with report type parameter
    header('Location: pdf.php?preview=true&report_type=' . $report_type);
    exit();
}
        
        // If download is requested, generate Excel file
        if (isset($_POST['generate_report'])) {
            generateExcelReport($report_type, $report_data, $start_date, $end_date, $location_id);
            exit();
        }
    }
}

// Handle download from preview
if (isset($_GET['download']) && $_GET['download'] === 'true' && isset($_SESSION['report_criteria'])) {
    $criteria = $_SESSION['report_criteria'];
    $report_data = generateReportData($pdo, $criteria['report_type'], $criteria['location_id'], $criteria['start_date'], $criteria['end_date']);
    
    if (!empty($report_data)) {
        generateExcelReport($criteria['report_type'], $report_data, $criteria['start_date'], $criteria['end_date'], $criteria['location_id']);
        exit();
    } else {
        $_SESSION['error'] = "Report data not found";
        header('Location: report.php');
        exit();
    }
}

// Function to generate report data
function generateReportData($pdo, $report_type, $location_id, $start_date, $end_date) {
    if ($report_type === 'stock_in') {
        $query = "SELECT 
                    si.*, 
                    l.name as location_name,
                    u.username as action_by_name,
                    c.name as category_name
                  FROM 
                    stock_in_history si
                  JOIN 
                    locations l ON si.location_id = l.id
                  JOIN
                    users u ON si.action_by = u.id
                  LEFT JOIN
                    categories c ON si.category_id = c.id
                  WHERE 
                    si.date BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
        
        if ($location_id) {
            $query .= " AND si.location_id = :location_id";
            $params[':location_id'] = $location_id;
        }
        
        $query .= " ORDER BY si.date, si.name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($report_type === 'stock_out') {
        $query = "SELECT 
                    so.*, 
                    l.name as location_name,
                    u.username as action_by_name,
                    c.name as category_name
                  FROM 
                    stock_out_history so
                  JOIN 
                    locations l ON so.location_id = l.id
                  JOIN
                    users u ON so.action_by = u.id
                  LEFT JOIN
                    categories c ON so.category_id = c.id
                  WHERE 
                    so.date BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
        
        if ($location_id) {
            $query .= " AND so.location_id = :location_id";
            $params[':location_id'] = $location_id;
        }
        
        $query .= " ORDER BY so.date, so.name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($report_type === 'stock_transfer') {
        $query = "SELECT 
                    t.*, 
                    fl.name as from_location_name,
                    tl.name as to_location_name,
                    u.username as action_by_name,
                    c.name as category_name
                FROM 
                    transfer_history t
                LEFT JOIN 
                    categories c ON t.category_id = c.id
                JOIN 
                    locations fl ON t.from_location_id = fl.id
                JOIN 
                    locations tl ON t.to_location_id = tl.id
                JOIN
                    users u ON t.action_by = u.id
                WHERE 
                    t.date BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
        
        if ($location_id) {
            $query .= " AND (t.from_location_id = :location_id OR t.to_location_id = :location_id)";
            $params[':location_id'] = $location_id;
        }
        
        $query .= " ORDER BY t.date, t.name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($report_type === 'repair') {
        $query = "SELECT 
                    rh.*, 
                    fl.name as from_location_name,
                    tl.name as to_location_name,
                    u.username as action_by_name,
                    c.name as category_name
                FROM 
                    repair_history rh
                LEFT JOIN 
                    categories c ON rh.category_id = c.id
                JOIN 
                    locations fl ON rh.from_location_id = fl.id
                JOIN 
                    locations tl ON rh.to_location_id = tl.id
                JOIN
                    users u ON rh.action_by = u.id
                WHERE 
                    rh.date BETWEEN :start_date AND :end_date
                    AND rh.action_type = 'send_for_repair'";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
        
        if ($location_id) {
            $query .= " AND (rh.from_location_id = :location_id OR rh.to_location_id = :location_id)";
            $params[':location_id'] = $location_id;
        }
        
        $query .= " ORDER BY rh.date, rh.item_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

// Function to generate Excel report
function generateExcelReport($report_type, $report_data, $start_date, $end_date, $location_id) {
    $filename = "";
    $title = "";
    $header_color = "";
    
    if ($report_type === 'stock_in') {
        $filename = "stock_in_report_" . date('Ymd') . ".xls";
        $title = "របាយការណ៍ទំនិញចូល";
        $header_color = "#4e73df";
    } elseif ($report_type === 'stock_out') {
        $filename = "stock_out_report_" . date('Ymd') . ".xls";
        $title = "របាយការណ៍ទំនិញចេញ";
        $header_color = "#e74a3b";
    } elseif ($report_type === 'stock_transfer') {
        $filename = "stock_transfer_report_" . date('Ymd') . ".xls";
        $title = "របាយការណ៍ផ្ទេរទំនិញ";
        $header_color = "#1cc88a";
    } elseif ($report_type === 'repair') {
        $filename = "repair_report_" . date('Ymd') . ".xls";
        $title = "របាយការណ៍ជួសជុលទំនិញ";
        $header_color = "#f6c23e";
    }
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    ob_end_clean();
    
    // Generate the Excel file content
    generateExcelContent($report_type, $report_data, $start_date, $end_date, $location_id, $title, $header_color);
}

// Function to generate Excel content
function generateExcelContent($report_type, $report_data, $start_date, $end_date, $location_id, $title, $header_color) {
    $location_name = $location_id ? $report_data[0]['location_name'] : 'ទីតាំងទាំងអស់';
    
    if ($report_type === 'stock_transfer' || $report_type === 'repair') {
        $location_name = $location_id ? $report_data[0]['from_location_name'] . ' ទៅ ' . $report_data[0]['to_location_name'] : 'ទីតាំងទាំងអស់';
    }
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="excel-format" content="excel-2007"> 
        <title>'.$title.'</title>
        <style>
            body { font-family: "Khmer OS Siemreap", sans-serif; }
            .report-header { 
                background: linear-gradient(135deg, '.$header_color.' 0%, '.darkenColor($header_color, 20).' 100%);
                color: white;
                padding: 20px;
                border-radius: 8px 8px 0 0;
                margin-bottom: 20px;
                text-align: center;
            }
            .report-title { font-size: 24px; font-weight: bold; margin-bottom: 5px;color:black; }
            .report-subtitle { font-size: 16px; opacity: 0.9; color:black; }
            .report-info { 
                background-color: #f8f9fc;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                border-left: 4px solid '.$header_color.';
            }
            table { 
                border-collapse: collapse; 
                width: 100%;
                border: 1px solid black;
            }
            th { 
                background-color: '.getHeaderBgColor($report_type).';
                color: '.getHeaderTextColor($report_type).';
                padding: 12px 8px;
                text-align: left;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 12px;
                border: 1px solid black;
            }
            td { 
                padding: 10px 8px;
                border: 1px solid black;
                vertical-align: middle;
            }
            .report-footer {
                margin-top: 20px;
                padding: 10px;
                text-align: right;
                font-size: 12px;
                color: #6c757d;
            }
        </style>
    </head>
    <body>
        <div class="report-header">
            <div class="report-title">'.$title.'</div>
            <div class="report-subtitle">ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ</div>
        </div>
        
        <div class="report-info">
            <p>ចាប់ពី: '.date('d/m/Y', strtotime($start_date)).' ដល់: '.date('d/m/Y', strtotime($end_date)).'</p>
            <p>ទីតាំង: '.$location_name.'</p>
        </div>
        
        <table>
            <thead>';
    
    // Table headers based on report type
    if ($report_type === 'stock_in' || $report_type === 'stock_out') {
        echo '<tr>
                <th>ល.រ</th>
                <th>លេខកូដទំនិញ</th>
                <th>ប្រភេទ</th>
                <th>លេខវិក័យប័ត្រ</th>
                <th>កាលបរិច្ឆេទ</th>
                <th>ឈ្មោះទំនិញ</th>
                <th>បរិមាណ</th>
                <th>សកម្មភាព</th>
                <th>ឯកតា</th>
                <th>ទីតាំង</th>
                <th>ផ្សេងៗ</th>
                <th>អ្នកប្រតិបត្តិ</th>
            </tr>';
    } elseif ($report_type === 'stock_transfer') {
        echo '<tr>
                <th>ល.រ</th>
                <th>លេខកូដទំនិញ</th>
                <th>ប្រភេទ</th>
                <th>លេខវិក័យប័ត្រ</th>
                <th>កាលបរិច្ឆេទ</th>
                <th>ឈ្មោះទំនិញ</th>
                <th>បរិមាណ</th>
                <th>ឯកតា</th>
                <th>ពីទីតាំង</th>
                <th>ទៅទីតាំង</th>
                <th>ផ្សេងៗ</th>
                <th>អ្នកប្រតិបត្តិ</th>
            </tr>';
    } elseif ($report_type === 'repair') {
        echo '<tr>
                <th>ល.រ</th>
                <th>លេខកូដទំនិញ</th>
                <th>ប្រភេទ</th>
                <th>លេខវិក័យប័ត្រ</th>
                <th>កាលបរិច្ឆេទ</th>
                <th>ឈ្មោះទំនិញ</th>
                <th>បរិមាណ</th>
                <th>សកម្មភាព</th>
                <th>ឯកតា</th>
                <th>ពីទីតាំង</th>
                <th>ទៅទីតាំង</th>
                <th>ផ្សេងៗ</th>
                <th>អ្នកប្រតិបត្តិ</th>
                <th>សកម្មភាពប្រវត្តិ</th>
            </tr>';
    }
    
    echo '</thead>
        <tbody>';
    
    $total_quantity = 0;
    foreach ($report_data as $index => $item) {
        $total_quantity += ($report_type === 'stock_transfer' || $report_type === 'repair') ? $item['quantity'] : $item['action_quantity'];
        $row_color = ($index % 2 === 0) ? getRowColor($report_type, true) : getRowColor($report_type, false);
        
        echo '<tr style="background-color: ' . $row_color . ';">
                <td style="border: 1px solid black;text-align:center;">'.($index + 1).'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['item_code'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['category_name'].'</td>
                <td style="mso-number-format:\@; border: 1px solid black; text-align: center;">'.$item['invoice_no'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.date('d/m/Y', strtotime($item['date'])).'</td>
                <td style="border: 1px solid black;text-align:center;">'.($report_type === 'repair' ? $item['item_name'] : $item['name']).'</td>';
        
        if ($report_type === 'stock_in' || $report_type === 'stock_out') {
            echo '<td style="border: 1px solid black;text-align:center;">'.$item['action_quantity'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.ucfirst($item['action_type']).'</td>';
        } else {
            echo '<td style="border: 1px solid black;text-align:center;">'.$item['quantity'].'</td>';
            
            if ($report_type === 'repair') {
                echo '<td style="border: 1px solid black;text-align:center;">'.($item['action_type'] == 'send_for_repair' ? 'ផ្ញើរជួសជុល' : 'ត្រឡប់មកវិញ').'</td>';
            } else {
                echo '<td style="border: 1px solid black;text-align:center;">'.$item['size'].'</td>';
            }
        }
        
        echo '<td style="border: 1px solid black;text-align:center;">'.$item['size'].'</td>';
        
        if ($report_type === 'stock_in' || $report_type === 'stock_out') {
            echo '<td style="border: 1px solid black;text-align:center;">'.$item['location_name'].'</td>';
        } else {
            echo '<td style="border: 1px solid black;text-align:center;">'.$item['from_location_name'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['to_location_name'].'</td>';
        }
        
        echo '<td style="border: 1px solid black;text-align:center;">'.$item['remark'].'</td>
              <td style="border: 1px solid black;text-align:center;">'.$item['action_by_name'].'</td>';
        
        if ($report_type === 'repair') {
            echo '<td style="border: 1px solid black;text-align:center;">'.$item['history_action'].'</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody>
        </table>
        
        <div class="report-footer">
            ថ្ងៃបង្កើតរបាយការណ៍: '.date('d/m/Y H:i:s').' | ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ
        </div>
    </body>
    </html>';
}

// Helper functions for styling
function darkenColor($color, $percent) {
    // Simple color darkening function
    $color = str_replace('#', '', $color);
    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));
    
    $r = max(0, min(255, $r - ($r * $percent / 100)));
    $g = max(0, min(255, $g - ($g * $percent / 100)));
    $b = max(0, min(255, $b - ($b * $percent / 100)));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

function getHeaderBgColor($report_type) {
    switch ($report_type) {
        case 'stock_in': return '#f6c23e'; // Yellow
        case 'stock_out': return '#36b9cc'; // Cyan
        case 'stock_transfer': return '#36b9cc'; // Cyan
        case 'repair': return '#36b9cc'; // Cyan
        default: return '#36b9cc';
    }
}

function getHeaderTextColor($report_type) {
    switch ($report_type) {
        case 'stock_in': return '#2c3e50'; // Dark blue
        case 'stock_out': return 'white';
        case 'stock_transfer': return 'white';
        case 'repair': return 'white';
        default: return 'white';
    }
}

function getRowColor($report_type, $is_even) {
    switch ($report_type) {
        case 'stock_in': return $is_even ? '#b1dcc8' : '#FAFAFA';
        case 'stock_out': return $is_even ? '#b1dcc8' : '#FAFAFA';
        case 'stock_transfer': return $is_even ? '#e8f4f0' : '#FAFAFA';
        case 'repair': return $is_even ? '#fef8e6' : '#FAFAFA';
        default: return $is_even ? '#f8f9fc' : '#FAFAFA';
    }
}
?>

<style>
    /* Your existing CSS remains unchanged */
    :root {
  --primary: #4e73df;
  --primary-dark: #2e59d9;
  --primary-light: #f8f9fc;
  --secondary: #858796;
  --success: #1cc88a;
  --info: #36b9cc;
  --warning: #f6c23e;
  --danger: #e74a3b;
  --light: #f8f9fa;
  --dark: #5a5c69;
  --white: #ffffff;
  --gray: #b7b9cc;
  --gray-dark: #7b7b8a;
  --font-family: "Khmer OS Siemreap", sans-serif;
}

/* Base Styles */
body {
  font-family: var(--font-family);
  background-color: var(--light);
  color: var(--dark);
  overflow-x: hidden;
}

/* Sidebar Styles */
.sidebar {
  width: 220px;
  min-width:220px;
  min-height: 100vh;
  background: #005064;
  color: var(--white);
  transition: all 0.3s;
  box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
  z-index: 1000;
}

.sidebar-brand {
  padding: 1.5rem 1rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-logo {
  height: 150px;
  width: auto;
}

.sidebar-nav {
  padding: 0.5rem 0;
}

.sidebar .nav-link {
    white-space: nowrap;       /* Prevent text wrapping */
    overflow: hidden;          /* Hide overflow */
    text-overflow: ellipsis;   /* Show ... if text is too long */
    padding: 0.75rem 1rem;     /* Adjust padding as needed */
    margin: 0.25rem 0;         /* Reduce margin */
    font-size: 0.875rem;       /* Slightly smaller font */
    display: flex;             /* Use flexbox for alignment */
    align-items: center;  
    color: var(--white);     /* Center items vertically */
}

.sidebar .nav-link:hover {
  color: var(--white);
  background-color: rgba(255, 255, 255, 0.1);
}

.sidebar .nav-link.active {
  color: var(--primary);
  background-color: var(--white);
  font-weight: 600;
}

.sidebar .nav-link i {
  margin-right: 0.5rem;
  font-size: 0.85rem;
  min-width: 1.25rem;       /* Fixed width for icons */
  text-align: center;
}

.sidebar-footer {
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

/* Main Content Styles */
.main-content {
  width: calc(100% - 14rem);
  min-height: 100vh;
  transition: all 0.3s;
  background-color: #f5f7fb;
}

/* Top Navigation */
.navbar {
  height: 4.375rem;
  box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
  background-color: var(--white);
}

.navbar .dropdown-menu {
  border: none;
  box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}

/* Card Styles */
.card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 1.5rem;
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.35rem;
            font-weight: 600;
            border-radius: 0.5rem 0.5rem 0 0 !important;
        }


.card-body {
  padding: 1.5rem;
}

/* Alert Styles */
.alert {
  border-radius: 0.35rem;
  border: none;
}

/* Button Styles */
.btn {
  border-radius: 0.35rem;
  padding: 0.5rem 1rem;
  font-weight: 500;
  transition: all 0.2s;
}

.btn-primary {
  background-color: var(--primary);
  border-color: var(--primary);
}

.btn-primary:hover {
  background-color: var(--primary-dark);
  border-color: var(--primary-dark);
}

.btn-outline-primary {
  color: var(--primary);
  border-color: var(--primary);
}

.btn-outline-primary:hover {
  background-color: var(--primary);
  border-color: var(--primary);
}

/* Table Styles */
.table {
  color: var(--dark);
  margin-bottom: 0;
}

.table th {
  background-color: var(--light);
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  border-bottom-width: 1px;
  white-space: nowrap;
  text-overflow: ellipsis;
  max-width: 200px;
  overflow: hidden;
}

.table > :not(:first-child) {
  border-top: none;
}

/* Form Styles */
.form-control,
.form-select {
  border-radius: 0.35rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid #d1d3e2;
}

.form-control:focus,
.form-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
}

/* Badge Styles */
.badge {
  font-weight: 500;
  padding: 0.35em 0.65em;
  border-radius: 0.25rem;
}

/* Custom Scrollbar */
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 10px;
}

::-webkit-scrollbar-thumb {
  background: var(--gray);
  border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
  background: var(--gray-dark);
}

/* Responsive Styles */
@media (max-width: 768px) {
  .sidebar {
    margin-left: -14rem;
    position: fixed;
  }

  .sidebar.show {
    margin-left: 0;
  }

  .main-content {
    width: 100%;
  }

  .main-content.show {
    margin-left: 14rem;
  }

  #sidebarToggle {
    display: block;
  }
}

/* Animation Classes */
.fade-in {
  animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* Utility Classes */
.text-khmer {
  font-family: var(--font-family);
}

.cursor-pointer {
  cursor: pointer;
}

.shadow-sm {
  box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

/* Image Styles */
.img-thumbnail {
  padding: 0.25rem;
  background-color: var(--white);
  border: 1px solid #d1d3e2;
  border-radius: 0.35rem;
  max-width: 100%;
  height: auto;
  transition: all 0.2s;
}

.img-thumbnail:hover {
  transform: scale(1.05);
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
}

/* Modal Styles */
.modal-content {
  border: none;
  border-radius: 0.5rem;
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.modal-header {
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
  padding: 1rem 1.5rem;
}

.modal-footer {
  border-top: 1px solid rgba(0, 0, 0, 0.05);
}

/* Pagination Styles */
.pagination .page-item .page-link {
  border-radius: 0.35rem;
  margin: 0 0.25rem;
  color: var(--primary);
}

.pagination .page-item.active .page-link {
  background-color: var(--primary);
  border-color: var(--primary);
  color: var(--white);
}

.pagination .page-item.disabled .page-link {
  color: var(--secondary);
}

/* Custom Toggle Switch */
.form-switch .form-check-input {
  width: 2.5em;
  height: 1.5em;
  cursor: pointer;
}

/* Custom File Upload */
.form-control-file::-webkit-file-upload-button {
  visibility: hidden;
}

.form-control-file::before {
  content: "ជ្រើសរើសឯកសារ";
  display: inline-block;
  background: var(--light);
  border: 1px solid #d1d3e2;
  border-radius: 0.35rem;
  padding: 0.375rem 0.75rem;
  outline: none;
  white-space: nowrap;
  cursor: pointer;
  color: var(--dark);
  font-weight: 500;
  transition: all 0.2s;
}

.form-control-file:hover::before {
  background: #e9ecef;
}
.container {
            max-width: 1400px;
        }
/* Mobile-specific styles */
@media (max-width: 576px) {
    /* Adjust container padding */
    .container-fluid {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    
    /* Card adjustments */
    .card-header h5 {
        font-size: 1rem;
    }
    
    /* Table adjustments */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table th, .table td {
        padding: 0.5rem;
        font-size: 0.8rem;
    }
    
    /* Pagination adjustments */
    .pagination {
        flex-wrap: wrap;
    }
    
    .page-item {
        margin-bottom: 0.25rem;
    }
    
    .page-link {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }
    
    /* Text adjustments */
    h2 {
        font-size: 1.25rem;
    }
    
    /* Main content width */
    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    /* Sidebar adjustments */
    .sidebar {
        margin-left: -220px;
        position: fixed;
        z-index: 1040;
    }
    
    .sidebar.show {
        margin-left: 0;
    }
    
    /* Navbar adjustments */
    .navbar {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
}

/* Additional touch targets for mobile */
@media (pointer: coarse) {
    .btn, .page-link, .nav-link {
        min-width: 44px;
        min-height: 44px;
        padding: 0.5rem 1rem;
    }
    
    .form-control, .form-select {
        min-height: 44px;
    }
}

/* Very small devices (portrait phones) */
@media (max-width: 360px) {
    .table th, .table td {
        padding: 0.3rem;
        font-size: 0.75rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .btn {
        padding: 0.375rem 0.75rem;
        font-size: 0.8rem;
    }
}

/* Landscape phones */
@media (max-width: 768px) and (orientation: landscape) {
    .table-responsive {
        max-height: 300px;
    }
    
    .sidebar {
        overflow-y: auto;
    }
}

/* High-resolution devices */
@media (min-resolution: 192dpi) {
    .btn, .form-control, .card {
        border-width: 0.5px;
    }
}

/* Dark mode support (optional) */
@media (prefers-color-scheme: dark) {
    .card {
        
        color: #000;
    }
    
    .table {
        color: #ecf0f1;
    }
    
    .table th {
        background-color: #34495e;
    }
}

/* Print styles */
@media print {
    .sidebar, .navbar, .btn {
        display: none !important;
    }
    
    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    .card {
        border: 1px solid #000;
        box-shadow: none;
    }
}

/* Preview Modal Styles */
.preview-modal {
  display: none;
  position: fixed;
  z-index: 1050;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgba(0, 0, 0, 0.5);
}

.preview-modal-content {
  background-color: #fefefe;
  margin: 2% auto;
  padding: 20px;
  border: 1px solid #888;
  width: 90%;
  max-width: 1200px;
  border-radius: 8px;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
  animation: modalFadeIn 0.3s;
}

@keyframes modalFadeIn {
  from {opacity: 0;}
  to {opacity: 1;}
}

.close-btn {
  color: #aaa;
  float: right;
  font-size: 28px;
  font-weight: bold;
  cursor: pointer;
}

.close-btn:hover,
.close-btn:focus {
  color: black;
  text-decoration: none;
}

.preview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 10px;
  border-bottom: 1px solid #eee;
}

.preview-title {
  margin: 0;
  color: #333;
}

.preview-body {
  max-height: 70vh;
  overflow-y: auto;
  padding: 10px;
  background: #f9f9f9;
  border-radius: 4px;
}

.preview-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
  padding-top: 10px;
  border-top: 1px solid #eee;
}

/* Responsive adjustments for preview */
@media (max-width: 768px) {
  .preview-modal-content {
    width: 95%;
    margin: 5% auto;
    padding: 15px;
  }
  
  .preview-body {
    max-height: 60vh;
  }
}
/* Preview Modal Styles */
.preview-modal {
  display: none;
  position: fixed;
  z-index: 1050;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgba(0, 0, 0, 0.5);
}

.preview-modal-content {
  background-color: #fefefe;
  margin: 2% auto;
  padding: 20px;
  border: 1px solid #888;
  width: 90%;
  max-width: 1200px;
  border-radius: 8px;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
  animation: modalFadeIn 0.3s;
}

@keyframes modalFadeIn {
  from {opacity: 0;}
  to {opacity: 1;}
}

.close-btn {
  color: #aaa;
  float: right;
  font-size: 28px;
  font-weight: bold;
  cursor: pointer;
}

.close-btn:hover,
.close-btn:focus {
  color: black;
  text-decoration: none;
}

.preview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 10px;
  border-bottom: 1px solid #eee;
}

.preview-title {
  margin: 0;
  color: #333;
}

.preview-body {
  max-height: 70vh;
  overflow-y: auto;
  padding: 10px;
  background: #f9f9f9;
  border-radius: 4px;
}

.preview-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
  padding-top: 10px;
  border-top: 1px solid #eee;
}
.pdf-table-container {
  width: 100%;
  overflow-x: auto;
  margin-bottom: 12px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  -webkit-overflow-scrolling: touch;
}

.pdf-table {
  width: 100%;
  border-collapse: collapse;
  /* Changed from fixed to auto for better content fitting */
  table-layout: auto;
}

.pdf-table th {
  background: #2c3e50;
  color: white;
  padding: 8px 6px;
  text-align: center;
  border: 1px solid #dee2e6;
  font-weight: 600;
  font-size: 11px;
  white-space: normal; /* Changed from nowrap to normal */
  overflow: visible;
  text-overflow: clip; /* Changed from ellipsis to clip */
  /* Removed min-width to allow natural sizing */
}

.pdf-table td {
  padding: 6px 4px;
  border: 1px solid #dee2e6;
  text-align: center;
  vertical-align: middle;
  font-size: 11px;
  word-wrap: break-word;
  overflow: visible;
  text-overflow: clip;
  /* Removed min-width to allow natural sizing */
}

/* Ensure content fits properly */
.pdf-table th,
.pdf-table td {
  max-width: 120px; /* Reasonable maximum width */
}

/* Mobile responsiveness */
@media (max-width: 768px) {
  .pdf-table {
    table-layout: auto;
    min-width: 100%; /* Ensure it uses available space */
  }
  
  .pdf-table th,
  .pdf-table td {
    max-width: none; /* Remove max-width on mobile */
    white-space: nowrap; /* Allow wrapping on mobile */
  }
}
</style>

<div class="container-fluid">
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['preview']) && $_GET['preview'] === 'true' && isset($_SESSION['report_data'])): ?>
        <!-- Report Preview Section -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="m-0 font-weight-bold text-primary"><?= t('report_preview') ?></h5>
                <div>
                    <button onclick="window.history.back()" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> <?= t('back') ?>
                    </button>
                    <a href="report.php?download=true" class="btn btn-success">
                        <i class="fas fa-download"></i> <?= t('download') ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="pdf-preview">
                    <div class="pdf-header">
                        <?php
                        $title = "";
                        $header_color = "";
                        
                        if ($_SESSION['report_type'] === 'stock_in') {
                            $title = "របាយការណ៍ទំនិញចូល";
                            $header_color = "#4e73df";
                        } elseif ($_SESSION['report_type'] === 'stock_out') {
                            $title = "របាយការណ៍ទំនិញចេញ";
                            $header_color = "#e74a3b";
                        } elseif ($_SESSION['report_type'] === 'stock_transfer') {
                            $title = "របាយការណ៍ផ្ទេរទំនិញ";
                            $header_color = "#1cc88a";
                        } elseif ($_SESSION['report_type'] === 'repair') {
                            $title = "របាយការណ៍ជួសជុលទំនិញ";
                            $header_color = "#f6c23e";
                        }
                        
                        $criteria = $_SESSION['report_criteria'];
                        $location_name = $criteria['location_id'] ? $_SESSION['report_data'][0]['location_name'] : 'ទីតាំងទាំងអស់';
                        
                        if ($_SESSION['report_type'] === 'stock_transfer' || $_SESSION['report_type'] === 'repair') {
                            $location_name = $criteria['location_id'] ? 
                                $_SESSION['report_data'][0]['from_location_name'] . ' ទៅ ' . $_SESSION['report_data'][0]['to_location_name'] : 
                                'ទីតាំងទាំងអស់';
                        }
                        ?>
                        
                        <h1 class="pdf-title"><?= $title ?></h1>
                        <p class="pdf-subtitle">ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ</p>
                    </div>
                    
                    <div class="pdf-info">
                        <p><strong>ចាប់ពី:</strong> <?= date('d/m/Y', strtotime($criteria['start_date'])) ?></p>
                        <p><strong>ដល់:</strong> <?= date('d/m/Y', strtotime($criteria['end_date'])) ?></p>
                        <p><strong>ទីតាំង:</strong> <?= $location_name ?></p>
                    </div>
                    
                    <table class="pdf-table">
                        <thead>
                            <?php if ($_SESSION['report_type'] === 'stock_in' || $_SESSION['report_type'] === 'stock_out'): ?>
                                <tr>
                                    <th>ល.រ</th>
                                    <th>លេខកូដទំនិញ</th>
                                    <th>ប្រភេទ</th>
                                    <th>លេខវិក័យប័ត្រ</th>
                                    <th>កាលបរិច្ឆេទ</th>
                                    <th>ឈ្មោះទំនិញ</th>
                                    <th>បរិមាណ</th>
                                    <th>សកម្មភាព</th>
                                    <th>ឯកតា</th>
                                    <th>ទីតាំង</th>
                                    <th>ផ្សេងៗ</th>
                                    <th>អ្នកប្រតិបត្តិ</th>
                                </tr>
                            <?php elseif ($_SESSION['report_type'] === 'stock_transfer'): ?>
                                <tr>
                                    <th>ល.រ</th>
                                    <th>លេខកូដទំនិញ</th>
                                    <th>ប្រភេទ</th>
                                    <th>លេខវិក័យប័ត្រ</th>
                                    <th>កាលបរិច្ឆេទ</th>
                                    <th>ឈ្មោះទំនិញ</th>
                                    <th>បរិមាណ</th>
                                    <th>ឯកតា</th>
                                    <th>ពីទីតាំង</th>
                                    <th>ទៅទីតាំង</th>
                                    <th>ផ្សេងៗ</th>
                                    <th>អ្នកប្រតិបត្តិ</th>
                                </tr>
                            <?php elseif ($_SESSION['report_type'] === 'repair'): ?>
                                <tr>
                                    <th>ល.រ</th>
                                    <th>លេខកូដទំនិញ</th>
                                    <th>ប្រភេទ</th>
                                    <th>លេខវិក័យប័ត្រ</th>
                                    <th>កាលបរិច្ឆេទ</th>
                                    <th>ឈ្មោះទំនិញ</th>
                                    <th>បរិមាណ</th>
                                    <th>សកម្មភាព</th>
                                    <th>ឯកតា</th>
                                    <th>ពីទីតាំង</th>
                                    <th>ទៅទីតាំង</th>
                                    <th>ផ្សេងៗ</th>
                                    <th>អ្នកប្រតិបត្តិ</th>
                                    <th>សកម្មភាពប្រវត្តិ</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php
                            $total_quantity = 0;
                            foreach ($_SESSION['report_data'] as $index => $item):
                                $total_quantity += ($_SESSION['report_type'] === 'stock_transfer' || $_SESSION['report_type'] === 'repair') ? 
                                    $item['quantity'] : $item['action_quantity'];
                            ?>
                                <tr>
                                    <td style="text-align:center;"><?= $index + 1 ?></td>
                                    <td style="text-align:center;"><?= $item['item_code'] ?></td>
                                    <td style="text-align:center;"><?= $item['category_name'] ?></td>
                                    <td style="text-align:center;"><?= $item['invoice_no'] ?></td>
                                    <td style="text-align:center;"><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                                    <td style="text-align:center;">
                                        <?= $_SESSION['report_type'] === 'repair' ? $item['item_name'] : $item['name'] ?>
                                    </td>
                                    
                                    <?php if ($_SESSION['report_type'] === 'stock_in' || $_SESSION['report_type'] === 'stock_out'): ?>
                                        <td style="text-align:center;"><?= $item['action_quantity'] ?></td>
                                        <td style="text-align:center;"><?= ucfirst($item['action_type']) ?></td>
                                    <?php else: ?>
                                        <td style="text-align:center;"><?= $item['quantity'] ?></td>
                                        
                                        <?php if ($_SESSION['report_type'] === 'repair'): ?>
                                            <td style="text-align:center;">
                                                <?= $item['action_type'] == 'send_for_repair' ? 'ផ្ញើរជួសជុល' : 'ត្រឡប់មកវិញ' ?>
                                            </td>
                                        <?php else: ?>
                                            <td style="text-align:center;"><?= $item['size'] ?></td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <td style="text-align:center;"><?= $item['size'] ?></td>
                                    
                                    <?php if ($_SESSION['report_type'] === 'stock_in' || $_SESSION['report_type'] === 'stock_out'): ?>
                                        <td style="text-align:center;"><?= $item['location_name'] ?></td>
                                    <?php else: ?>
                                        <td style="text-align:center;"><?= $item['from_location_name'] ?></td>
                                        <td style="text-align:center;"><?= $item['to_location_name'] ?></td>
                                    <?php endif; ?>
                                    
                                    <td style="text-align:center;"><?= $item['remark'] ?></td>
                                    <td style="text-align:center;"><?= $item['action_by_name'] ?></td>
                                    
                                    <?php if ($_SESSION['report_type'] === 'repair'): ?>
                                        <td style="text-align:center;"><?= $item['history_action'] ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="pdf-footer">
                        ថ្ងៃបង្កើតរបាយការណ៍: <?= date('d/m/Y H:i:s') ?> | ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ
                    </div>
                </div>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Report Generation Form -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h5 class="m-0 font-weight-bold text-primary"><?= t('generate_report') ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" id="reportForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="report_type" class="form-label"><?= t('report_type') ?></label>
                            <select class="form-select" id="report_type" name="report_type" required>
                                <option value=""><?= t('select_report_type') ?></option>
                                <option value="stock_in"><?= t('stock_in_report') ?></option>
                                <option value="stock_out"><?= t('stock_out_report') ?></option>
                                <option value="stock_transfer"><?= t('stock_transfer_report') ?></option>
                                <option value="repair"><?= t('repair_report') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="location_id" class="form-label"><?= t('location') ?></label>
                            <select class="form-select" id="location_id" name="location_id">
                                <option value=""><?= t('all_locations') ?></option>
                                <?php foreach ($all_locations as $location): ?>
                                    <option value="<?= $location['id'] ?>"><?= $location['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="period" class="form-label"><?= t('time_period') ?></label>
                            <select class="form-select" id="period" name="period" required>
                                <option value="monthly"><?= t('this_month') ?></option>
                                <option value="yearly"><?= t('this_year') ?></option>
                                <option value="custom"><?= t('custom_range') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6" id="custom_date_range" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="start_date" class="form-label"><?= t('start_date') ?></label>
                                    <input type="date" class="form-control" id="start_date" name="start_date">
                                </div>
                                <div class="col-md-6">
                                    <label for="end_date" class="form-label"><?= t('end_date') ?></label>
                                    <input type="date" class="form-control" id="end_date" name="end_date">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" name="preview_report" class="btn btn-primary">
                            <i class="fas fa-eye"></i> <?= t('preview') ?>
                        </button>
                        <button type="submit" name="generate_report" class="btn btn-success">
                            <i class="fas fa-download"></i> <?= t('download') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
    // Enhance mobile table scrolling
    function enhanceMobileTableScrolling() {
        const tableContainers = document.querySelectorAll('.pdf-table-container');
        
        tableContainers.forEach(container => {
            // Add scroll indicators for mobile
            if (window.innerWidth <= 768) {
                // Remove existing indicators
                const existingIndicators = container.querySelectorAll('.scroll-indicator-right, .scroll-indicator-left');
                existingIndicators.forEach(indicator => indicator.remove());
                
                // Add right scroll indicator
                const rightIndicator = document.createElement('div');
                rightIndicator.className = 'scroll-indicator-right';
                rightIndicator.style.cssText = `
                    position: absolute;
                    top: 0;
                    right: 0;
                    width: 20px;
                    height: 100%;
                    background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(0,0,0,0.1) 100%);
                    pointer-events: none;
                    z-index: 5;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                container.appendChild(rightIndicator);
                
                // Add left scroll indicator (hidden initially)
                const leftIndicator = document.createElement('div');
                leftIndicator.className = 'scroll-indicator-left';
                leftIndicator.style.cssText = `
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 20px;
                    height: 100%;
                    background: linear-gradient(270deg, rgba(255,255,255,0) 0%, rgba(0,0,0,0.1) 100%);
                    pointer-events: none;
                    z-index: 5;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                container.appendChild(leftIndicator);
                
                // Update indicators on scroll
                container.addEventListener('scroll', function() {
                    const scrollLeft = this.scrollLeft;
                    const scrollWidth = this.scrollWidth;
                    const clientWidth = this.clientWidth;
                    
                    // Show/hide indicators based on scroll position
                    rightIndicator.style.opacity = (scrollLeft + clientWidth < scrollWidth - 10) ? '1' : '0';
                    leftIndicator.style.opacity = (scrollLeft > 10) ? '1' : '0';
                });
                
                // Trigger initial check
                container.dispatchEvent(new Event('scroll'));
            }
        });
    }
    
    // Run on load and resize
    enhanceMobileTableScrolling();
    window.addEventListener('resize', enhanceMobileTableScrolling);
    
    // Add momentum scrolling for iOS
    document.querySelectorAll('.pdf-table-container').forEach(container => {
        container.style.webkitOverflowScrolling = 'touch';
    });
});
document.addEventListener('DOMContentLoaded', function() {
    // Handle period selection
    const periodSelect = document.getElementById('period');
    const customDateRange = document.getElementById('custom_date_range');
    
    if (periodSelect && customDateRange) {
        periodSelect.addEventListener('change', function() {
            if (this.value === 'custom') {
                customDateRange.style.display = 'block';
            } else {
                customDateRange.style.display = 'none';
            }
        });
    }
    
    // Form validation
    const reportForm = document.getElementById('reportForm');
    if (reportForm) {
        reportForm.addEventListener('submit', function(e) {
            const period = document.getElementById('period').value;
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            if (period === 'custom' && (!startDate.value || !endDate.value)) {
                e.preventDefault();
                alert('<?= t('please_select_both_start_and_end_dates') ?>');
                return false;
            }
            
            if (period === 'custom' && startDate.value && endDate.value) {
                const start = new Date(startDate.value);
                const end = new Date(endDate.value);
                
                if (start > end) {
                    e.preventDefault();
                    alert('<?= t('start_date_cannot_be_after_end_date') ?>');
                    return false;
                }
            }
        });
    }
    
    // Handle report type change to filter locations
    const reportTypeSelect = document.getElementById('report_type');
    const locationSelect = document.getElementById('location_id');
    const repairLocationIds = <?= json_encode($repair_location_ids) ?>;
    const nonRepairLocationIds = <?= json_encode($non_repair_location_ids) ?>;
    
    if (reportTypeSelect && locationSelect) {
        reportTypeSelect.addEventListener('change', function() {
            const reportType = this.value;
            const currentLocationValue = locationSelect.value;
            
            // Enable all options first
            Array.from(locationSelect.options).forEach(option => {
                if (option.value !== '') {
                    option.disabled = false;
                    option.style.display = '';
                }
            });
            
            // Filter based on report type
            if (reportType === 'repair') {
                // Only show repair locations
                Array.from(locationSelect.options).forEach(option => {
                    if (option.value !== '' && !repairLocationIds.includes(parseInt(option.value))) {
                        option.disabled = true;
                        option.style.display = 'none';
                    }
                });
            } else if (reportType === 'stock_transfer') {
                // Only show non-repair locations
                Array.from(locationSelect.options).forEach(option => {
                    if (option.value !== '' && !nonRepairLocationIds.includes(parseInt(option.value))) {
                        option.disabled = true;
                        option.style.display = 'none';
                    }
                });
            }
            
            // Reset selection if current selection is no longer valid
            if (currentLocationValue && locationSelect.options[locationSelect.selectedIndex].disabled) {
                locationSelect.value = '';
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
ob_end_flush();
?>