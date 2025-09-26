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
if (isset($_POST['download'])) {
    require_once 'report.php'; // Include the original report file to access functions
    generateExcelReport($report_type, $report_data, $criteria['start_date'], $criteria['end_date'], $criteria['location_id']);
    exit();
}

// Calculate totals
$total_beginning = 0;
$total_add = 0;
$total_used = 0;
$total_broken = 0;
$total_ending = 0;

foreach ($report_data as $item) {
    $total_beginning += $item['beginning_quantity'];
    $total_add += $item['add_quantity'];
    $total_used += $item['used_quantity'];
    $total_broken += $item['broken_quantity'];
    $total_ending += $item['ending_quantity'];
}

// Helper function to format numbers (same as in report.php)
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
            padding: 20px;
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
        
        .container-fluid {
            max-width: 100%;
            padding: 20px;
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
                    <th rowspan="2">Item Code</th>
                    <th rowspan="2">Category</th>
                    <th rowspan="2">Invoice No</th>
                    <th rowspan="2">Description</th>
                    <th rowspan="2">Unit</th>
                    <th colspan="5">Quantity</th>
                    <th rowspan="2">Supplier</th>
                    <th rowspan="2">Location</th>
                    <th rowspan="2">Remark</th>
                </tr>
                <tr>
                    <th>(Beginning Period)</th>
                    <th>(Add)</th>
                    <th>(Used)</th>
                    <th>(Broken)</th>
                    <th>(Ending Period)</th>
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