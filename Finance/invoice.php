<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header-finance.php';
require_once 'translate.php';

if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php');
    exit();
}
checkAuth();

// Get locations for dropdowns
$location_stmt = $pdo->query("SELECT * FROM locations WHERE type != 'repair' ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get deporties for dropdowns
$deporty_stmt = $pdo->query("SELECT * FROM deporty ORDER BY name");
$deporties = $deporty_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter parameters
$search_filter = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$deporty_filter = isset($_GET['deporty']) ? (int)$_GET['deporty'] : null;
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Sort mapping
$sort_mapping = [
    'receipt_asc' => ['field' => 'receipt_no', 'direction' => 'ASC'],
    'receipt_desc' => ['field' => 'receipt_no', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'date', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'deporty_asc' => ['field' => 'd.name', 'direction' => 'ASC'],
    'deporty_desc' => ['field' => 'd.name', 'direction' => 'DESC'],
    'price_asc' => ['field' => 'total_price', 'direction' => 'ASC'],
    'price_desc' => ['field' => 'total_price', 'direction' => 'DESC'],
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_invoice'])) {
        $receipt_no = sanitizeInput($_POST['receipt_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        $deporty_id = !empty($_POST['deporty_id']) ? (int)$_POST['deporty_id'] : null;
        $total_price = (float)$_POST['total_price'];
        $remark = sanitizeInput($_POST['remark'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Insert the invoice into the database
            $stmt = $pdo->prepare("INSERT INTO finance_invoice (receipt_no, date, location_id, deporty_id, total_price, remark, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$receipt_no, $date, $location_id, $deporty_id, $total_price, $remark, $_SESSION['user_id']]);
            $invoice_id = $pdo->lastInsertId();
            
            // File upload handling
            if (!empty($_FILES['invoice_image']['name']) && $_FILES['invoice_image']['error'] === UPLOAD_ERR_OK) {
                // Validate image type
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fileInfo, $_FILES['invoice_image']['tmp_name']);
                finfo_close($fileInfo);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/jpg'];
                
                if (!in_array($mime, $allowedMimes)) {
                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and AVIF images are allowed.");
                }
                
                // Read the file content
                $imageData = file_get_contents($_FILES['invoice_image']['tmp_name']);
                
                // Update the invoice with image data
                $stmt = $pdo->prepare("UPDATE finance_invoice SET image_data = ? WHERE id = ?");
                $stmt->execute([$imageData, $invoice_id]);
            }
            
            // Log activity
            $log_message = "Added New Invoice: Receipt #$receipt_no - Amount: $total_price";
            logActivity($_SESSION['user_id'], 'Create Invoice', $log_message);
            
            $pdo->commit();
            $_SESSION['success'] = t('invoice_added_success');
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['error'] = t('duplicate_receipt');
            } else {
                $_SESSION['error'] = t('invoice_add_error');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['edit_invoice'])) {
        $invoice_id = (int)$_POST['invoice_id'];
        $receipt_no = sanitizeInput($_POST['receipt_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        $deporty_id = !empty($_POST['deporty_id']) ? (int)$_POST['deporty_id'] : null;
        $total_price = (float)$_POST['total_price'];
        $remark = sanitizeInput($_POST['remark'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Check if receipt number already exists for another invoice
            $stmt = $pdo->prepare("SELECT id FROM finance_invoice WHERE receipt_no = ? AND id != ?");
            $stmt->execute([$receipt_no, $invoice_id]);
            
            if ($stmt->fetch()) {
                throw new Exception(t('duplicate_receipt'));
            }
            
            // Update the invoice
            $stmt = $pdo->prepare("UPDATE finance_invoice 
                SET receipt_no = ?, date = ?, location_id = ?, deporty_id = ?, total_price = ?, remark = ?, updated_at = NOW() 
                WHERE id = ?");
            $stmt->execute([$receipt_no, $date, $location_id, $deporty_id, $total_price, $remark, $invoice_id]);
            
            // Handle image upload if provided
            if (!empty($_FILES['invoice_image']['name']) && $_FILES['invoice_image']['error'] === UPLOAD_ERR_OK) {
                // Validate image type
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fileInfo, $_FILES['invoice_image']['tmp_name']);
                finfo_close($fileInfo);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/jpg'];
                
                if (!in_array($mime, $allowedMimes)) {
                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and AVIF images are allowed.");
                }
                
                // Read the file content
                $imageData = file_get_contents($_FILES['invoice_image']['tmp_name']);
                
                // Update image data
                $stmt = $pdo->prepare("UPDATE finance_invoice SET image_data = ? WHERE id = ?");
                $stmt->execute([$imageData, $invoice_id]);
            }
            
            // Log activity
            $log_message = "Updated Invoice: Receipt #$receipt_no - Amount: $total_price";
            logActivity($_SESSION['user_id'], 'Update Invoice', $log_message);
            
            $pdo->commit();
            $_SESSION['success'] = t('invoice_updated_success');
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = t('invoice_update_error');
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['delete_invoice'])) {
        $invoice_id = (int)$_POST['invoice_id'];
        
        try {
            // Get invoice info for logging
            $stmt = $pdo->prepare("SELECT receipt_no, total_price FROM finance_invoice WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                throw new Exception(t('invoice_not_found'));
            }
            
            // Delete the invoice
            $stmt = $pdo->prepare("DELETE FROM finance_invoice WHERE id = ?");
            $stmt->execute([$invoice_id]);
            
            // Log activity
            $log_message = "Deleted Invoice: Receipt #" . $invoice['receipt_no'] . " - Amount: " . $invoice['total_price'];
            logActivity($_SESSION['user_id'], 'Delete Invoice', $log_message);
            
            $_SESSION['success'] = t('invoice_deleted_success');
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = t('invoice_delete_error');
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
}

// Pagination
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $per_page;
$offset = ($page - 1) * $limit;

// Build query for invoices
$query = "SELECT 
    fi.id,
    fi.receipt_no,
    fi.date,
    fi.location_id,
    l.name as location_name,
    fi.deporty_id,
    d.name as deporty_name,
    fi.total_price,
    fi.remark,
    fi.image_data,
    fi.created_by,
    u.username as created_by_name,
    fi.created_at
FROM 
    finance_invoice fi
LEFT JOIN 
    locations l ON fi.location_id = l.id
LEFT JOIN 
    deporty d ON fi.deporty_id = d.id
LEFT JOIN
    users u ON fi.created_by = u.id
WHERE 1=1";

$params = [];
$count_params = [];

// Add filters
if ($search_filter) {
    $query .= " AND (fi.receipt_no LIKE :search OR fi.remark LIKE :search)";
    $params[':search'] = "%$search_filter%";
    $count_params[':search'] = "%$search_filter%";
}

if ($location_filter) {
    $query .= " AND fi.location_id = :location_id";
    $params[':location_id'] = $location_filter;
    $count_params[':location_id'] = $location_filter;
}

if ($deporty_filter) {
    $query .= " AND fi.deporty_id = :deporty_id";
    $params[':deporty_id'] = $deporty_filter;
    $count_params[':deporty_id'] = $deporty_filter;
}

if ($year_filter && $year_filter != '0') {
    $query .= " AND YEAR(fi.date) = :year";
    $params[':year'] = $year_filter;
    $count_params[':year'] = $year_filter;
}

if ($month_filter && $month_filter != '0') {
    $query .= " AND MONTH(fi.date) = :month";
    $params[':month'] = $month_filter;
    $count_params[':month'] = $month_filter;
}

// Order by
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM finance_invoice fi
                LEFT JOIN locations l ON fi.location_id = l.id
                LEFT JOIN deporty d ON fi.deporty_id = d.id
                WHERE 1=1";

if ($search_filter) {
    $count_query .= " AND (fi.receipt_no LIKE :search OR fi.remark LIKE :search)";
}

if ($location_filter) {
    $count_query .= " AND fi.location_id = :location_id";
}

if ($deporty_filter) {
    $count_query .= " AND fi.deporty_id = :deporty_id";
}

if ($year_filter && $year_filter != '0') {
    $count_query .= " AND YEAR(fi.date) = :year";
}

if ($month_filter && $month_filter != '0') {
    $count_query .= " AND MONTH(fi.date) = :month";
}

$count_stmt = $pdo->prepare($count_query);
foreach ($count_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
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
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
    :root {
  --primary: #0d6efd;
  --primary-dark: #0d6efd;
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
  color: var(--primary-dark);
  border-color: var(--primary-dark);
}

.btn-outline-primary:hover {
  background-color: var(--primary-dark);
  border-color: var(--primary-dark);
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
  --primary: #0d6efd;
  --primary-dark: #0d6efd;
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

    padding: 8px 20px;
    font-weight: 600;
}

#deleteUserInfo {
    text-align: left;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
}

#unblockConfirmModal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}
#unblockUserInfo{
  text-align:left;
  background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
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
@media (max-width: 767.98px) {
    /* Adjust main content width when sidebar is hidden */
    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Optional: Add some padding to table cells for better mobile readability */
    .table td, .table th {
        padding: 8px 12px;
        white-space: nowrap; /* Prevent text wrapping */
    }
    
    /* Optional: Make the table a bit more compact on mobile */
    .table {
        font-size: 0.9rem;
    }
    /* Make modals full width */
    .modal-dialog {
        margin: 0.5rem auto;
        max-width: 95%;
    }
    
    /* Adjust card padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Make buttons full width */
    .btn {
        display: block;
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    /* Adjust form controls */
    .form-control, .form-select {
        font-size: 16px; /* prevents iOS zoom */
    }
}
#passwordMismatchModal {
    z-index: 1060 !important; /* Higher than Bootstrap's default 1050 */
}

/* Ensure the modal backdrop is also above the transfer modal */
.modal-backdrop.show:nth-of-type(even) {
    z-index: 1055 !important;
}
#duplicateEmailModal{
  z-index: 1060 !important; 
}

#duplicateUsernameModal {
    z-index: 1070 !important;
}

/* Animation for duplicate username modal */
@keyframes pulseWarning {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
    70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
}

#duplicateUsernameModal .modal-content {
    animation: pulseWarning 1.5s infinite;
    border: 2px solid #ffc107;
    box-shadow: 0 0 20px rgba(255, 193, 7, 0.4);
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
.btn-outline-orange {
        color: #ce7e00;
        border-color: #ce7e00;
        background-color: transparent;
    }
    
    .btn-outline-orange:hover {
        color: white;
        background-color: #ce7e00;
        border-color: #ce7e00;
    }
    .btn-outline-purple {
        color: #415A77;
        border-color: #415A77;
        background-color: transparent;
    }
    
    .btn-outline-purple:hover {
        color: white;
        background-color: #415A77;
        border-color: #415A77;
    }
    .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .stat-card {
            transition: transform 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .quick-actions-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
        }

        .quick-action-card {
            flex: 1;
            min-width: 160px;
            max-width: 200px;
        }
        /* Mobile view - one card per row */
        @media (max-width: 767.98px) {
            .quick-actions-container {
                flex-direction: column;
                align-items: center;
            }
            
            .quick-action-card {
                width: 100%;
                max-width: 100%;
                margin-bottom: 1rem;
            }
        }

        /* Desktop view - all in one row */
        @media (min-width: 768px) {
            .quick-actions-container {
                flex-wrap: nowrap;
                justify-content: space-around;
            }
        }

        .btn-outline-orange {
            color: #ce7e00;
            border-color: #ce7e00;
            background-color: transparent;
        }
        
        .btn-outline-orange:hover {
            color: white;
            background-color: #ce7e00;
            border-color: #ce7e00;
        }

        .btn-outline-purple {
            color: #415A77;
            border-color: #415A77;
            background-color: transparent;
        }
        
        .btn-outline-purple:hover {
            color: white;
            background-color: #415A77;
            border-color: #415A77;
        }

        .table-responsive {
            border-radius: 0.35rem;
        }

        .low-stock-table td {
            vertical-align: middle;
        }
        .table-cards {
        display: none;
    }
    
    .table-card {
        background-color: #fff;
        border-radius: 0.35rem;
        box-shadow: 0 0.15rem 0.75rem rgba(0, 0, 0, 0.1);
        margin-bottom: 1rem;
        padding: 1rem;
        border-left: 4px solid transparent;
    }
    
    .table-card.activity-card {
        border-left-color: #1cc88a;
    }
    
    .table-card.stock-card {
        border-left-color: #e74a3b;
    }
    
    .card-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
    }
    
    .card-label {
        font-weight: 600;
        color: #5a5c69;
        min-width: 30%;
    }
    
    .card-value {
        text-align: right;
        flex-grow: 1;
    }
    
    /* Show cards on mobile, tables on desktop */
    @media (max-width: 767.98px) {
        .table-responsive {
            display: none;
        }
        
        .table-cards {
            display: block;
        }
    }
    
    @media (min-width: 768px) {
        .table-cards {
            display: none;
        }
        
        .table-responsive {
            display: block;
        } 
    }
    /* Add this to your existing CSS */
.table th {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Specifically for recent activities table */
.table-responsive .table thead th {
    white-space: nowrap;
}
.invoice-image:hover {
        transform: scale(1.5);
        z-index: 1000;
        position: relative;
    }
    
    .image-modal-img {
        max-width: 100%;
        max-height: 80vh;
        object-fit: contain;
    }
    
    .total-amount {
        font-weight: bold;
        color: #28a745;
    }
    
    /* Modal specific styles */
    #addInvoiceModal .modal-dialog,
    #editInvoiceModal .modal-dialog {
        max-width: 600px;
    }
    
    /* File upload styling */
    .custom-file-upload {
        border: 2px dashed #dee2e6;
        border-radius: 5px;
        padding: 20px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s;
    }
    
    .custom-file-upload:hover {
        border-color: var(--primary);
    }
    
    .custom-file-upload i {
        font-size: 3rem;
        color: var(--primary);
        margin-bottom: 10px;
    }
    
    .file-name {
        margin-top: 10px;
        font-size: 0.9rem;
        color: var(--secondary);
    }
    /* Image Preview Styles */
.img-thumbnail {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.custom-file-upload {
    transition: all 0.3s ease;
    border-radius: 8px;
}

.custom-file-upload:hover {
    background-color: rgba(13, 110, 253, 0.05);
    border-color: #0d6efd;
}

/* Modal-specific styles */
.modal-content {
    max-height: 90vh;
    overflow-y: auto;
}

.modal-body {
    max-height: 70vh;
    overflow-y: auto;
}
/* Fix modal backdrop issues */
.modal-backdrop {
    z-index: 1040 !important;
}

.modal {
    z-index: 1050 !important;
}

/* Ensure modal content is visible */
#imageModal .modal-content {
    background-color: transparent;
    border: none;
}

#imageModal .modal-body {
    padding: 0;
    background-color: rgba(0, 0, 0, 0.9);
}

#imageModal .modal-header,
#imageModal .modal-footer {
    background-color: white;
    border: none;
}

#imageModal .modal-header {
    border-bottom: 1px solid #dee2e6;
}

#imageModal .modal-footer {
    border-top: 1px solid #dee2e6;
}

/* Make sure image displays properly */
#modalImage {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
}
.invoice-image {
        cursor: pointer;
        transition: transform 0.2s;
    }

    .invoice-image:hover {
        transform: scale(1.2);
    }

    .image-modal-img {
        max-width: 100%;
        max-height: 80vh;
        object-fit: contain;
    }

    .total-amount {
        font-weight: bold;
        color: #28a745;
    }

    @media (max-width: 768px) {
        .main-content {
            width: 100%;
        }
        
        .table-responsive {
            font-size: 0.9rem;
        }
    }
</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('invoice_management'); ?></h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('receipt_no'); ?></label>
                    <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $search_filter; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('location'); ?></label>
                    <select name="location" class="form-select">
                        <option value=""><?php echo t('all_locations'); ?></option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                <?php echo $location['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('deporty'); ?></label>
                    <select name="deporty" class="form-select">
                        <option value=""><?php echo t('all_deporties'); ?></option>
                        <?php foreach ($deporties as $deporty): ?>
                            <option value="<?php echo $deporty['id']; ?>" <?php echo $deporty_filter == $deporty['id'] ? 'selected' : ''; ?>>
                                <?php echo $deporty['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('month'); ?></label>
                    <select name="month" class="form-select">
                        <option value="0"><?php echo t('all_months'); ?></option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('year'); ?></label>
                    <select name="year" class="form-select">
                        <option value="0"><?php echo t('all_years'); ?></option>
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('sort_by'); ?></label>
                    <select name="sort_option" class="form-select">
                        <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                        <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                        <option value="receipt_asc" <?php echo $sort_option === 'receipt_asc' ? 'selected' : ''; ?>><?php echo t('receipt_asc'); ?></option>
                        <option value="receipt_desc" <?php echo $sort_option === 'receipt_desc' ? 'selected' : ''; ?>><?php echo t('receipt_desc'); ?></option>
                        <option value="price_desc" <?php echo $sort_option === 'price_desc' ? 'selected' : ''; ?>><?php echo t('price_desc'); ?></option>
                        <option value="price_asc" <?php echo $sort_option === 'price_asc' ? 'selected' : ''; ?>><?php echo t('price_asc'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo t('show_entries'); ?></label>
                    <select class="form-select form-select-sm" id="per_page_select">
                        <?php foreach ($limit_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                                <?php echo $option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <?php echo t('search'); ?>
                    </button>
                    <a href="invoice.php" class="btn btn-secondary">
                        <?php echo t('reset'); ?>
                    </a>
                    <button type="button" class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_invoice'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Invoices Table Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo t('invoices_list'); ?></h5>
            <span class="badge bg-light text-dark">
                <?php echo t('total'); ?>: <?php echo $total_items; ?> <?php echo t('invoices'); ?>
            </span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('receipt_no'); ?></th>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('location'); ?></th>
                            <th><?php echo t('deporty'); ?></th>
                            <th><?php echo t('total_price'); ?></th>
                            <th><?php echo t('remark'); ?></th>
                            <th><?php echo t('image'); ?></th>
                            <th><?php echo t('created_by'); ?></th>
                            <th><?php echo t('created_at'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="11" class="text-center"><?php echo t('no_invoices_found'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $index => $invoice): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $invoice['receipt_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($invoice['date'])); ?></td>
                                    <td><?php echo $invoice['location_name'] ?: 'N/A'; ?></td>
                                    <td><?php echo $invoice['deporty_name'] ?: 'N/A'; ?></td>
                                    <td class="total-amount">$<?php echo number_format($invoice['total_price'], 2); ?></td>
                                    <td><?php echo $invoice['remark'] ?: '-'; ?></td>
                                    <td>
                                        <?php if ($invoice['image_data']): ?>
                                            <img src="display_invoice_image.php?id=<?php echo $invoice['id']; ?>" 
                                                 class="img-thumbnail invoice-image" width="50" height="50"
                                                 data-bs-toggle="modal" data-bs-target="#imageModal"
                                                 data-image-id="<?php echo $invoice['id']; ?>">
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $invoice['created_by_name']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($invoice['created_at'])); ?></td>
                                    <td>
    <button class="btn btn-sm btn-warning edit-invoice" 
            data-id="<?php echo $invoice['id']; ?>"
            data-receipt="<?php echo $invoice['receipt_no']; ?>"
            data-date="<?php echo $invoice['date']; ?>"
            data-location="<?php echo $invoice['location_id']; ?>"
            data-deporty="<?php echo $invoice['deporty_id']; ?>"
            data-price="<?php echo $invoice['total_price']; ?>"
            data-remark="<?php echo $invoice['remark']; ?>">
        <i class="bi bi-pencil"></i> <?php echo t('edit'); ?>
    </button>
    <button class="btn btn-sm btn-danger delete-invoice" 
            data-id="<?php echo $invoice['id']; ?>"
            data-receipt="<?php echo $invoice['receipt_no']; ?>">
        <i class="bi bi-trash"></i> <?php echo t('delete'); ?>
    </button>
</td>
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
                        $pagination_params = $_GET;
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
                    <?php echo t('page'); ?> <?php echo $page; ?> <?php echo t('of'); ?> <?php echo $total_pages; ?> 
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Invoice Modal -->
<div class="modal fade" id="addInvoiceModal" tabindex="-1" aria-labelledby="addInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addInvoiceModalLabel"><?php echo t('add_invoice'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('receipt_no'); ?> *</label>
                        <input type="text" class="form-control" name="receipt_no" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('date'); ?> *</label>
                        <input type="date" class="form-control" name="date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('location'); ?> *</label>
                        <select class="form-select" name="location_id" required>
                            <option value=""><?php echo t('select_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('deporty'); ?></label>
                        <select class="form-select" name="deporty_id">
                            <option value=""><?php echo t('select_deporty'); ?></option>
                            <?php foreach ($deporties as $deporty): ?>
                                <option value="<?php echo $deporty['id']; ?>"><?php echo $deporty['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('total_price'); ?> *</label>
                        <input type="number" class="form-control" name="total_price" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('remark'); ?></label>
                        <input type="text" class="form-control" name="remark">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('invoice_image'); ?></label>
                        <div class="custom-file-upload">
                            <input type="file" class="form-control" name="invoice_image" accept="image/*">
                            <div class="form-text"><?php echo t('image_upload_hint'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="add_invoice" class="btn btn-primary"><?php echo t('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Invoice Modal -->
<div class="modal fade" id="editInvoiceModal" tabindex="-1" aria-labelledby="editInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="invoice_id" id="edit_invoice_id">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title" id="editInvoiceModalLabel"><?php echo t('edit_invoice'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('receipt_no'); ?> *</label>
                        <input type="text" class="form-control" name="receipt_no" id="edit_receipt_no" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('date'); ?> *</label>
                        <input type="date" class="form-control" name="date" id="edit_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('location'); ?> *</label>
                        <select class="form-select" name="location_id" id="edit_location_id" required>
                            <option value=""><?php echo t('select_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('deporty'); ?></label>
                        <select class="form-select" name="deporty_id" id="edit_deporty_id">
                            <option value=""><?php echo t('select_deporty'); ?></option>
                            <?php foreach ($deporties as $deporty): ?>
                                <option value="<?php echo $deporty['id']; ?>"><?php echo $deporty['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('total_price'); ?> *</label>
                        <input type="number" class="form-control" name="total_price" id="edit_total_price" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('remark'); ?></label>
                        <input type="text" class="form-control" name="remark" id="edit_remark">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('invoice_image'); ?></label>
                        <div class="custom-file-upload">
                            <input type="file" class="form-control" name="invoice_image" accept="image/*">
                            <div class="form-text"><?php echo t('image_upload_hint'); ?></div>
                            <div class="form-text text-muted"><?php echo t('leave_empty_keep_current'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="edit_invoice" class="btn btn-warning"><?php echo t('update'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo t('invoice_image'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="image-modal-img" alt="Invoice Image">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('close'); ?></button>
            </div>
        </div>
    </div>
</div>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="delete_invoice" value="1">
                <input type="hidden" name="invoice_id" id="deleteInvoiceId">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this invoice?</p>
                    <div id="deleteInvoiceInfo" class="alert alert-warning">
                        <!-- Invoice info will be inserted here -->
                    </div>
                    <p class="text-danger"><strong>Warning: This action cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden form for delete -->
<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="delete_invoice" value="1">
    <input type="hidden" name="invoice_id" id="deleteInvoiceId">
</form>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle entries per page change
    const perPageSelect = document.getElementById('per_page_select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }
    
    // Handle edit invoice button clicks
    document.querySelectorAll('.edit-invoice').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('edit_invoice_id').value = this.dataset.id;
            document.getElementById('edit_receipt_no').value = this.dataset.receipt;
            document.getElementById('edit_date').value = this.dataset.date;
            document.getElementById('edit_location_id').value = this.dataset.location;
            document.getElementById('edit_deporty_id').value = this.dataset.deporty;
            document.getElementById('edit_total_price').value = this.dataset.price;
            document.getElementById('edit_remark').value = this.dataset.remark;
            
            const editModal = new bootstrap.Modal(document.getElementById('editInvoiceModal'));
            editModal.show();
        });
    });
    
     // Handle delete invoice button clicks
     document.querySelectorAll('.delete-invoice').forEach(button => {
        button.addEventListener('click', function() {
            const invoiceId = this.dataset.id;
            const receiptNo = this.dataset.receipt;
            
            // Update modal content
            document.getElementById('deleteInvoiceId').value = invoiceId;
            document.getElementById('deleteInvoiceInfo').innerHTML = 
                '<strong>Receipt No:</strong> ' + receiptNo + '<br>' +
                '<strong>ID:</strong> ' + invoiceId;
            
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            deleteModal.show();
        });
    });
    
    // Remove the confirmDeleteBtn event listener since form will auto-submit

    // Handle confirm delete button click
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            console.log('Confirm delete clicked'); // 调试
            document.getElementById('deleteForm').submit();
        });
    }
    
    // Handle image modal
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const imageId = button.getAttribute('data-image-id');
            const modalImage = document.getElementById('modalImage');
            modalImage.src = 'display_invoice_image.php?id=' + imageId;
        });
    }
    
    // Auto-hide success/error messages
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});
</script>

<?php
require_once '../includes/footer.php';
?>