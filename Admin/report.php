<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once 'translate.php';

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

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
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
    
    // Generate report data based on type
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
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($report_data)) {
            $_SESSION['error'] = "No stock in records found for the selected criteria";
            header('Location: report.php');
            exit();
        }
        
        $filename = "stock_in_report_" . date('Ymd') . ".xls";
    
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        ob_end_clean();
        
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <meta name="excel-format" content="excel-2007"> 
            <title>របាយការណ៍ទំនិញចូល</title>
            <style>
                body { font-family: "Khmer OS Siemreap", sans-serif; }
                .report-header { 
                    background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
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
                    border-left: 4px solid #4e73df;
                }
               table { 
            border-collapse: collapse; 
            width: 100%;
            border: 1px solid black;
        }
        th { 
            background-color: #f6c23e; /* Yellow header */
            color: #2c3e50;
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
                <div class="report-title">របាយការណ៍ទំនិញចូល</div>
                <div class="report-subtitle">ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ</div>
            </div>
            
            <div class="report-info">
                <p>ចាប់ពី: '.date('d/m/Y', strtotime($start_date)).' ដល់: '.date('d/m/Y', strtotime($end_date)).'</p>
                <p>ទីតាំង: '.($location_id ? $report_data[0]['location_name'] : 'ទីតាំងទាំងអស់').'</p>
            </div>
            
            <table>
                <thead style="background-color:#2ecc71">
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
                </thead>
                <tbody>';
        
        $total_quantity = 0;
        foreach ($report_data as $index => $item) {
          $total_quantity += $item['action_quantity'];
          $row_color = ($index % 2 === 0) ? '#b1dcc8' : '#FAFAFA';
          echo '
              <tr style="background-color: ' . $row_color . ';">
                  <td style="border: 1px solid black;text-align:center;">'.($index + 1).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['item_code'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['category_name'].'</td>
                 <td style="mso-number-format:\@; border: 1px solid black; text-align: center;">'.$item['invoice_no'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.date('d/m/Y', strtotime($item['date'])).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['name'].'</td>
                  
                  <td style="border: 1px solid black;text-align:center;">'.$item['action_quantity'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.ucfirst($item['action_type']).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['size'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['location_name'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['remark'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['action_by_name'].'</td>
              </tr>';
        }
        
        echo '
                  </tbody>
            </table>
            
            <div class="report-footer">
                ថ្ងៃបង្កើតរបាយការណ៍: '.date('d/m/Y H:i:s').' | ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ
            </div>
        </body>
        </html>';
        
        exit();
        
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
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($report_data)) {
            $_SESSION['error'] = "No stock out records found for the selected criteria";
            header('Location: report.php');
            exit();
        }
        
        $filename = "stock_out_report_" . date('Ymd') . ".xls";
    
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        ob_end_clean();
        
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <meta name="excel-format" content="excel-2007">
            <title>របាយការណ៍ទំនិញចេញ</title>
            <style>
                body { font-family: "Khmer OS Siemreap", sans-serif; }
                .report-header { 
                    background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%);
                    color: white;
                    padding: 20px;
                    border-radius: 8px 8px 0 0;
                    margin-bottom: 20px;
                    text-align: center;
                }
                .report-title { font-size: 24px; font-weight: bold; margin-bottom: 5px;color:black; }
                .report-subtitle { font-size: 16px; opacity: 0.9;color:black; }
                .report-info { 
                    background-color: #fdf3f2;
                    padding: 15px;
                    border-radius: 6px;
                    margin-bottom: 20px;
                    border-left: 4px solid #e74a3b;
                }
               table { 
            border-collapse: collapse; 
            width: 100%;
            border: 1px solid black;
        }
        th { 
            background-color: #36b9cc;
            color: white;
            padding: 12px 8px;
            text-align: center;
            vertical-align:center;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            border: 1px solid black;
        }
        td { 
            padding: 10px 8px;
            border: 1px solid black;
            vertical-align: middle;
            text-align:center;
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
                <div class="report-title">របាយការណ៍ទំនិញចេញ</div>
                <div class="report-subtitle">ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ</div>
            </div>
            
            <div class="report-info">
                <p>ចាប់ពី: '.date('d/m/Y', strtotime($start_date)).' ដល់: '.date('d/m/Y', strtotime($end_date)).'</p>
                <p>ទីតាំង: '.($location_id ? $report_data[0]['location_name'] : 'ទីតាំងទាំងអស់').'</p>
            </div>
            
            <table>
                <thead>
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
                </thead>
                <tbody>';
        
        $total_quantity = 0;
        foreach ($report_data as $index => $item) {
          $total_quantity += $item['action_quantity'];
          $row_color = ($index % 2 === 0) ? '#b1dcc8' : '#FAFAFA';
          echo '
              <tr style="background-color: ' . $row_color . ';">
                  <td style="border: 1px solid black;text-align:center;">'.($index + 1).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['item_code'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['category_name'].'</td>
                  <td style="mso-number-format:\@; border: 1px solid black; text-align: center;">'.$item['invoice_no'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.date('d/m/Y', strtotime($item['date'])).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['name'].'</td>
                  
                  <td style="border: 1px solid black;text-align:center;">'.$item['action_quantity'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.ucfirst($item['action_type']).'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['size'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['location_name'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['remark'].'</td>
                  <td style="border: 1px solid black;text-align:center;">'.$item['action_by_name'].'</td>
              </tr>';
        }
        
        echo '
                   
                </tbody>
            </table>
            
            <div class="report-footer">
                ថ្ងៃបង្កើតរបាយការណ៍: '.date('d/m/Y H:i:s').' | ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ
            </div>
        </body>
        </html>';
        
        exit();
    }elseif ($report_type === 'stock_transfer') {
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
      $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
      
      if (empty($report_data)) {
          $_SESSION['error'] = "No stock transfer records found for the selected criteria";
          header('Location: report.php');
          exit();
      }
      
      $filename = "stock_transfer_report_" . date('Ymd') . ".xls";
  
      // Set headers for Excel download
      header('Content-Type: application/vnd.ms-excel');
      header('Content-Disposition: attachment; filename="'.$filename.'"');
      header('Pragma: no-cache');
      header('Expires: 0');
      
      ob_end_clean();
      
      echo '<!DOCTYPE html>
      <html>
      <head>
          <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
          <meta name="excel-format" content="excel-2007">
          <title>របាយការណ៍ផ្ទេរទំនិញ</title>
          <style>
              body { font-family: "Khmer OS Siemreap", sans-serif; }
              .report-header { 
                  background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
                  color: white;
                  padding: 20px;
                  border-radius: 8px 8px 0 0;
                  margin-bottom: 20px;
                  text-align: center;
              }
              .report-title { font-size: 24px; font-weight: bold; margin-bottom: 5px;color:black; }
              .report-subtitle { font-size: 16px; opacity: 0.9;color:black; }
              .report-info { 
                  background-color: #f0f9f5;
                  padding: 15px;
                  border-radius: 6px;
                  margin-bottom: 20px;
                  border-left: 4px solid #1cc88a;
              }
              table { 
                  border-collapse: collapse; 
                  width: 100%;
                  border: 1px solid black;
              }
              th { 
                  background-color: #36b9cc;
                  color: white;
                  padding: 12px 8px;
                  text-align: center;
                  vertical-align:center;
                  font-weight: 600;
                  text-transform: uppercase;
                  font-size: 12px;
                  border: 1px solid black;
              }
              td { 
                  padding: 10px 8px;
                  border: 1px solid black;
                  vertical-align: middle;
                  text-align:center;
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
              <div class="report-title">របាយការណ៍ផ្ទេរទំនិញ</div>
              <div class="report-subtitle">ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ</div>
          </div>
          
          <div class="report-info">
              <p>ចាប់ពី: '.date('d/m/Y', strtotime($start_date)).' ដល់: '.date('d/m/Y', strtotime($end_date)).'</p>
              <p>ទីតាំង: '.($location_id ? $report_data[0]['from_location_name'] . ' ទៅ ' . $report_data[0]['to_location_name'] : 'ទីតាំងទាំងអស់').'</p>
          </div>
          
          <table>
              <thead>
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
              </thead>
              <tbody>';
      
      $total_quantity = 0;
      foreach ($report_data as $index => $item) {
        $total_quantity += $item['quantity'];
        $row_color = ($index % 2 === 0) ? '#e8f4f0' : '#FAFAFA';
        echo '
            <tr style="background-color: ' . $row_color . ';">
                <td style="border: 1px solid black;text-align:center;">'.($index + 1).'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['item_code'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['category_name'].'</td>
                <td style="mso-number-format:\@; border: 1px solid black; text-align: center;">'.$item['invoice_no'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.date('d/m/Y', strtotime($item['date'])).'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['name'].'</td>
                
                <td style="border: 1px solid black;text-align:center;">'.$item['quantity'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['size'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['from_location_name'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['to_location_name'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['remark'].'</td>
                <td style="border: 1px solid black;text-align:center;">'.$item['action_by_name'].'</td>
            </tr>';
      }
      
      echo '
              </tbody>
          </table>
          
          <div class="report-footer">
              ថ្ងៃបង្កើតរបាយការណ៍: '.date('d/m/Y H:i:s').' | ប្រព័ន្ធគ្រប់គ្រងស្តុកទំនិញ
          </div>
      </body>
      </html>';
      
      exit();
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
  --gray-dark: #7b7d8a;
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
  border-radius: 0.35rem;
  box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
  margin-bottom: 1.5rem;
}

.card-header {
  background-color: var(--white);
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
  padding: 1rem 1.35rem;
  font-weight: 600;
  border-radius: 0.35rem 0.35rem 0 0 !important;
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
        padding: 0.75rem;
    }
    
    .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }
}
@media (max-width: 768px) {
    /* Make table display as cards on mobile */
    .table-responsive table, 
    .table-responsive thead, 
    .table-responsive tbody, 
    .table-responsive th, 
    .table-responsive td, 
    .table-responsive tr { 
        display: block; 
        width: 100%;
    }
    
    /* Hide table headers */
    .table-responsive thead tr { 
        position: absolute;
        top: -9999px;
        left: -9999px;
    }
    
    .table-responsive tr {
        margin-bottom: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 0.35rem;
        box-shadow: 0 0.15rem 0.75rem rgba(0, 0, 0, 0.1);
    }
    
    .table-responsive td {
        /* Behave like a row */
        border: none;
        position: relative;
        padding-left: 50%;
        white-space: normal;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    .table-responsive td:before {
        /* Now like a table header */
        position: absolute;
        top: 0.75rem;
        left: 0.75rem;
        width: 45%; 
        padding-right: 1rem; 
        white-space: nowrap;
        font-weight: bold;
        content: attr(data-label);
    }
    
    /* Remove bottom border from last td */
    .table-responsive td:last-child {
        border-bottom: none;
    }
}

@media (max-width: 576px) {
    /* Make table cells more compact */
    .table-responsive td {
        padding-left: 45%;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        font-size: 0.9rem;
    }
    
    .table-responsive td:before {
        font-size: 0.85rem;
        top: 0.5rem;
    }
}

/* Filter section styles */
.filter-section {
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.5rem;
}

@media (max-width: 768px) {
    .filter-group {
        min-width: 100%;
    }
}
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('reports_button');?></h2>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><?php echo t('report_info');?></h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="report_type" class="form-label"><?php echo t('report_type');?></label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="stock_in"><?php echo t('stock_in_history');?></option>
                            <option value="stock_out"><?php echo t('stock_out_history');?></option>
                            <option value="stock_transfer"><?php echo t('stock_transfer_history');?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="location_id" name="location_id">
                            <option value=""><?php echo t('report_all_location');?></option>
                            <!-- Locations will be populated by JavaScript based on report type -->
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="period" class="form-label"><?php echo t('report_time');?></label>
                        <select class="form-select" id="period" name="period" required>
                            <option value="monthly"><?php echo t('report_month');?></option>
                            <option value="yearly"><?php echo t('report_year');?></option>
                            <option value="custom"><?php echo t('report_range');?></option>
                        </select>
                    </div>
                </div>
                
                <div class="row mb-3" id="custom_date_range" style="display: none;">
                    <div class="col-md-6">
                        <label for="start_date" class="form-label"><?php echo t('report_from');?></label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="end_date" class="form-label"><?php echo t('report_to');?></label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <div class="text-center">
                    <button type="submit" name="generate_report" class="btn btn-primary">
                        <i class="bi bi-file-earmark-arrow-down"></i> <?php echo t('report_info');?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Show/hide custom date range based on period selection
document.getElementById('period').addEventListener('change', function() {
    const customDateRange = document.getElementById('custom_date_range');
    
    if (this.value === 'custom') {
        customDateRange.style.display = 'flex';
    } else {
        customDateRange.style.display = 'none';
    }
});

// Set default dates for custom range
const today = new Date().toISOString().split('T')[0];
document.getElementById('start_date').value = today;
document.getElementById('end_date').value = today;

// Function to populate location dropdown based on report type
function populateLocations(reportType) {
    const locationSelect = document.getElementById('location_id');
    
    // Clear existing options except the first one
    while (locationSelect.options.length > 1) {
        locationSelect.remove(1);
    }
    
    // Get locations based on report type
    let locations = [];
    if (reportType === 'stock_in') {
        locations = <?php echo json_encode($non_repair_locations); ?>;
    } else if (reportType === 'stock_out') {
        locations = <?php echo json_encode($repair_locations); ?>;
    } else if (reportType === 'stock_transfer') {
        locations = <?php echo json_encode($all_locations); ?>;
    }
    
    // Add locations to dropdown
    locations.forEach(location => {
        const option = document.createElement('option');
        option.value = location.id;
        option.textContent = location.name;
        locationSelect.appendChild(option);
    });
}

// Initial population of locations based on default report type
populateLocations(document.getElementById('report_type').value);

// Update locations when report type changes
document.getElementById('report_type').addEventListener('change', function() {
    populateLocations(this.value);
});
</script>

<?php
require_once '../includes/footer.php';
?>