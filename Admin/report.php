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
        $no_record_report=t('no_record_report');
        if (empty($report_data)) {
            $_SESSION['error'] = "$no_record_report";
            header('Location: report.php');
            exit();
        }
        
        // If preview is requested, store data for modal display
       // In the POST handling section, replace the preview code:
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
    
    // Redirect to preview page instead of showing modal
    header('Location: report-preview.php');
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

// Function to generate report data - FIXED DATE COLUMN TO USE ACTUAL DATES
function generateReportData($pdo, $report_type, $location_id, $start_date, $end_date) {
    if ($report_type === 'stock_in') {
        // Calculate previous period correctly
        $prev_start_date = date('Y-m-01', strtotime($start_date . ' -1 month'));
        $prev_end_date = date('Y-m-t', strtotime($start_date . ' -1 month'));
        
        // Get all items with their beginning quantities (ending quantity from previous period)
        $query = "SELECT 
            i.id as item_id,
            i.item_code,
            i.name,
            i.category_id,
            c.name as category_name,
            i.size,
            i.location_id,
            l.name as location_name,
            i.remark,
            i.deporty_id,
            d.name as deporty_name,
            COALESCE((
                -- Calculate ending quantity for previous period using a simpler approach
                SELECT (COALESCE(SUM(CASE WHEN action_type IN ('new', 'add') THEN action_quantity ELSE 0 END), 0)
                       - COALESCE(SUM(CASE WHEN action_type = 'deduct' THEN action_quantity ELSE 0 END), 0)
                       - COALESCE(SUM(CASE WHEN action_type = 'broken' THEN action_quantity ELSE 0 END), 0))
                FROM (
                    SELECT 'in' as source, action_type, action_quantity, date, invoice_no 
                    FROM stock_in_history 
                    WHERE item_id = i.id AND location_id = i.location_id
                    AND date BETWEEN :prev_start_date AND :prev_end_date
                    UNION ALL
                    SELECT 'out' as source, action_type, action_quantity, date, NULL as invoice_no 
                    FROM stock_out_history 
                    WHERE item_id = i.id AND location_id = i.location_id
                    AND date BETWEEN :prev_start_date2 AND :prev_end_date2
                    UNION ALL
                    SELECT 'broken' as source, action_type, action_quantity, date, NULL as invoice_no 
                    FROM broken_items_history 
                    WHERE item_id = i.id AND location_id = i.location_id
                    AND date BETWEEN :prev_start_date3 AND :prev_end_date3
                ) combined_history
            ), 0) as beginning_quantity,
            -- Get the most recent invoice number for current period
            COALESCE((
                SELECT sih.invoice_no 
                FROM stock_in_history sih 
                WHERE sih.item_id = i.id 
                AND sih.location_id = i.location_id
                AND sih.date BETWEEN :start_date_search AND :end_date_search
                AND sih.invoice_no IS NOT NULL
                AND sih.invoice_no != ''
                ORDER BY sih.date DESC, sih.id DESC 
                LIMIT 1
            ), 'N/A') as invoice_no
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN deporty d ON i.deporty_id = d.id
        JOIN locations l ON i.location_id = l.id
        WHERE 1=1";
        
        $params = [
            ':prev_start_date' => $prev_start_date,
            ':prev_end_date' => $prev_end_date,
            ':prev_start_date2' => $prev_start_date,
            ':prev_end_date2' => $prev_end_date,
            ':prev_start_date3' => $prev_start_date,
            ':prev_end_date3' => $prev_end_date,
            ':start_date_search' => $start_date,
            ':end_date_search' => $end_date
        ];
        
        if ($location_id) {
            $query .= " AND i.location_id = :location_id";
            $params[':location_id'] = $location_id;
        }
        
        $query .= " ORDER BY i.name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For each item, calculate add, used, and broken quantities for current period
        $report_data = [];
        foreach ($items as $item) {
            $item_id = $item['item_id'];
            $item_location_id = $item['location_id'];
            
            // Ensure beginning quantity is never negative
            $beginning_quantity = max(0, $item['beginning_quantity']);
            
            // Get the most recent action date for this item in the period
            $date_query = "SELECT date, action_type 
            FROM (
                SELECT date, action_type FROM stock_in_history 
                WHERE item_id = :item_id 
                AND location_id = :location_id
                AND date BETWEEN :start_date AND :end_date
                AND action_type IN ('new', 'add')
                UNION ALL
                SELECT date, action_type FROM stock_out_history 
                WHERE item_id = :item_id2 
                AND location_id = :location_id2
                AND date BETWEEN :start_date2 AND :end_date2
                AND action_type = 'deduct'
                UNION ALL
                SELECT date, action_type FROM broken_items_history 
                WHERE item_id = :item_id3 
                AND location_id = :location_id3
                AND date BETWEEN :start_date3 AND :end_date3
                AND action_type = 'broken'
            ) as all_actions
            ORDER BY date DESC
            LIMIT 1";
            
            $date_stmt = $pdo->prepare($date_query);
            $date_stmt->execute([
                ':item_id' => $item_id,
                ':location_id' => $item_location_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':item_id2' => $item_id,
                ':location_id2' => $item_location_id,
                ':start_date2' => $start_date,
                ':end_date2' => $end_date,
                ':item_id3' => $item_id,
                ':location_id3' => $item_location_id,
                ':start_date3' => $start_date,
                ':end_date3' => $end_date
            ]);
            $date_result = $date_stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no action found in period, skip this item
            if (!$date_result) {
                continue;
            }
            
            $action_date = $date_result['date'];
            $action_type = $date_result['action_type'];
            
            // Calculate add quantity (from stock_in_history for current period)
            $add_query = "SELECT COALESCE(SUM(action_quantity), 0) as total_add
                FROM stock_in_history 
                WHERE item_id = :item_id 
                AND location_id = :location_id
                AND date BETWEEN :start_date AND :end_date
                AND action_type IN ('new', 'add')";
                
            $add_stmt = $pdo->prepare($add_query);
            $add_stmt->execute([
                ':item_id' => $item_id,
                ':location_id' => $item_location_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ]);
            $add_result = $add_stmt->fetch(PDO::FETCH_ASSOC);
            $add_quantity = $add_result['total_add'];
            
            // Calculate used quantity (from stock_out_history for current period)
            $used_query = "SELECT COALESCE(SUM(action_quantity), 0) as total_used
                FROM stock_out_history 
                WHERE item_id = :item_id 
                AND location_id = :location_id
                AND date BETWEEN :start_date AND :end_date
                AND action_type = 'deduct'";
                
            $used_stmt = $pdo->prepare($used_query);
            $used_stmt->execute([
                ':item_id' => $item_id,
                ':location_id' => $item_location_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ]);
            $used_result = $used_stmt->fetch(PDO::FETCH_ASSOC);
            $used_quantity = $used_result['total_used'];
            
            // Calculate broken quantity (from broken_items_history for current period)
            $broken_query = "SELECT COALESCE(SUM(action_quantity), 0) as total_broken
                FROM broken_items_history 
                WHERE item_id = :item_id 
                AND location_id = :location_id
                AND date BETWEEN :start_date AND :end_date
                AND action_type = 'broken'";
                
            $broken_stmt = $pdo->prepare($broken_query);
            $broken_stmt->execute([
                ':item_id' => $item_id,
                ':location_id' => $item_location_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ]);
            $broken_result = $broken_stmt->fetch(PDO::FETCH_ASSOC);
            $broken_quantity = $broken_result['total_broken'];
            
            // Calculate ending quantity (ensure it's never negative)
            $ending_quantity = max(0, $beginning_quantity + $add_quantity - $used_quantity - $broken_quantity);
            
            // Only include items with activity in the period
            if ($add_quantity > 0 || $used_quantity > 0 || $broken_quantity > 0 || $beginning_quantity > 0) {
                $report_data[] = [
                    'item_id' => $item_id,
                    'item_code' => $item['item_code'],
                    'name' => $item['name'],
                    'category_name' => $item['category_name'],
                    'size' => $item['size'],
                    'location_id' => $item_location_id,
                    'location_name' => $item['location_name'],
                    'remark' => $item['remark'],
                    'invoice_no' => $item['invoice_no'], // Add invoice number
                    'beginning_quantity' => $beginning_quantity,
                    'add_quantity' => $add_quantity,
                    'used_quantity' => $used_quantity,
                    'broken_quantity' => $broken_quantity,
                    'ending_quantity' => $ending_quantity,
                    'date' => $action_date,
                    'deporty_name' => $item['deporty_name'] // Add deporty name
                ];
            }
        }
        
        return $report_data;
    }
    
    return [];
}
// Function to generate Excel report
function generateExcelReport($report_type, $report_data, $start_date, $end_date, $location_id) {
    $filename = "";
    $title = "";
    $header_color = "";
    $stock_in_report=t('stock_in_report');

    if ($report_type === 'stock_in') {
        $filename = "stock_in_report_" . date('Ymd') . ".xls";
        $title = "$stock_in_report";
        $header_color = "#4e73df";
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

// Function to generate Excel content - UPDATED VERSION with alternating row colors
function generateExcelContent($report_type, $report_data, $start_date, $end_date, $location_id, $title, $header_color) {
    // Get location name
    $location_name = $location_id ? $report_data[0]['location_name'] : t('report_all_location');
    
    // Translations
    $report_title = t('reports');
    $site_location = t('site_location');
    $period_label = t('period');
    $no = t('item_no');
    $date = t('item_date');
    $item_code = t('item_code');
    $category = t('category');
    $invoice_no = t('invoice_no');
    $description = t('description');
    $unit = t('unit');
    $quantity = t('quantity');
    $beginning_period = t('beginning_period');
    $add = t('add');
    $used = t('used');
    $broken = t('broken');
    $ending_period = t('ending_period');
    $location_col = t('location_column');
    $remarks = t('remarks');
    $prepared_by = t('prepared_by');
    $checked_by = t('checked_by');
    $approved_by = t('approved_by');
    $name = t('name');
    $date_label = t('date');
    $total_label = t('total');
    $deporty = t('deporty');
    
    // Helper function to format numbers
    function formatQuantity($number) {
        if ($number >= 1000) {
            return number_format($number);
        }
        return $number;
    }
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="excel-format" content="excel-2007"> 
        <title>'.$title.'</title>
        <style>
            body { font-family: "Khmer OS Siemreap", sans-serif; }
            .report-title { 
                font-size: 18px; 
                font-weight: bold; 
                margin-bottom: 5px;
                text-align: left;
            }
            .report-info { 
                margin-bottom: 10px;
            }
            .info-line {
                margin-bottom: 3px;
            }
            table { 
                border-collapse: collapse; 
                width: 100%;
            }
            th { 
                background-color: #d9d9d9;
                padding: 5px;
                text-align: center;
                font-weight: bold;
                border: 1px solid #000;
                font-size: 12px;
            }
            td { 
                padding: 4px;
                border: 1px solid #000;
                vertical-align: middle;
                font-size: 16px;
            }
            .text-center { text-align: center; }
            .text-left { text-align: left; }
            .text-right { text-align: right; }
            .bold { font-weight: bold; }
            .no-border { border: none; }
            .signature-cell {
                text-align: center;
                padding: 10px;
                vertical-align: top;
                height: 100px;
            }
            .signature-line {
                margin-top: 40px;
                border-top: 1px solid #000;
                width: 80%;
                display: inline-block;
            }
            /* Add styles for alternating row colors */
            .row-odd {
                background-color:#83E28E;
                color: #ffffff;
            }
            .row-even {
                background-color: #ffffff;
                color: #000000;
            }
            /* Number formatting for Excel */
            .number-cell {
                mso-number-format:"#,##0";
                text-align: right;
                padding-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="report-title" style="text-align:center;">Reports</div>
        
        <div class="report-info">
            <div class="info-line"><span class="bold">Site Location:</span> '.$location_name.'</div>
            <div class="info-line"><span class="bold">Period:</span> '.date('d/m/Y', strtotime($start_date)).' - '.date('d/m/Y', strtotime($end_date)).'</div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th rowspan="2">No</th>
                    <th rowspan="2">Date</th>
                    <th rowspan="2">Item Code</th>
                    <th rowspan="2">Category</th>
                    <th rowspan="2">Invoice No</th>
                    <th rowspan="2">Description</th>
                    <th rowspan="2">Unit</th>
                    <th colspan="5">Quantity</th>
                    <th rowspan="2">Deporty</th>
                    <th rowspan="2">Location</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th>(Beginning <br> Period)</th>
                    <th>(Add)</th>
                    <th>(Used)</th>
                    <th>(Broken)</th>
                    <th>(Ending <br> Period)</th>
                </tr>
            </thead>
            <tbody>';
    
    // Initialize quantities
    $total_beginning = 0;
    $total_add = 0;
    $total_used = 0;
    $total_broken = 0;
    $total_ending = 0;
    
    foreach ($report_data as $index => $item) {
        // Determine row class for alternating colors
        $row_class = ($index % 2 == 0) ? 'row-even' : 'row-odd';
        
        // Get quantities from the query results
        $beginning = $item['beginning_quantity'];
        $add = $item['add_quantity'];
        $used = $item['used_quantity'];
        $broken = $item['broken_quantity'];
        $ending = $item['ending_quantity'];
        
        // Update totals
        $total_beginning += $beginning;
        $total_add += $add;
        $total_used += $used;
        $total_broken += $broken;
        $total_ending += $ending;
        
        echo '<tr class="'.$row_class.'">
                <td class="text-center">'.($index + 1).'</td>
                <td class="text-center">'.$item['date'].'</td>
                <td class="text-center">'.$item['item_code'].'</td>
                <td class="text-center">'.$item['category_name'].'</td>
                <td class="text-center" style="mso-number-format:\@">'.$item['invoice_no'].'</td>
                <td class="text-left">'.$item['name'].'</td>
                <td class="text-center">'.$item['size'].'</td>
                <td class="number-cell" style="text-align:center;">'.formatQuantity($beginning).'</td>
                <td class="number-cell" style="text-align:center;">'.formatQuantity($add).'</td>
                <td class="number-cell" style="text-align:center;">'.formatQuantity($used).'</td>
                <td class="number-cell" style="text-align:center;">'.formatQuantity($broken).'</td>
                <td class="number-cell" style="text-align:center;">'.formatQuantity($ending).'</td>
                <td class="text-center">'.($item['deporty_name'] ?: 'N/A').'</td>
                <td class="text-center">'.$item['location_name'].'</td>
                <td class="text-left">'.$item['remark'].'</td>
            </tr>';
    }
    
    // Add totals row (no special background color)
    echo '<tr class="bold">
            <td colspan="7" class="text-right">'.$total_label.':</td>
            <td class="number-cell">'.formatQuantity($total_beginning).'</td>
            <td class="number-cell">'.formatQuantity($total_add).'</td>
            <td class="number-cell">'.formatQuantity($total_used).'</td>
            <td class="number-cell">'.formatQuantity($total_broken).'</td>
            <td class="number-cell">'.formatQuantity($total_ending).'</td>
            <td colspan="3"></td>
        </tr>';
    
    echo '</tbody>
        </table>
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
        default: return '#36b9cc';
    }
}

function getHeaderTextColor($report_type) {
    switch ($report_type) {
        case 'stock_in': return '#2c3e50'; // Dark blue
        default: return 'white';
    }
}

function getRowColor($report_type, $is_even) {
    switch ($report_type) {
        case 'stock_in': return $is_even ? '#b1dcc8' : '#FAFAFA';
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
        padding: 0.5rem 1rem;
    }
    
    .navbar-brand {
        font-size: 1rem;
    }
    
    /* Button adjustments */
    .btn {
        padding: 0.375rem 0.75rem;
        font-size: 0.8rem;
    }
    
    /* Form adjustments */
    .form-control, .form-select {
        font-size: 0.8rem;
        padding: 0.375rem 0.5rem;
    }
    
    /* Card body padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Alert adjustments */
    .alert {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    /* Modal adjustments */
    .modal-content {
        margin: 0.5rem;
    }
    
    .modal-header, .modal-footer {
        padding: 0.75rem;
    }
    
    /* Badge adjustments */
    .badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
    }
}

</style>

<body>
<button id="sidebarToggle" class="btn btn-primary d-md-none rounded-circle mr-3 no-print" style="position: fixed; bottom: 20px; right: 20px; z-index: 1000; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
    <i class="bi bi-list"></i>
</button>
            
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h2 class="h3 mb-0 text-gray-800"><?php echo t('reports_button');?></h2>
    </div>

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

    <div class="card shadow mb-4">
        
        <div class="card-body">
            <form method="POST" id="reportForm">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="report_type" class="form-label"><?php echo t('report_type');?></label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="stock_in"><?php echo t('todays_stock_in');?></option>
                          
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="location_id" name="location_id">
                            <option value=""><?php echo t('report_all_location');?></option>
                            <?php foreach ($all_locations as $location): ?>
                                <option value="<?= $location['id'] ?>"><?= $location['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="period" class="form-label"><?php echo t('report_time');?></label>
                        <select class="form-select" id="period" name="period" required>
                            <option value="monthly" selected><?php echo t('report_month');?></option>
                            <option value="yearly"><?php echo t('report_year');?></option>
                            <option value="custom"><?php echo t('report_range');?></option>
                        </select>
                    </div>
                    <div class="col-md-3 custom-date" style="display: none;">
                        <label for="start_date" class="form-label"><?php echo t('report_from');?></label>
                        <input type="date" class="form-control" id="start_date" name="start_date">
                    </div>
                    <div class="col-md-3 custom-date" style="display: none;">
                        <label for="end_date" class="form-label"><?php echo t('report_to');?></label>
                        <input type="date" class="form-control" id="end_date" name="end_date">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <button type="submit" name="preview_report" class="btn btn-primary">
                            <i class="fas fa-eye me-2"></i><?php echo t('preview');?>
                        </button>
                        <button type="submit" name="generate_report" class="btn btn-success">
                            <i class="fas fa-download me-2"></i><?php echo t('download_excel');?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle period selection
        const periodSelect = document.getElementById('period');
        const customDateFields = document.querySelectorAll('.custom-date');
        
        periodSelect.addEventListener('change', function() {
            if (this.value === 'custom') {
                customDateFields.forEach(field => field.style.display = 'block');
            } else {
                customDateFields.forEach(field => field.style.display = 'none');
            }
        });
        
        // Handle form validation for custom dates
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            if (periodSelect.value === 'custom') {
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                if (!startDate || !endDate) {
                    e.preventDefault();
                    alert('សូមជ្រើសរើសថ្ងៃចាប់ផ្ដើម និងថ្ងៃបញ្ចប់សម្រាប់រយៈពេលផ្ទាល់ខ្លួន');
                }
            }
        });
        
     
        
        // Sidebar toggle functionality for mobile
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        
        if (sidebarToggle && sidebar && mainContent) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                mainContent.classList.toggle('show');
            });
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth < 768 && sidebar && mainContent) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = sidebarToggle.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    mainContent.classList.remove('show');
                }
            }
        });
    });
</script>
</body>

<?php
ob_end_flush();
?>