<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once 'translate.php';

if (!isAdmin()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard-staff.php');
    exit();
}
checkAuth();

// Check if report data exists in session
if (!isset($_SESSION['report_data']) || empty($_SESSION['report_data'])) {
    $_SESSION['error'] = "No report data found. Please generate a report first.";
    header('Location: report.php');
    exit();
}

// Get report data from session
$report_data = $_SESSION['report_data'];
$report_type = $_SESSION['report_type'];
$criteria = $_SESSION['report_criteria'];

// Get location name
$location_name = 'All Locations';
if ($criteria['location_id']) {
    if (isset($report_data[0]['location_name'])) {
        $location_name = $report_data[0]['location_name'];
    } elseif (isset($report_data[0]['from_location_name'])) {
        $location_name = $report_data[0]['from_location_name'];
        if (isset($report_data[0]['to_location_name'])) {
            $location_name .= ' to ' . $report_data[0]['to_location_name'];
        }
    }
}

// Handle download request
// Handle download request
if (isset($_POST['download'])) {
  // Instead of including report.php, redirect to report.php with download parameter
  header('Location: report.php?download=true');
  exit();
}

// Calculate totals
$total_beginning = 0;
$total_add = 0;
$total_transfer_in = 0;
$total_transfer_out = 0;
$total_used = 0;
$total_broken = 0;
$total_ending = 0;

foreach ($report_data as $item) {
    $total_beginning += $item['beginning_quantity'];
    $total_add += $item['add_quantity'];
    $total_transfer_in += $item['transfer_in_quantity'];
    $total_transfer_out += $item['transfer_out_quantity'];
    $total_used += $item['used_quantity'];
    $total_broken += $item['broken_quantity'];
    $total_ending += $item['ending_quantity'];
}

// Helper function to format numbers with decimal places
function formatQuantity($number) {
    // Format as float with 1 decimal place
    return number_format((int)$number);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('report_preview'); ?> - <?php echo t('stock_system'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        
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

        body {
            font-family: var(--font-family);
            background-color: var(--light);
            color: var(--dark);
            margin: 0;
         
        }

        .report-title { 
            font-size: 18px; 
            font-weight: bold; 
            margin-bottom: 5px;
            text-align: center;
        }
        
        .report-info { 
            margin-bottom: 10px;
            text-align: left;
        }
        
        .info-line {
            margin-bottom: 3px;
        }
        
        table { 
            border-collapse: collapse; 
            width: 100%;
            margin-bottom: 20px;
        }
        
        th { 
            background-color: #ffffff;
            padding: 5px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #000;
            font-size: 14px;
            color: #000000;
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
            background-color: #ffffff;
            color: #000000;
        }
        
        .row-even {
            background-color: #DDDDDD;
            color: #000000;
        }
        
        .number-cell {
            text-align: right;
            padding-right: 8px;
        }
        
        .action-buttons {
            margin-bottom: 20px;
        }
        
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
</head>
<body>
    <div class="container-fluid">
        <div class="action-buttons">
            <a href="report.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i><?php echo t('return'); ?>
            </a>
            <form method="POST" class="d-inline">
                <button type="submit" name="download" class="btn btn-success ms-2">
                    <i class="bi bi-download me-2"></i><?php echo t('download_excel'); ?>
                </button>
            </form>
        </div>

        <div class="report-title">Reports</div>
        
        <div class="report-info">
            <div class="info-line"><span class="bold">Site Location:</span> <?php echo $location_name; ?></div>
            <div class="info-line"><span class="bold">Period:</span> <?php echo date('d/m/Y', strtotime($criteria['start_date'])); ?> - <?php echo date('d/m/Y', strtotime($criteria['end_date'])); ?></div>
        </div>
        
        <table>
        <thead>
                                <tr>
                                    <th rowspan="2">No</th>
                                    <th rowspan="2" style="width:100px;">Item Code</th>
                                    <th rowspan="2" style="width:250px;">Category</th>
                                    <th rowspan="2"  style="width:80px;">Invoice No</th>
                                    <th rowspan="2"style="width:200px;" >Description</th>
                                    <th rowspan="2" style="width:90px;">Unit</th>
                                    <th colspan="7">Quantity</th>
                                    <th rowspan="2" style="width:150px;">Supplier</th>
                                    <th rowspan="2" style="width:150px;">Location</th>
                                    <th rowspan="2" style="width:100px;">Remark</th>
                                </tr>
                                <tr>
                                    <th style="width:100px;">(Beginning Period)</th>
                                    <th style="width:100px;">(Add)</th>
                                    <th style="width:100px;">(Transfer In)</th>
                                    <th style="width:100px;">(Transfer Out)</th>
                                    <th style="width:100px;">(Used)</th>
                                    <th style="width:100px;">(Broken)</th>
                                    <th style="width:100px;">(Ending Period)</th>
                                </tr>
                            </thead>
            <tbody>
                <?php foreach ($report_data as $index => $item): ?>
                    <?php 
                    // Determine row class for alternating colors
                    $row_class = ($index % 2 == 0) ? 'row-even' : 'row-odd';
                    
                    // Get quantities from the query results
                    $beginning = $item['beginning_quantity'];
                    $add = $item['add_quantity'];
                    $transfer_in = $item['transfer_in_quantity'];
                    $transfer_out = $item['transfer_out_quantity'];
                    $used = $item['used_quantity'];
                    $broken = $item['broken_quantity'];
                    $ending = $item['ending_quantity'];
                    ?>
                    
                    <tr class="<?php echo $row_class; ?>">
                    <td class="text-center"><?php echo $index + 1; ?></td>
                                        <td class="text-center"><?php echo $item['item_code']; ?></td>
                                        <td class="text-left"><?php echo $item['category_name']; ?></td>
                                        <td class="text-center"><?php echo $item['invoice_no']; ?></td>
                                        <td class="text-left"><?php echo $item['name']; ?></td>
                                        <td class="text-center"><?php echo $item['size']; ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($beginning); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($add); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($transfer_in); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($transfer_out); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($used); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($broken); ?></td>
                                        <td class="number-cell"><?php echo formatQuantity($ending); ?></td>
                                        <td class="text-center"><?php echo ($item['deporty_name'] ?: 'N/A'); ?></td>
                                        <td class="text-center"><?php echo $item['location_name']; ?></td>
                                        <td class="text-left"><?php echo $item['remark']; ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Totals row -->
                <tr class="bold">
                <td colspan="6" class="text-center" rowspan="2" style="background-color:yellow;font-size:22px;"><?php echo t('total'); ?>:</td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_beginning); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_add); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_transfer_in); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_transfer_out); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_used); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_broken); ?></td>
                                    <td class="number-cell" rowspan="2" style="background-color:yellow;"><?php echo formatQuantity($total_ending); ?></td>
                                    <td colspan="3" rowspan="2" style="background-color:yellow;"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
ob_end_flush();
?>