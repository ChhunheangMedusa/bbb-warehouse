<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header-staff.php';
require_once 'translate.php';

if (!isStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard.php');
    exit();
  }
checkAuth();

// Get active tab from URL
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'broken';

// Get filter parameters
$name_filter = isset($_GET['name']) ? sanitizeInput($_GET['name']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$location_filter = isset($_GET['location']) ? sanitizeInput($_GET['location']) : '';
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Sort mapping
$sort_mapping = [
    'name_asc' => ['field' => 'bi.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'bi.name', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'bi.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'bi.date', 'direction' => 'DESC'],
    'quantity_asc' => ['field' => 'bi.broken_quantity', 'direction' => 'ASC'],
    'quantity_desc' => ['field' => 'bi.broken_quantity', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_broken_item'])) {
        $invoice_no = sanitizeInput($_POST['invoice_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Loop through each item
            foreach ($_POST['item_id'] as $index => $item_id) {
                $item_id = (int)$item_id;
                $broken_quantity = (float)$_POST['broken_quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index] ?? '');
                $remark = sanitizeInput($_POST['remark'][$index] ?? '');
                
                // Get current item details
                $stmt = $pdo->prepare("SELECT name, quantity, size, remark FROM items WHERE id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$item) {
                    throw new Exception("Item not found");
                }
                
                $item_name = $item['name'];
                $available_quantity = $item['quantity'];
                $item_size = $item['size'];
                $item_remark = $item['remark'];
                
                // Validate broken quantity
                if ($broken_quantity > $available_quantity) {
                    throw new Exception("Broken quantity cannot exceed available quantity for: $item_name");
                }
                
                // Update main item quantity
                $new_qty = $available_quantity - $broken_quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
                $stmt->execute([$new_qty, $item_id]);
                
                // Insert into broken_items table
                $stmt = $pdo->prepare("INSERT INTO broken_items 
                    (item_id, invoice_no, date, name, available_quantity, broken_quantity, size, location_id, remark, action_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $item_id,
                    $invoice_no,
                    $date,
                    $item_name,
                    $available_quantity,
                    $broken_quantity,
                    $size,
                    $location_id,
                    $remark,
                    $_SESSION['user_id']
                ]);
                
                // Insert into broken_items_history instead of stock_out_history
                $stmt = $pdo->prepare("INSERT INTO broken_items_history 
                (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
                SELECT 
                    id, item_code, category_id, ?, ?, name, quantity, alert_quantity, size, location_id, remark, 'broken', ?, ?
                FROM items 
                WHERE id = ?");
            $stmt->execute([$invoice_no, $date, $broken_quantity, $_SESSION['user_id'], $item_id]);
                // NEW: Also insert into stock_in_history with action_type 'broken'
$stmt = $pdo->prepare("INSERT INTO stock_in_history 
(item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
SELECT 
    id, item_code, category_id, ?, ?, name, quantity, alert_quantity, size, location_id, remark, 'broken', ?, ?
FROM items 
WHERE id = ?");
$stmt->execute([$invoice_no, $date, $broken_quantity, $_SESSION['user_id'], $item_id]);
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log activity
                logActivity($_SESSION['user_id'], 'Broken Item', "Marked as broken: $item_name ($broken_quantity $size) at {$location['name']}");
            }
            $broke_itm=t('broke_itm');
            $pdo->commit();
            $_SESSION['success'] = "$broke_itm";
            redirect('broken-items.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }
}

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations WHERE type !='repair' ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for dropdown
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size, remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Pagination setup
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $per_page;
$offset = ($page - 1) * $limit;

// Get filters
$year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query for broken items - MODIFIED TO INCLUDE ITEM CODE, CATEGORY, AND IMAGE
$query = "SELECT 
    bi.id,
    bi.item_id,
    bi.invoice_no,
    bi.date,
    bi.name,
    bi.available_quantity,
    bi.broken_quantity,
    bi.size,
    bi.location_id,
    l.name as location_name,
    bi.remark,
    bi.action_by,
    u.username as action_by_name,
    bi.action_at,
    i.item_code,
    c.name as category_name,
    (SELECT id FROM item_images WHERE item_id = bi.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    broken_items bi
JOIN 
    locations l ON bi.location_id = l.id
JOIN
    users u ON bi.action_by = u.id
LEFT JOIN
    items i ON bi.item_id = i.id
LEFT JOIN
    categories c ON i.category_id = c.id
WHERE 1=1";

$params = [];

// Add filters
if ($year_filter !== null) {
    $query .= " AND YEAR(bi.date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $query .= " AND MONTH(bi.date) = :month";
    $params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND bi.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($category_filter) {
    $query .= " AND i.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($search_query) {
    $query .= " AND (bi.name LIKE :search OR bi.invoice_no LIKE :search OR bi.remark LIKE :search OR i.item_code LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Order by
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM broken_items bi
                JOIN locations l ON bi.location_id = l.id
                LEFT JOIN items i ON bi.item_id = i.id
                WHERE 1=1";

$count_params = [];

if ($year_filter !== null) {
    $count_query .= " AND YEAR(bi.date) = :year";
    $count_params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $count_query .= " AND MONTH(bi.date) = :month";
    $count_params[':month'] = $month_filter;
}

if ($location_filter) {
    $count_query .= " AND bi.location_id = :location_id";
    $count_params[':location_id'] = $location_filter;
}

if ($category_filter) {
    $count_query .= " AND i.category_id = :category_id";
    $count_params[':category_id'] = $category_filter;
}

if ($search_query) {
    $count_query .= " AND (bi.name LIKE :search OR bi.invoice_no LIKE :search OR bi.remark LIKE :search OR i.item_code LIKE :search)";
    $count_params[':search'] = "%$search_query%";
}

$stmt = $pdo->prepare($count_query);
foreach ($count_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get paginated results
$query .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$broken_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

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
  width: 14rem;
  min-height: 100vh;
  background: linear-gradient(
    180deg,
    var(--primary) 0%,
    var(--primary-dark) 100%
  );
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
.form-label{
    margin-top:10px;
}
.sidebar-nav {
  padding: 0.5rem 0;
}

.sidebar .nav-link {
  color: rgba(255, 255, 255, 0.8);
  padding: 0.75rem 1.5rem;
  margin: 0.25rem 1rem;
  border-radius: 0.35rem;
  font-weight: 500;
  transition: all 0.3s;
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
@media (max-width: 768px) {
    table th {
        padding: 0.5rem 0.3rem;
        font-size: 0.85rem;
    }
}



/* Make all table cells single line by default */
.table td {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}

/* Specifically allow the action column to expand */
.table td:last-child {
    overflow: visible;
    white-space: normal;
    text-overflow: clip;
    max-width: none;
    min-width: 150px; /* Ensure enough space for buttons */
}
table th{
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0.75rem 0.5rem;
    line-height: 1.2;
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
/* Duplicate Item Modal Styles */
#duplicateItemModal .modal-content {
    border: 2px solid #dc3545;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(220, 53, 69, 0.3);
}

#duplicateItemModal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

#duplicateItemModal .modal-footer {
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

#duplicateItemModal .btn-danger {
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
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
/* Select Location Modal Styles */
#selectLocationModal .modal-content {
    border: 2px solid #ffc107;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(255, 193, 7, 0.3);
}

#selectLocationModal .modal-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

#selectLocationModal .modal-footer {
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

#selectLocationModal .btn-warning {
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
    color: #212529;
}
.form-control-file:hover::before {
  background: #e9ecef;
}
/* Image Preview Styles */
.image-preview-container {
    min-height: 150px;
}

.image-preview-wrapper {
    position: relative;
    width: 150px;
    height: 150px;
    margin-right: 5px;
    margin-bottom: 5px;
    display: inline-block;
}

.image-preview {
   
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
}

.remove-preview {
    position: absolute;
    top: 0;
    right: 0;
    background: rgba(255,0,0,0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
}
/* Delete Confirmation Modal Styles */
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
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
}

#deleteItemInfo {
    text-align: left;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
}
@media (max-width: 768px) {
    .modal-dialog {
        margin: 0.5rem;
        width: auto;
    }
}
@media (max-width: 768px) {
    .form-row > .col, 
    .form-row > [class*="col-"] {
        padding-right: 0;
        padding-left: 0;
        margin-bottom: 10px;
    }
    
    .form-control, .form-select {
        width: 100%;
    }
}
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        z-index: 1000;
        width: 250px;
        height: 100vh;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    #sidebarToggle {
        display: block !important;
    }
}
/* Mobile-specific styles */
@media (max-width: 768px) {
    /* Make buttons full width */
    .btn {
        width: 100%;
        margin-bottom: 5px;
    }
    
    /* Adjust card padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Make form controls easier to tap */
    .form-control, .form-select {
        padding: 0.75rem;
        font-size: 16px; /* Prevent iOS zoom */
    }
    
    /* Adjust modal padding */
    .modal-body {
        padding: 1rem;
    }
    
    /* Make pagination more compact */
    .pagination .page-item .page-link {
        padding: 0.375rem 0.5rem;
        font-size: 0.875rem;
    }
    
    /* Stack filter form elements */
    .filter-form .col-md-3, 
    .filter-form .col-md-2 {
        margin-bottom: 10px;
    }
}

/* Prevent text input zoom on iOS */
@media screen and (-webkit-min-device-pixel-ratio:0) {
    select:focus,
    textarea:focus,
    input:focus {
        font-size: 16px;
    }
}
@media (max-width: 576px) {
    .card-header h5 {
        font-size: 1rem;
        white-space: nowrap;
       
        display: block;
        margin-top:1px;
        width: 100%;
    }
}
.custom-dropdown-menu {
        width: 100%;
        min-width: 15rem;
    }

    .dropdown-item-container {
        max-height: 200px;
        overflow-y: auto;
    }

    .dropdown-item-container .dropdown-item {
        white-space: normal;
        padding: 0.5rem 1rem;
    }

    .dropdown-item-container .dropdown-item:hover {
        background-color: #f8f9fa;
        color: #000;
    }

    .dropdown-item-container .dropdown-item.active {
        background-color: var(--primary);
        color: white;
    }
    #quantityExceedModal{
        z-index: 1060 !important;
    }
    /* Button group styling in card headers */
.card-header .btn-group {
    margin-left: auto;
}

/* Responsive adjustments for buttons */
@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .card-header .btn {
        margin-top: 0.5rem;
        width: 100%;
    }
}
/* Show entries styling - Right aligned */
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

/* For the specific show entries section in your code */
.row.mb-3 .col-md-6 {
    display: flex;
    align-items: center;
    justify-content: flex-end; /* Align content to the right */
}

.row.mb-3 .col-md-6 > .d-flex {
    background-color: #f8f9fa;
    padding: 0.5rem 1rem;
    border-radius: 0.35rem;
    border: 1px solid #e3e6f0;
    margin-left: auto; /* Push to the right */
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .entries-per-page {
        flex-wrap: wrap;
        justify-content: center; /* Center on mobile */
        text-align: center;
        margin-left: 0; /* Reset margin on mobile */
        width: 100%; /* Full width on mobile */
    }
    
    .entries-per-page label,
    .entries-per-page select,
    .entries-per-page span {
        margin: 0.25rem;
    }
    
    .row.mb-3 .col-md-6 {
        justify-content: center; /* Center on mobile */
    }
    
    .row.mb-3 .col-md-6 > .d-flex {
        margin-left: 0; /* Reset margin on mobile */
    }
}
@media (max-width: 768px) {
    #addBrokenItemModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #addBrokenItemModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #addBrokenItemModal .modal-footer form {
        flex: 1;
    }
}
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('broken_items_history'); ?></h2>
    
    <!-- Filter Card -->
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
    
    <div class="card mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-2">
                        <input type="hidden" name="tab" value="broken">
                        <div class="col-md-2">
                            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $search_query; ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="location" class="form-select">
                                <option value=""><?php echo t('report_all_location'); ?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                        <?php echo $location['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="category" class="form-select">
                                <option value=""><?php echo t('all_categories'); ?></option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo $category['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="month" class="form-select">
                                <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month'); ?></option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="year" class="form-select">
                                <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="sort_option" class="form-select">
                                <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                                <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                <option value="name_asc" <?php echo $sort_option === 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                                <option value="name_desc" <?php echo $sort_option === 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                                <option value="quantity_asc" <?php echo $sort_option === 'quantity_asc' ? 'selected' : ''; ?>><?php echo t('quantity_low_to_high'); ?></option>
                                <option value="quantity_desc" <?php echo $sort_option === 'quantity_desc' ? 'selected' : ''; ?>><?php echo t('quantity_high_to_low'); ?></option>
                            </select>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                            </button>
                            <a href="broken-items.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Data Table Card -->
<div class="card mb-4">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?php echo t('broken_items_history'); ?></h5>
        <div>
            <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addBrokenItemModal">
                <i class="bi bi-plus-circle"></i> <?php echo t('broken_items_history'); ?>
            </button>
        </div>
    </div>
    <div class="card-body">
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
                        <th><?php echo t('item_addby'); ?></th>
                        <th><?php echo t('action_at'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($broken_items)): ?>
                        <tr>
                            <td colspan="14" class="text-center"><?php echo t('no_broken_items'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($broken_items as $index => $item): ?>
                            <tr>
                                <td><?php echo $index + 1 + $offset; ?></td>
                                <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                <td><?php echo $item['invoice_no']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                <td><?php echo $item['name']; ?>
                                    <span class="badge bg-danger"><?php echo t('status_broken'); ?></span>
                                </td>
                                <td class="text-danger"><?php echo $item['broken_quantity']; ?></td>
                                <td><?php echo $item['size']; ?></td>
                                <td><?php echo $item['location_name']; ?></td>
                                <td><?php echo $item['remark']; ?></td>
                                <td>
                                    <?php if ($item['image_id']): ?>
                                        <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                             class="img-thumbnail" width="50"
                                             data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                             data-item-id="<?php echo $item['item_id']; ?>">
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $item['action_by_name']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages >= 1): ?>
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php 
                    $pagination_params = array_merge($_GET, [
                        'tab' => 'broken',
                        'search' => $search_query,
                        'location' => $location_filter,
                        'category' => $category_filter,
                        'month' => $month_filter,
                        'year' => $year_filter,
                        'sort_option' => $sort_option
                    ]);
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => 1])); ?>" aria-label="First">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $page - 1])); ?>" aria-label="Previous">
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
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<li class="page-item"><span class="page-link">...</span></li>';
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor;
                    
                    if ($end_page < $total_pages) {
                        echo '<li class="page-item"><span class="page-link">...</span></li>';
                    }
                    ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $page + 1])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $total_pages])); ?>" aria-label="Last">
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
    </div>
</div>
</div>

<!-- Add Broken Item Modal -->
<div class="modal fade" id="addBrokenItemModal" tabindex="-1" aria-labelledby="addBrokenItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="addBrokenItemModalLabel"><?php echo t('broken_items_history'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="broken_invoice_no">
                        </div>
                        <div class="col-md-5">
                            <label for="broken_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="broken_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="broken_location_id" class="form-label"><?php echo t('location_column'); ?></label>
                        <select class="form-select" id="broken_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="broken_items_container">
                        <!-- First item row -->
                        <div class="broken-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_name'); ?></label>
                                    <div class="dropdown item-dropdown">
                                        <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item'); ?>
                                        </button>
                                        <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                        <ul class="dropdown-menu custom-dropdown-menu p-2">
                                            <li>
                                                <div class="px-2 mb-2">
                                                    <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item'); ?>...">
                                                </div>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <div class="dropdown-item-container">
                                                <div class="px-2 py-1 text-muted"><?php echo t('warning_location1'); ?></div>
                                            </div>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><?php echo t('available_qty'); ?></label>
                                    <input type="number" class="form-control available-quantity" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                                    <input type="number" class="form-control" name="broken_quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_size'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-broken-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="add_broken_item" class="btn btn-danger"><?php echo t('send'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Select Location Modal for Broken Items -->
<div class="modal fade" id="selectLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('warning_location1'); ?></h4>
                <p><?php echo t('warn_loc'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree'); ?>
                </button>
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
    // Function to check if location is selected
function checkLocationSelected() {
    const locationId = document.getElementById('broken_location_id').value;
    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return false;
    }
    return true;
}

// Store items by location data
const itemsByLocation = <?php echo json_encode($items_by_location); ?>;

// Function to populate item dropdown for broken items
function populateBrokenItemDropdown(dropdownElement, locationId) {
    const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
    const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
    const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
    const hiddenInput = dropdownElement.querySelector('.item-id-input');
    const availableQtyInput = dropdownElement.closest('.broken-item-row').querySelector('.available-quantity');
    const sizeInput = dropdownElement.closest('.broken-item-row').querySelector('input[name="size[]"]');
    const remarkInput = dropdownElement.closest('.broken-item-row').querySelector('input[name="remark[]"]');
    
    // Clear previous items
    itemContainer.innerHTML = '';
    
    if (!locationId) {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('warning_location1'); ?></div>';
        return;
    }
    
    if (itemsByLocation[locationId] && itemsByLocation[locationId].length > 0) {
        // Store the original items for this location
        const originalItems = itemsByLocation[locationId];
        
        // Function to render items based on search term
        const renderItems = (searchTerm = '') => {
            itemContainer.innerHTML = '';
            let hasVisibleItems = false;
            
            originalItems.forEach(item => {
                const itemText = `${item.name} (${item.quantity} ${item.size || ''})`.trim().toLowerCase();
                
                if (!searchTerm || itemText.includes(searchTerm.toLowerCase())) {
                    const itemElement = document.createElement('button');
                    itemElement.className = 'dropdown-item';
                    itemElement.type = 'button';
                    itemElement.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                    itemElement.dataset.id = item.id;
                    itemElement.dataset.quantity = item.quantity;
                    itemElement.dataset.size = item.size || '';
                    itemElement.dataset.remark = item.remark || '';
                    
                    itemElement.addEventListener('click', function() {
                        dropdownToggle.textContent = this.textContent;
                        hiddenInput.value = this.dataset.id;
                        
                        // Update available quantity, size, and remark
                        if (availableQtyInput) availableQtyInput.value = this.dataset.quantity;
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (remarkInput) remarkInput.value = this.dataset.remark;
                        
                        // Set max value for broken quantity input
                        const brokenQtyInput = dropdownElement.closest('.broken-item-row').querySelector('input[name="broken_quantity[]"]');
                        if (brokenQtyInput) {
                            brokenQtyInput.setAttribute('max', this.dataset.quantity);
                        }
                    });
                    
                    itemContainer.appendChild(itemElement);
                    hasVisibleItems = true;
                }
            });
            
            if (!hasVisibleItems) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_items_found'); ?></div>';
            }
        };
        
        // Initial render
        renderItems();
        
        // Add search functionality
        const searchInput = dropdownMenu.querySelector('.search-item-input');
        searchInput.addEventListener('input', function() {
            renderItems(this.value);
        });
    } else {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_items_in_location'); ?></div>';
    }
}

// Initialize broken items functionality
document.addEventListener('DOMContentLoaded', function() {
    // Set today's date as default
    document.getElementById('broken_date').valueAsDate = new Date();
    
    // Generate invoice number

    
    // When location changes, update all item dropdowns
    const locationSelect = document.getElementById('broken_location_id');
    if (locationSelect) {
        locationSelect.addEventListener('change', function() {
            const locationId = this.value;
            const dropdowns = document.querySelectorAll('#broken_items_container .item-dropdown');
            
            dropdowns.forEach(dropdown => {
                populateBrokenItemDropdown(dropdown, locationId);
            });
        });
    }
    
    // Initialize first item dropdown
    const firstDropdown = document.querySelector('#broken_items_container .item-dropdown');
    if (firstDropdown && locationSelect) {
        populateBrokenItemDropdown(firstDropdown, locationSelect.value);
    }
    
    // Add more rows functionality
    const addMoreBtn = document.getElementById('add-broken-more-row');
    if (addMoreBtn) {
        addMoreBtn.addEventListener('click', function() {
            if (!checkLocationSelected()) {
            return;
        }
            const container = document.getElementById('broken_items_container');
            const firstRow = container.querySelector('.broken-item-row');
            const newRow = firstRow.cloneNode(true);
            
            // Clear values in the new row
            newRow.querySelector('.dropdown-toggle').textContent = '<?php echo t('select_item'); ?>';
            newRow.querySelector('.item-id-input').value = '';
            newRow.querySelector('.available-quantity').value = '';
            newRow.querySelector('input[name="broken_quantity[]"]').value = '';
            newRow.querySelector('input[name="size[]"]').value = '';
            newRow.querySelector('input[name="remark[]"]').value = '';
            
            // Add remove button
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-danger btn-sm remove-row-btn';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.style.position = 'absolute';
            removeBtn.style.top = '5px';
            removeBtn.style.right = '5px';
            
            removeBtn.addEventListener('click', function() {
                if (container.querySelectorAll('.broken-item-row').length > 1) {
                    this.parentElement.remove();
                }
            });
            
            newRow.style.position = 'relative';
            newRow.appendChild(removeBtn);
            container.appendChild(newRow);
            
            // Initialize the new dropdown
            const locationId = document.getElementById('broken_location_id').value;
            const newDropdown = newRow.querySelector('.item-dropdown');
            populateBrokenItemDropdown(newDropdown, locationId);
        });
    }
    
    // Image gallery functionality
    const imageGalleryModal = document.getElementById('imageGalleryModal');
    if (imageGalleryModal) {
        imageGalleryModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const itemId = button.getAttribute('data-item-id');
            const carouselInner = document.getElementById('carousel-inner');
            
            // Clear previous images
            carouselInner.innerHTML = '';
            
            // Fetch images for this item
            fetch(`get_item_images.php?item_id=${itemId}`)
                .then(response => response.json())
                .then(images => {
                    if (images.length > 0) {
                        images.forEach((image, index) => {
                            const carouselItem = document.createElement('div');
                            carouselItem.className = `carousel-item ${index === 0 ? 'active' : ''}`;
                            
                            const img = document.createElement('img');
                            img.src = `display_image.php?id=${image.id}`;
                            img.className = 'd-block w-100';
                            img.alt = 'Item image';
                            img.style.maxHeight = '500px';
                            img.style.objectFit = 'contain';
                            
                            carouselItem.appendChild(img);
                            carouselInner.appendChild(carouselItem);
                        });
                    } else {
                        carouselInner.innerHTML = `
                            <div class="carousel-item active">
                                <div class="d-flex justify-content-center align-items-center" style="height: 300px;">
                                    <p class="text-muted"><?php echo t('no_images_available'); ?></p>
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error fetching images:', error);
                    carouselInner.innerHTML = `
                        <div class="carousel-item active">
                            <div class="d-flex justify-content-center align-items-center" style="height: 300px;">
                                <p class="text-danger"><?php echo t('error_loading_images'); ?></p>
                            </div>
                        </div>
                    `;
                });
        });
    }
    
    // Per page select change
    const perPageSelect = document.getElementById('per_page_select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
ob_end_flush();
?>
