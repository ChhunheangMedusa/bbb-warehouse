<?php
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
// Handle update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
  $id = (int)$_POST['id'];
  $quantity = (float)$_POST['quantity'];
  $price = (float)$_POST['price'];
  $price_upt=t('price_upt');
  try {
      $update_stmt = $pdo->prepare("UPDATE store_items SET quantity = :quantity, price = :price WHERE id = :id");
      $update_stmt->execute([
          ':quantity' => $quantity,
          ':price' => $price,
          ':id' => $id
      ]);
      
      $_SESSION['success'] = "$price_upt";
      
      // Check if headers have been sent
      if (!headers_sent()) {
          header('Location: store.php?' . http_build_query($_GET));
          exit();
      } else {
          echo '<script>window.location.href = "store.php?' . http_build_query($_GET) . '";</script>';
          exit();
      }
  } catch (PDOException $e) {
      $_SESSION['error'] = "Error updating item: " . $e->getMessage();
      
      // Check if headers have been sent
      if (!headers_sent()) {
          header('Location: store.php?' . http_build_query($_GET));
          exit();
      } else {
          echo '<script>window.location.href = "store.php?' . http_build_query($_GET) . '";</script>';
          exit();
      }
  }
}
// Get filter parameters
$name_filter = isset($_GET['name']) ? sanitizeInput($_GET['name']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$location_filter = isset($_GET['location']) ? sanitizeInput($_GET['location']) : '';
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';
// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Sort mapping - Added date_asc and date_desc options
$sort_mapping = [
    'name_asc' => ['field' => 'si.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'si.name', 'direction' => 'DESC'],
    'quantity_asc' => ['field' => 'si.quantity', 'direction' => 'ASC'],
    'quantity_desc' => ['field' => 'si.quantity', 'direction' => 'DESC'],
    'price_asc' => ['field' => 'si.price', 'direction' => 'ASC'],
    'price_desc' => ['field' => 'si.price', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'si.date', 'direction' => 'ASC'],      // Added: Date Old-New
    'date_desc' => ['field' => 'si.date', 'direction' => 'DESC']     // Added: Date New-Old
];

// Default to name_asc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
  $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Build query for counting total items
$count_query = "SELECT COUNT(*) as total
FROM 
    store_items si
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
WHERE 1=1";

$params = [];

// Add filters to count query
if ($name_filter) {
    $count_query .= " AND si.name LIKE :name";
    $params[':name'] = "%$name_filter%";
}

if ($category_filter) {
    $count_query .= " AND si.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($location_filter) {
    $count_query .= " AND si.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($search_query) {
    $count_query .= " AND (si.name LIKE :search OR si.item_code LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Execute count query
$stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $per_page);

// Ensure page is within valid range
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Build main query
// Change the query to use store_items instead of items
$query = "SELECT 
    si.id,
    si.item_code,
    si.category_id,
    c.name as category_name,
    si.invoice_no,
    si.date,
    si.name,
    si.quantity,
    si.price,
    si.alert_quantity,
    si.size,
    si.location_id,
    l.name as location_name,
    si.remark,
    (SELECT id FROM item_images WHERE item_id = si.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    store_items si
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
WHERE 1=1";

// Add filters to main query
if ($name_filter) {
    $query .= " AND si.name LIKE :name";
    // Parameter already bound above
}

if ($category_filter) {
    $query .= " AND si.category_id = :category_id";
    // Parameter already bound above
}

if ($location_filter) {
    $query .= " AND si.location_id = :location_id";
    // Parameter already bound above
}

if ($search_query) {
    $query .= " AND (si.name LIKE :search OR si.item_code LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";
    // Parameter already bound above
}

$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

// Update the pagination calculation
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_items / $per_page);

// Order by
$query .= " ORDER BY $sort_by $sort_order LIMIT :limit OFFSET :offset";

// Add pagination parameters
$params[':limit'] = $per_page;
$params[':offset'] = $offset;

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations WHERE type !='repair' ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Execute main query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to safely output values
function safeOutput($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars($value);
}
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
    #editItemModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #editItemModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #editItemModal .modal-footer form {
        flex: 1;
    }
}
</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('store_inventory'); ?></h2>
    <!-- Show entries per page selection -->
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
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2">
                <input type="hidden" name="page" value="1">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo safeOutput($search_query); ?>">
                </div>
                <div class="col-md-2">
                    <select name="location" class="form-select">
                        <option value=""><?php echo t('report_all_location'); ?></option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo safeOutput($location['id']); ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                <?php echo safeOutput($location['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select">
                        <option value=""><?php echo t('all_categories'); ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo safeOutput($category['id']); ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo safeOutput($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="sort_option" class="form-select">
                        <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                        <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                        <option value="name_asc" <?php echo $sort_option == 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                        <option value="name_desc" <?php echo $sort_option == 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                        <option value="price_asc" <?php echo $sort_option == 'price_asc' ? 'selected' : ''; ?>><?php echo t('price_low_high'); ?></option>
                        <option value="price_desc" <?php echo $sort_option == 'price_desc' ? 'selected' : ''; ?>><?php echo t('price_high_low'); ?></option>
                        <option value="category_asc" <?php echo $sort_option == 'category_asc' ? 'selected' : ''; ?>><?php echo t('category_az'); ?></option>
                        <option value="category_desc" <?php echo $sort_option == 'category_desc' ? 'selected' : ''; ?>><?php echo t('category_za'); ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary"><?php echo t('search'); ?></button>
                    <a href="store.php" class="btn btn-secondary"><?php echo t('reset'); ?></a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Store Inventory Table -->
    <div class="card">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo t('store_inventory'); ?></h5>
            <!--   <span class="badge bg-light text-dark"><?php echo t('total_items'); ?>: <?php echo $total_items; ?></span>-->
        </div>
        <div class="card-body">
            <?php if ($items): ?>
                <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no'); ?></th>
                            <th><?php echo t('item_code'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('item_invoice'); ?></th>
                            <th><?php echo t('item_date'); ?></th>
                            <th><?php echo t('item_name'); ?></th>
                            <th><?php echo t('item_qty'); ?></th>
                            <th><?php echo t('price'); ?></th>
                            <th><?php echo t('sub_total'); ?></th> <!-- NEW COLUMN -->
                            <th><?php echo t('unit'); ?></th>
                            <th><?php echo t('location'); ?></th>
                            <th><?php echo t('item_remark'); ?></th>
                            <th><?php echo t('item_photo'); ?></th>
                            <th><?php echo t('action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): 
                            // Calculate subtotal
                            $quantity = (float)$item['quantity'];
                            $price = (float)$item['price'];
                            $subtotal = $quantity * $price;
                        ?>
                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>
                                <td><?php echo safeOutput($item['item_code']); ?></td>
                                <td><?php echo safeOutput($item['category_name']); ?></td>
                                <td><?php echo safeOutput($item['invoice_no']); ?></td>
                                <td><?php echo $item['date'] ? date('d/m/Y', strtotime($item['date'])) : ''; ?></td>
                                <td><?php echo safeOutput($item['name']); ?></td>
                                <td><?php echo safeOutput($item['quantity']); ?></td>
                                <td><?php echo '$' . ($item['price'] ? number_format($item['price'], 3) : '0.0000'); ?></td>
                                <td><?php echo '$' . number_format($subtotal, 2); ?></td> <!-- NEW COLUMN DATA -->
                                <td><?php echo safeOutput($item['size']); ?></td>
                                <td><?php echo safeOutput($item['location_name']); ?></td>
                                <td><?php echo safeOutput($item['remark']); ?></td>
                                <td>
                                    <?php if ($item['image_id']): ?>
                                        <img src="display_image.php?id=<?php echo safeOutput($item['image_id']); ?>" 
                                             class="img-thumbnail" width="50"
                                             data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                             data-item-id="<?php echo safeOutput($item['id']); ?>">
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                        <button class="btn btn-sm btn-warning edit-btn" 
                                                data-id="<?php echo $item['id']; ?>"
                                                data-quantity="<?php echo $item['quantity']; ?>"
                                                data-price="<?php echo $item['price']; ?>">
                                            <i class="fas fa-edit"></i> <?php echo t('update_button'); ?>
                                        </button>
                                    </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                
               <!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-center">
        <!-- First page link -->
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                <span aria-hidden="true">&laquo;&laquo;</span>
            </a>
        </li>
        
        <!-- Previous page link -->
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                <span aria-hidden="true">&laquo;</span>
            </a>
        </li>

        <!-- Page number links -->
        <?php 
        // Show page numbers with ellipsis for many pages
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        if ($start_page > 1) {
            echo '<li class="page-item"><span class="page-link">...</span></li>';
        }
        
        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                    <?php echo $i; ?>
                </a>
            </li>
        <?php endfor;
        
        if ($end_page < $total_pages) {
            echo '<li class="page-item"><span class="page-link">...</span></li>';
        }
        ?>

        <!-- Next page link -->
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
            </a>
        </li>
        
        <!-- Last page link -->
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                <span aria-hidden="true">&raquo;&raquo;</span>
            </a>
        </li>
    </ul>
</nav>

<!-- Page number display -->
<div class="text-center text-muted">
    <?php echo t('page'); ?> <?php echo $page; ?> <?php echo t('page_of'); ?> <?php echo $total_pages; ?> 
</div>
<?php endif; ?>
                
            <?php else: ?>
                <div class="alert alert-info"><?php echo t('no_items_found'); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Image Gallery Modal (same as in items.php) -->
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
<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="editItemModalLabel"><?php echo t('edit_item'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editItemForm">
                <input type="hidden" name="id" id="editItemId">
                <input type="hidden" name="update_item" value="1">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editQuantity" class="form-label"><?php echo t('quantity'); ?></label>
                        <input type="number" step="0.01" class="form-control" id="editQuantity" name="quantity" required>
                    </div>
                    <div class="mb-3">
                        <label for="editPrice" class="form-label"><?php echo t('price'); ?></label>
                        <input type="number" step="0.01" class="form-control" id="editPrice" name="price" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" class="btn btn-warning"><?php echo t('update_button'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>

<script>
  // JavaScript for handling edit modal
document.addEventListener('DOMContentLoaded', function() {
    // Handle edit button clicks
    const editButtons = document.querySelectorAll('.edit-btn');
    const editModal = new bootstrap.Modal(document.getElementById('editItemModal'));
    const editForm = document.getElementById('editItemForm');
    const editItemId = document.getElementById('editItemId');
    const editQuantity = document.getElementById('editQuantity');
    const editPrice = document.getElementById('editPrice');
    
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const quantity = this.getAttribute('data-quantity');
            const price = this.getAttribute('data-price');
            
            editItemId.value = id;
            editQuantity.value = quantity;
            editPrice.value = price;
            
            editModal.show();
        });
    });
    const perPageSelect = document.getElementById('per_page_select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', 1); // Reset to first page when changing per page
            window.location.href = url.toString();
        });
    }
});
// Image gallery functionality (same as in items.php)
document.addEventListener('DOMContentLoaded', function() {
    const imageGalleryModal = document.getElementById('imageGalleryModal');
    
    if (imageGalleryModal) {
        imageGalleryModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget; // Button that triggered the modal
            const itemId = button.getAttribute('data-item-id');
            const carouselInner = document.getElementById('carousel-inner');
            
            // Clear previous content
            carouselInner.innerHTML = '';
            
            // Check if there's an image ID (from your PHP code)
            const imageId = button.closest('td').querySelector('img')?.getAttribute('src')?.split('=')[1];
            
            if (imageId) {
                // Create carousel item with the single image
                const item = document.createElement('div');
                item.className = 'carousel-item active';
                
                const imgElement = document.createElement('img');
                imgElement.src = `display_image.php?id=${imageId}`;
                imgElement.className = 'd-block w-100';
                imgElement.alt = 'Item Image';
                imgElement.style.maxHeight = '70vh';
                imgElement.style.objectFit = 'contain';
                
                item.appendChild(imgElement);
                carouselInner.appendChild(item);
            } else {
                // No image available
                carouselInner.innerHTML = `
                    <div class="carousel-item active">
                        <div class="d-flex align-items-center justify-content-center" style="height: 400px;">
                            <p class="text-muted">${t('no_image')}</p>
                        </div>
                    </div>
                `;
            }
        });
    }
});
</script>