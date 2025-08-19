<?php
ob_start();
require_once 'includes/header-staff.php';
require_once 'translate.php';

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



$stmt = $pdo->prepare("SELECT COUNT(*) as new_items FROM addnewitems WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$new_items = $stmt->fetch(PDO::FETCH_ASSOC)['new_items'];

// Count of quantity increases today
$stmt = $pdo->prepare("SELECT COUNT(*) as qty_increases FROM addqtyitems WHERE DATE(added_at) = CURDATE()");
$stmt->execute();
$qty_increases = $stmt->fetch(PDO::FETCH_ASSOC)['qty_increases'];

// Total is the sum of both
$total_items = $new_items + $qty_increases;



$stmt = $pdo->prepare("SELECT COUNT(*) as total_transfers FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$total_transfers = $stmt->fetch(PDO::FETCH_ASSOC)['total_transfers'];

// Count of repairs today (excluding quantity = 0)
$stmt = $pdo->prepare("SELECT COUNT(*) as total_repairs FROM items i JOIN locations l ON i.location_id = l.id 
                      WHERE l.type = 'Repair' AND DATE(i.created_at) = CURDATE()
                      AND i.quantity > 0");
$stmt->execute();
$total_repairs = $stmt->fetch(PDO::FETCH_ASSOC)['total_repairs'];

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
// Get today's transfers for modal (corrected version)
// Simplified version without user info
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
  overflow: hidden;
  height: 100vh;
}
.pagination-container {
        display: flex;
        justify-content: center;
        margin-top: 20px;
    }
.stat-card {
        cursor: pointer;
        transition: transform 0.2s;
        
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        
    }
    
    .modal-lg-custom {
        max-width: 90%;
    }
    
    .table-modal {
        max-height: 70vh;
        overflow-y: auto;
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
  width: 100%;
  min-height: 100vh;
  transition: all 0.3s;
  background-color: #f5f7fb;
}
/* Default (desktop) - no scrolling */
body {
    overflow: hidden;
    height: 100vh;
}

.main-content {
    overflow: hidden;
    height: calc(100vh - 4.375rem); /* Adjust for navbar height */
}


/* Mobile devices - allow scrolling */
@media (max-width: 768px) {
    body {
        overflow: auto;
        height: auto;
    }
    
    .main-content {
        overflow: visible;
        height: auto;
    }
    
    .container-fluid {
        overflow: visible;
        height: auto;
    }
    
    /* Ensure tables are scrollable horizontally on mobile */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}
@media (max-width: 768px) {
    /* Stack the two columns vertically */
    .row > .col-md-6 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }

    /* Add margin between stacked cards */
    .row > .col-md-6:not(:last-child) {
        margin-bottom: 1.5rem;
    }

    /* Ensure tables are scrollable horizontally */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
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
  white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
@media (max-width: 768px) {
    /* Make stat cards stack vertically */
    .row.mb-3 {
        flex-direction: column;
    }
    
    .row.mb-3 .col-md-4 {
        width: 100%;
        margin-bottom: 1rem;
    }
    
    /* Make content columns stack vertically */
    .row:not(.mb-3) {
        flex-direction: column;
    }
    
    .row:not(.mb-3) > .col-md-6 {
        width: 100%;
        margin-bottom: 1rem;
    }
    
    /* Adjust quick action buttons */
    .card-body .row.text-center {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .card-body .col.flex-grow-1 {
        flex: 0 0 50%;
        max-width: 50%;
        padding: 0.5rem;
    }
    
    .card-body .btn-lg {
        padding: 0.75rem;
        font-size: 0.9rem;
    }
    
    .card-body .btn-lg i {
        font-size: 1.5rem;
        margin-bottom: 0.25rem;
    }
    
    /* Adjust table for mobile */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Make list items more compact */
    .list-group-item {
        padding: 0.75rem 1rem;
    }
    
    /* Adjust card headers */
    .card-header h5 {
        font-size: 1.1rem;
    }
    
    /* Make main title smaller */
    h2.mb-4 {
        font-size: 1.5rem;
    }
}

@media (max-width: 576px) {
    /* Make quick action buttons single column */
    .card-body .col.flex-grow-1 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    /* Adjust stat card content */
    .stat-card .card-body {
        padding: 1rem;
    }
    
    .stat-card h5 {
        font-size: 1rem;
    }
    
    .stat-card h2 {
        font-size: 1.5rem;
    }
    
    .stat-card i {
        font-size: 2rem !important;
    }
    
    /* Make tables display as cards on very small screens */
  }
  @media (max-width: 768px) {
    /* Make tables display as cards on mobile */
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
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('dashboard_title');?></h2>
    
    <div class="row mb-3">
    <div class="col-md-4">
    <a href="today_items-staff.php" style="text-decoration: none;">
        <div class="card bg-primary text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayItemsModal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title"><?php echo t('new_items_card');?></h5>
                        <h2 class="mb-0"><?php echo $total_items; ?></h2>
                    </div>
                    <i class="bi bi-box-seam fs-1"></i>
                </div>
            </div>
        </div>
    </a>
</div>

        <div class="col-md-4">
          <a href="today_transfer-staff.php" style="text-decoration:none;">
          <div class="card bg-success text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayTransfersModal">
          
       
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title"><?php echo t('transfers_card');?></h5>
                            <h2 class="mb-0"><?php echo $total_transfers; ?></h2>
                        </div>
                        <i class="bi bi-arrow-left-right fs-1"></i>
                    </div>
                </div>
            </div></a>
        </div>
<div class="col-md-4">
    <a href="today_repairs-staff.php" style="text-decoration: none;">
        <div class="card bg-warning text-white stat-card" data-bs-toggle="modal" data-bs-target="#todayRepairsModal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title"><?php echo t('repairs_card');?></h5>
                        <h2 class="mb-0"><?php echo $total_repairs; ?></h2>
                    </div>
                    <i class="bi bi-tools fs-1"></i>
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
        <div class="row text-center">
            <div class="col flex-grow-1 mb-3">
                <a href="item-control-staff.php" class="btn btn-outline-primary btn-lg w-100 py-3">
                    <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                    <?php echo t('items_button');?>
                </a>
            </div>
           
            <div class="col flex-grow-1 mb-3">
                <a href="stock-transfer-staff.php" class="btn btn-outline-success btn-lg w-100 py-3">
                    <i class="bi bi-arrow-left-right fs-1 d-block mb-2"></i>
                    <?php echo t('transfers_button');?>
                </a>
            </div>
        
          
           
        
            <div class="col flex-grow-1 mb-3">
                <a href="low-stock-alert-staff.php" class="btn btn-outline-danger btn-lg w-100 py-3">
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
                <div class="card-body" >
                    <div class="table-responsive">
                    <table class="table table-striped">
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
                <td data-label="ឈ្មោះទំនិញ"><?php echo $item['name']; ?></td>
                <td data-label="បរិមាណ" class="text-danger"><?php echo $item['quantity']; ?></td>
                <td data-label="ទំហំ"><?php echo !empty($item['size']) ? $item['size'] : 'N/A'; ?></td>
                <td data-label="ទីតាំង"><?php echo $item['location']; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                    </div>
                </div>
            </div>
        </div>
    
    
    

<?php
require_once 'includes/footer.php';
?>