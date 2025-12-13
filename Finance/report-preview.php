<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header-finance.php';
require_once 'translate.php';

if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard-staff.php');
    exit();
}
checkAuth();

// Check if report data exists in session
if (!isset($_SESSION['report_data']) || empty($_SESSION['report_data'])) {
    $_SESSION['error'] = "No report data found. Please generate a report first.";
    if (isset($_SESSION['report_type']) && $_SESSION['report_type'] === 'invoice') {
        header('Location: report.php');
    } else {
        header('Location: report.php');
    }
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
    }
}

// Handle download request
if (isset($_POST['download'])) {
    if ($report_type === 'invoice') {
        header('Location: report.php?download=true');
    } else {
        header('Location: report.php?download=true');
    }
    exit();
}

// Calculate total for invoice reports
$grand_total = 0;
foreach ($report_data as $item) {
    $grand_total += $item['total'];
}

// Helper function to format currency
function formatCurrency($number) {
    return number_format($number, 2);
}

// Helper function to format date
function formatDate($date_string) {
    return date('d/m/Y', strtotime($date_string));
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
        
        /* Mobile-specific styles */
        @media (max-width: 576px) {
            .container-fluid {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            
            h2 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="action-buttons">
            <?php if ($report_type === 'invoice'): ?>
                <a href="report.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i><?php echo t('return'); ?>
                </a>
            <?php else: ?>
                <a href="report.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i><?php echo t('return'); ?>
                </a>
            <?php endif; ?>
            <form method="POST" class="d-inline">
                <button type="submit" name="download" class="btn btn-success ms-2">
                    <i class="bi bi-download me-2"></i><?php echo t('download_excel'); ?>
                </button>
            </form>
        </div>

        <div class="report-title"><?php echo t('invoice_report'); ?></div>
        
        <div class="report-info">
            <div class="info-line"><span class="bold"><?php echo "Site Location" ?>:</span> <?php echo $location_name; ?></div>
            <div class="info-line"><span class="bold"><?php echo "Period" ?>:</span> <?php echo date('d/m/Y', strtotime($criteria['start_date'])); ?> - <?php echo date('d/m/Y', strtotime($criteria['end_date'])); ?></div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Invoice No</th>
                    <th>Supplier</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $index => $item): ?>
                    <?php 
                    // Determine row class for alternating colors
                    $row_class = ($index % 2 == 0) ? 'row-even' : 'row-odd';
                    ?>
                    
                    <tr class="<?php echo $row_class; ?>">
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td class="text-center"><?php echo formatDate($item['date']); ?></td>
                        <td class="text-left"><?php echo $item['location_name']; ?></td>
                        <td class="text-center"><?php echo $item['invoice_no']; ?></td>
                        <td class="text-left"><?php echo $item['supplier_name']; ?></td>
                        <td class="number-cell">$<?php echo formatCurrency($item['total']); ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Totals row -->
                <tr class="bold">
                    <td colspan="5" class="text-right">Total  :</td>
                    <td class="number-cell" style="background-color:yellow;">$<?php echo formatCurrency($grand_total); ?></td>
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