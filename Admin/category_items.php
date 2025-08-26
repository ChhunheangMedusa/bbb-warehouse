<?php
ob_start();
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

// Get category ID from URL
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Get category details
$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$category_id]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    $_SESSION['error'] = t('category_not_found');
    header('Location: category.php');
    exit();
}

// Get filter parameters
$year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Get sort parameters
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Validate and parse sort option
$sort_mapping = [
    'name_asc' => ['field' => 'i.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'i.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'i.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'i.date', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'quantity_asc' => ['field' => 'i.quantity', 'direction' => 'ASC'],
    'quantity_desc' => ['field' => 'i.quantity', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Build query for items in this category
$query = "SELECT i.*, l.name as location_name, c.name as category_name 
          FROM items i 
          JOIN locations l ON i.location_id = l.id 
          LEFT JOIN categories c ON i.category_id = c.id
          WHERE i.category_id = :category_id";
$params = [':category_id' => $category_id];

// Add filters only if they have values
if ($year_filter !== null) {
    $query .= " AND YEAR(i.date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $query .= " AND MONTH(i.date) = :month";
    $params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND i.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($search_query) {
    $query .= " AND (i.name LIKE :search OR i.invoice_no LIKE :search OR i.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Add sorting
$query .= " ORDER BY $sort_by $sort_order";

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM items i 
                JOIN locations l ON i.location_id = l.id 
                LEFT JOIN categories c ON i.category_id = c.id
                WHERE i.category_id = :category_id";

// Add the same filters to the count query
if ($year_filter !== null) {
    $count_query .= " AND YEAR(i.date) = :year";
}
if ($month_filter !== null) {
    $count_query .= " AND MONTH(i.date) = :month";
}
if ($location_filter) {
    $count_query .= " AND i.location_id = :location_id";
}
if ($search_query) {
    $count_query .= " AND (i.name LIKE :search OR i.invoice_no LIKE :search OR i.remark LIKE :search)";
}

$stmt = $pdo->prepare($count_query);
$stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    if ($key !== ':category_id') {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get items with pagination
$query .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
$stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    if ($key !== ':category_id') {
        $stmt->bindValue($key, $value);
    }
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
    /* Add your existing styles here */
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
    /* Add your existing CSS styles from remaining.php here */
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
</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('items_in_category'); ?>: <?php echo htmlspecialchars($category['name']); ?></h2>
    
    <!-- Back button -->

    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-form">
                <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('search'); ?></label>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="<?php echo t('search'); ?>...">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('location_column'); ?></label>
                        <select name="location" class="form-select">
                            <option value=""><?php echo t('report_all_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                    <?php echo $location['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('month'); ?></label>
                        <select name="month" class="form-select">
                            <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_months'); ?></option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('year'); ?></label>
                        <select name="year" class="form-select">
                            <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('sort'); ?></label>
                        <select name="sort_option" class="form-select">
                            <option value="name_asc" <?php echo $sort_option == 'name_asc' ? 'selected' : ''; ?>>
                                <?php echo t('name_a_to_z'); ?>
                            </option>
                            <option value="name_desc" <?php echo $sort_option == 'name_desc' ? 'selected' : ''; ?>>
                                <?php echo t('name_z_to_a'); ?>
                            </option>
                            <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>>
                                <?php echo t('date_oldest_first'); ?>
                            </option>
                            <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>>
                                <?php echo t('date_newest_first'); ?>
                            </option>
                            <option value="location_asc" <?php echo $sort_option == 'location_asc' ? 'selected' : ''; ?>>
                                <?php echo t('location_az'); ?>
                            </option>
                            <option value="location_desc" <?php echo $sort_option == 'location_desc' ? 'selected' : ''; ?>>
                                <?php echo t('location_za'); ?>
                            </option>
                            <option value="quantity_asc" <?php echo $sort_option == 'quantity_asc' ? 'selected' : ''; ?>>
                                <?php echo t('quantity_low_to_high'); ?>
                            </option>
                            <option value="quantity_desc" <?php echo $sort_option == 'quantity_desc' ? 'selected' : ''; ?>>
                                <?php echo t('quantity_high_to_low'); ?>
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                    </button>
                    <a href="category_items.php?category_id=<?php echo $category_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                    </a>
                </div>
                
                <input type="hidden" name="page" value="1">
            </form>
        </div>
    </div>
    
    <!-- Data Card -->
    <div class="card mb-4">
        <div class="card-header text-white" style="background-color:#674ea7;">
            <h5 class="mb-0"><?php echo t('item_list'); ?>: <?php echo htmlspecialchars($category['name']); ?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($search_query) || $location_filter || $month_filter || $year_filter): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo t('showing_filtered_results'); ?>
                    <?php if (!empty($search_query)): ?>
                        <span class="badge bg-secondary"><?php echo t('search'); ?>: <?php echo htmlspecialchars($search_query); ?></span>
                    <?php endif; ?>
                    <?php if ($location_filter): ?>
                        <span class="badge bg-secondary"><?php echo t('location_column'); ?>: <?php 
                            $location_name = '';
                            foreach ($locations as $loc) {
                                if ($loc['id'] == $location_filter) {
                                    $location_name = $loc['name'];
                                    break;
                                }
                            }
                            echo $location_name;
                        ?></span>
                    <?php endif; ?>
                    <?php if ($month_filter && $month_filter != 0): ?>
                        <span class="badge bg-secondary"><?php echo t('month'); ?>: <?php echo date('F', mktime(0, 0, 0, $month_filter, 1)); ?></span>
                    <?php endif; ?>
                    <?php if ($year_filter && $year_filter != 0): ?>
                        <span class="badge bg-secondary"><?php echo t('year'); ?>: <?php echo $year_filter; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no'); ?></th>
                            <th><?php echo t('item_code'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('item_invoice'); ?></th>
                            <th><?php echo t('item_date'); ?></th>
                            <th><?php echo t('item_name'); ?></th>
                            <th><?php echo t('item_qty'); ?></th>
                            <th><?php echo t('item_size'); ?></th>
                            <th><?php echo t('item_location'); ?></th>
                            <th><?php echo t('item_remark'); ?></th>
                            <th><?php echo t('item_photo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="11" class="text-center"><?php echo t('no_items_in_category'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                    <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                    <td><?php echo $item['invoice_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                    <td><?php echo $item['name']; ?></td>
                                    <td class="<?php echo $item['quantity'] <= $item['alert_quantity'] ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo $item['quantity']; ?>
                                        <?php if ($item['quantity'] <= $item['alert_quantity']): ?>
                                            <span class="badge bg-danger"><?php echo t('low_stock_title'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['size']; ?></td>
                                    <td><?php echo $item['location_name']; ?></td>
                                    <td><?php echo $item['remark']; ?></td>
                                    <td>
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT id FROM item_images WHERE item_id = ? ORDER BY id DESC LIMIT 1");
                                        $stmt->execute([$item['id']]);
                                        $image = $stmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($image): ?>
                                            <img src="display_image.php?id=<?php echo $image['id']; ?>" 
                                                 alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                 class="img-thumbnail" 
                                                 width="50"
                                                 data-bs-toggle="modal" 
                                                 data-bs-target="#imageGalleryModal"
                                                 data-item-id="<?php echo $item['id']; ?>">
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No image</span>
                                        <?php endif; ?>
                                    </td>
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
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                    <span aria-hidden="true">&laquo;&laquo;</span>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
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
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;
                        
                        if ($end_page < $total_pages) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
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
                    <?php echo t('page'); ?> <?php echo $page; ?> <?php echo t('page_of'); ?> <?php echo $total_pages; ?> 
                </div>
            <?php endif; ?>
            <div class="mb-3">
        <a href="category.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> <?php echo t('return'); ?>
        </a>
    </div>
        </div>
    </div>
</div>

<!-- Image Gallery Modal -->
<div class="modal fade" id="imageGalleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo t('item_photo'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="carouselExample" class="carousel slide">
                    <div class="carousel-inner" id="carousel-inner">
                        <!-- Images will be loaded here via JavaScript -->
                    </div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#carouselExample" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#carouselExample" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Image Gallery functionality
document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
    img.addEventListener('click', function() {
        const itemId = this.getAttribute('data-item-id');
        fetch(`get_item_images.php?id=${itemId}`)
            .then(response => response.json())
            .then(images => {
                const carouselInner = document.getElementById('carousel-inner');
                carouselInner.innerHTML = '';
                
                if (images.length > 0) {
                    images.forEach((image, index) => {
                        const item = document.createElement('div');
                        item.className = `carousel-item ${index === 0 ? 'active' : ''}`;
                        
                        const imgElement = document.createElement('img');
                        imgElement.src = `display_image.php?id=${image.id}`;
                        imgElement.className = 'd-block w-100';
                        imgElement.alt = 'Item Image';
                        imgElement.style.maxHeight = '70vh';
                        imgElement.style.objectFit = 'contain';
                        
                        item.appendChild(imgElement);
                        carouselInner.appendChild(item);
                    });
                } else {
                    carouselInner.innerHTML = `
                        <div class="carousel-item active">
                            <img src="assets/images/no-image.png" 
                                 class="d-block w-100" 
                                 alt="No image"
                                 style="max-height: 70vh; object-fit: contain;">
                        </div>
                    `;
                }
            });
    });
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
</script>

<?php
require_once '../includes/footer.php';
?>