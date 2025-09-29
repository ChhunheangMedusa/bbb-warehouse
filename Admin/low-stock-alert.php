<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once  'translate.php'; 
if (!isAdmin()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard-staff.php');
  exit();
}
checkAuth();

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : 0;
$month_filter = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year_filter = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'quantity_asc';

// Get all locations for filter dropdown
$location_stmt = $pdo->query("SELECT id, name FROM locations WHERE type != 'repair'  ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query with filters
$query = "SELECT i.id, i.name, i.quantity, i.size, l.name as location, i.created_at 
          FROM items i 
          JOIN locations l ON i.location_id = l.id 
          WHERE i.quantity < 10 and  type != 'repair'";

$count_query = "SELECT COUNT(*) as total 
                FROM items i 
                JOIN locations l ON i.location_id = l.id 
                WHERE i.quantity < 10";

$params = [];
$conditions = [];

// Add search filter
if (!empty($search)) {
    $conditions[] = "(i.name LIKE :search OR i.size LIKE :search)";
    $params[':search'] = "%$search%";
}

// Add location filter
if ($location_filter > 0) {
    $conditions[] = "i.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

// Add month filter
if ($month_filter > 0) {
    $conditions[] = "MONTH(i.created_at) = :month";
    $params[':month'] = $month_filter;
}

// Add year filter
if ($year_filter > 0) {
    $conditions[] = "YEAR(i.created_at) = :year";
    $params[':year'] = $year_filter;
}

// Add conditions to query
if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
    $count_query .= " AND " . implode(" AND ", $conditions);
}

// Add sorting
switch ($sort_by) {
    case 'name_asc':
        $query .= " ORDER BY i.name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY i.name DESC";
        break;
    case 'quantity_asc':
        $query .= " ORDER BY i.quantity ASC";
        break;
    case 'quantity_desc':
        $query .= " ORDER BY i.quantity DESC";
        break;
    case 'location_asc':
        $query .= " ORDER BY l.name ASC";
        break;
    case 'location_desc':
        $query .= " ORDER BY l.name DESC";
        break;
    case 'date_asc':
        $query .= " ORDER BY i.created_at ASC";
        break;
    case 'date_desc':
        $query .= " ORDER BY i.created_at DESC";
        break;
    default:
        $query .= " ORDER BY i.quantity ASC";
}
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}
// Pagination settings
$records_per_page = $per_page;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Get total count
$count_stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";

// Get filtered low stock items
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark alerts as read
if (isAdmin()) {
    $pdo->query("UPDATE low_stock_alerts SET notified = 1");
}

// Get distinct years for filter
$year_stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) as year FROM items WHERE YEAR(created_at) IS NOT NULL ORDER BY year DESC");
$years = $year_stmt->fetchAll(PDO::FETCH_ASSOC);
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
.entries-per-page {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    padding: 0.5rem;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    justify-content: flex-end; /* Changed from default to push to right */
    margin-left: auto; /* Push to the right side */
    width: fit-content; /* Only take needed width */
}

.entries-per-page label {
    margin-bottom: 0;
    margin-right: 0.5rem;
    font-weight: 500;
    color: #5a5c69;
}

.entries-per-page select {
    width: auto;
    min-width: 70px;
    margin: 0 0.5rem;
}
</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('low_stock_button');?></h2>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options');?></h5>
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('names');?></label>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search');?>" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('location');?></label>
                        <select name="location" class="form-select">
                            <option value="0"><?php echo t('report_all_location');?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    
                   
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('sort');?></label>
                        <select name="sort" class="form-select">
                            <option value="quantity_asc" <?php echo $sort_by == 'quantity_asc' ? 'selected' : ''; ?>>
                                <?php echo t('quantity_low_to_high');?>
                            </option>
                            <option value="quantity_desc" <?php echo $sort_by == 'quantity_desc' ? 'selected' : ''; ?>>
                                <?php echo t('quantity_high_to_low');?>
                            </option>
                            <option value="name_asc" <?php echo $sort_by == 'name_asc' ? 'selected' : ''; ?>>
                                <?php echo t('names');?>
                            </option>
                            <option value="date_asc" <?php echo $sort_by == 'date_asc' ? 'selected' : ''; ?>>
                                <?php echo t('date_oldest_first');?>
                            </option>
                            <option value="date_desc" <?php echo $sort_by == 'date_desc' ? 'selected' : ''; ?>>
                                <?php echo t('date_newest_first');?>
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">
                     <?php echo t('search'); ?>
                    </button>
                    <a href="low-stock-alert.php" class="btn btn-secondary">
                     <?php echo t('reset'); ?>
                    </a>
                </div>
                
                <!-- Keep page parameter for pagination -->
                <input type="hidden" name="page" value="1">
            </form>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><?php echo t('list_low_stock');?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($search) || $location_filter > 0 || $month_filter > 0 || $year_filter > 0): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo t('showing_filtered_results');?>
                    <?php if (!empty($search)): ?>
                        <span class="badge bg-secondary"><?php echo t('search');?>: <?php echo htmlspecialchars($search); ?></span>
                    <?php endif; ?>
                    <?php if ($location_filter > 0): 
                        $location_name = '';
                        foreach ($locations as $loc) {
                            if ($loc['id'] == $location_filter) {
                                $location_name = $loc['name'];
                                break;
                            }
                        }
                    ?>
                        <span class="badge bg-secondary"><?php echo t('location');?>: <?php echo htmlspecialchars($location_name); ?></span>
                    <?php endif; ?>
                    <?php if ($month_filter > 0): ?>
                        <span class="badge bg-secondary"><?php echo t('month');?>: <?php echo $months[$month_filter]; ?></span>
                    <?php endif; ?>
                    <?php if ($year_filter > 0): ?>
                        <span class="badge bg-secondary"><?php echo t('year');?>: <?php echo $year_filter; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no');?></th>
                            <th><?php echo t('item_name');?></th>
                            <th><?php echo t('item_qty');?></th>
                            <th><?php echo t('item_size');?></th>
                            <th><?php echo t('item_location');?></th>
                            <th><?php echo t('item_date');?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($low_stock_items)): ?>
                            <tr>
                                <td colspan="6" class="text-center"><?php echo t('no_low_stock');?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($low_stock_items as $index => $item): ?>
                                <tr>
                                    <td data-label="ល.រ"><?php echo $index + 1 + $offset; ?></td>
                                    <td data-label="ឈ្មោះទំនិញ"><?php echo htmlspecialchars($item['name']); ?></td>
                                    <td data-label="បរិមាណ" class="text-danger fw-bold"><?php echo $item['quantity']; ?></td>
                                    <td data-label="ទំហំ"><?php echo !empty($item['size']) ? htmlspecialchars($item['size']) : 'N/A'; ?></td>
                                    <td data-label="ទីតាំង"><?php echo htmlspecialchars($item['location']); ?></td>
                                    <td data-label="កាលបរិច្ឆេទ"><?php echo !empty($item['created_at']) ? date('M j, Y', strtotime($item['created_at'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 0): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php 
                        // Build query string for pagination links
                        $query_params = $_GET;
                        unset($query_params['page']);
                        
                        if ($current_page > 1): 
                            $query_params['page'] = 1;
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($query_params); ?>" aria-label="First">
                                    <span aria-hidden="false">&laquo;&laquo;</span>
                                </a>
                            </li>
                            <?php 
                            $query_params['page'] = $current_page - 1;
                            ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($query_params); ?>" aria-label="Previous">
                                    <span aria-hidden="false">&laquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;&laquo;</span>
                            </li>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;</span>
                            </li>
                        <?php endif; ?>

                        <?php 
                        // Show page numbers
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++): 
                            $query_params['page'] = $i;
                        ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query($query_params); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;
                        
                        if ($end_page < $total_pages) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <?php if ($current_page < $total_pages): 
                            $query_params['page'] = $current_page + 1;
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($query_params); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <?php 
                            $query_params['page'] = $total_pages;
                            ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query($query_params); ?>" aria-label="Last">
                                    <span aria-hidden="true">&raquo;&raquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;</span>
                            </li>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;&raquo;</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <div class="text-center text-muted">
                <?php echo t('page');?> <?php echo $current_page; ?> <?php echo t('page_of');?> <?php echo $total_pages; ?> 
             
                </div>
            <?php endif; ?> 
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
    // Handle entries per page change
    const perPageSelect = document.getElementById('per_page_select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        });
    }
});
</script>
<?php
require_once '../includes/footer.php';
?>