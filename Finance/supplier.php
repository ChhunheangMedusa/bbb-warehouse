<?php
ob_start();
require_once '../includes/header-finance.php';
// Add authentication check
require_once '../includes/auth.php';

// Check if user is authenticated
checkAuth();
// Check if user has permission (admin or finance staff only)
if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php'); // Redirect to login or home page
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'translate.php';

// Get filter parameters
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'name_asc';

// Sort mapping
$sort_mapping = [
    'name_asc' => ['field' => 'name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'name', 'direction' => 'DESC'],
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
    if (isset($_POST['add_supplier'])) {
        $name = sanitizeInput($_POST['name']);
        
        try {
            // Check for duplicate supplier name
            $stmt = $pdo->prepare("SELECT id FROM finance_supplier WHERE name = ?");
            $stmt->execute([$name]);
            
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("Supplier with this name already exists!");
            }
            
            // Insert supplier
            $stmt = $pdo->prepare("INSERT INTO finance_supplier (name) VALUES (?)");
            $stmt->execute([$name]);
            
            // Log activity
            $log_message = "Added new supplier: $name";
            logActivity($_SESSION['user_id'], 'Add Supplier', $log_message);
            
            $_SESSION['success'] = "Supplier added successfully!";
            redirect('supplier.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['edit_supplier'])) {
        $supplier_id = (int)$_POST['supplier_id'];
        $name = sanitizeInput($_POST['name']);
        
        try {
            // Check for duplicate supplier name (excluding current supplier)
            $stmt = $pdo->prepare("SELECT id FROM finance_supplier WHERE name = ? AND id != ?");
            $stmt->execute([$name, $supplier_id]);
            
            if ($stmt->fetch()) {
                throw new Exception("Supplier with this name already exists!");
            }
            
            // Get old supplier name for logging
            $stmt = $pdo->prepare("SELECT name FROM finance_supplier WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $old_supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update supplier
            $stmt = $pdo->prepare("UPDATE finance_supplier SET name = ? WHERE id = ?");
            $stmt->execute([$name, $supplier_id]);
            
            // Log activity
            $log_message = "Updated supplier: {$old_supplier['name']} to $name";
            logActivity($_SESSION['user_id'], 'Edit Supplier', $log_message);
            
            $_SESSION['success'] = "Supplier updated successfully!";
            redirect('supplier.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['delete_supplier'])) {
        $supplier_id = (int)$_POST['supplier_id'];
        
        try {
            // Check if supplier is used in invoices
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM finance_invoice WHERE supplier = ?");
            $stmt->execute([$supplier_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Cannot delete supplier. It is being used in invoices.");
            }
            
            // Get supplier details before deletion for logging
            $stmt = $pdo->prepare("SELECT name FROM finance_supplier WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($supplier) {
                // Delete supplier
                $stmt = $pdo->prepare("DELETE FROM finance_supplier WHERE id = ?");
                $stmt->execute([$supplier_id]);
                
                // Log activity
                $log_message = "Deleted supplier: " . $supplier['name'];
                logActivity($_SESSION['user_id'], 'Delete Supplier', $log_message);
                
                $_SESSION['success'] = "Supplier deleted successfully!";
            }
            
            redirect('supplier.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            redirect('supplier.php');
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            redirect('supplier.php');
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

// Build query for suppliers
$query = "SELECT 
    id,
    name,
    created_at
FROM 
    finance_supplier
WHERE 1=1";

$params = [];
$count_params = [];

// Add search filter
if ($search_query) {
    $query .= " AND name LIKE :search";
    $params[':search'] = "%$search_query%";
    $count_params[':search'] = "%$search_query%";
}

// Order by
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM finance_supplier WHERE 1=1";

if ($search_query) {
    $count_query .= " AND name LIKE :search";
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
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
    /* Keep the existing styles from invoice.php */
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

    .supplier-count {
        font-weight: bold;
        color: var(--primary);
    }
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('deporty_management'); ?></h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-2">
                        <div class="col-md-4">
                            <label for="search" class="form-label"><?php echo t('search'); ?></label>
                            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $search_query; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="sort" class="form-label"><?php echo t('sort'); ?></label>
                            <select name="sort_option" class="form-select">
                                <option value="name_asc" <?php echo $sort_option === 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                                <option value="name_desc" <?php echo $sort_option === 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                                <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
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
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <?php echo t('search'); ?>
                            </button>
                            <a href="supplier.php" class="btn btn-secondary">
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
            <h5 class="mb-0"><?php echo t('deporty_list'); ?> <span class="supplier-count">(<?php echo $total_items; ?>)</span></h5>
            <div class="d-inline-flex gap-2 align-items-center flex-nowrap">
                <button class="btn btn-light btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                    <i class="bi bi-plus-circle"></i> <?php echo t('add_deporty'); ?>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no'); ?></th>
                            <th><?php echo t('name_deport'); ?></th>
                            <th><?php echo t('created_at'); ?></th>
                            <th><?php echo t('column_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="4" class="text-center"><?php echo t('no_deporty_found'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $index => $supplier): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $supplier['name']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($supplier['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning edit-supplier-btn" 
                                                data-id="<?php echo $supplier['id']; ?>"
                                                data-name="<?php echo $supplier['name']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-supplier-btn" 
                                                data-id="<?php echo $supplier['id']; ?>"
                                                data-name="<?php echo $supplier['name']; ?>">
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

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addSupplierModalLabel"><?php echo t('add_deporty'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('name_deport'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required maxlength="255">
                           
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="add_supplier" class="btn btn-primary"><?php echo t('form_save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="supplier_id" id="edit_supplier_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editSupplierModalLabel"><?php echo t('edit_supplier'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('supplier_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_supplier_name" required maxlength="255">
                            <div class="form-text"><?php echo t('supplier_name_hint'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="edit_supplier" class="btn btn-warning"><?php echo t('update'); ?></button>
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
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('delete_supplier'); ?></h4>
                <p id="deleteSupplierMessage"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <form method="POST" id="deleteSupplierForm" style="display: inline;">
                    <input type="hidden" name="supplier_id" id="delete_supplier_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="delete_supplier" class="btn btn-danger"><?php echo t('delete'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Duplicate Modal -->
<div class="modal fade" id="duplicateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_supplier'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_supplier_title'); ?></h4>
                <p><?php echo t('duplicate_supplier_message'); ?></p>
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
document.addEventListener('DOMContentLoaded', function() {
    // Edit supplier button click
    document.querySelectorAll('.edit-supplier-btn').forEach(button => {
        button.addEventListener('click', function() {
            const supplierId = this.dataset.id;
            const supplierName = this.dataset.name;

            // Set values in edit modal
            document.getElementById('edit_supplier_id').value = supplierId;
            document.getElementById('edit_supplier_name').value = supplierName;

            // Show edit modal
            const editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
            editModal.show();
        });
    });

    // Delete supplier button click
    document.querySelectorAll('.delete-supplier-btn').forEach(button => {
        button.addEventListener('click', function() {
            const supplierId = this.dataset.id;
            const supplierName = this.dataset.name;

            document.getElementById('delete_supplier_id').value = supplierId;
            document.getElementById('deleteSupplierMessage').textContent = 
                'Are you sure you want to delete supplier "' + supplierName + '"? This action cannot be undone.';

            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            deleteModal.show();
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