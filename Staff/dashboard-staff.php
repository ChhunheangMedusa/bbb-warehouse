<?php
ob_start();
require_once '../includes/header-staff.php';
require_once  'translate.php'; 

if (!isStaff()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard.php');
  exit();
}

$show_welcome = false;
if (isset($_SESSION['show_welcome']) && $_SESSION['show_welcome']) {
    $show_welcome = true;
    unset($_SESSION['show_welcome']); // Unset it so it only shows once
}
checkAuth();

// Count of new items today
$stmt = $pdo->prepare("SELECT COUNT(*) as new_items FROM addnewitems WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$new_items = $stmt->fetch(PDO::FETCH_ASSOC)['new_items'];

// Count of quantity increases today
$stmt = $pdo->prepare("SELECT COUNT(*) as qty_increases FROM addqtyitems WHERE DATE(added_at) = CURDATE()");
$stmt->execute();
$qty_increases = $stmt->fetch(PDO::FETCH_ASSOC)['qty_increases'];

// Count stock in items today (both new items and quantity increases)
$stmt = $pdo->prepare("SELECT COUNT(*) as total_items 
                      FROM stock_in_history 
                      WHERE DATE(action_at) = CURDATE() 
                      AND action_type IN ('new', 'add')");
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total_items'];

// Count of quantity deductions today
$stmt = $pdo->prepare("SELECT COUNT(*) as qty_deductions FROM deductqtyitems WHERE DATE(deducted_at) = CURDATE()");
$stmt->execute();
$qty_deductions = $stmt->fetch(PDO::FETCH_ASSOC)['qty_deductions'];

// Count of new deductions today (if you have a separate table for new deductions)
$stmt = $pdo->prepare("SELECT COUNT(*) as new_deductions FROM stock_out_history WHERE DATE(action_at) = CURDATE() AND action_type = 'deduct'");
$stmt->execute();
$new_deductions = $stmt->fetch(PDO::FETCH_ASSOC)['new_deductions'];

// Count of new transfer today (if you have a separate table for new deductions)
$stmt = $pdo->prepare("SELECT COUNT(*) as new_transfer FROM transfer_history WHERE DATE(action_at) = CURDATE()");
$stmt->execute();
$new_transfer = $stmt->fetch(PDO::FETCH_ASSOC)['new_transfer'];

// Count of new repair today (if you have a separate table for new deductions)
$stmt = $pdo->prepare("SELECT COUNT(*) as new_repair FROM repair_items WHERE DATE(action_at) = CURDATE() AND action_type = 'send_for_repair'");
$stmt->execute();
$new_repair = $stmt->fetch(PDO::FETCH_ASSOC)['new_repair'];

// Count of new repairs today from repair_history table
$stmt = $pdo->prepare("SELECT COUNT(*) as new_repair_history FROM repair_history 
                      WHERE DATE(history_action_at) = CURDATE() 
                      AND action_type = 'send_for_repair'");
$stmt->execute();
$new_repair_history = $stmt->fetch(PDO::FETCH_ASSOC)['new_repair_history'];

// Add to your existing total if needed, or use it separately
$total_new_repair = $new_repair;
$total_new_repair_history = $new_repair_history;

$total_new_transfer = $new_transfer;

// Total deductions is the sum of both
$total_items_out = $new_deductions;

// Get recent access logs
$stmt = $pdo->prepare("SELECT al.activity_type, al.activity_detail, al.created_at, u.username 
                      FROM access_logs al 
                      LEFT JOIN users u ON al.user_id = u.id 
                      ORDER BY al.created_at DESC LIMIT 5");
$stmt->execute();
$recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock items
$stmt = $pdo->prepare("SELECT i.name, i.quantity, i.size, l.name as location 
                      FROM items i 
                      JOIN locations l ON i.location_id = l.id 
                      WHERE i.quantity < 10 
                      ORDER BY i.quantity ASC LIMIT 5");
$stmt->execute();
$low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items_per_page = 10;
$page = isset($_GET['items_page']) ? max(1, intval($_GET['items_page'])) : 1;
$offset = ($page - 1) * $items_per_page;
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM items WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$total_items_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_items_pages = ceil($total_items_count / $items_per_page);

// Get today's items for modal
$stmt = $pdo->prepare("SELECT 
                        i.id,
                        i.name, 
                        i.quantity, 
                        i.size, 
                        l.name as location,
                        i.status,
                        i.created_at
                    FROM items i 
                    JOIN locations l ON i.location_id = l.id 
                    WHERE DATE(i.created_at) = CURDATE()
                    ORDER BY i.created_at DESC
                    LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$today_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($today_items as $item) {
  $activity_stmt = $pdo->prepare("
      SELECT al.activity_detail, al.created_at, u.username 
      FROM access_logs al
      LEFT JOIN users u ON al.user_id = u.id
      WHERE al.activity_detail LIKE :item_name
      ORDER BY al.created_at DESC 
      LIMIT 1
  ");
  $activity_stmt->bindValue(':item_name', '%'.$item['name'].'%');
  $activity_stmt->execute();
  $latest_activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);
  
  $status_color = 'secondary';
  if ($item['status'] == 'In Stock') $status_color = 'success';
  elseif ($item['status'] == 'Low Stock') $status_color = 'warning';
  elseif ($item['status'] == 'Out of Stock') $status_color = 'danger';
  elseif ($item['status'] == 'In Repair') $status_color = 'info';
}

// Get today's transfers for modal
$transfers_page = isset($_GET['transfers_page']) ? max(1, intval($_GET['transfers_page'])) : 1;
$transfers_offset = ($transfers_page - 1) * $items_per_page;
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$total_transfers_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_transfers_pages = ceil($total_transfers_count / $items_per_page);

$stmt = $pdo->prepare("SELECT st.id, i.name as item_name, l1.name as from_location, l2.name as to_location, st.quantity, st.created_at
                      FROM stock_transfers st 
                      JOIN items i ON st.item_id = i.id 
                      JOIN locations l1 ON st.from_location_id = l1.id 
                      JOIN locations l2 ON st.to_location_id = l2.id 
                      WHERE DATE(st.created_at) = CURDATE()
                      ORDER BY st.created_at DESC
                      LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $transfers_offset, PDO::PARAM_INT);
$stmt->execute();
$today_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's repairs for modal
$repairs_page = isset($_GET['repairs_page']) ? max(1, intval($_GET['repairs_page'])) : 1;
$repairs_offset = ($repairs_page - 1) * $items_per_page;

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM items i JOIN locations l ON i.location_id = l.id 
                      WHERE l.type = 'Repair' AND DATE(i.created_at) = CURDATE()");
$stmt->execute();
$total_repairs_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_repairs_pages = ceil($total_repairs_count / $items_per_page);

$stmt = $pdo->prepare("SELECT i.name, i.quantity, i.size, l.name as location, i.created_at 
                      FROM items i 
                      JOIN locations l ON i.location_id = l.id 
                      WHERE l.type = 'Repair' AND DATE(i.created_at) = CURDATE()
                      ORDER BY i.created_at DESC
                      LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $repairs_offset, PDO::PARAM_INT);
$stmt->execute();
$today_repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        color: #674ea7;
        border-color: #674ea7;
        background-color: transparent;
    }
    
    .btn-outline-purple:hover {
        color: white;
        background-color: #674ea7;
        border-color: #674ea7;
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

  

        /* Desktop view - all in one row */
        @media (min-width: 768px) {
       
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
            color: #674ea7;
            border-color: #674ea7;
            background-color: transparent;
        }
        
        .btn-outline-purple:hover {
            color: white;
            background-color: #674ea7;
            border-color: #674ea7;
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
    .quick-actions-container {
    display: flex;
    flex-wrap: nowrap;
    justify-content: left; /* Center the cards */
    gap: 0.25rem; /* Very small gap between cards */
    overflow-x: auto;
    padding-bottom: 0.5rem;
}

.quick-action-card {
    flex: 1;
    min-width: 140px;
    max-width: 160px; /* Reduced maximum width */
    margin: 0 0.1rem; /* Minimal margin */
}

.quick-action-card .btn {
    padding: 0.6rem 0.4rem; /* Compact padding */
    white-space: nowrap;
    border-radius: 0.5rem; /* Slightly rounded corners */
}

.quick-action-card .btn i {
    font-size: 1.5rem !important; /* Smaller icons */
    margin-bottom: 0.4rem;
}

/* Mobile view adjustments */
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



</style>

<div class="container-fluid">
<h2 class="mb-4"><?php echo t('dashboard_title'); ?></h2>
    
    <div class="row mb-2">
    <div class="col-md-3">
    <a href="today_items.php" style="text-decoration: none;">
        <div class="card bg-primary text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayItemsModal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title"><?php echo t('new_items_card'); ?></h5>
                        <h2 class="mb-0"><?php echo $total_items; ?></h2>
                    </div>
                    <i class="bi bi-box-seam fs-1"></i>
                </div>
            </div>
        </div>
    </a>
</div>
<div class="col-md-3">
    <a href="today_items_out.php" style="text-decoration: none;">
        <div class="card bg-danger text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayItemsModal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title"><?php echo t('stock_out'); ?></h5>
                        <h2 class="mb-0"><?php echo $total_items_out; ?></h2>
                    </div>
                    <i class="bi bi-box-seam fs-1"></i>
                </div>
            </div>
        </div>
    </a>
</div>
        <div class="col-md-3">
          <a href="today_transfer.php" style="text-decoration:none;">
          <div class="card bg-success text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayTransfersModal">
          
       
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title"><?php echo t('transfers_card'); ?></h5>
                            <h2 class="mb-0"><?php echo $total_new_transfer; ?></h2>
                        </div>
                        <i class="bi bi-arrow-left-right fs-1"></i>
                    </div>
                </div>
            </div></a>
        </div>
        <div class="col-md-3">
    <a href="today_repairs.php" style="text-decoration: none;">
        <div class="card bg-warning text-white stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title"><?php echo t('repairs_card'); ?></h5>
                        <h2 class="mb-0"><?php echo $total_new_repair_history; ?></h2>
                    </div>
                    <i class="bi bi-clock-history fs-1"></i>
                </div>
            </div>
        </div>
    </a>
</div>

<div class="col-md-12">
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><?php echo t('quick_actions_title');?></h5>
        </div>
        <div class="card-body">
            <div class="quick-actions-container">
                <div class="quick-action-card">
                    <a href="items.php" class="btn btn-outline-primary btn-lg w-100 py-3">
                        <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                        <?php echo t('items_button');?>
                    </a>
                </div>
               
                <div class="quick-action-card">
                    <a href="stock-transfer.php" class="btn btn-outline-success btn-lg w-100 py-3">
                        <i class="bi bi-arrow-left-right fs-1 d-block mb-2"></i>
                        <?php echo t('transfers_button');?>
                    </a>
                </div>

                <div class="quick-action-card">
                    <a href="low-stock-alert.php" class="btn btn-outline-danger btn-lg w-100 py-3">
                        <i class="bi bi-file-earmark-text fs-1 d-block mb-2"></i>
                        <?php echo t('low_stock_button');?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

    
    <div class="col-md-12">
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><?php echo t('low_stock_title');?></h5>
            </div>
            <div class="card-body">
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table class="table table-striped low-stock-table">
                        <thead>
                            <tr>
                                <th><?php echo t('item_name_column');?></th>
                                <th><?php echo t('quantity_column');?></th>
                                <th><?php echo t('size_column');?></th>
                                <th><?php echo t('location_column');?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_items as $item): ?>
                                <tr>
                                    <td data-label="<?php echo t('item_name_column');?>"><?php echo $item['name']; ?></td>
                                    <td data-label="<?php echo t('quantity_column');?>" class="text-danger"><?php echo $item['quantity']; ?></td>
                                    <td data-label="<?php echo t('size_column');?>"><?php echo !empty($item['size']) ? $item['size'] : 'N/A'; ?></td>
                                    <td data-label="<?php echo t('location_column');?>"><?php echo $item['location']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Cards -->
                <div class="table-cards">
                    <?php foreach ($low_stock_items as $item): ?>
                        <div class="table-card stock-card">
                            <div class="card-row">
                                <span class="card-label"><?php echo t('item_name_column');?>:</span>
                                <span class="card-value"><?php echo $item['name']; ?></span>
                            </div>
                            <div class="card-row">
                                <span class="card-label"><?php echo t('quantity_column');?>:</span>
                                <span class="card-value text-danger"><?php echo $item['quantity']; ?></span>
                            </div>
                            <div class="card-row">
                                <span class="card-label"><?php echo t('size_column');?>:</span>
                                <span class="card-value"><?php echo !empty($item['size']) ? $item['size'] : 'N/A'; ?></span>
                            </div>
                            <div class="card-row">
                                <span class="card-label"><?php echo t('location_column');?>:</span>
                                <span class="card-value"><?php echo $item['location']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>