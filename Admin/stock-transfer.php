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



// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_items'])) {
    $invoice_no = sanitizeInput($_POST['invoice_no']);
    $date = sanitizeInput($_POST['date']);
    $from_location_id = (int)$_POST['from_location_id'];
    $to_location_id = (int)$_POST['to_location_id'];
    
    // Validate date format
    if (!strtotime($date)) {
        $_SESSION['error'] = t('invalid_date_format');
        redirect('stock-transfer.php');
    }
    
    // Validate locations are different
    if ($from_location_id === $to_location_id) {
        $_SESSION['error'] = t('cannot_transfer_to_same_location');
        redirect('stock-transfer.php');
    }
    
    // ... existing code before the transfer loop ...

try {
    $pdo->beginTransaction();
    
    // Loop through each item
    foreach ($_POST['item_id'] as $index => $item_id) {
        $item_id = (int)$item_id;
        $quantity = (float)$_POST['quantity'][$index];
        $size = sanitizeInput($_POST['size'][$index] ?? '');
        $remark = sanitizeInput($_POST['remark'][$index] ?? '');
        
        // Get current item details including deporty_id
        $stmt = $pdo->prepare("SELECT quantity, name, item_code, category_id, size, deporty_id FROM items WHERE id = ? AND location_id = ?");
        $stmt->execute([$item_id, $from_location_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            throw new Exception(t('item_not_found_in_location'));
        }
        
        $old_qty = $item['quantity'];
        $item_name = $item['name'];
        $deporty_id = $item['deporty_id']; // Get the deporty_id from source item
        
        if ($quantity > $old_qty) {
            throw new Exception(t('cannot_transfer_more_than_available') . ": $item_name");
        }
        
        // Update quantity in source location
        $new_qty = $old_qty - $quantity;
        $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
        $stmt->execute([$new_qty, $item_id]);
        
        // Check if item exists in destination location
        $stmt = $pdo->prepare("SELECT id, quantity, deporty_id FROM items WHERE name = ? AND location_id = ?");
        $stmt->execute([$item_name, $to_location_id]);
        $dest_item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dest_item) {
            // Item exists in destination - update it
            $destination_item_id = $dest_item['id'];
            $dest_new_qty = $dest_item['quantity'] + $quantity;
            
            // If destination item has no deporty_id, update it with source deporty_id
            $update_deporty_id = $dest_item['deporty_id'] ? $dest_item['deporty_id'] : $deporty_id;
            
            $stmt = $pdo->prepare("UPDATE items SET quantity = ?, invoice_no = ?, remark = ?, date = ?, deporty_id = ? WHERE id = ?");
            $stmt->execute([$dest_new_qty, $invoice_no, $remark, $date, $update_deporty_id, $dest_item['id']]);
        } else {
            // Insert new item in destination with deporty_id
            $stmt = $pdo->prepare("INSERT INTO items 
            (item_code, category_id, date, invoice_no, name, quantity, size, location_id, remark, alert_quantity, deporty_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 10, ?)");
            $stmt->execute([
                $item['item_code'],
                $item['category_id'],
                $date,
                $invoice_no,
                $item_name,
                $quantity,
                $item['size'],
                $to_location_id,
                $remark,
                $deporty_id  // Include deporty_id here
            ]);
            $destination_item_id = $pdo->lastInsertId();
        }
        
        // Record in stock_in_history for the destination location with transfer action type and deporty_id
        $destination_item_id = $dest_item ? $dest_item['id'] : $pdo->lastInsertId();
        $action_type = 'transfer';
        
        // Now record in stock_in_history with the CORRECT destination item ID and deporty_id
        $stmt = $pdo->prepare("INSERT INTO stock_in_history 
            (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, deporty_id, remark, action_type, action_quantity, action_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $destination_item_id,
            $item['item_code'],
            $item['category_id'],
            $invoice_no,
            $date,
            $item_name,
            $dest_item ? ($dest_item['quantity'] + $quantity) : $quantity,
            10,
            $item['size'],
            $to_location_id,
            $deporty_id,  // Use the actual deporty_id
            "TRANSFER_FROM_" . $from_location_id,
            'transfer',
            $quantity,
            $_SESSION['user_id']
        ]);
        
        // Record in transfer history with deporty_id
        $stmt = $pdo->prepare("INSERT INTO transfer_history 
            (item_id, item_code, category_id, invoice_no, date, name, quantity, size, from_location_id, to_location_id, deporty_id, remark, action_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $item_id,
            $item['item_code'],
            $item['category_id'],
            $invoice_no,
            $date,
            $item_name,
            $quantity,
            $item['size'],
            $from_location_id,
            $to_location_id,
            $deporty_id,  // Add deporty_id here
            $remark,
            $_SESSION['user_id']
        ]);

        // Log activity including deporty information if available
        $stmt = $pdo->prepare("SELECT name FROM locations WHERE id IN (?, ?)");
        $stmt->execute([$from_location_id, $to_location_id]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Include deporty name in the log if available
        $deporty_info = "";
        if ($deporty_id) {
            $stmt = $pdo->prepare("SELECT name FROM deporty WHERE id = ?");
            $stmt->execute([$deporty_id]);
            $deporty = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($deporty) {
                $deporty_info = " [Deporty: {$deporty['name']}]";
            }
        }
        
        $log_message = "Transferred $item_name ($quantity $size) from {$locations[0]['name']} to {$locations[1]['name']}$deporty_info";
        logActivity($_SESSION['user_id'], 'Transfer', $log_message);
    }
    
    $pdo->commit();
    $_SESSION['success'] = t('transfer_success');
    redirect('stock-transfer.php');
} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['error'] = t('transfer_error') . ": " . $e->getMessage();
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

}

// Get filter parameters
$search_filter = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : 0;
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Validate and parse sort option
$sort_mapping = [
    'name_asc' => ['field' => 't.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 't.name', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 't.date, t.action_at', 'direction' => 'ASC'],
    'date_desc' => ['field' => 't.date DESC, t.action_at', 'direction' => 'DESC'],
    'quantity_asc' => ['field' => 't.quantity', 'direction' => 'ASC'],
    'quantity_desc' => ['field' => 't.quantity', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'fl.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'fl.name', 'direction' => 'DESC'],
    'action_by_asc' => ['field' => 'u.username', 'direction' => 'ASC'],
    'action_by_desc' => ['field' => 'u.username', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Get all locations
$stmt = $pdo->query("SELECT * FROM locations WHERE type != 'repair' ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for dropdowns
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size, remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

// Get transfer history with pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $per_page;
$offset = ($page - 1) * $limit;

// Update the transfer history query to include deporty information
$query = "SELECT 
    t.id,
    t.item_id,
    t.item_code, 
    t.category_id,
    c.name as category_name,
    t.invoice_no,
    t.date,
    t.name,
    t.quantity,
    t.size,
    t.from_location_id,
    fl.name as from_location_name,
    t.to_location_id,
    tl.name as to_location_name,
    t.deporty_id,
    d.name as deporty_name,
    t.remark,
    t.action_by,
    u.username as action_by_name,
    t.action_at,
    (SELECT id FROM item_images WHERE item_id = t.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    transfer_history t
LEFT JOIN 
    categories c ON t.category_id = c.id
JOIN 
    locations fl ON t.from_location_id = fl.id
JOIN 
    locations tl ON t.to_location_id = tl.id
LEFT JOIN 
    deporty d ON t.deporty_id = d.id 
JOIN
    users u ON t.action_by = u.id
WHERE 1=1";

$count_query = "SELECT COUNT(*) as total 
FROM transfer_history t
LEFT JOIN categories c ON t.category_id = c.id
JOIN locations fl ON t.from_location_id = fl.id
JOIN locations tl ON t.to_location_id = tl.id
JOIN users u ON t.action_by = u.id
WHERE 1=1";

$params = [];
$count_params = [];

// Apply filters
if ($search_filter) {
    $query .= " AND (t.name LIKE :search OR t.item_code LIKE :search OR t.invoice_no LIKE :search)";
    $count_query .= " AND (t.name LIKE :search OR t.item_code LIKE :search OR t.invoice_no LIKE :search)";
    $params[':search'] = "%$search_filter%";
    $count_params[':search'] = "%$search_filter%";
}

if ($category_filter > 0) {
    $query .= " AND t.category_id = :category_id";
    $count_query .= " AND t.category_id = :category_id";
    $params[':category_id'] = $category_filter;
    $count_params[':category_id'] = $category_filter;
}

if ($location_filter > 0) {
    $query .= " AND (t.from_location_id = :location_id OR t.to_location_id = :location_id)";
    $count_query .= " AND (t.from_location_id = :location_id OR t.to_location_id = :location_id)";
    $params[':location_id'] = $location_filter;
    $count_params[':location_id'] = $location_filter;
}

if ($month_filter && $month_filter !== 'all') {
    $query .= " AND MONTH(t.date) = :month";
    $count_query .= " AND MONTH(t.date) = :month";
    $params[':month'] = $month_filter;
    $count_params[':month'] = $month_filter;
}

if ($year_filter && $year_filter !== 'all') {
    $query .= " AND YEAR(t.date) = :year";
    $count_query .= " AND YEAR(t.date) = :year";
    $params[':year'] = $year_filter;
    $count_params[':year'] = $year_filter;
}

// Add sorting
$query .= " ORDER BY $sort_by $sort_order";

// Add pagination
$query .= " LIMIT :limit OFFSET :offset";

// Get total count
$stmt = $pdo->prepare($count_query);
foreach ($count_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get filtered data
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transfer_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available years from the transfer history
$stmt = $pdo->query("SELECT DISTINCT YEAR(date) as year FROM transfer_history ORDER BY year DESC");
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('stock_transfer'); ?></title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    
</head>
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
@media (max-width: 768px) {
    /* Make table horizontally scrollable instead of hiding headers */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        display: block;
        width: 100%;
    }
    
    /* Keep table headers visible */
    .table-responsive table {
        min-width: 600px; /* Minimum width to ensure content fits */
        width: 100%;
    }
    
    .table-responsive thead,
    .table-responsive th {
        display: table-header-group;
        position: static;
        top: auto;
        left: auto;
    }
    
    .table-responsive tr {
        display: table-row;
        margin-bottom: 0;
        border: none;
        border-radius: 0;
        box-shadow: none;
    }
    
    .table-responsive td {
        display: table-cell;
        padding: 0.75rem;
        padding-left: 0.75rem;
        white-space: nowrap;
        text-align: left;
        border-bottom: 1px solid #dee2e6;
    }
    
    .table-responsive td:before {
        display: none; /* Remove the pseudo-elements that showed labels */
    }
}

/* For very small screens, add some padding adjustments */
@media (max-width: 576px) {
    .table-responsive {
        margin-left: -0.5rem;
        margin-right: -0.5rem;
    }
    
    .card-body {
        padding: 0.75rem;
    }
}

/* Add a visual indicator that table can be scrolled horizontally on mobile */
.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.table-responsive {
    scrollbar-width: thin;
    scrollbar-color: #c1c1c1 transparent;
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
@media (max-width: 768px) {
    #transferItemModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #transferItemModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #transferItemModal .modal-footer form {
        flex: 1;
    }
}
    </style>
<body>
      <!-- Add this button to your navbar -->
<button id="sidebarToggle" class="btn btn-primary d-md-none rounded-circle mr-3 no-print" style="position: fixed; bottom: 20px; right: 20px; z-index: 1000; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
    <i class="bi bi-list"></i>
</button>
<div class="container-fluid">
        <h2 class="mb-4"><?php echo t('transfer_items'); ?></h2>

        <!-- Filter Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
            </div>
            <div class="card-body">
                <form method="GET" class="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label"><?php echo t('names'); ?></label>
                            <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search_filter); ?>" placeholder="<?php echo t('search'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label"><?php echo t('category'); ?></label>
                            <select name="category" class="form-select">
                                <option value="0"><?php echo t('all_categories'); ?></option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo $category['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label"><?php echo t('location'); ?></label>
                            <select name="location" class="form-select">
                                <option value="0"><?php echo t('report_all_location'); ?></option>
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
                                <option value="all"><?php echo t('all_months'); ?></option>
                                <?php foreach ($months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" <?php echo $month_filter == $num ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label"><?php echo t('year'); ?></label>
                            <select name="year" class="form-select">
                                <option value="all"><?php echo t('all_years'); ?></option>
                                <?php foreach ($available_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label"><?php echo t('sort'); ?></label>
                            <select name="sort_option" class="form-select sort-select">
                                <option value="name_asc" <?php echo $sort_option == 'name_asc' ? 'selected' : ''; ?>>
                                    <?php echo t('name_a_to_z'); ?>
                                </option>
                                <option value="name_desc" <?php echo $sort_option == 'name_desc' ? 'selected' : ''; ?>>
                                    <?php echo t('name_z_to_a'); ?>
                                </option>
                                <option value="category_asc" <?php echo $sort_option == 'category_asc' ? 'selected' : ''; ?>>
                                    <?php echo t('category_az'); ?>
                                </option>
                                <option value="category_desc" <?php echo $sort_option == 'category_desc' ? 'selected' : ''; ?>>
                                    <?php echo t('category_za'); ?>
                                </option>
                                <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>>
                                    <?php echo t('date_oldest_first'); ?>
                                </option>
                                <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>>
                                    <?php echo t('date_newest_first'); ?>
                                </option>

                            </select>
                        </div>
                        <div class="col-md-2">
                        <label class="filter-label"><?php echo t('show_entries'); ?></label>
                   
            <select class="form-select form-select-sm" id="per_page_select">
                <?php foreach ($limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary">
                           <?php echo t('search'); ?>
                        </button>
                        <a href="stock-transfer.php" class="btn btn-secondary">
                          <?php echo t('reset'); ?>
                        </a>
                    </div>
                    
                    <input type="hidden" name="page" value="1">
                </form>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?php echo t('transfer_items'); ?></h5>
                <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#transferItemModal">
                    <i class="bi bi-arrow-left-right"></i> <?php echo t('transfer_history'); ?>
                </button>
            </div>
            <div class="card-body">
                <?php if (!empty($search_filter) || $category_filter > 0 || $location_filter > 0 || ($month_filter && $month_filter !== 'all') || ($year_filter && $year_filter !== 'all')): ?>
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i> 
                        <?php echo t('showing_filtered_results'); ?>
                        <?php if (!empty($search_filter)): ?>
                            <span class="badge bg-secondary"><?php echo t('search'); ?>: <?php echo htmlspecialchars($search_filter); ?></span>
                        <?php endif; ?>
                        <?php if ($category_filter > 0): 
                            $category_name = '';
                            foreach ($categories as $cat) {
                                if ($cat['id'] == $category_filter) {
                                    $category_name = $cat['name'];
                                    break;
                                }
                            }
                        ?>
                            <span class="badge bg-secondary"><?php echo t('category'); ?>: <?php echo $category_name; ?></span>
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
                            <span class="badge bg-secondary"><?php echo t('location'); ?>: <?php echo $location_name; ?></span>
                        <?php endif; ?>
                        <?php if ($month_filter && $month_filter !== 'all'): ?>
                            <span class="badge bg-secondary"><?php echo t('month'); ?>: <?php echo $months[$month_filter]; ?></span>
                        <?php endif; ?>
                        <?php if ($year_filter && $year_filter !== 'all'): ?>
                            <span class="badge bg-secondary"><?php echo t('year'); ?>: <?php echo $year_filter; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
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
                                <th><?php echo t('unit'); ?></th>
                                <th><?php echo t('deporty'); ?></th>
                                <th><?php echo t('from_location'); ?></th>
                                <th><?php echo t('to_location'); ?></th>
                                <th><?php echo t('item_remark'); ?></th>
                                <th><?php echo t('item_photo'); ?></th>
                                <th><?php echo t('action_by'); ?></th>
                                <th><?php echo t('action_at'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transfer_history)): ?>
                                <tr>
                                    <td colspan="14" class="text-center"><?php echo t('no_transfer_history'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transfer_history as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1 + $offset; ?></td>
                                        <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['invoice_no']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                        <td><?php echo $item['name']; ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo $item['size']; ?></td>
                                        <td>
    <?php if ($item['deporty_name']): ?>
        <?php echo htmlspecialchars($item['deporty_name']); ?>
    <?php else: ?>
        <span class="badge bg-secondary"><?php echo "N/A" ?></span>
    <?php endif; ?>
</td>
                                        <td><?php echo $item['from_location_name']; ?></td>
                                        <td><?php echo $item['to_location_name']; ?></td>
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
                
                <!-- Pagination - Always show pagination controls -->
                <?php if ($total_items > 0): ?>
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
            </div>
        </div>
    </div>
<!-- Transfer Item Modal -->
<div class="modal fade" id="transferItemModal" tabindex="-1" aria-labelledby="transferItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="transferItemModalLabel"><?php echo t('transfer_items'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="transfer_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="transfer_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="transfer_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- From Location selection -->
                    <div class="mb-3">
                        <label for="from_location_id" class="form-label"><?php echo t('from_location'); ?></label>
                        <select class="form-select" id="from_location_id" name="from_location_id" required>
                            <option value=""><?php echo t(''); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- To Location selection -->
                    <div class="mb-3">
                        <label for="to_location_id" class="form-label"><?php echo t('to_location'); ?></label>
                        <select class="form-select" id="to_location_id" name="to_location_id" required>
                            <option value=""><?php echo t(''); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="transfer_items_container">
                        <!-- First item row -->
                        <div class="transfer-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label"><?php echo t('item_name'); ?></label>
                                    <div class="dropdown item-dropdown">
                                        <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t(''); ?>
                                        </button>
                                        <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                        <ul class="dropdown-menu custom-dropdown-menu p-2">
                                            <li>
                                                <div class="px-2 mb-2">
                                                    <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item'); ?>...">
                                                </div>
                                            </li>
                                           
                                            <div class="dropdown-item-container">
                                                <div class="px-2 py-1 text-muted"><?php echo t(''); ?></div>
                                            </div>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('unit'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="transfer-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="transfer_items" class="btn btn-info"><?php echo t('transferss'); ?></button>
                </div>
            </form>
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
<!-- Select Location Modal -->
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
    <!-- Quantity Exceed Modal -->
    <div class="modal fade" id="quantityExceedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning'); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <h4 class="text-danger mb-3"><?php echo t('quantity_exceeded'); ?></h4>
                    <p id="quantityExceedMessage" style="text-align:left;"></p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                        <i class="bi bi-check-circle"></i> <?php echo t('understand'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
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
    // Store items by location data
    const itemsByLocation = <?php echo json_encode($items_by_location); ?>;

    // Function to populate item dropdown
    function populateItemDropdown(dropdownElement, locationId) {
        const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
        const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
        const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
        const hiddenInput = dropdownElement.querySelector('.item-id-input');
        
        // Clear previous items
        itemContainer.innerHTML = '';
        
        if (!locationId) {
            itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('select_from_location_first'); ?></div>';
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
                            
                            const row = dropdownElement.closest('.transfer-item-row');
                            const sizeInput = row.querySelector('input[name="size[]"]');
                            const remarkInput = row.querySelector('input[name="remark[]"]');
                            
                            if (sizeInput) sizeInput.value = this.dataset.size;
                            if (remarkInput) remarkInput.value = this.dataset.remark;
                            
                            const quantityInput = row.querySelector('input[name="quantity[]"]');
                            if (quantityInput) {
                                quantityInput.setAttribute('max', this.dataset.maxQuantity);
                            }
                        });
                        
                        itemContainer.appendChild(itemElement);
                        hasVisibleItems = true;
                    }
                });
                
                if (!hasVisibleItems) {
                    itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item'); ?></div>';
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
            itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item_location'); ?></div>';
        }
    }

    // Handle from location change for transfer modal
    document.getElementById('from_location_id').addEventListener('change', function() {
        const locationId = this.value;
        const dropdowns = document.querySelectorAll('#transfer_items_container .item-dropdown');
        
        dropdowns.forEach(dropdown => {
            populateItemDropdown(dropdown, locationId);
        });
    });

    // Handle add more row for transfer modal
    document.getElementById('transfer-more-row').addEventListener('click', function() {
    const container = document.getElementById('transfer_items_container');
    const locationId = document.getElementById('from_location_id').value;
    const rowCount = container.querySelectorAll('.transfer-item-row').length;

    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'transfer-item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label"><?php echo t('item_name'); ?></label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php echo t(''); ?>
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search'); ?>...">
                            </div>
                        </li>
                       
                        <div class="dropdown-item-container">
                            <div class="px-2 py-1 text-muted"><?php echo t(''); ?></div>
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty'); ?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('unit'); ?></label>
                <input type="text" class="form-control" name="size[]" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark'); ?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row'); ?>
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
                        const modal = new bootstrap.Modal(document.getElementById('quantityExceedModal'));
                        const message = document.getElementById('quantityExceedMessage');
                        
                        message.innerHTML = `
                            <?php echo t('cannot_transfer_more_than_available'); ?>: <strong>${max}</strong><br>
                            <?php echo t('attempted_to_transfer'); ?>: <strong>${value}</strong>
                        `;
                        
                        modal.show();
                    }
                }
            }
        });
    }
});

    // Set up transfer modal when shown
    document.getElementById('transferItemModal').addEventListener('shown.bs.modal', function() {
        // Set today's date
        const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    document.getElementById('transfer_date').value = formattedDate;
    });

    // Image gallery functionality
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
                                <img src="../assets/images/no-image.png" 
                                     class="d-block w-100" 
                                     alt="No image"
                                     style="max-height: 70vh; object-fit: contain;">
                            </div>
                        `;
                    }
                });
        });
    });
// Sidebar toggle functionality for mobile
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebarToggle && sidebar && mainContent) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            mainContent.classList.toggle('show');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        if (window.innerWidth < 768 && sidebar && mainContent) {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickOnToggle = sidebarToggle.contains(event.target);
            
            if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                mainContent.classList.remove('show');
            }
        }
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
</body>
</html>