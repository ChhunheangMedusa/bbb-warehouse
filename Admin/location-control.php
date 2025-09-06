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

checkAdminAccess();

// Get filter parameters
$name_filter = isset($_GET['name']) ? sanitizeInput($_GET['name']) : '';
$type_filter = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'name_asc';

// Validate and parse sort option
$sort_mapping = [
    'name_asc' => ['field' => 'name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'name', 'direction' => 'DESC'],
    'id_asc' => ['field' => 'id', 'direction' => 'ASC'],
    'id_desc' => ['field' => 'id', 'direction' => 'DESC'],
    'type_asc' => ['field' => 'type', 'direction' => 'ASC'],
    'type_desc' => ['field' => 'type', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'created_at', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'created_at', 'direction' => 'DESC']
];

// Default to name_asc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'name_asc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_location'])) {
        // Add new location
        $name = sanitizeInput($_POST['name']);
        $type = sanitizeInput($_POST['type']);
        $location_success=t('location_add_success');
        try {
            $stmt = $pdo->prepare("INSERT INTO locations (name, type) VALUES (?, ?)");
            $stmt->execute([$name, $type]);
            
            $_SESSION['success'] = "$location_success";
            logActivity($_SESSION['user_id'], 'Create New Location', "Created new location: $name ($type) ");
            
            redirect('location-control.php');
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
              echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateLocationModal"));
                    duplicateModal.show();
                });
            </script>';

               
            } else {
               
            }
        }
    } elseif (isset($_POST['edit_location'])) {
        // Edit location
        $id = (int)$_POST['id'];
        $name = sanitizeInput($_POST['name']);
        $type = sanitizeInput($_POST['type']);
        $location_update_success=t('location_edit_success');
        // Get old location data
        $stmt = $pdo->prepare("SELECT name, type FROM locations WHERE id = ?");
        $stmt->execute([$id]);
        $old_location = $stmt->fetch(PDO::FETCH_ASSOC);
        
        try {
            $stmt = $pdo->prepare("UPDATE locations SET name = ?, type = ? WHERE id = ?");
            $stmt->execute([$name, $type, $id]);
            
            // Build change log
            $changes = [];
            if ($old_location['name'] != $name) $changes[] = "Updated name ({$old_location['name']}) : {$old_location['name']} →  $name";
            if ($old_location['type'] != $type) $changes[] = "Updated type ({$old_location['name']}) : {$old_location['type']} →  $type";
            
            if (!empty($changes)) {
                $change_log = "" . implode(" ", $changes);
                logActivity($_SESSION['user_id'], 'Edit Location', $change_log);
            }
            
            $_SESSION['success'] = "$location_update_success";
            redirect('location-control.php');
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
              echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateLocationModal"));
                    duplicateModal.show();
                });
            </script>';
              
            } else {
                
            }
        }
    }
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $location_del_err=t('no_del_location');
    $location_del_succ=t('location_del_success');
    $location_no=t('no_location');
    // Check if location has items
    $stmt = $pdo->prepare("SELECT COUNT(*) as item_count FROM items WHERE location_id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['item_count'] > 0) {
        $_SESSION['error'] = "$location_del_err";
    } else {
        // Get location info for log
        $stmt = $pdo->prepare("SELECT name, type FROM locations WHERE id = ?");
        $stmt->execute([$id]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($location) {
            $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
            $stmt->execute([$id]);
            
            logActivity($_SESSION['user_id'], 'Delete Location', "Deleted Location: {$location['name']} ({$location['type']})");
            $_SESSION['success'] = "$location_del_succ";
        } else {
            $_SESSION['error'] = "$location_no";
        }
    }
    
    redirect('location-control.php');
}
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}
// Pagination settings
$records_per_page =  $per_page;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query for locations with filters
$query = "SELECT * FROM locations WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM locations WHERE 1=1";
$params = [];

if ($name_filter) {
    $query .= " AND name LIKE :name";
    $count_query .= " AND name LIKE :name";
    $params[':name'] = "%$name_filter%";
}

if ($type_filter && $type_filter !== 'all') {
    $query .= " AND type = :type";
    $count_query .= " AND type = :type";
    $params[':type'] = $type_filter;
}

// Add month filter condition
if ($month_filter && $month_filter !== 'all') {
    $query .= " AND MONTH(created_at) = :month";
    $count_query .= " AND MONTH(created_at) = :month";
    $params[':month'] = $month_filter;
}

// Add year filter condition
if ($year_filter && $year_filter !== 'all') {
    $query .= " AND YEAR(created_at) = :year";
    $count_query .= " AND YEAR(created_at) = :year";
    $params[':year'] = $year_filter;
}

// Add sorting
$query .= " ORDER BY $sort_by $sort_order";

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

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";

// Get locations
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

if (!$stmt->execute()) {
    throw new Exception("Main query failed: " . implode(" ", $stmt->errorInfo()));
}
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct types for filter
$stmt = $pdo->query("SELECT DISTINCT type FROM locations ORDER BY type");
$location_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years from the locations
$stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) as year FROM locations ORDER BY year DESC");
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
<!-- Rest of the CSS remains the same -->
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
#deleteConfirmModal .modal-content {
    border: 2px solid #dc3545;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(220, 53, 69, 0.3);
}

#deleteConfirmModal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

#deleteConfirmModal .modal-footer {
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

#deleteConfirmModal .btn-danger {
   
    padding: 8px 20px;
    font-weight: 600;
}

#deleteLocationInfo {
    text-align: left;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
}
/* Mobile-specific styles */
@media (max-width: 767.98px) {
    .container-fluid {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    
    /* Make table horizontally scrollable */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust table cells */
    .table td, .table th {
        white-space: nowrap;
        padding: 0.5rem;
    }
    
    /* Stack buttons vertically in action column */
    .table td:last-child {
        white-space: normal;
    }
    
    .table td:last-child .btn {
        display: block;
        width: 100%;
        margin-bottom: 0.25rem;
    }
    
    /* Full-width modals on mobile */
    .modal-dialog {
        margin: 0.5rem auto;
        max-width: 95%;
    }
    
    /* Adjust card padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Larger tap targets */
    .btn {
        padding: 0.75rem;
        font-size: 1rem;
    }
    
    /* Pagination adjustments */
    .pagination .page-item .page-link {
        padding: 0.5rem;
        margin: 0 0.1rem;
    }
    
    /* Header adjustments */
    h2 {
        font-size: 1.5rem;
    }
    
    /* Form control sizing */
    .form-control, .form-select {
        font-size: 1rem;
        padding: 0.75rem;
    }
}
/* Mobile action buttons */
@media (max-width: 767.98px) {
    .table td:last-child {
        min-width: 150px; /* Ensure enough space for buttons */
    }
    
    .table td:last-child .btn {
        padding: 0.35rem 0.5rem;
        font-size: 0.8rem;
        white-space: nowrap;
        flex-grow: 0; /* Don't stretch buttons */
    }
    
    .table td:last-child .btn i {
        margin-right: 0;
    }
    
    .table td:last-child .d-flex {
        gap: 0.25rem;
    }
}

/* Very small screens (adjust as needed) */
@media (max-width: 400px) {
    .table td:last-child .btn {
        padding: 0.25rem;
    }
    
    .table td:last-child .btn i {
        font-size: 0.75rem;
    }
}
/* Very small devices (portrait phones, less than 576px) */
@media (max-width: 575.98px) {
    /* Hide some table columns if needed */
  
    
    /* Even larger tap targets */
    .btn {
        padding: 0.85rem;
    }
    
    /* Adjust modal padding */
    .modal-body, .modal-footer {
        padding: 1rem;
    }
}
/* Delete Modal Mobile Styles */
@media (max-width: 767.98px) {
    #deleteConfirmModal .modal-dialog {
        margin: 0.5rem;
        max-width: calc(100% - 1rem);
    }
    
    #deleteConfirmModal .modal-content {
        border-radius: 0.5rem;
    }
    
    #deleteConfirmModal .modal-header,
    #deleteConfirmModal .modal-footer {
        padding: 1rem;
    }
    
    #deleteConfirmModal .modal-body {
        padding: 1rem;
    }
    
    #deleteConfirmModal .btn {
        padding: 0.5rem;
        min-width: 120px;
        font-size: 0.9rem;
    }
    
    #deleteConfirmModal .modal-title {
        font-size: 1.1rem;
    }
}

/* Very small devices */
@media (max-width: 400px) {
    #deleteConfirmModal .btn {
        padding: 0.4rem;
        font-size: 0.85rem;
    }
    
    #deleteConfirmModal .modal-title {
        font-size: 1rem;
    }
    
    #deleteConfirmModal .modal-body i {
        font-size: 2rem;
    }
    
    #deleteConfirmModal h4 {
        font-size: 1.1rem;
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
    <h2 class="mb-4"> <?php echo t('location_title');?></h2>
    <div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex align-items-center entries-per-page">
            <span class="me-2"><?php echo t('show_entries'); ?></span>
            <select class="form-select form-select-sm" id="per_page_select">
                <?php foreach ($limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ms-2"><?php echo t('entries'); ?></span>
        </div>
    </div>
</div>
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options');?></h5>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('location_name');?></label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="<?php echo t('search');?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('column_type');?></label>
                        <select name="type" class="form-select">
                            <option value="all"><?php echo t('type_all');?></option>
                            <?php foreach ($location_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>>
                                    <?php echo $type; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('month');?></label>
                        <select name="month" class="form-select">
                            <option value="all"><?php echo t('all_months');?></option>
                            <?php foreach ($months as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $month_filter == $num ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('year');?></label>
                        <select name="year" class="form-select">
                            <option value="all"><?php echo t('all_years');?></option>
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('sort');?></label>
                        <select name="sort_option" class="form-select">
                            <option value="name_asc" <?php echo $sort_option == 'name_asc' ? 'selected' : ''; ?>>
                                <?php echo t('name_a_to_z'); ?>
                            </option>
                            <option value="name_desc" <?php echo $sort_option == 'name_desc' ? 'selected' : ''; ?>>
                                <?php echo t('name_z_to_a'); ?>
                            </option>
                            <option value="type_asc" <?php echo $sort_option == 'type_asc' ? 'selected' : ''; ?>>
                                <?php echo t('type_az'); ?>
                            </option>
                            <option value="type_desc" <?php echo $sort_option == 'type_desc' ? 'selected' : ''; ?>>
                                <?php echo t('type_za'); ?>
                            </option>
                            <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>>
                                <?php echo t('date_oldest_first'); ?>
                            </option>
                            <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>>
                                <?php echo t('date_newest_first'); ?>
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">
                    <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                    </button>
                    <a href="location-control.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                    </a>
                </div>
                
                <input type="hidden" name="page" value="1">
            </form>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header text-white" style="background-color:#674ea7;">
            <button class="btn btn-light btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                <i class="bi bi-plus-circle"></i> <?php echo t('add_location');?>
            </button>
            <h5 class="mb-0"> <?php echo t('location_list');?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($name_filter) || ($type_filter && $type_filter !== 'all') || ($month_filter && $month_filter !== 'all') || ($year_filter && $year_filter !== 'all')): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo t('showing_filtered_results');?>
                    <?php if (!empty($name_filter)): ?>
                        <span class="badge bg-secondary"><?php echo t('name');?>: <?php echo htmlspecialchars($name_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($type_filter && $type_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('type');?>: <?php echo $type_filter; ?></span>
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
                        <th width="10%"><?php echo t('item_no');?></th>
                <th> <?php echo t('location_name');?></th>
                <th width="20%"><?php echo t('column_type');?></th>
                <th width="30%"><?php echo t('column_action');?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($locations)): ?>
                            <tr>
                                <td colspan="4" class="text-center"> <?php echo t('no_location');?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($locations as $index => $location): ?>
                                <tr>
                                <td><?php echo $index + 1 + $offset; ?></td>
                                    <td>
    <a href="location-items.php?location_id=<?php echo $location['id']; ?>" class="text-dark text-decoration-none">
        <?php echo $location['name']; ?>
    </a>
</td>
<td>
    <?php 
    $btn_class = 'btn-primary'; // Default
    if ($location['type'] == 'Construction Site') {
        $btn_class = 'btn-success';
    } elseif ($location['type'] == 'Repair') {
        $btn_class = 'btn-warning';
    }
    ?>
    <a href="location-items.php?location_id=<?php echo $location['id']; ?>" class="btn btn-sm <?php echo $btn_class; ?>">
        <?php echo $location['type']; ?>
    </a>
</td>
                                    <td>
    <button class="btn btn-sm btn-warning edit-location" 
            data-id="<?php echo $location['id']; ?>"
            data-name="<?php echo $location['name']; ?>"
            data-type="<?php echo $location['type']; ?>">
        <i class="bi bi-pencil"></i> <?php echo t('update_button');?>
    </button>
    <button class="btn btn-sm btn-danger delete-location" 
            data-id="<?php echo $location['id']; ?>"
            data-name="<?php echo $location['name']; ?>"
            data-type="<?php echo $location['type']; ?>">
        <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
    </button>
</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
         <!-- Pagination -->
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
        </div>
    </div>
</div>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_location');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 2.5rem;"></i>
                </div>
                <h4 class="text-danger mb-2" style="font-size: 1.25rem;"><?php echo t('del_location1');?></h4>
                <p class="mb-3"><?php echo t('del_usr2');?></p>
                <div id="deleteLocationInfo" class="alert alert-light mb-0"></div>
            </div>
            <div class="modal-footer d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-secondary flex-grow-1 flex-md-grow-0" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger flex-grow-1 flex-md-grow-0">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Duplicate Location Modal -->
<div class="modal fade" id="duplicateLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('error_location');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal, aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('location_duplicate1');?></h4>
                <p><?php echo t('location_duplicate2');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1" aria-labelledby="addLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addLocationModalLabel"><?php echo t('add_location');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo t('location_name');?></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="form-label"><?php echo t('column_type');?></label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="Construction Site"><?php echo t('location_type1');?></option>
                            <option value="Warehouse"><?php echo t('location_type2');?></option>
                            <option value="Repair"><?php echo t('location_type3');?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_location" class="btn btn-primary"><?php echo t('form_save');?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1" aria-labelledby="editLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editLocationModalLabel"><?php echo t('location_update');?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label"><?php echo t('location_name');?></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label"><?php echo t('column_type');?></label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <option value="Construction Site"><?php echo t('location_type1');?></option>
                            <option value="Warehouse"><?php echo t('location_type2');?></option>
                            <option value="Repair"><?php echo t('location_type3');?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="edit_location" class="btn btn-warning"><?php echo t('form_update');?></button>
                </div>
            </form>
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
    // Auto-hide success messages after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successMessages = document.querySelectorAll('.alert-success');
    
    successMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease';
            message.style.opacity = '0';
            
            // Remove the element after fade out
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 5000); // 5000 milliseconds = 5 seconds
    });
});
  // Delete confirmation modal handler
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let deleteUrl = '';
    
    // Handle delete button clicks
    document.querySelectorAll('.delete-location').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const locationId = this.getAttribute('data-id');
            const locationName = this.getAttribute('data-name');
            const locationType = this.getAttribute('data-type');
            
            // Set the delete URL
            deleteUrl = `location-control.php?delete=${locationId}`;
            
            // Update modal content
            document.getElementById('deleteLocationInfo').innerHTML = `
                <strong><?php echo t('location_name');?>:</strong> ${locationName}<br>
                <strong><?php echo t('column_type');?>:</:</strong> ${locationType}
            `;
            
            // Show the modal
            deleteModal.show();
        });
    });
    
    // Handle confirm delete button click
    document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
        window.location.href = deleteUrl;
    });
});
// Handle edit location button click
document.querySelectorAll('.edit-location').forEach(button => {
    button.addEventListener('click', function() {
        const id = this.getAttribute('data-id');
        const name = this.getAttribute('data-name');
        const type = this.getAttribute('data-type');
        
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_type').value = type;
        
        const editModal = new bootstrap.Modal(document.getElementById('editLocationModal'));
        editModal.show();
    });
});
</script>

<?php
require_once '../includes/footer.php';
?>