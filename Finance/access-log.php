
<?php
require_once '../includes/header-finance.php';
require_once '../includes/db.php';
require_once  'translate.php'; 
if (!isAdmin() && !isFinanceStaff()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: ../index.php'); // Redirect to login or home page
  exit();
}
checkAuth();

// Get filter parameters
$type_filter = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$user_filter = isset($_GET['user']) ? sanitizeInput($_GET['user']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Validate and parse sort option
$sort_mapping = [
    'date_asc' => ['field' => 'created_at', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'created_at', 'direction' => 'DESC'],
    'user_asc' => ['field' => 'username', 'direction' => 'ASC'],
    'user_desc' => ['field' => 'username', 'direction' => 'DESC'],
    'activity_asc' => ['field' => 'activity_detail', 'direction' => 'ASC'],
    'activity_desc' => ['field' => 'activity_detail', 'direction' => 'DESC'],
    'type_asc' => ['field' => 'activity_type', 'direction' => 'ASC'],
    'type_desc' => ['field' => 'activity_type', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];
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

try {
    // Build query for access logs
    $query = "SELECT al.*, 
              IFNULL(u.username, CONCAT('User ID ', al.user_id)) as username 
              FROM finance_logs al 
              LEFT JOIN users u ON al.user_id = u.id 
              WHERE 1=1";
    $count_query = "SELECT COUNT(*) as total 
                    FROM finance_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    WHERE 1=1";
    $params = [];

    if ($type_filter) {
        $query .= " AND al.activity_type = :type";
        $count_query .= " AND al.activity_type = :type";
        $params[':type'] = $type_filter;
    }

    // Add month filter condition
    if ($month_filter && $month_filter !== 'all') {
        $query .= " AND MONTH(al.created_at) = :month";
        $count_query .= " AND MONTH(al.created_at) = :month";
        $params[':month'] = $month_filter;
    }

    // Add year filter condition
    if ($year_filter && $year_filter !== 'all') {
        $query .= " AND YEAR(al.created_at) = :year";
        $count_query .= " AND YEAR(al.created_at) = :year";
        $params[':year'] = $year_filter;
    }

    // Add user filter condition
    if ($user_filter) {
        $query .= " AND (u.username LIKE :user OR al.user_id = :user_id)";
        $count_query .= " AND (u.username LIKE :user OR al.user_id = :user_id)";
        $params[':user'] = '%' . $user_filter . '%';
        $params[':user_id'] = is_numeric($user_filter) ? $user_filter : -1; // -1 will never match if not numeric
    }

    // Get total count
    $stmt = $pdo->prepare($count_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if (!$stmt->execute()) {
        throw new Exception("Count query failed: " . implode(" ", $stmt->errorInfo()));
    }
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);

    // Add sorting and pagination to main query
    $query .= " ORDER BY $sort_by $sort_order LIMIT :limit OFFSET :offset";

    // Get access logs
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new Exception("Main query failed: " . implode(" ", $stmt->errorInfo()));
    }
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get distinct activity types for filter
    $stmt = $pdo->query("SELECT DISTINCT activity_type FROM finance_logs ORDER BY activity_type");
    if (!$stmt) {
        throw new Exception("Activity types query failed: " . implode(" ", $pdo->errorInfo()));
    }
    $activity_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get available years from the logs
    $stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) as year FROM finance_logs ORDER BY year DESC");
    $available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get distinct users for filter (users who have activity logs)
    $stmt = $pdo->query("SELECT DISTINCT u.id, u.username 
                         FROM users u 
                         INNER JOIN finance_logs al ON u.id = al.user_id 
                         ORDER BY u.username");
    $available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Month names for the dropdown
$months = [
    '1' => t('jan'),
    '2' => t('feb'),
    '3' => t('mar'),
    '4' => t('apr'),
    '5' => t('may'),
    '6' => t('jun'),
    '7' => t('jul'),
    '8' => t('aug'),
    '9' => t('sep'),
    '10' => t('oct'),
    '11' => t('nov'),
    '12' => t('dec'),
];
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

.sort-group {
    display: flex;
    gap: 0.5rem;
    align-items: end;
}

.sort-select {
    min-width: 120px;
}

.sort-order-select {
    min-width: 100px;
}

@media (max-width: 768px) {
    .filter-group {
        min-width: 100%;
    }
    .sort-group {
        flex-direction: column;
        align-items: stretch;
    }
    .sort-select, .sort-order-select {
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
    <h2 class="mb-4"><?php echo t('logs_button');?></h2>
 
<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><?php echo t('filter_options');?></h5>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label"><?php echo t('activity_type_column');?></label>
                    <select name="type" class="form-select">
                        <option value=""><?php echo t('type_all');?></option>
                        <?php foreach ($activity_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label"><?php echo t('users_button');?></label>
                    <select name="user" class="form-select">
                        <option value=""><?php echo t('');?></option>
                        <?php foreach ($available_users as $user): ?>
                            <option value="<?php echo $user['username']; ?>" <?php echo $user_filter == $user['username'] ? 'selected' : ''; ?>>
                                <?php echo $user['username']; ?> (ID: <?php echo $user['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label"><?php echo t('sort');?></label>
                    <select name="sort_option" class="form-select">
                        <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>>
                            <?php echo t('date_newest_first'); ?>
                        </option>
                        <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>>
                            <?php echo t('date_oldest_first'); ?>
                        </option>
                        <option value="user_asc" <?php echo $sort_option == 'user_asc' ? 'selected' : ''; ?>>
                            <?php echo t('users_button'); ?>
                        </option>
                       
                        <option value="activity_asc" <?php echo $sort_option == 'activity_asc' ? 'selected' : ''; ?>>
                            <?php echo t('activity_column'); ?>
                        </option>
                       
                        <option value="type_asc" <?php echo $sort_option == 'type_asc' ? 'selected' : ''; ?>>
                            <?php echo t('activity_type_column'); ?>
                        </option>
                        
                    </select>
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary">
                <?php echo t('search'); ?>
                </button>
                <a href="access-log.php" class="btn btn-secondary">
                 <?php echo t('reset'); ?>
                </a>
            </div>
            
            <input type="hidden" name="page" value="1">
        </form>
    </div>
</div>
    
    <!-- Log List Card -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><?php echo t('log_list');?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($type_filter) || !empty($user_filter) || ($month_filter && $month_filter !== 'all') || ($year_filter && $year_filter !== 'all')): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo t('showing_filtered_results');?>
                    <?php if (!empty($type_filter)): ?>
                        <span class="badge bg-secondary"><?php echo t('type');?>: <?php echo htmlspecialchars($type_filter); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($user_filter)): ?>
                        <span class="badge bg-secondary"><?php echo t('user');?>: <?php echo htmlspecialchars($user_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($month_filter && $month_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('month');?>: <?php echo $months[$month_filter]; ?></span>
                    <?php endif; ?>
                    <?php if ($year_filter && $year_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('year');?>: <?php echo $year_filter; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                        <th><?php echo t('item_no');?></th>
                            <th><?php echo t('users_button');?></th>
                            <th><?php echo t('activity_type_column');?></th>
                            <th><?php echo t('activity_column');?></th>
                            <th><?php echo t('item_date');?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center"><?php echo t('acc_n_rec');?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $index => $log): ?>
                                <tr>
                                        <td data-label="ល.រ"><?php echo $index + 1 + $offset; ?></td>
                                    <td data-label="អ្នកប្រើប្រាស់"><?php echo $log['username'] ?? 'System'; ?></td>
                                    <td data-label="ប្រភេទសកម្មភាព"><?php echo $log['activity_type']; ?></td>
                                    <td data-label="សកម្មភាព"><?php echo $log['activity_detail']; ?></td>
                                    <td data-label="កាលបរិច្ឆេទ"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($current_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                    <span aria-hidden="true">&laquo;&laquo;</span>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
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
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;
                        
                        if ($end_page < $total_pages) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <?php if ($current_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
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
