<?php

ob_start();
require_once '../includes/header-finance.php';
// Add authentication check
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'translate.php';
// Check if user is authenticated
checkAuth();
// Check if user has permission (admin or finance staff only)
if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php'); // Redirect to login or home page
    exit();
}

// Get locations for dropdown
$location_stmt = $pdo->query("SELECT * FROM finance_location ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set default dates to current month
$default_start_date = date('Y-m-01'); // First day of current month
$default_end_date = date('Y-m-t'); // Last day of current month

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_report']) || isset($_POST['preview_report'])) {
        $report_type = 'invoice';
        $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $start_date = sanitizeInput($_POST['start_date']);
        $end_date = sanitizeInput($_POST['end_date']);
        
        // Validate dates
        if (empty($start_date) || empty($end_date)) {
            $_SESSION['error'] = "Please select both start and end dates";
            header('Location: report.php');
            exit();
        }
        
        // Store report criteria in session for preview/download
        $_SESSION['report_criteria'] = [
            'report_type' => $report_type,
            'location_id' => $location_id,
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
        
        // If preview is requested, store data for preview
        if (isset($_POST['preview_report'])) {
            // Store report data in session for preview
            $_SESSION['report_data'] = $report_data;
            $_SESSION['report_type'] = $report_type;
            
            // Get location name safely
            $location_name = 'All Locations';
            if ($location_id) {
                if (isset($report_data[0]['location_name'])) {
                    $location_name = $report_data[0]['location_name'];
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

// Function to generate report data
function generateReportData($pdo, $report_type, $location_id, $start_date, $end_date) {
    if ($report_type === 'invoice') {
        // Build query based on location filter
        if ($location_id) {
            // Detailed report for specific location
            $query = "SELECT 
                fi.id,
                fi.receipt_no as invoice_no,
                fi.date,
                fl.name as location_name,
                fs.name as supplier_name,
                fi.total
            FROM 
                finance_invoice fi
            LEFT JOIN 
                locations fl ON fi.location = fl.id
            LEFT JOIN 
                deporty fs ON fi.supplier = fs.id
            WHERE 
                fi.location = :location_id
                AND fi.date BETWEEN :start_date AND :end_date
            ORDER BY 
                fi.date DESC, fi.receipt_no";
            
            $params = [
                ':location_id' => $location_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
        } else {
            // Summary report for all locations
            $query = "SELECT 
                fi.id,
                fi.receipt_no as invoice_no,
                fi.date,
                fl.name as location_name,
                fs.name as supplier_name,
                fi.total
            FROM 
                finance_invoice fi
            LEFT JOIN 
                locations fl ON fi.location = fl.id
            LEFT JOIN 
                deporty fs ON fi.supplier = fs.id
            WHERE 
                fi.date BETWEEN :start_date AND :end_date
            ORDER BY 
                fl.name, fi.date DESC, fi.receipt_no";
            
            $params = [
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
        }
        
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

// Function to generate Excel report
function generateExcelReport($report_type, $report_data, $start_date, $end_date, $location_id) {
    $filename = "Invoice_Report_" . date('d_m_Y') . ".xls";
    $title = "Invoice Report";
    $header_color = "#0d6efd";
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    ob_end_clean();
    
    // Generate the Excel file content
    generateExcelContent($report_type, $report_data, $start_date, $end_date, $location_id, $title, $header_color);
}

// Function to generate Excel content - UPDATED VERSION with Date column
function generateExcelContent($report_type, $report_data, $start_date, $end_date, $location_id, $title, $header_color) {
    // Get location name
    $location_name = $location_id ? $report_data[0]['location_name'] : 'All Locations';
    
    // Translations
    $report_title = t('reports_button');
    $site_location = t('site_location');
    $period_label = t('period');
    $no = t('no');
    $date = t('date');
    $invoice_no = t('invoice_no');
    $supplier = t('supplier');
    $total = t('total');
    $location_col = t('location');
    $total_label = t('total');
    
    // Helper function to format numbers
    function formatQuantity($number) {
        return number_format($number, 2);
    }
    
    // Helper function to format date
    function formatDate($date_string) {
        return date('d/m/Y', strtotime($date_string));
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
            /* Add styles for alternating row colors */
            .row-odd {
                background-color:#ffffff;
                color: #000000;
            }
            .row-even {
                background-color: #DDDDDD;
                color: #000000;
            }
            /* Number formatting for Excel */
            .number-cell {
                mso-number-format:"#,##0.00";
                text-align: right;
                padding-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="report-title" style="text-align:center;">'.$report_title.'</div>
        
        <div class="report-info">
            <div class="info-line"><span class="bold">'.$site_location.':</span> '.$location_name.'</div>
            <div class="info-line"><span class="bold">'.$period_label.':</span> '.date('d/m/Y', strtotime($start_date)).' - '.date('d/m/Y', strtotime($end_date)).'</div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>'.$no.'</th>
                    <th>'.$date.'</th>
                    <th>'.$location_col.'</th>
                    <th>'.$invoice_no.'</th>
                    <th>'.$supplier.'</th>
                    <th>'.$total.'</th>
                </tr>
            </thead>
            <tbody>';
    
    // Initialize total
    $grand_total = 0;
    
    foreach ($report_data as $index => $item) {
        // Determine row class for alternating colors
        $row_class = ($index % 2 == 0) ? 'row-even' : 'row-odd';
        
        // Get data
        $invoice_number = $item['invoice_no'];
        $date_value = $item['date'];
        $location = $item['location_name'];
        $supplier_name = $item['supplier_name'];
        $item_total = $item['total'];
        
        // Update grand total
        $grand_total += $item_total;

        echo '<tr class="'.$row_class.'">
            <td class="text-center">'.($index + 1).'</td>
            <td class="text-center" style="mso-number-format:\@">'.formatDate($date_value).'</td>
            <td class="text-left">'.$location.'</td>
            <td class="text-center" style="mso-number-format:\@">'.$invoice_number.'</td>
            <td class="text-left">'.$supplier_name.'</td>
            <td class="number-cell">$'.formatQuantity($item_total).'</td>
        </tr>';
    }
    
    // Add totals row
    echo '<tr class="bold">
        <td colspan="5" class="text-right">'.$total_label.':</td>
        <td class="number-cell" style="background-color:yellow;">$'.formatQuantity($grand_total).'</td>
    </tr>';
    
    echo '</tbody>
        </table>
    </body>
    </html>';
}
?>

<style>
    :root {
  --primary: #0d6efd;
  --primary-dark: #0d6efd;
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
  .container-fluid {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
  }
  
  .card-header h5 {
    font-size: 1rem;
  }
  
  .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  
  .table th, .table td {
    padding: 0.5rem;
    font-size: 0.8rem;
  }
  
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
  
  h2 {
    font-size: 1.25rem;
  }
  
  .main-content {
    width: 100%;
    margin-left: 0;
  }
  
  .sidebar {
    margin-left: -220px;
    position: fixed;
    z-index: 1040;
  }
  
  .sidebar.show {
    margin-left: 0;
  }
  
  .navbar {
    padding: 0.5rem 1rem;
  }
  
  .navbar-brand {
    font-size: 1rem;
  }
  
  .btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.8rem;
  }
  
  .form-control, .form-select {
    font-size: 0.8rem;
    padding: 0.375rem 0.5rem;
  }
  
  .card-body {
    padding: 1rem;
  }
  
  .alert {
    font-size: 0.8rem;
    padding: 0.75rem;
  }
  
  .modal-content {
    margin: 0.5rem;
  }
  
  .modal-header, .modal-footer {
    padding: 0.75rem;
  }
  
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
        <h2 class="h3 mb-0 text-gray-800"><?php echo t('reports_button'); ?></h2>
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
                    <div class="col-md-4">
                        <label for="location_id" class="form-label"><?php echo t('location'); ?></label>
                        <select class="form-select" id="location_id" name="location_id">
                            <option value=""><?php echo t('all_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= $location['id'] ?>"><?= $location['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="start_date" class="form-label"><?php echo t('report_from'); ?></label>
                        <input type="date" class="form-control" id="start_date" name="start_date" required 
                               value="<?php echo $default_start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label"><?php echo t('report_to'); ?></label>
                        <input type="date" class="form-control" id="end_date" name="end_date" required 
                               value="<?php echo $default_end_date; ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <button type="submit" name="preview_report" class="btn btn-primary">
                            <i class="fas fa-eye me-2"></i><?php echo t('preview'); ?>
                        </button>
                        <button type="submit" name="generate_report" class="btn btn-success">
                            <i class="fas fa-download me-2"></i><?php echo t('download_excel'); ?>
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
        // Handle form validation for custom dates
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (!startDate || !endDate) {
                e.preventDefault();
                alert('Please select both start and end dates');
                return false;
            }
            
            // Validate that end date is not before start date
            if (new Date(endDate) < new Date(startDate)) {
                e.preventDefault();
                alert('End date cannot be earlier than start date');
                return false;
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