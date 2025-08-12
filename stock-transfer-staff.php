<?php
ob_start();

// Includes in correct order
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/header-staff.php';
require_once  'translate.php'; 
if (!isStaff()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard.php');
  exit();
}
checkAuth();

// Handle form submission
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_item'])) {
  $invoice_no = sanitizeInput($_POST['invoice_no']);
  $date = sanitizeInput($_POST['date']);
  $from_location_id = (int)$_POST['from_location_id'];
  $to_location_id = (int)$_POST['to_location_id'];
  $item_ids = $_POST['item_id'] ?? [];
  $quantities = $_POST['quantity'] ?? [];
  $sizes = $_POST['size'] ?? [];
  $remarks = $_POST['remark'] ?? [];
  
  try {
    $pdo->beginTransaction();
    
    // Get location names once at the start
    $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE id IN (?, ?)");
    $stmt->execute([$from_location_id, $to_location_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $from_location_name = $locations[$from_location_id] ?? 'Unknown';
    $to_location_name = $locations[$to_location_id] ?? 'Unknown';
    
    // Process each item
for ($i = 0; $i < count($item_ids); $i++) {
  $item_id = (int)$item_ids[$i];
  $quantity = (float)$quantities[$i];
  $size = sanitizeInput($sizes[$i] ?? '');
  $remark = sanitizeInput($remarks[$i] ?? '');
  $no_itm=t('no_itm');
  $tran_qty_excc=t('tran_qty_excc');
  if ($item_id <= 0 || $quantity <= 0) continue;
  
  // Check if item exists in from location with sufficient quantity
  $stmt = $pdo->prepare("SELECT quantity, name FROM items WHERE id = ? AND location_id = ?");
  $stmt->execute([$item_id, $from_location_id]);
  $item = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$item) {
      throw new Exception("$no_itm");
  }
  
  if ($item['quantity'] < $quantity) {
      throw new Exception("$tran_qty_excc");
  }
  
  // Deduct quantity from source location
  $new_qty = $item['quantity'] - $quantity;
  $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
  $stmt->execute([$new_qty, $item_id]);
  
  // Check if item exists in destination location
  $stmt = $pdo->prepare("SELECT id, quantity FROM items WHERE name = ? AND location_id = ?");
  $stmt->execute([$item['name'], $to_location_id]);
  $dest_item = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if ($dest_item) {
      // Update quantity in destination
      $new_dest_qty = $dest_item['quantity'] + $quantity;
      $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
      $stmt->execute([$new_dest_qty, $dest_item['id']]);
      $new_item_id = $dest_item['id'];
  } else {
      // Create new item in destination
      $stmt = $pdo->prepare("INSERT INTO items (invoice_no, date, name, quantity, size, location_id, remark) 
                            SELECT ?, ?, name, ?, size, ?, ? FROM items WHERE id = ?");
      $stmt->execute([$invoice_no, $date, $quantity, $to_location_id, $remark, $item_id]);
      $new_item_id = $pdo->lastInsertId();
      
      // Copy images from original item to new item
      $stmt = $pdo->prepare("SELECT image_path FROM item_images WHERE item_id = ?");
      $stmt->execute([$item_id]);
      $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
      
      foreach ($images as $image) {
          $stmt = $pdo->prepare("INSERT INTO item_images (item_id, image_path) VALUES (?, ?)");
          $stmt->execute([$new_item_id, $image['image_path']]);
      }
  }
  
  // Record the transfer
  $stmt = $pdo->prepare("INSERT INTO stock_transfers 
  (invoice_no, date, item_id, from_location_id, to_location_id, quantity, size, remark, user_id) 
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->execute([
    $invoice_no, 
    $date, 
    $item_id, 
    $from_location_id, 
    $to_location_id, 
    $quantity, 
    $size,
    $remark,
    $_SESSION['user_id'] 
  ]);        
  
  // Log each item transfer separately
  logActivity($_SESSION['user_id'], 'Stock Transfer', "Stock Transfered: {$item['name']}($quantity $size) from $from_location_name → $to_location_name");
}
    
    $pdo->commit();
    $succ_tran=t('succ_tran');
    $err_tran=t('err_tran');
    $_SESSION['success'] = "$succ_tran";
    redirect('stock-transfer-staff.php');
} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "$err_tran";
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // Get transfer info
        $stmt = $pdo->prepare("SELECT st.*, i.name as item_name, l1.name as from_location, l2.name as to_location 
                              FROM stock_transfers st
                              JOIN items i ON st.item_id = i.id
                              JOIN locations l1 ON st.from_location_id = l1.id
                              JOIN locations l2 ON st.to_location_id = l2.id
                              WHERE st.id = ?");
        $stmt->execute([$id]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transfer) {
    
            
            // Deduct quantity from destination location
            $stmt = $pdo->prepare("UPDATE items SET quantity = quantity - ? WHERE name = ? AND location_id = ?");
            $stmt->execute([$transfer['quantity'], $transfer['item_name'], $transfer['to_location_id']]);
            
            // Delete the transfer record
            $stmt = $pdo->prepare("DELETE FROM stock_transfers WHERE id = ?");
            $stmt->execute([$id]);
            $del_tran_succ=t('del_tran_succ');
            $no_transfers=t('no_transfers');
            $err_del_tran=t('err_del_tran');

            logActivity($_SESSION['user_id'], 'Delete Stock Transfer', "Deleted Transfer: {$transfer['item_name']} ({$transfer['quantity']} {$transfer['size']}) from {$transfer['from_location']} → {$transfer['to_location']}");
            $_SESSION['success'] = "$del_tran_succ";
        } else {
            $_SESSION['error'] = "$no_transfers";
        }
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "$err_del_tran";
    }
    
    redirect('stock-transfer-staff.php');
}

// Get filter parameters
$from_location_filter = isset($_GET['from_location']) ? (int)$_GET['from_location'] : null;
$to_location_filter = isset($_GET['to_location']) ? (int)$_GET['to_location'] : null;
$month_filter = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year_filter = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query for transfers
$query = "SELECT st.*, i.name as item_name, l1.name as from_location, l2.name as to_location 
          FROM stock_transfers st
          JOIN items i ON st.item_id = i.id
          JOIN locations l1 ON st.from_location_id = l1.id
          JOIN locations l2 ON st.to_location_id = l2.id
          WHERE MONTH(st.date) = :month AND YEAR(st.date) = :year";
$params = [':month' => $month_filter, ':year' => $year_filter];

if ($from_location_filter) {
    $query .= " AND st.from_location_id = :from_location_id";
    $params[':from_location_id'] = $from_location_filter;
}

if ($to_location_filter) {
    $query .= " AND st.to_location_id = :to_location_id";
    $params[':to_location_id'] = $to_location_filter;
}

if ($search_query) {
    $query .= " AND (i.name LIKE :search OR st.invoice_no LIKE :search OR st.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$query .= " ORDER BY st.date DESC, st.created_at DESC";

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$stmt = $pdo->prepare(str_replace('SELECT st.*, i.name as item_name, l1.name as from_location, l2.name as to_location', 'SELECT COUNT(*) as total', $query));
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_transfers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_transfers / $limit);

// Get transfers with pagination
$query .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdowns
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for dropdown
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size,remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!-- Add these to your includes/header.php -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
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
#locationAlertModal {
    z-index: 1060 !important; /* Higher than Bootstrap's default 1050 */
}

/* Ensure the modal backdrop is also above the transfer modal */
.modal-backdrop.show:nth-of-type(even) {
    z-index: 1055 !important;
}
.custom-dropdown-menu {
    width: 100%;
    min-width: 15rem;
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
</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('transfers_button');?></h2>
    
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <button class="btn btn-light btn-sm float-end" data-bs-toggle="modal" data-bs-target="#transferModal">
                <i class="bi bi-arrow-left-right"></i> <?php echo t('transfers_button');?>
            </button>
            <h5 class="mb-0"><?php echo t('transfer_list');?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-10">
                    <form method="GET" class="row g-2">
                    <div class="col-md-2">
                    <input type="text" name="search" class="form-control" placeholder="<?php echo t('search');?>..." value="<?php echo $search_query; ?>">
</div>

                     <!--   <div class="col-md-3">
                            <select name="from_location" class="form-select">
                                <option value="">ទីតាំងដើមទាំងអស់</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $from_location_filter == $location['id'] ? 'selected' : ''; ?>>
                                        <?php echo $location['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>-->
                        <div class="col-md-2">
                            <select name="to_location" class="form-select">
                                <option value=""><?php echo t('report_all_location');?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $to_location_filter == $location['id'] ? 'selected' : ''; ?>>
                                        <?php echo $location['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="month" class="form-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="year" class="form-select">
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
            <a href="stock-transfer-staff.php" class="btn btn-danger w-100">Reset</a>
        </div>
                    </form>
                </div>
             
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no');?></th>
                            <th><?php echo t('item_invoice');?></th>
                            <th><?php echo t('item_date');?></th>
                            <th><?php echo t('item_name');?></th>
                            <th><?php echo t('item_qty');?></th>
                            <th><?php echo t('from_location');?></th>
                            <th><?php echo t('to_location');?></th>
                            
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" class="text-center"><?php echo t('no_transfer');?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $index => $transfer): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $transfer['invoice_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($transfer['date'])); ?></td>
                                    <td><?php echo $transfer['item_name']; ?></td>
                                    <td><?php echo $transfer['quantity']; ?></td>
                                    <td><?php echo $transfer['from_location']; ?></td>
                                    <td><?php echo $transfer['to_location']; ?></td>
                                  
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
              <!-- Pagination -->
<nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                <span aria-hidden="true">&laquo;&laquo;</span>
            </a>
        </li>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                <span aria-hidden="true">&laquo;</span>
            </a>
        </li>

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

        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
            </a>
        </li>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                <span aria-hidden="true">&raquo;&raquo;</span>
            </a>
        </li>
    </ul>
      </nav>
    <div class="text-center text-muted">
        <?php echo t('page');?> <?php echo $page; ?> <?php echo t('page_of');?> <?php echo $total_pages; ?> 
    </div>

<!-- Nested Location Alert Modal -->
<div class="modal fade" id="locationAlertModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i><?php echo t('warning');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('warning_location1');?></h4>
                <p><?php echo t('warning_location2');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_transfer');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_transfer2');?></h4>
                <p><?php echo t('del_usr2');?></p>
                <div id="deleteItemInfo" class="alert alert-light mt-3">
                   
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="transferModalLabel"><?php echo t('transfers_button');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                <div id="items-transfer-container">
                        <div class="transfer-item-row mb-3 border p-3 rounded">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="transfer_invoice_no" class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" id="transfer_invoice_no" name="invoice_no">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="transfer_date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="transfer_date" name="date" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="from_location_id" class="form-label"><?php echo t('from_location');?></label>
                            <select class="form-select from-location" id="from_location_id" name="from_location_id" required>
                                <option value=""><?php echo t('select_from_location');?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
<!-- Replace the item selection part in your transfer modal with this: -->
<div class="col-md-6 mb-3">
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
            <div class="dropdown-item-container" style="max-height: 200px; overflow-y: auto;">
                <div class="px-2 py-1 text-muted"><?php echo t('select_location');?></div>
            </div>
        </ul>
    </div>
</div>                       </div>
                                <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_qty');?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_size');?></label>
                                    <input type="text" class="form-control" name="size[]">
                                </div>
                                </div>
                                <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark');?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                        <div class="col-md-6 mb-3">
                            <label for="to_location_id" class="form-label"><?php echo t('to_location');?></label>
                            <select class="form-select" id="to_location_id" name="to_location_id" required>
                                <option value=""><?php echo t('select_to_location');?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </div>
                                </div>
                    </div>
                                <button type="button" id="add-more-items" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="transfer_item" class="btn btn-primary"><?php echo t('transfers_button');?></button>
                </div>    
                </div>
            </form>
        </div>
    </div>
</div>

<script>
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
// Delete confirmation modal handling
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    const deleteButtons = document.querySelectorAll('.delete-btn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Set the data in the modal
            const itemName = this.getAttribute('data-item-name');
            const quantity = this.getAttribute('data-quantity');
            const fromLocation = this.getAttribute('data-from-location');
            const toLocation = this.getAttribute('data-to-location');
            
            document.getElementById('deleteItemInfo').innerHTML = `
                <strong><?php echo t('item_name');?>:</strong> ${itemName}<br>
                <strong><?php echo t('item_qty');?>:</strong> ${quantity}<br>
                <strong><?php echo t('from_location');?>:</strong> ${fromLocation}<br>
                <strong><?php echo t('to_location');?>:</strong> ${toLocation}
            `;
            
            // Set the delete URL
            const deleteUrl = `stock-transfer-staff.php?delete=${this.getAttribute('data-id')}`;
            confirmDeleteBtn.setAttribute('href', deleteUrl);
            
            // Show the modal
            deleteModal.show();
        });
    });
});

const itemsByLocation = <?php echo json_encode($items_by_location); ?>;

// Function to populate item dropdown
function populateItemDropdown(dropdownElement, locationId) {
    const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
    const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
    const hiddenInput = dropdownElement.querySelector('.item-id-input');
    
    // Clear previous items
    itemContainer.innerHTML = '';
    
    if (!locationId) {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('select_location');?></div>';
        return;
    }
    
    if (itemsByLocation[locationId] && itemsByLocation[locationId].length > 0) {
        // Store original items for this location
        const originalItems = itemsByLocation[locationId];
        
        // Function to render items based on search term
        const renderItems = (searchTerm = '') => {
            itemContainer.innerHTML = '';
            let hasVisibleItems = false;
            
            originalItems.forEach(item => {
                const itemText = `${item.name} (${item.quantity} ${item.size || ''})`.trim().toLowerCase();
                const searchTermLower = searchTerm.toLowerCase();
                
                if (!searchTerm || itemText.includes(searchTermLower)) {
                    const itemElement = document.createElement('button');
                    itemElement.className = 'dropdown-item';
                    itemElement.type = 'button';
                    itemElement.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                    itemElement.dataset.id = item.id;
                    itemElement.dataset.size = item.size || '';
                    itemElement.dataset.remark = item.remark || '';
                    
                    itemElement.addEventListener('click', function() {
                        dropdownToggle.textContent = this.textContent;
                        hiddenInput.value = this.dataset.id;
                        
                        const row = dropdownElement.closest('.transfer-item-row');
                        const sizeInput = row.querySelector('input[name="size[]"]');
                        const remarkInput = row.querySelector('input[name="remark[]"]');
                        
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (remarkInput) remarkInput.value = this.dataset.remark;
                    });
                    
                    itemContainer.appendChild(itemElement);
                    hasVisibleItems = true;
                }
            });
            
            if (!hasVisibleItems && searchTerm) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item');?></div>';
            } else if (!hasVisibleItems) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item_location');?></div>';
            }
        };
        
        // Initial render with all items
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

// Initialize dropdown when modal is shown
document.getElementById('transferModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('.item-dropdown');
    const locationId = document.getElementById('from_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('transfer_date').valueAsDate = new Date();
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
// Add reset functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle form reset
    const resetButtons = document.querySelectorAll('.reset-filters');
    resetButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get the form
            const form = this.closest('form');
            
            // Reset all form elements
            form.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
                input.value = '';
            });
            
            form.querySelectorAll('select').forEach(select => {
                select.selectedIndex = 0;
            });
            
            // Submit the form (which will now have empty/default values)
            form.submit();
        });
    });
});
// Handle from location change for all item dropdowns
document.getElementById('from_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('.item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

// Update the event listener for adding new rows
document.getElementById('add-more-items').addEventListener('click', function() {
    const container = document.getElementById('items-transfer-container');
    const fromLocation = document.getElementById('from_location_id').value;
    
    if (!fromLocation) {
        const locationAlertModal = new bootstrap.Modal(
            document.getElementById('locationAlertModal'), 
            {
                backdrop: 'static',
                keyboard: false
            }
        );
        document.getElementById('locationAlertModal').style.zIndex = '1060';
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'transfer-item-row mb-3 border p-3 rounded';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-6 mb-3">
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
                        <div class="dropdown-item-container" style="max-height: 200px; overflow-y: auto;">
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger remove-item">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
        </button>
    `;
    
    container.appendChild(newRow);
    
    // Initialize the dropdown for the new row
    const dropdown = newRow.querySelector('.item-dropdown');
    populateItemDropdown(dropdown, fromLocation);
    
    // Initialize Bootstrap dropdown
    new bootstrap.Dropdown(newRow.querySelector('.dropdown-toggle'));
});

// Initialize the first dropdown when page loads
document.addEventListener('DOMContentLoaded', function() {
    const firstDropdown = document.querySelector('.item-dropdown');
    if (firstDropdown) {
        const initialLocation = document.getElementById('from_location_id').value;
        populateItemDropdown(firstDropdown, initialLocation);
    }
});

// Remove item row
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-item')) {
        const row = e.target.closest('.transfer-item-row');
        if (row && document.querySelectorAll('.transfer-item-row').length > 1) {
            row.remove();
        }
    }
});

// Auto-fill size and remark when item is selected
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('transfer-item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('.transfer-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
            
            // Set max quantity validation
            const maxQuantity = selectedOption.getAttribute('data-max-quantity');
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            if (maxQuantity && quantityInput) {
                quantityInput.setAttribute('max', maxQuantity);
                if (parseInt(quantityInput.value) > parseInt(maxQuantity)) {
                    quantityInput.value = maxQuantity;
                }
            }
        }
    }
});

// Initialize search functionality for existing rows
document.querySelectorAll('.search-item-input').forEach(input => {
    const select = input.nextElementSibling;
    if (select && select.classList.contains('transfer-item-select')) {
        setupSearchableDropdownForRow(input, select);
    }
});

// Set today's date as default
document.getElementById('transfer_date').valueAsDate = new Date();

// Initialize the first item select when location changes
document.getElementById('from_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const firstSelect = document.querySelector('.transfer-item-select');
    if (firstSelect) {
        populateItemDropdown(firstSelect, locationId);
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>