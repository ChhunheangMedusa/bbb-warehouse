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

// Only admin can access item control


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (isset($_POST['deduct_qty'])) {
        // Deduct quantity from items
        $invoice_no = sanitizeInput($_POST['invoice_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Loop through each item
            foreach ($_POST['item_id'] as $index => $item_id) {
                $item_id = (int)$item_id;
                $quantity = (float)$_POST['quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index] ?? '');
                $remark = sanitizeInput($_POST['remark'][$index] ?? '');
                
                // Get current quantity
                $stmt = $pdo->prepare("SELECT quantity, name FROM items WHERE id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                $old_qty = $item['quantity'];
                $item_name = $item['name'];
                $deduct_qty1=t('deduct_qty1');
                
                if ($quantity > $old_qty) {
                    throw new Exception("$deduct_qty1: $item_name");
                }
                
                // Update quantity
                $new_qty = $old_qty - $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ?, invoice_no = ?, date = ?, remark = ? WHERE id = ?");
                $stmt->execute([$new_qty, $invoice_no, $date, $remark, $item_id]);
                
                // Insert into stock_out_history
                $stmt = $pdo->prepare("INSERT INTO stock_out_history 
                    (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
                    SELECT 
                        id, item_code, category_id, ?, ?, name, quantity, alert_quantity, size, location_id, remark, 'deduct', ?, ?
                    FROM items 
                    WHERE id = ?");
                $stmt->execute([$invoice_no, $date, $quantity, $_SESSION['user_id'], $item_id]);
                
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log each item update
                logActivity($_SESSION['user_id'], 'Stock Out', "Decrease stock: $item_name ($quantity $size) at {$location['name']} (Total: $old_qty-$quantity=$new_qty)");
            }
            
            $pdo->commit();
            $deduct_qty2=t('deduct_qty2');
            $deduct_qty3=t('deduct_qty3');
            $_SESSION['success'] = "$deduct_qty2";
            redirect('stock-in.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "$deduct_qty3";
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }

  
    }


$year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
// Build query for stock OUT history
$query = "SELECT 
    so.id,
    so.item_id,
    so.item_code, 
    so.category_id,
    c.name as category_name,
    so.invoice_no,
    so.date,
    so.name,
    i.quantity as current_quantity,
    so.quantity as history_quantity,
    so.action_quantity,
    so.action_type,
    so.alert_quantity,
    so.size,
    so.location_id,
    l.name as location_name,
    so.remark,
    so.action_by,
    so.action_at,
    (SELECT id FROM item_images WHERE item_id = so.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    stock_out_history so
LEFT JOIN 
    items i ON so.item_id = i.id
LEFT JOIN 
    categories c ON so.category_id = c.id
JOIN 
    locations l ON so.location_id = l.id
WHERE 1=1";

$params = [];

// Add filters
if ($year_filter !== null) {
    $query .= " AND YEAR(so.date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $query .= " AND MONTH(so.date) = :month";
    $params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND so.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($search_query) {
    $query .= " AND (so.name LIKE :search OR so.invoice_no LIKE :search OR so.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Order by action date (newest first)
$query .= " ORDER BY so.action_at DESC";

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Count total records
$count_query = "SELECT COUNT(*) as total FROM stock_out_history so
                LEFT JOIN items i ON so.item_id = i.id
                LEFT JOIN categories c ON so.category_id = c.id
                JOIN locations l ON so.location_id = l.id
                WHERE 1=1";

// Apply same filters to count query
if ($year_filter !== null) $count_query .= " AND YEAR(so.date) = :year";
if ($month_filter !== null) $count_query .= " AND MONTH(so.date) = :month";
if ($location_filter) $count_query .= " AND so.location_id = :location_id";
if ($search_query) $count_query .= " AND (so.name LIKE :search OR so.invoice_no LIKE :search OR so.remark LIKE :search)";

$stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
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
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for add/deduct quantity dropdowns
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size,remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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

.btn-primary {
  background-color: var(--primary);
  border-color: var(--primary);
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

.btn-primary {
  background-color: var(--primary);
  border-color: var(--primary);
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
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('item_management');?></h2>
    
    <div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><?php echo t('stock_out_history');?></h5>
    <div>
        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#deductQtyModal">
            <i class="bi bi-dash-lg"></i> <?php echo t('deduct_qty');?>
        </button>
    </div>
</div>
        <div class="card-body">
        <div class="row mb-3">
    <div class="col-md-8">
        <form method="GET" class="row g-2">
            <div class="col-md-2">
                <input type="text" name="search" class="form-control" placeholder="<?php echo t('search');?>..." value="<?php echo $search_query; ?>">
            </div>

            <div class="col-md-2">
                <select name="location" class="form-select">
                    <option value=""><?php echo t('report_all_location');?></option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                            <?php echo $location['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="month" class="form-select">
                    <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month');?></option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="year" class="form-select">
                    <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_year');?></option>
                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2">
                <a href="stock-out.php" class="btn btn-danger w-100">Reset</a>
            </div>
        </form>
    </div>
</div>
            
            <div class="table-responsive">
                    <table class="table table-striped">
                    <thead>
    <tr>
        <th><?php echo t('item_no');?></th>
        <th><?php echo t('item_code');?></th>
        <th><?php echo t('category');?></th>
        <th><?php echo t('item_invoice');?></th>
        <th><?php echo t('item_date');?></th>
        <th><?php echo t('item_name');?></th>
        <th>Current Qty</th>
        <th>Deducted Qty</th>
        <th>Action</th>
        <th><?php echo t('item_size');?></th>
        <th><?php echo t('item_location');?></th>
        <th><?php echo t('item_remark');?></th>
        <th><?php echo t('item_photo');?></th>
        <th>Action By</th>
        <th>Action At</th>
    </tr>
</thead>
<tbody>
    <?php if (empty($items)): ?>
        <tr>
            <td colspan="15" class="text-center">No stock out history found</td>
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
                <td><?php echo $item['current_quantity']; ?></td>
                <td class="text-danger">
                    -<?php echo $item['action_quantity']; ?>
                </td>
                <td>
                    <span class="badge bg-danger">
                        Deduct
                    </span>
                </td>
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
                        <span class="badge bg-secondary">No image</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php 
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$item['action_by']]);
                    echo $stmt->fetchColumn() ?: 'System';
                    ?>
                </td>
                <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>
                    </table>
                </div>
            
<?php if ($total_pages > 0): ?>
<!-- Pagination -->
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
    <?php echo t('page');?> <?php echo $page; ?> <?php echo t('page_of');?> <?php echo $total_pages; ?> 
</div>
<?php endif; ?>
<!-- View Item Modal -->
<div class="modal fade" id="viewItemModal" tabindex="-1" aria-labelledby="viewItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewItemModalLabel"><?php echo t('view_item_detail');?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Table (Basic Info) -->
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%"><?php echo t('item_invoice');?></th>
                                <td id="view_invoice_no"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_date');?></th>
                                <td id="view_date"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_name');?></th>
                                <td id="view_name"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_qty');?></th>
                                <td id="view_quantity"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_size');?></th>
                                <td id="view_size"></td>
                            </tr>
                             <tr>
                                <th><?php echo t('item_remark');?></th>
                                <td id="view_remark"></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Right Table (Code/Category) -->
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%"><?php echo t('item_code');?></th>
                                <td id="view_item_code"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('category');?></th>
                                <td id="view_category"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_location');?></th>
                                <td id="view_location"></td>
                            </tr>
                           
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><?php echo t('item_photo');?></h5>
                        <div class="row g-2" id="view_images">
                            <!-- Images will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
            </div>
        </div>
    </div>
</div>
<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addItemModalLabel"><?php echo t('add_new_item');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" name="invoice_no" id="main_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="items-container">
                        <!-- First item row -->
                        <div class="item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="location_id" class="form-label"><?php echo t('location_column');?></label>
                                    <select class="form-select" id="location_id" name="location_id[]" required>
                                        <option value=""><?php echo t('item_locations');?></option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_code');?></label>
                                    <input type="text" class="form-control" name="item_code[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('category');?></label>
                                    <select class="form-select" name="category_id[]" required>
                                        <option value=""><?php echo t('select_category');?></option>
                                        <?php 
                                        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
                                        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo t('item_name');?></label>
                                    <input type="text" class="form-control" name="name[]" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo t('item_qty');?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('low_stock_title');?></label>
                                    <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_size');?></label>
                                    <input type="text" class="form-control" name="size[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_remark');?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label"><?php echo t('item_photo');?></label>
                                    <input type="file" class="form-control" name="images[0][]" multiple accept="image/*">
                                    <div class="image-preview-container mt-2 row g-1" id="image-preview-0"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_item" class="btn btn-primary"><?php echo t('form_save');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_item');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_item1');?></h4>
                <p><?php echo t('del_usr2');?></p>
                <div id="deleteItemInfo" class="alert alert-light mt-3"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Quantity Modal -->
<div class="modal fade" id="addQtyModal" tabindex="-1" aria-labelledby="addQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addQtyModalLabel"><?php echo t('add_qty');?> </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" name="invoice_no" id="add_invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="add_date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="add_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="add_location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="add_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations');?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="add_qty_items_container">
               <!-- First item row -->
<div class="add-qty-item-row mb-3 border p-3">
    <div class="row">
    <div class="col-md-8 mb-3">
                                        <label class="form-label"><?php echo t('item_name');?></label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item');?>
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>

        <div class="col-md-4 mb-3">
            <label class="form-label"><?php echo t('item_qty');?></label>
            <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label"><?php echo t('item_size');?></label>
            <input type="text" class="form-control" name="size[]" readonly>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label"><?php echo t('item_remark');?></label>
            <input type="text" class="form-control" name="remark[]">
        </div>
    </div>
</div>
                    </div>
                    
                    <button type="button" id="add-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_qty" class="btn btn-success"><?php echo t('add');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Quantity Exceed Modal -->
<div class="modal fade" id="quantityExceedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('qty_issue1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('qty_issue2');?></h4>
                <p id="quantityExceedMessage" style="text-align:left;"><?php echo t('qty_issue3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Deduct Quantity Modal -->
<div class="modal fade" id="deductQtyModal" tabindex="-1" aria-labelledby="deductQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deductQtyModalLabel"><?php echo t('deduct_qty');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="deduct_invoice_no" class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" id="deduct_invoice_no" name="invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="deduct_date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="deduct_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="deduct_location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="deduct_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations');?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="deduct_qty_items_container">
                        <!-- First item row -->
                        <div class="deduct-qty-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                        <label class="form-label"><?php echo t('item_name');?></label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item');?>
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_qty');?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_size');?></label>
                                    <input type="text" class="form-control" name="size[]"readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark');?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="deduct-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="deduct_qty" class="btn btn-danger"><?php echo t('deduct');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Add this modal to your HTML (place it near the other modals) -->
<div class="modal fade" id="duplicateItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_itm1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_itm2');?></h4>
                <p><?php echo t('duplicate_itm3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Add this modal to your HTML -->
<div class="modal fade" id="selectLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('warning_location1');?></h4>
                <p><?php echo t('warn_loc');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Image Delete Confirmation Modal -->
<div class="modal fade" id="deleteImageConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_pic1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_pic2');?></h4>
                <p><?php echo t('del_pic3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <button type="button" id="confirmImageDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Image Replace Confirmation Modal -->
<div class="modal fade" id="replaceImageConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-images text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('rep_pic1');?></h4>
                <p><?php echo t('rep_pic2');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <button type="button" id="confirmReplaceBtn" class="btn btn-warning">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
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
                <h5 class="modal-title"><?php echo t('item_photo');?></h5>
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
const itemsByLocation = <?php echo json_encode($items_by_location); ?>;
// Function to populate item dropdown
// Function to populate item dropdown
function populateItemDropdown(dropdownElement, locationId) {
    const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
    const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
    const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
    const hiddenInput = dropdownElement.querySelector('.item-id-input');
    
    // Clear previous items
    itemContainer.innerHTML = '';
    
    if (!locationId) {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>';
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
                    itemElement.dataset.maxQuantity = item.quantity;
                    itemElement.dataset.size = item.size || '';
                    itemElement.dataset.remark = item.remark || '';
                    
                    itemElement.addEventListener('click', function() {
                        dropdownToggle.textContent = this.textContent;
                        hiddenInput.value = this.dataset.id;
                        
                        const row = dropdownElement.closest('.add-qty-item-row, .deduct-qty-item-row');
                        const sizeInput = row.querySelector('input[name="size[]"]');
                        const remarkInput = row.querySelector('input[name="remark[]"]');
                        
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (remarkInput) remarkInput.value = this.dataset.remark;
                        
                        const quantityInput = row.querySelector('input[name="quantity[]"]');
                        if (quantityInput) {
                            quantityInput.max = this.dataset.maxQuantity;
                            if (row.classList.contains('deduct-qty-item-row')) {
            quantityInput.max = this.dataset.maxQuantity;
        }
        // For add modal, you might not need a max, but you can set one if needed
        if (row.classList.contains('add-qty-item-row')) {
            // Optional: Set a reasonable max for adding quantity
            quantityInput.removeAttribute('max'); // Or set a high limit
        }
                        }
                    });
                    
                    itemContainer.appendChild(itemElement);
                    hasVisibleItems = true;
                }
            });
            
            if (!hasVisibleItems) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item');?></div>';
            }
        };
        
        // Initial render
        renderItems();
        
        // Setup search functionality
        const searchInput = dropdownElement.querySelector('.search-item-input');
        searchInput.addEventListener('input', function() {
            renderItems(this.value);
        });
    } else {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item_location');?></div>';
    }
}
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

    // Store the image to be deleted
let pendingImageDelete = null;
let pendingImageElement = null;

// Handle image delete button click
document.addEventListener('click', function(e) {
    if (e.target.closest('.btn-danger') && e.target.closest('[data-image-id]')) {
        e.preventDefault();
        const deleteBtn = e.target.closest('[data-image-id]');
        pendingImageDelete = deleteBtn.getAttribute('data-image-id');
        pendingImageElement = deleteBtn.closest('.col-md-3');
        
        // Show the confirmation modal
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteImageConfirmModal'));
        deleteModal.show();
    }
});


    // Delete confirmation modal handler
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let deleteUrl = '';
    
    // Handle delete button clicks
    document.querySelectorAll('.delete-item').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const itemId = this.getAttribute('data-id');
            const itemName = this.getAttribute('data-name');
            const itemLocation = this.getAttribute('data-location');
            
            // Set the delete URL
            deleteUrl = `stock-in.php?delete=${itemId}`;
            
            // Update modal content
            document.getElementById('deleteItemInfo').innerHTML = `
                <strong><?php echo t('item_name');?>:</strong> ${itemName}<br>
                <strong><?php echo t('location_column');?>:</strong> ${itemLocation}
            `;
            
            // Show the modal
            deleteModal.show();
        });
    });
    
    // Handle confirm delete button click
    document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
        if (deleteUrl) {
            window.location.href = deleteUrl;
        }
    });
});
    // Function to handle image preview for a specific input
    function setupImagePreview(inputElement, previewContainerId) {
    const previewContainer = document.getElementById(previewContainerId);
    
    if (!previewContainer) return;
    
    inputElement.addEventListener('change', function(e) {
        previewContainer.innerHTML = '';
        
        if (this.files && this.files.length > 0) {
            Array.from(this.files).forEach((file, index) => {
                if (!file.type.match('image.*')) return;
                
                const reader = new FileReader();
                const previewWrapper = document.createElement('div');
                previewWrapper.className = 'col-3 image-preview-wrapper';
                
                reader.onload = function(event) {
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.className = 'image-preview';
                    img.alt = file.name;
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-preview';
                    removeBtn.innerHTML = '×';
                    removeBtn.title = 'Remove this image';
                    removeBtn.dataset.index = index;
                    
                    removeBtn.addEventListener('click', function() {
                        // Remove the preview
                        previewWrapper.remove();
                        
                        // Remove the file from the input
                        const dt = new DataTransfer();
                        const { files } = inputElement;
                        
                        for (let i = 0; i < files.length; i++) {
                            if (i !== parseInt(this.dataset.index)) {
                                dt.items.add(files[i]);
                            }
                        }
                        
                        inputElement.files = dt.files;
                    });
                    
                    previewWrapper.appendChild(img);
                    previewWrapper.appendChild(removeBtn);
                    previewContainer.appendChild(previewWrapper);
                };
                
                reader.readAsDataURL(file);
            });
        }
    });
}

// Initialize preview for the first row
document.addEventListener('DOMContentLoaded', function() {
    // Setup for the first row's image input
    const firstImageInput = document.querySelector('input[name="images[0][]"]');
    if (firstImageInput) {
        setupImagePreview(firstImageInput, 'image-preview-0');
    } document.querySelectorAll('.search-item-input').forEach(input => {
        const select = input.nextElementSibling;
        if (select && (select.classList.contains('add-item-select') || select.classList.contains('deduct-item-select'))) {
            setupSearchableDropdownForRow(input, select);
        }
    });
});

// Update the add-more-row event listener
document.getElementById('add-more-row').addEventListener('click', function() {
    const container = document.getElementById('items-container');
    const rowCount = container.querySelectorAll('.item-row').length;
    const locationSelect = container.querySelector('[name="location_id[]"]');
    const locationId = locationSelect ? locationSelect.value : '';
    
    if (!locationId) {
        var locationModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('location_column');?></label>
                <select class="form-select" name="location_id[]" required>
                    <option value=""><?php echo t('item_locations');?></option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" ${locationId == <?php echo $location['id']; ?> ? 'selected' : ''}>
                            <?php echo $location['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_code');?></label>
                <input type="text" class="form-control" name="item_code[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('category');?></label>
                <select class="form-select" name="category_id[]">
                    <option value=""><?php echo t('select_category');?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <label class="form-label"><?php echo t('item_name');?></label>
                <input type="text" class="form-control" name="name[]" required>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('low_stock_title');?></label>
                <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 mb-3">
                <label class="form-label"><?php echo t('item_photo');?></label>
                <input type="file" class="form-control item-images-input" name="images[${rowCount}][]" multiple accept="image/*">
                <div class="image-preview-container mt-2 row g-1" id="image-preview-${rowCount}"></div>
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
        </button>
    `;
    
    container.appendChild(newRow);
    
    // Setup image preview for the new row
    const newImageInput = newRow.querySelector('.item-images-input');
    setupImagePreview(newImageInput, `image-preview-${rowCount}`);
    
    // Add event listener for the remove button
    newRow.querySelector('.remove-row').addEventListener('click', function() {
        newRow.remove();
    });
});
// Handle location change for add quantity modal
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#add_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});
// For the initial add quantity row
document.querySelectorAll('.add-item-select').forEach(select => {
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const row = this.closest('.add-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    });
});

// For the initial deduct quantity row
document.querySelectorAll('.deduct-item-select').forEach(select => {
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const row = this.closest('.deduct-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    });
});
// Function to update item select dropdown
function updateItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}
// Handle location change for deduct quantity modal
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#deduct_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

// Function to update deduct item select dropdown
function updateDeductItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}

// Handle add more row for deduct quantity modal
document.getElementById('deduct-qty-more-row').addEventListener('click', function() {
    const container = document.getElementById('deduct_qty_items_container');
    const locationId = document.getElementById('deduct_location_id').value;
    const rowCount = container.querySelectorAll('.deduct-qty-item-row').length;

    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'deduct-qty-item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label"><?php echo t('item_name');?></label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php echo t('select_item');?>
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="dropdown-item-container">
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]"readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
        </button>
    `;
    
    container.appendChild(newRow);
    
    // Initialize the dropdown for the new row
    const dropdown = newRow.querySelector('.item-dropdown');
    populateItemDropdown(dropdown, locationId);
    
    // Initialize Bootstrap dropdown
    new bootstrap.Dropdown(newRow.querySelector('.dropdown-toggle'));
    
    // Add event listener for the remove button
    newRow.querySelector('.remove-row').addEventListener('click', function() {
        newRow.remove();
    });

    // Add validation for quantity input
    const newQuantityInput = newRow.querySelector('input[name="quantity[]"]');
    if (newQuantityInput) {
        newQuantityInput.addEventListener('input', function() {
            const itemIdInput = newRow.querySelector('.item-id-input');
            if (itemIdInput && itemIdInput.value) {
                const selectedItem = itemsByLocation[locationId].find(item => item.id == itemIdInput.value);
                if (selectedItem) {
                    const max = parseFloat(selectedItem.quantity);
                    const value = parseFloat(this.value);
                    
                    if (!isNaN(max) && !isNaN(value) && value > max) {
                        this.value = max;
                        alert('You cannot deduct more than the available quantity');
                    }
                }
            }
        });
    }
});

// Setup search functionality for a specific row
function setupSearchableDropdownForRow(searchInput, selectElement) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const options = selectElement.querySelectorAll('option');
        
        options.forEach(option => {
            if (option.value === '') {
                option.style.display = ''; // Always show the placeholder
                return;
            }
            
            const searchText = option.getAttribute('data-search');
            if (searchText.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    });
}
document.querySelectorAll('.search-item-input').forEach(input => {
    const select = input.nextElementSibling; // The select element follows the input
    if (select && select.classList.contains('add-item-select')) {
        setupSearchableDropdownForRow(input, select);
    }
});
// Initialize search functionality for existing rows
document.querySelectorAll('.search-item-input').forEach(input => {
    const select = input.nextElementSibling; // The select element follows the input
    setupSearchableDropdownForRow(input, select);
});

// Initialize the first row's select when location changes
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const firstSelect = document.querySelector('.deduct-item-select');
    if (firstSelect) {
        updateDeductItemSelect(firstSelect, locationId);
    }
});
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const container = document.getElementById('add_qty_items_container');
    const itemSelects = container.querySelectorAll('.add-item-select');
    
    // Update all existing item selects
    itemSelects.forEach(select => {
        updateItemSelect(select, locationId);
    });
});


// Function to update item select dropdown
function updateItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}

// Handle add more row for add quantity modal
document.getElementById('add-qty-more-row').addEventListener('click', function() {
    const container = document.getElementById('add_qty_items_container');
    const locationId = document.getElementById('add_location_id').value;

    const rowCount = container.querySelectorAll('.add-qty-item-row').length;
    
if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'add-qty-item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label"><?php echo t('item_name');?></label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php echo t('select_item');?>
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="dropdown-item-container">
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]"readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
        </button>
    `;
    
    container.appendChild(newRow);
    
    // Initialize the dropdown for the new row
    const dropdown = newRow.querySelector('.item-dropdown');
    populateItemDropdown(dropdown, locationId);
    
    // Initialize Bootstrap dropdown
    new bootstrap.Dropdown(newRow.querySelector('.dropdown-toggle'));
    
    // Add event listener for the remove button
    newRow.querySelector('.remove-row').addEventListener('click', function() {
        newRow.remove();
    });
});


// Add event listener for item selection in add quantity modal
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('add-item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('.add-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    }
    
    // Same for deduct quantity modal
    if (e.target.classList.contains('deduct-item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('.deduct-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    }
});
// Store these variables at the top of your script
let pendingFileInput = null;
let pendingPreviewContainer = null;


    


// Handle confirm button click
document.getElementById('confirmReplaceBtn').addEventListener('click', function() {
    if (pendingFileInput && pendingPreviewContainer) {
        // Clear all existing images
        pendingPreviewContainer.innerHTML = '';
        
        // Process the new images
        processNewImages(pendingFileInput, pendingPreviewContainer);
        
        // Hide the modal
        const replaceModal = bootstrap.Modal.getInstance(document.getElementById('replaceImageConfirmModal'));
        replaceModal.hide();
    }
});

function processNewImages(fileInput, previewContainer) {
    Array.from(fileInput.files).forEach((file, index) => {
        if (!file.type.match('image.*')) return;
        
        const reader = new FileReader();
        const col = document.createElement('div');
        col.className = 'col-md-3 mb-3 position-relative';
        
        reader.onload = function(event) {
            const img = document.createElement('img');
            img.src = event.target.result;
            img.className = 'img-thumbnail w-100';
            img.style.height = '150px';
            img.style.objectFit = 'cover';
            
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-1';
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            removeBtn.title = 'Remove this preview';
            removeBtn.dataset.index = index;
            
            removeBtn.addEventListener('click', function() {
                col.remove();
                
                // Update file input
                const dt = new DataTransfer();
                const { files } = fileInput;
                
                for (let i = 0; i < files.length; i++) {
                    if (i !== parseInt(this.dataset.index)) {
                        dt.items.add(files[i]);
                    }
                }
                
                fileInput.files = dt.files;
            });
            
            col.appendChild(img);
            col.appendChild(removeBtn);
            previewContainer.appendChild(col);
        };
        
        reader.readAsDataURL(file);
    });
}
// Handle location change for add quantity
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const itemSelect = document.getElementById('add_item_id');
    
    itemSelect.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                itemSelect.appendChild(option);
            });
        }
    }
});

// Handle location change for deduct quantity
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const itemSelect = document.getElementById('deduct_item_id');
    
    itemSelect.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                itemSelect.appendChild(option);
            });
        }
    }
});
// In your JavaScript, update the image gallery code to use display_image.php
document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
    img.addEventListener('click', function() {
        const itemId = this.getAttribute('data-item-id');
        fetch(`get_item_history_images.php?id=${itemId}`)
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
// Handle view item button click
document.querySelectorAll('.view-item').forEach(button => {
    button.addEventListener('click', function() {
        const itemId = this.getAttribute('data-id');
        
        // Show loading state
        document.getElementById('view_images').innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
        
        // Fetch item details
        fetch(`get_item_history_details.php?id=${itemId}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                // Update basic info - access the 'item' property
                const item = data.item;
                document.getElementById('view_item_code').textContent = item.item_code || 'N/A';
                document.getElementById('view_category').textContent = item.category_name || 'N/A';
                document.getElementById('view_invoice_no').textContent = item.invoice_no || 'N/A';
                document.getElementById('view_date').textContent = item.date || 'N/A';
                document.getElementById('view_name').textContent = item.name || 'N/A';
                document.getElementById('view_quantity').textContent = item.quantity || '0';
                document.getElementById('view_size').textContent = item.size || 'N/A';
                document.getElementById('view_location').textContent = item.location_name || 'N/A';
                document.getElementById('view_remark').textContent = item.remark || 'N/A';
                
                // Update images - access the 'images' property
                const imagesContainer = document.getElementById('view_images');
                imagesContainer.innerHTML = '';
                
                if (data.images && data.images.length > 0) {
                    data.images.forEach(image => {
                        const imgUrl = `display_image.php?id=${image.id}`;
                        
                        const col = document.createElement('div');
                        col.className = 'col-md-3 mb-3';
                        
                        const img = document.createElement('img');
                        img.src = imgUrl;
                        img.className = 'img-thumbnail w-100';
                        img.style.height = '150px';
                        img.style.objectFit = 'cover';
                        img.alt = item.name;
                        
                        col.appendChild(img);
                        imagesContainer.appendChild(col);
                    });
                } else {
                    imagesContainer.innerHTML = '<div class="col-12 text-muted"><?php echo t('no_photo');?></div>';
                }
                
                // Show modal
                const viewModal = new bootstrap.Modal(document.getElementById('viewItemModal'));
                viewModal.show();
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('view_images').innerHTML = '<div class="col-12 text-danger">Error loading item details</div>';
            });
    });
});
// Search functionality for item dropdowns
function setupSearchableDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control mb-2';
    searchInput.placeholder = '<?php echo t('search');?>...';
    
    dropdown.parentNode.insertBefore(searchInput, dropdown);
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const options = dropdown.getElementsByTagName('option');
        
        for (let i = 0; i < options.length; i++) {
            const option = options[i];
            const text = option.textContent.toLowerCase();
            
            if (text.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        }
    });
}
// Function to set today's date in a date input
function setTodayDate(inputId) {
    const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    document.getElementById(inputId).value = formattedDate;
}
// Add this event listener for the deduct quantity inputs
document.addEventListener('input', function(e) {
    if (e.target.matches('#deduct_qty_items_container input[name="quantity[]"]')) {
        const input = e.target;
        const max = parseFloat(input.max);
        const value = parseFloat(input.value);
        
        if (!isNaN(max) && !isNaN(value) && value > max) {
            const modal = new bootstrap.Modal(document.getElementById('quantityExceedModal'));
                    const message = document.getElementById('quantityExceedMessage');
                    
                    // Update the message with specific quantities
                    message.innerHTML = `
                        <?php echo t('qty_issue4');?> <strong>${max}</strong> ( <?php echo t('qty_issue5');?>)<br>
                         <?php echo t('qty_issue6');?>: <strong>${value}</strong>
                    `;
                    
                    modal.show();
        }
    }
});
// Set dates when modals are shown
document.getElementById('addItemModal').addEventListener('shown.bs.modal', function() {
    setTodayDate('date');
});

document.getElementById('addQtyModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('#add_qty_items_container .item-dropdown');
    const locationId = document.getElementById('add_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('add_date').valueAsDate = new Date();
});

document.getElementById('deductQtyModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('#deduct_qty_items_container .item-dropdown');
    const locationId = document.getElementById('deduct_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('deduct_date').valueAsDate = new Date();
});

// Initialize searchable dropdowns
setupSearchableDropdown('add_item_id');
setupSearchableDropdown('deduct_item_id');
</script>

<?php
require_once '../includes/footer.php';
?>