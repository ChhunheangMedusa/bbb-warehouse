
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
$location_stmt = $pdo->query("SELECT * FROM finance_location ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter parameters
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$start_date = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : date('Y-m-01'); // First day of current month
$end_date = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : date('Y-m-d'); // Today
$report_format = isset($_GET['format']) ? sanitizeInput($_GET['format']) : 'preview'; // preview or download

// Validate dates
if ($start_date && !strtotime($start_date)) {
    $start_date = date('Y-m-01');
}
if ($end_date && !strtotime($end_date)) {
    $end_date = date('Y-m-d');
}
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Build query for report
$query = "SELECT 
    fl.id as location_id,
    fl.name as location_name,
    COUNT(fi.id) as total_invoices,
    SUM(fi.total) as total_amount,
    MIN(fi.date) as first_invoice_date,
    MAX(fi.date) as last_invoice_date
FROM 
    finance_location fl
LEFT JOIN 
    finance_invoice fi ON fl.id = fi.location";

$conditions = [];
$params = [];

// Add date filter
if ($start_date && $end_date) {
    $conditions[] = "(fi.date BETWEEN :start_date AND :end_date OR fi.date IS NULL)";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Add location filter if selected
if ($location_filter) {
    $conditions[] = "fl.id = :location_id";
    $params[':location_id'] = $location_filter;
}

// Add WHERE clause if there are conditions
if (!empty($conditions)) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}

// Group by location
$query .= " GROUP BY fl.id, fl.name ORDER BY fl.name";

// Prepare and execute query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$grand_total_invoices = 0;
$grand_total_amount = 0;
foreach ($report_data as $row) {
    $grand_total_invoices += $row['total_invoices'];
    $grand_total_amount += $row['total_amount'];
}

// Handle download request
if ($report_format === 'download') {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=invoice_report_' . date('Y-m-d') . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
    
    // CSV headers
    $headers = array(
        t('location'),
        t('total_invoices'),
        t('total_amount'),
        t('first_invoice_date'),
        t('last_invoice_date')
    );
    fputcsv($output, $headers);
    
    // Add data rows
    foreach ($report_data as $row) {
        $csv_row = array(
            $row['location_name'],
            $row['total_invoices'],
            '$' . number_format($row['total_amount'], 2),
            $row['first_invoice_date'] ? date('d/m/Y', strtotime($row['first_invoice_date'])) : t('no_data'),
            $row['last_invoice_date'] ? date('d/m/Y', strtotime($row['last_invoice_date'])) : t('no_data')
        );
        fputcsv($output, $csv_row);
    }
    
    // Add total row
    fputcsv($output, array('')); // Empty row
    fputcsv($output, array(
        t('grand_total'),
        $grand_total_invoices,
        '$' . number_format($grand_total_amount, 2),
        '',
        ''
    ));
    
    fclose($output);
    exit();
}
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
/* Report specific styles */
.report-summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.report-summary-card h5 {
    font-size: 1rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
}

.report-summary-card h2 {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 0;
}

.report-date-range {
    background-color: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 0.35rem;
}

.report-date-range h6 {
    color: #0d6efd;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.report-total-row {
    background-color: #f8f9fa;
    font-weight: 600;
    border-top: 2px solid #dee2e6;
}

.report-total-cell {
    font-weight: 600;
    color: #0d6efd;
}

.report-empty-state {
    text-align: center;
    padding: 3rem;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    border: 2px dashed #dee2e6;
}

.report-empty-state i {
    font-size: 3rem;
    color: #6c757d;
    margin-bottom: 1rem;
}

.report-empty-state h5 {
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.report-empty-state p {
    color: #6c757d;
    margin-bottom: 0;
}

/* Download button styles */
.btn-download {
    background-color: #28a745;
    border-color: #28a745;
    color: white;
}

.btn-download:hover {
    background-color: #218838;
    border-color: #1e7e34;
    color: white;
}

.btn-download i {
    margin-right: 0.5rem;
}

/* Loading spinner for report generation */
.spinner-border {
    width: 1.5rem;
    height: 1.5rem;
    border-width: 0.2em;
}

</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('invoice_report'); ?></h2>
    
    <!-- Report Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('report_filters'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" id="reportForm">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label for="location" class="form-label"><?php echo t('location'); ?></label>
                        <select name="location" id="location" class="form-select">
                            <option value=""><?php echo t('all_locations'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                    <?php echo $location['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="start_date" class="form-label"><?php echo t('start_date'); ?></label>
                        <input type="date" class="form-control" name="start_date" id="start_date" 
                               value="<?php echo $start_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label"><?php echo t('end_date'); ?></label>
                        <input type="date" class="form-control" name="end_date" id="end_date" 
                               value="<?php echo $end_date; ?>" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="d-flex gap-2">
                            <button type="submit" name="format" value="preview" class="btn btn-primary">
                                <i class="bi bi-eye"></i> <?php echo t('preview_report'); ?>
                            </button>
                            <?php if (!empty($report_data)): ?>
                            <button type="submit" name="format" value="download" class="btn btn-download">
                                <i class="bi bi-download"></i> <?php echo t('download_report'); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set default dates if not set
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (!startDateInput.value) {
        const firstDay = new Date();
        firstDay.setDate(1);
        startDateInput.value = firstDay.toISOString().split('T')[0];
    }
    
    if (!endDateInput.value) {
        const today = new Date().toISOString().split('T')[0];
        endDateInput.value = today;
    }
    
    // Validate date range
    const reportForm = document.getElementById('reportForm');
    reportForm.addEventListener('submit', function(e) {
        const startDate = new Date(startDateInput.value);
        const endDate = new Date(endDateInput.value);
        
        if (startDate > endDate) {
            e.preventDefault();
            alert('<?php echo t('invalid_date_range'); ?>');
            startDateInput.focus();
            return false;
        }
        
        // Show loading for download
        const submitButton = e.submitter;
        if (submitButton && submitButton.name === 'format' && submitButton.value === 'download') {
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?php echo t('generating_report'); ?>...';
            submitButton.disabled = true;
        }
    });
    
    // Quick date range buttons
    function setDateRange(days) {
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(endDate.getDate() - days);
        
        startDateInput.value = startDate.toISOString().split('T')[0];
        endDateInput.value = endDate.toISOString().split('T')[0];
    }
    
    // Add quick date range buttons if needed
    const dateFilterDiv = document.querySelector('.col-md-3:has(#start_date)');
    if (dateFilterDiv) {
        const quickButtons = document.createElement('div');
        quickButtons.className = 'mt-2';
        quickButtons.innerHTML = `
            <small class="text-muted d-block mb-1"><?php echo t('quick_ranges'); ?>:</small>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(7)">7 <?php echo t('days'); ?></button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(30)">30 <?php echo t('days'); ?></button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(90)">90 <?php echo t('days'); ?></button>
                <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(365)">1 <?php echo t('year'); ?></button>
            </div>
        `;
        dateFilterDiv.appendChild(quickButtons);
    }
    
    // Auto-hide messages
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

// Make setDateRange function available globally
window.setDateRange = function(days) {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(endDate.getDate() - days);
    
    startDateInput.value = startDate.toISOString().split('T')[0];
    endDateInput.value = endDate.toISOString().split('T')[0];
};
</script>

<?php
require_once '../includes/footer.php';
?>
