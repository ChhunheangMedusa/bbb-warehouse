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
        }

        .report-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .report-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .report-info {
            background-color: #f8f9fc;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }

        .table-card {
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .table-card-header {
            background-color: var(--light);
            padding: 15px 20px;
            border-bottom: 1px solid #e3e6f0;
            font-weight: 600;
            color: var(--dark);
        }

        .table-card-body {
            padding: 0;
        }

        .table th {
            background-color: var(--light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 12px 15px;
        }

        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }

        .action-buttons {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
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

                <div class="report-header">
                    <div class="report-title"><?php echo t('report_preview'); ?></div>
                    <div class="report-subtitle"><?php echo t('stock_system'); ?></div>
                </div>

                <div class="report-info">
                    <p><strong><?php echo t('report_type'); ?>:</strong> 
                        <?php 
                        switch($report_type) {
                            case 'stock_in': echo t('todays_stock_in'); break;
                            case 'stock_out': echo t('todays_stock_out'); break;
                            case 'stock_transfer': echo t('todays_transfers'); break;
                            case 'repair': echo t('todays_repair_records'); break;
                        }
                        ?>
                    </p>
                    <p><strong><?php echo t('report_froms'); ?>:</strong> <?php echo date('d/m/Y', strtotime($criteria['start_date'])); ?></p>
                    <p><strong><?php echo t('report_tos'); ?>:</strong> <?php echo date('d/m/Y', strtotime($criteria['end_date'])); ?></p>
                    <p><strong><?php echo t('location_column'); ?>:</strong> <?php echo $location_name; ?></p>
                </div>

                <!-- White background card for the table -->
                <div class="table-card">
    <div class="table-card-header">
        <?php 
        switch($report_type) {
            case 'stock_in': echo t('todays_stock_in'); break;
            case 'stock_out': echo t('todays_stock_out'); break;
            case 'stock_transfer': echo t('todays_transfers'); break;
            case 'repair': echo t('todays_repair_records'); break;
        }
        ?> 
    </div>
    <div class="table-card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead>
                    <tr>
                        <?php 
                        $no = t('item_no');
                        $code = t('item_code');
                        $category = t('category');
                        $name = t('item_name');
                        $size = t('item_size');
                        $location = t('item_location');
                        $remark = t('item_remark');
                        $beginning = t('beginning_period');
                        $add = t('add');
                        $used = t('used');
                        $broken = t('broken');
                        $ending = t('ending_period');
                        $date_col = t('item_date');
                        
                        if ($report_type === 'stock_in'): ?>
                            <th><?= $no ?></th>
                            <th><?= $code ?></th>
                            <th><?= $category ?></th>
                            <th><?= $name ?></th>
                            <th><?= $size ?></th>
                            <th><?= $location ?></th>
                            <th><?= $beginning ?></th>
                            <th><?= $add ?></th>
                            <th><?= $used ?></th>
                            <th><?= $broken ?></th>
                            <th><?= $ending ?></th>
                            <th><?= $remark ?></th>
                            <th><?= $date_col ?></th>
                        <?php elseif ($report_type === 'stock_out'): ?>
                            <!-- Add stock_out columns if needed -->
                        <?php elseif ($report_type === 'stock_transfer'): ?>
                            <!-- Add stock_transfer columns if needed -->
                        <?php elseif ($report_type === 'repair'): ?>
                            <!-- Add repair columns if needed -->
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $index => $item): ?>
                        <tr>
                            <?php if ($report_type === 'stock_in'): ?>
                                <td><?= $index + 1 ?></td>
                                <td><?= $item['item_code'] ?></td>
                                <td><?= $item['category_name'] ?></td>
                                <td><?= $item['name'] ?></td>
                                <td><?= $item['size'] ?></td>
                                <td><?= $item['location_name'] ?></td>
                                <td><?= $item['beginning_quantity'] ?></td>
                                <td><?= $item['add_quantity'] ?></td>
                                <td><?= $item['used_quantity'] ?></td>
                                <td><?= $item['broken_quantity'] ?></td>
                                <td><?= $item['ending_quantity'] ?></td>
                                <td><?= $item['remark'] ?></td>
                                <td><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                            <?php elseif ($report_type === 'stock_out'): ?>
                                <!-- Add stock_out data if needed -->
                            <?php elseif ($report_type === 'stock_transfer'): ?>
                                <!-- Add stock_transfer data if needed -->
                            <?php elseif ($report_type === 'repair'): ?>
                                <!-- Add repair data if needed -->
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                <!-- End of white background card -->
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
ob_end_flush();
?>