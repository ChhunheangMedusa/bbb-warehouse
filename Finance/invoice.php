<?php

ob_start();
require_once '../includes/header-finance.php';
// Add authentication check
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'translate.php';
// Check if user is authenticated
checkAuth();
// Check if user has permission (admin or finance staff only)
if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php'); // Redirect to login or home page
    exit();
}



// Get locations for dropdown
$location_stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for dropdown
$supplier_stmt = $pdo->query("SELECT * FROM deporty ORDER BY name");
$suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter parameters
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$supplier_filter = isset($_GET['supplier']) ? (int)$_GET['supplier'] : null;
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Sort mapping
$sort_mapping = [
    'receipt_asc' => ['field' => 'receipt_no', 'direction' => 'ASC'],
    'receipt_desc' => ['field' => 'receipt_no', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'date', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'fl.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'fl.name', 'direction' => 'DESC'],
    'supplier_asc' => ['field' => 'fs.name', 'direction' => 'ASC'],
    'supplier_desc' => ['field' => 'fs.name', 'direction' => 'DESC'],
    'total_asc' => ['field' => 'total', 'direction' => 'ASC'],
    'total_desc' => ['field' => 'total', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_invoice'])) {
        $receipt_no = sanitizeInput($_POST['receipt_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        $supplier_id = (int)$_POST['supplier_id'];
        $total = (float)$_POST['total'];
        
        try {
            // Check for duplicate receipt number
            $stmt = $pdo->prepare("SELECT id FROM finance_invoice WHERE receipt_no = ?");
            $stmt->execute([$receipt_no]);
            
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateReceiptModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("Duplicate receipt number!");
            }
            
            // Handle file upload
            $image_path = null;
            if (!empty($_FILES['image']['name'])) {
                $upload_dir = '../uploads/invoices/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['image']['name']);
                $target_file = $upload_dir . $file_name;
                
                // Validate image
                $check = getimagesize($_FILES['image']['tmp_name']);
                if ($check === false) {
                    throw new Exception("File is not an image.");
                }
                
                // Check file size (5MB max)
                if ($_FILES['image']['size'] > 5000000) {
                    throw new Exception("File is too large. Maximum size is 5MB.");
                }
                
                // Allow certain file formats
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
                if (!in_array($file_extension, $allowed_extensions)) {
                    throw new Exception("Only JPG, JPEG, PNG, GIF & PDF files are allowed.");
                }
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $image_path = $target_file;
                } else {
                    throw new Exception("Sorry, there was an error uploading your file.");
                }
            }
            
            // Insert invoice
$stmt = $pdo->prepare("INSERT INTO finance_invoice (receipt_no, date, location_id, deporty_id, total_price, image, remark, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$receipt_no, $date, $location_id, $supplier_id, $total, $image_path, '', $_SESSION['user_id']]);
            
            // Log activity
            $location_name = $locations[array_search($location_id, array_column($locations, 'id'))]['name'];
            $supplier_name = $suppliers[array_search($supplier_id, array_column($suppliers, 'id'))]['name'];
            
            $log_message = "Added new invoice #$receipt_no for $location_name from $supplier_name - Total: $" . number_format($total, 2);
            financelog($_SESSION['user_id'], 'Add Invoice', $log_message);
            
            $_SESSION['success'] = "Invoice added successfully!";
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['edit_invoice'])) {
        $invoice_id = (int)$_POST['invoice_id'];
        $receipt_no = sanitizeInput($_POST['receipt_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        $supplier_id = (int)$_POST['supplier_id'];
        $total = (float)$_POST['total'];
        
        try {
            // Check for duplicate receipt number (excluding current invoice)
            $stmt = $pdo->prepare("SELECT id FROM finance_invoice WHERE receipt_no = ? AND id != ?");
            $stmt->execute([$receipt_no, $invoice_id]);
            
            if ($stmt->fetch()) {
                throw new Exception("Duplicate receipt number!");
            }
            
            // Handle file upload if new file is provided
            $image_path = $_POST['current_image'];
            if (!empty($_FILES['image']['name'])) {
                $upload_dir = '../uploads/invoices/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['image']['name']);
                $target_file = $upload_dir . $file_name;
                
                // Validate image
                $check = getimagesize($_FILES['image']['tmp_name']);
                if ($check === false) {
                    throw new Exception("File is not an image.");
                }
                
                // Check file size (5MB max)
                if ($_FILES['image']['size'] > 5000000) {
                    throw new Exception("File is too large. Maximum size is 5MB.");
                }
                
                // Allow certain file formats
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
                if (!in_array($file_extension, $allowed_extensions)) {
                    throw new Exception("Only JPG, JPEG, PNG, GIF & PDF files are allowed.");
                }
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    // Delete old image if exists
                    if ($image_path && file_exists($image_path)) {
                        unlink($image_path);
                    }
                    $image_path = $target_file;
                } else {
                    throw new Exception("Sorry, there was an error uploading your file.");
                }
            }
            
            // Update invoice
            // Update invoice
// Update invoice
$stmt = $pdo->prepare("UPDATE finance_invoice SET receipt_no = ?, date = ?, location_id = ?, 
                      deporty_id = ?, total_price = ?, image = ? WHERE id = ?");
$stmt->execute([$receipt_no, $date, $location_id, $supplier_id, $total, $image_path, $invoice_id]);
            
            // Log activity
            $location_name = $locations[array_search($location_id, array_column($locations, 'id'))]['name'];
            $supplier_name = $suppliers[array_search($supplier_id, array_column($suppliers, 'id'))]['name'];
            
            $log_message = "Updated invoice #$receipt_no for $location_name from $supplier_name - Total: $" . number_format($total, 2);
            financelog($_SESSION['user_id'], 'Edit Invoice', $log_message);
            
            $_SESSION['success'] = "Invoice updated successfully!";
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['delete_invoice'])) {
        $invoice_id = (int)$_POST['invoice_id'];
        
        try {
            // Get invoice details before deletion for logging
            $stmt = $pdo->prepare("SELECT receipt_no, image FROM finance_invoice WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($invoice) {
                // Delete image file if exists
                if ($invoice['image'] && file_exists($invoice['image'])) {
                    unlink($invoice['image']);
                }
                
                // Delete invoice
                $stmt = $pdo->prepare("DELETE FROM finance_invoice WHERE id = ?");
                $stmt->execute([$invoice_id]);
                
                // Log activity
                $log_message = "Deleted invoice #" . $invoice['receipt_no'];
                financelog($_SESSION['user_id'], 'Delete Invoice', $log_message);
                
                $_SESSION['success'] = "Invoice deleted successfully!";
            }
            
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            redirect('invoice.php');
        }
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

// Build query for invoices
// Build query for invoices
// Build query for invoices
$query = "SELECT 
    fi.id,
    fi.receipt_no,
    fi.date,
    fi.location_id as location,
    fl.name as location_name,
    fi.deporty_id as supplier,
    fs.name as supplier_name,
    fi.total_price as total,
    fi.image,
    fi.created_at
FROM 
    finance_invoice fi
LEFT JOIN 
    locations fl ON fi.location_id = fl.id
LEFT JOIN 
    deporty fs ON fi.deporty_id = fs.id
WHERE 1=1";

$params = [];
$count_params = [];

// Add filters
if ($year_filter) {
    $query .= " AND YEAR(fi.date) = :year";
    $params[':year'] = $year_filter;
    $count_params[':year'] = $year_filter;
}

if ($month_filter) {
    $query .= " AND MONTH(fi.date) = :month";
    $params[':month'] = $month_filter;
    $count_params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND fi.location_id = :location_id";
    $params[':location_id'] = $location_filter;
    $count_params[':location_id'] = $location_filter;
}

if ($supplier_filter) {
    $query .= " AND fi.deporty_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
    $count_params[':supplier_id'] = $supplier_filter;
}

if ($search_query) {
    $query .= " AND (fi.receipt_no LIKE :search OR fl.name LIKE :search OR fs.name LIKE :search)";
    $params[':search'] = "%$search_query%";
    $count_params[':search'] = "%$search_query%";
}

// Order by
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM finance_invoice fi
                LEFT JOIN locations fl ON fi.location_id = fl.id
                LEFT JOIN deporty fs ON fi.deporty_id = fs.id
                WHERE 1=1";

foreach ($count_params as $key => $value) {
    $count_query .= str_replace(':year', $key, " AND YEAR(fi.date) = :year");
    $count_query .= str_replace(':month', $key, " AND MONTH(fi.date) = :month");
    $count_query .= str_replace(':location_id', $key, " AND fi.location = :location_id");
    $count_query .= str_replace(':supplier_id', $key, " AND fi.supplier = :supplier_id");
    $count_query .= str_replace(':search', $key, " AND (fi.receipt_no LIKE :search OR fl.name LIKE :search OR fs.name LIKE :search)");
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
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('invoice_management'); ?></h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-2">
                        <div class="col-md-2">
                            <label for="search" class="form-label"><?php echo t('search'); ?></label>
                            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $search_query; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="location" class="form-label"><?php echo t('location'); ?></label>
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
                            <label for="supplier" class="form-label"><?php echo t('deporty'); ?></label>
                            <select name="supplier" class="form-select">
                                <option value=""><?php echo t('all_suppliers'); ?></option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo $supplier['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="month" class="form-label"><?php echo t('month'); ?></label>
                            <select name="month" class="form-select">
                                <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_months'); ?></option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="year" class="form-label"><?php echo t('year'); ?></label>
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
                            <label for="sort" class="form-label"><?php echo t('sort'); ?></label>
                            <select name="sort_option" class="form-select">
                                <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                                <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                <option value="receipt_asc" <?php echo $sort_option === 'receipt_asc' ? 'selected' : ''; ?>><?php echo t('receipt_a_to_z'); ?></option>
                                <option value="receipt_desc" <?php echo $sort_option === 'receipt_desc' ? 'selected' : ''; ?>><?php echo t('receipt_z_to_a'); ?></option>
                                <option value="total_asc" <?php echo $sort_option === 'total_asc' ? 'selected' : ''; ?>><?php echo t('total_low_to_high'); ?></option>
                                <option value="total_desc" <?php echo $sort_option === 'total_desc' ? 'selected' : ''; ?>><?php echo t('total_high_to_low'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="per_page" class="form-label"><?php echo t('show_entries'); ?></label>
                            <select name="per_page" class="form-select form-select-sm" id="per_page_select">
                                <?php foreach ($limit_options as $option): ?>
                                    <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                                        <?php echo $option; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <?php echo t('search'); ?>
                            </button>
                            <a href="invoice.php" class="btn btn-secondary">
                                <?php echo t('reset'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Data Table Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo t('invoice_list'); ?></h5>
            <div class="d-inline-flex gap-2 align-items-center flex-nowrap">
                <button class="btn btn-light btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
                    <i class="bi bi-plus-circle"></i> <?php echo t('add_invoice'); ?>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no'); ?></th>
                            <th><?php echo t('item_invoice'); ?></th>
                            <th><?php echo t('item_date'); ?></th>
                            <th><?php echo t('location_column'); ?></th>
                            <th><?php echo t('deporty'); ?></th>
                            <th><?php echo t('sub_total'); ?></th>
                            <th><?php echo t('column_picture'); ?></th>
                            <th><?php echo t('created_at'); ?></th>
                            <th><?php echo t('column_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center"><?php echo t('no_invoices_found'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $index => $invoice): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $invoice['receipt_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($invoice['date'])); ?></td>
                                    <td><?php echo $invoice['location_name']; ?></td>
                                    <td><?php echo $invoice['supplier_name']; ?></td>
                                    <td class="total-amount">$<?php echo number_format($invoice['total'], 2); ?></td>
                                    <td>
                                        <?php if ($invoice['image']): ?>
                                            <img src="<?php echo $invoice['image']; ?>" 
                                                 class="invoice-image img-thumbnail" 
                                                 width="50"
                                                 data-bs-toggle="modal" 
                                                 data-bs-target="#imageModal"
                                                 data-image-src="<?php echo $invoice['image']; ?>">
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($invoice['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning edit-invoice-btn" 
                                                data-id="<?php echo $invoice['id']; ?>"
                                                data-receipt="<?php echo $invoice['receipt_no']; ?>"
                                                data-date="<?php echo date('Y-m-d', strtotime($invoice['date'])); ?>"
                                                data-location="<?php echo $invoice['location']; ?>"
                                                data-supplier="<?php echo $invoice['supplier']; ?>"
                                                data-total="<?php echo $invoice['total']; ?>"
                                                data-image="<?php echo $invoice['image']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-invoice-btn" 
                                                data-id="<?php echo $invoice['id']; ?>"
                                                data-receipt="<?php echo $invoice['receipt_no']; ?>">
                                            <i class="bi bi-trash"></i>
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
                        // Create parameter array for pagination
                        $pagination_params = [
                            'search' => $search_query,
                            'location' => $location_filter,
                            'supplier' => $supplier_filter,
                            'month' => $month_filter,
                            'year' => $year_filter,
                            'sort_option' => $sort_option,
                            'per_page' => $per_page
                        ];
                        
                        // Remove empty values
                        $pagination_params = array_filter($pagination_params);
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
                        // Show page numbers
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
                    (<?php echo t('total_records'); ?>: <?php echo $total_items; ?>)
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
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="receipt_no" required>
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" name="date" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="location_id" class="form-label"><?php echo t('location'); ?></label>
                            <select class="form-select" name="location_id" required>
                                <option value=""><?php echo t(''); ?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('deporty'); ?></label>
                            <select class="form-select" name="supplier_id" required>
                                <option value=""><?php echo t(''); ?></option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo $supplier['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('total'); ?></label>
                            <input type="number" class="form-control" name="total" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row mb-3">
    <div class="col-md-12">
        <label class="form-label"><?php echo t('column_picture'); ?></label>
        
        <!-- Image Preview Container -->
        <div class="mb-3 text-center" id="imagePreviewContainer" style="display: none;">
            <img id="imagePreview" class="img-thumbnail" style="max-height: 200px;" alt="Selected Image">
            <button type="button" class="btn btn-sm btn-danger mt-2" onclick="removeSelectedImage()">
                <i class="bi bi-trash"></i> <?php echo t('remove'); ?>
            </button>
        </div>
        
        <!-- PDF Preview Container -->
        <div class="mb-3" id="pdfPreviewContainer" style="display: none;">
            <div class="alert alert-info d-flex align-items-center">
                <i class="bi bi-file-earmark-pdf fs-4 me-2"></i>
                <div>
                    <strong id="pdfFileName"></strong>
                    <button type="button" class="btn btn-sm btn-danger mt-1" onclick="removeSelectedImage()">
                        <i class="bi bi-trash"></i> <?php echo t('remove'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- File Upload Area -->
        <div class="custom-file-upload" id="fileUploadArea" style="border: 2px dashed #dee2e6; padding: 2rem; text-align: center; cursor: pointer;">
            <i class="bi bi-cloud-upload fs-1 text-primary"></i>
            <p class="mt-2 mb-1"><?php echo t('click_to_upload'); ?></p>
            <p class="small text-muted mb-0"><?php echo t('supported_formats'); ?>: JPG, PNG, GIF, PDF</p>
            <p class="small text-muted mb-0"><?php echo t('max_size'); ?>: 5MB</p>
            <input type="file" class="d-none" name="image" id="fileInput" accept="image/*,.pdf">
            <div class="file-name mt-2" id="fileName"></div>
        </div>
    </div>
</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="add_invoice" class="btn btn-primary"><?php echo t('form_save'); ?></button>
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
                <input type="hidden" name="current_image" id="edit_current_image">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editInvoiceModalLabel"><?php echo t('edit_invoice'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('receipt_no'); ?></label>
                            <input type="text" class="form-control" name="receipt_no" id="edit_receipt_no" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_date" class="form-label"><?php echo t('date'); ?></label>
                            <input type="date" class="form-control" name="date" id="edit_date" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_location_id" class="form-label"><?php echo t('location'); ?></label>
                            <select class="form-select" name="location_id" id="edit_location_id" required>
                                <option value=""><?php echo t('select_location'); ?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('supplier'); ?></label>
                            <select class="form-select" name="supplier_id" id="edit_supplier_id" required>
                                <option value=""><?php echo t('select_supplier'); ?></option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo $supplier['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('total'); ?></label>
                            <input type="number" class="form-control" name="total" id="edit_total" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row mb-3">
    <div class="col-md-12">
        <label class="form-label"><?php echo t('image'); ?></label>
        
        <!-- Current Image Preview -->
        <div class="mb-3" id="currentImagePreview"></div>
        
        <!-- New Image Preview Container -->
        <div class="mb-3 text-center" id="editImagePreviewContainer" style="display: none;">
            <img id="editImagePreview" class="img-thumbnail" style="max-height: 200px;" alt="Selected Image">
            <button type="button" class="btn btn-sm btn-danger mt-2" onclick="removeSelectedEditImage()">
                <i class="bi bi-trash"></i> <?php echo t('remove'); ?>
            </button>
        </div>
        
        <!-- PDF Preview Container -->
        <div class="mb-3" id="editPdfPreviewContainer" style="display: none;">
            <div class="alert alert-info d-flex align-items-center">
                <i class="bi bi-file-earmark-pdf fs-4 me-2"></i>
                <div>
                    <strong id="editPdfFileName"></strong>
                    <button type="button" class="btn btn-sm btn-danger mt-1" onclick="removeSelectedEditImage()">
                        <i class="bi bi-trash"></i> <?php echo t('remove'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- File Upload Area -->
        <div class="custom-file-upload" id="editFileUploadArea" style="border: 2px dashed #dee2e6; padding: 2rem; text-align: center; cursor: pointer;">
            <i class="bi bi-cloud-upload fs-1 text-primary"></i>
            <p class="mt-2 mb-1"><?php echo t('click_to_upload_new'); ?></p>
            <p class="small text-muted mb-0"><?php echo t('supported_formats'); ?>: JPG, PNG, GIF, PDF</p>
            <p class="small text-muted mb-0"><?php echo t('max_size'); ?>: 5MB</p>
            <input type="file" class="d-none" name="image" id="editFileInput" accept="image/*,.pdf">
            <div class="file-name mt-2" id="editFileName"></div>
        </div>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('confirm_delete'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 2.5rem;"></i>
                </div>
                <h4 class="text-danger mb-2" style="font-size: 1.25rem;"><?php echo t('delete_invoice'); ?></h4>
                <p class="mb-3"><?php echo t('del_usr2'); ?></p>
                <div id="deleteInvoiceInfo" class="alert alert-light mb-0">
                    <!-- Dynamic content will go here -->
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-secondary flex-grow-1 flex-md-grow-0" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close'); ?>
                </button>
                <form method="POST" id="deleteInvoiceForm" style="display: inline; flex-grow: 1;" class="flex-md-grow-0">
                    <input type="hidden" name="invoice_id" id="delete_invoice_id">
                    <button type="submit" name="delete_invoice" class="btn btn-danger flex-grow-1 flex-md-grow-0">
                        <i class="bi bi-trash"></i> <?php echo t('delete'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Image Preview Modal - SIMPLIFIED -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo t('image_preview'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-0">
                <img src="" class="img-fluid" id="modalImage" alt="Invoice Image" style="max-height: 70vh; width: auto;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('close'); ?></button>
                <a href="#" class="btn btn-primary" id="downloadImage" download>
                    <i class="bi bi-download"></i> <?php echo t('download'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Duplicate Receipt Modal -->
<div class="modal fade" id="duplicateReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_receipt'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_receipt_title'); ?></h4>
                <p><?php echo t('duplicate_receipt_message'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('ok'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Function to display image preview
function displayImagePreview(file) {
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    const pdfPreviewContainer = document.getElementById('pdfPreviewContainer');
    const imagePreview = document.getElementById('imagePreview');
    const pdfFileName = document.getElementById('pdfFileName');
    const fileName = document.getElementById('fileName');
    const fileUploadArea = document.getElementById('fileUploadArea');
    
    // Hide both previews initially
    imagePreviewContainer.style.display = 'none';
    pdfPreviewContainer.style.display = 'none';
    
    if (file) {
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension);
        const isPDF = fileExtension === 'pdf';
        
        fileName.textContent = file.name;
        fileUploadArea.style.borderColor = '#0d6efd';
        
        if (isImage) {
            // Create image preview
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
                imagePreviewContainer.style.display = 'block';
                fileUploadArea.style.display = 'none';
            }
            reader.readAsDataURL(file);
        } else if (isPDF) {
            // Show PDF info
            pdfFileName.textContent = file.name;
            pdfPreviewContainer.style.display = 'block';
            fileUploadArea.style.display = 'none';
        }
    }
}

// Function to remove selected image
function removeSelectedImage() {
    const fileInput = document.getElementById('fileInput');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    const pdfPreviewContainer = document.getElementById('pdfPreviewContainer');
    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileName = document.getElementById('fileName');
    const imagePreview = document.getElementById('imagePreview');
    
    // Reset file input
    fileInput.value = '';
    fileName.textContent = '';
    
    // Clear image preview
    imagePreview.src = '';
    
    // Hide previews and show upload area
    imagePreviewContainer.style.display = 'none';
    pdfPreviewContainer.style.display = 'none';
    fileUploadArea.style.display = 'block';
    fileUploadArea.style.borderColor = '#dee2e6';
    fileUploadArea.style.backgroundColor = 'transparent';
}

// Function to display image preview for edit modal
function displayEditImagePreview(file) {
    const imagePreviewContainer = document.getElementById('editImagePreviewContainer');
    const pdfPreviewContainer = document.getElementById('editPdfPreviewContainer');
    const imagePreview = document.getElementById('editImagePreview');
    const pdfFileName = document.getElementById('editPdfFileName');
    const fileName = document.getElementById('editFileName');
    const fileUploadArea = document.getElementById('editFileUploadArea');
    const currentImagePreview = document.getElementById('currentImagePreview');
    
    // Hide both previews initially
    imagePreviewContainer.style.display = 'none';
    pdfPreviewContainer.style.display = 'none';
    
    if (file) {
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension);
        const isPDF = fileExtension === 'pdf';
        
        fileName.textContent = file.name;
        fileUploadArea.style.borderColor = '#0d6efd';
        
        // Hide current image preview when new file is selected
        if (currentImagePreview) {
            currentImagePreview.style.display = 'none';
        }
        
        if (isImage) {
            // Create image preview
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
                imagePreviewContainer.style.display = 'block';
                fileUploadArea.style.display = 'none';
            }
            reader.readAsDataURL(file);
        } else if (isPDF) {
            // Show PDF info
            pdfFileName.textContent = file.name;
            pdfPreviewContainer.style.display = 'block';
            fileUploadArea.style.display = 'none';
        }
    }
}

// Function to remove selected image in edit modal
function removeSelectedEditImage() {
    const editFileInput = document.getElementById('editFileInput');
    const imagePreviewContainer = document.getElementById('editImagePreviewContainer');
    const pdfPreviewContainer = document.getElementById('editPdfPreviewContainer');
    const fileUploadArea = document.getElementById('editFileUploadArea');
    const fileName = document.getElementById('editFileName');
    const currentImagePreview = document.getElementById('currentImagePreview');
    const imagePreview = document.getElementById('editImagePreview');
    
    // Reset file input
    editFileInput.value = '';
    fileName.textContent = '';
    
    // Clear image preview
    imagePreview.src = '';
    
    // Hide previews and show upload area
    imagePreviewContainer.style.display = 'none';
    pdfPreviewContainer.style.display = 'none';
    fileUploadArea.style.display = 'block';
    fileUploadArea.style.borderColor = '#dee2e6';
    fileUploadArea.style.backgroundColor = 'transparent';
    
    // Show current image preview again
    if (currentImagePreview) {
        currentImagePreview.style.display = 'block';
    }
}

// Main DOMContentLoaded event
document.addEventListener('DOMContentLoaded', function() {
    // Set today's date in add modal
    document.getElementById('addInvoiceModal').addEventListener('shown.bs.modal', function() {
        const today = new Date().toISOString().split('T')[0];
        this.querySelector('input[name="date"]').value = today;
    });

    // File upload functionality for add modal
    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileInput = document.getElementById('fileInput');
    const fileName = document.getElementById('fileName');

    if (fileUploadArea) {
        fileUploadArea.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                displayImagePreview(this.files[0]);
            }
        });
    }

    // File upload functionality for edit modal
    const editFileUploadArea = document.getElementById('editFileUploadArea');
    const editFileInput = document.getElementById('editFileInput');
    const editFileName = document.getElementById('editFileName');

    if (editFileUploadArea) {
        editFileUploadArea.addEventListener('click', function() {
            editFileInput.click();
        });

        editFileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                displayEditImagePreview(this.files[0]);
            }
        });
    }

    // Edit invoice button click
    document.querySelectorAll('.edit-invoice-btn').forEach(button => {
        button.addEventListener('click', function() {
            const invoiceId = this.dataset.id;
            const receiptNo = this.dataset.receipt;
            const date = this.dataset.date;
            const locationId = this.dataset.location;
            const supplierId = this.dataset.supplier;
            const total = this.dataset.total;
            const image = this.dataset.image;

            // Set values in edit modal
            document.getElementById('edit_invoice_id').value = invoiceId;
            document.getElementById('edit_receipt_no').value = receiptNo;
            document.getElementById('edit_date').value = date;
            document.getElementById('edit_location_id').value = locationId;
            document.getElementById('edit_supplier_id').value = supplierId;
            document.getElementById('edit_total').value = total;
            document.getElementById('edit_current_image').value = image;

            // Show current image preview
            const currentImagePreview = document.getElementById('currentImagePreview');
            if (image) {
                const fileExtension = image.split('.').pop().toLowerCase();
                if (fileExtension === 'pdf') {
                    currentImagePreview.innerHTML = `
                        <div class="alert alert-info">
                            <i class="bi bi-file-earmark-pdf"></i> PDF File: ${image.split('/').pop()}
                        </div>
                    `;
                } else {
                    currentImagePreview.innerHTML = `
                        <img src="${image}" class="img-thumbnail" style="max-width: 150px;" alt="Current Image">
                        <p class="small text-muted mt-1">${image.split('/').pop()}</p>
                    `;
                }
            } else {
                currentImagePreview.innerHTML = '<p class="text-muted">No image</p>';
            }

            // Reset new file selection
            const editFileInput = document.getElementById('editFileInput');
            const editFileName = document.getElementById('editFileName');
            const editFileUploadArea = document.getElementById('editFileUploadArea');
            const editImagePreviewContainer = document.getElementById('editImagePreviewContainer');
            const editPdfPreviewContainer = document.getElementById('editPdfPreviewContainer');
            
            editFileInput.value = '';
            editFileName.textContent = '';
            editFileUploadArea.style.display = 'block';
            editFileUploadArea.style.borderColor = '#dee2e6';
            editFileUploadArea.style.backgroundColor = 'transparent';
            editImagePreviewContainer.style.display = 'none';
            editPdfPreviewContainer.style.display = 'none';

            // Show edit modal
            const editModal = new bootstrap.Modal(document.getElementById('editInvoiceModal'));
            editModal.show();
        });
    });

    // Delete invoice button click
    document.querySelectorAll('.delete-invoice-btn').forEach(button => {
        button.addEventListener('click', function() {
            const invoiceId = this.dataset.id;
            const receiptNo = this.dataset.receipt;

            document.getElementById('delete_invoice_id').value = invoiceId;
            
            // Update modal content
            document.getElementById('deleteInvoiceInfo').innerHTML = `
                <strong><?php echo t('item_invoice'); ?>:</strong> #${receiptNo}
            `;

            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            deleteModal.show();
        });
    });

    // SIMPLE IMAGE PREVIEW FIX
    document.querySelectorAll('.invoice-image').forEach(img => {
        img.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const imageSrc = this.src;
            console.log('Image clicked, src:', imageSrc); // Debug log
            
            const modalImage = document.getElementById('modalImage');
            const downloadBtn = document.getElementById('downloadImage');
            
            // Set image source
            modalImage.src = imageSrc;
            
            // Set download link
            if (downloadBtn) {
                downloadBtn.href = imageSrc;
                downloadBtn.download = imageSrc.split('/').pop();
            }
            
            // Show modal using Bootstrap
            const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
            imageModal.show();
        });
    });

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

    // Auto-hide success messages after 5 seconds
    const successMessages = document.querySelectorAll('.alert-success');
    successMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease';
            message.style.opacity = '0';
            
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 5000);
    });

    // Auto-hide error messages after 10 seconds
    const errorMessages = document.querySelectorAll('.alert-danger');
    errorMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease';
            message.style.opacity = '0';
            
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 10000);
    });
});
</script>

<?php
require_once '../includes/footer.php';
?>