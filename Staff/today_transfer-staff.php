<?php
require_once 'includes/header-staff.php';
require_once  'translate.php'; 
if (!isStaff()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard.php');
  exit();
}
checkAuth();



$stmt = $pdo->prepare("SELECT COUNT(*) as total_transfers FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$total_transfers = $stmt->fetch(PDO::FETCH_ASSOC)['total_transfers'];



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



$transfers_page = isset($_GET['transfers_page']) ? max(1, intval($_GET['transfers_page'])) : 1;
$transfers_offset = ($transfers_page - 1) * $items_per_page;
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$total_transfers_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_transfers_pages = ceil($total_transfers_count / $items_per_page);

$stmt = $pdo->prepare("SELECT st.id, i.name as item_name, l1.name as from_location, l2.name as to_location, 
                      st.quantity, st.remark, st.size, st.invoice_no, st.created_at, u.username
                      FROM stock_transfers st 
                      JOIN items i ON st.item_id = i.id 
                      JOIN locations l1 ON st.from_location_id = l1.id 
                      JOIN locations l2 ON st.to_location_id = l2.id 
                      JOIN users u ON st.user_id = u.id
                      WHERE DATE(st.created_at) = CURDATE()
                      ORDER BY st.created_at DESC
                      LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $transfers_offset, PDO::PARAM_INT);
$stmt->execute();
$today_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);


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
/* Add this to your style section */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    width: 100%;
}

/* Optional: Add horizontal scroll indicator for mobile */
.table-responsive::-webkit-scrollbar {
    height: 5px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: var(--secondary);
    border-radius: 10px;
}

/* Make table cells wrap content */
.table td, .table th {
    white-space: nowrap;
}

/* Adjust font sizes for mobile */
@media (max-width: 768px) {
    .table {
        font-size: 0.85rem;
    }
    
    .table th, .table td {
        padding: 0.5rem;
    }
    
    .h3, h1 {
        font-size: 1.5rem;
    }
    
    .btn {
        padding: 0.35rem 0.75rem;
        font-size: 0.85rem;
    }
}
/* Add this to your style section */
.table th {
    white-space: nowrap;       /* Prevent text wrapping */
    overflow: hidden;          /* Hide overflow */
    text-overflow: ellipsis;   /* Add ... if text is too long */
    max-width: 200px;          /* Optional: Set a max-width */
 
}
.table td {
    white-space: normal;      /* Allow text to wrap */
    overflow: visible;        /* Show all content */
    word-break: break-word;   /* Break long words if needed */
}
/* For mobile responsiveness */
@media (max-width: 768px) {
    .table th {
      padding: 0.5rem;
        font-size: 0.8rem;
        white-space: nowrap;  /* Still single line */
        overflow: hidden;
        text-overflow: ellipsis; /* Slightly smaller font */
    }
    .table td {
        padding: 0.5rem;
        font-size: 0.8rem;
        white-space: normal;
    }
}
/* Force single line for ALL table cells */
.table th,
.table td {
  white-space: nowrap;   /* Prevent wrapping */
    overflow: visible;     /* Show all content (no hiding) */
    text-overflow: clip;   /* Disable ellipsis (optional) */
    padding: 0.75rem;         /* Maintain padding */
}

/* Mobile responsiveness */
@media (max-width: 768px) {
  .table th,
    .table td {
        padding: 0.5rem;     /* Tighter spacing on mobile */
        font-size: 0.8rem;    /* Slightly smaller text */
    }
}

/* Ensure horizontal scrolling works */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch; /* Smooth iOS scrolling */
}


</style>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><?php echo t('total_transfer');?> (<?php echo $total_transfers_count; ?> <?php echo t('total');?>)</h1>
        <a href="dashboard-staff.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> <?php echo t('return');?>
        </a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><?php echo t('total_transfer');?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                        <th><?php echo t('item_no');?></th>
                        <th><?php echo t('item_date');?></th>
                        <th><?php echo t('item_invoice');?></th>
                                <th><?php echo t('item_name');?></th>
                                <th><?php echo t('item_qty');?></th>
                                <th><?php echo t('item_size');?></th>
                                <th><?php echo t('from_location');?></th>
                                <th><?php echo t('to_location');?></th>
                                <th><?php echo t('item_remark');?></th>
                                <th><?php echo t('item_addby');?></th>
                                
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($today_transfers) > 0): ?>
                            <?php foreach ($today_transfers as $index => $transfer): ?>
                                <tr>
                                <td><?= $index + 1 + $transfers_offset ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($transfer['created_at'])) ?></td>
                                <td><?= $transfer['invoice_no'] ?></td>
                                        <td><?= htmlspecialchars($transfer['item_name']) ?></td>
                                        <td><?= $transfer['quantity'] ?></td>
                                        <td><?= $transfer['size'] ?></td>
                                        
                                        <td><?= htmlspecialchars($transfer['from_location']) ?></td>
                                        <td><?= htmlspecialchars($transfer['to_location']) ?></td>
                                        <td><?= $transfer['remark'] ?></td>
                                        <td><?= htmlspecialchars($transfer['username']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center"><?php echo t('transfer_info');?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Page navigation">
    <ul class="pagination justify-content-center mt-3">
        <li class="page-item <?php echo $transfers_page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?transfers_page=<?php echo $transfers_page - 1; ?>"><?php echo t('previous');?></a>
        </li>
        
        <?php for ($i = 1; $i <= $total_transfers_pages; $i++): ?>
            <li class="page-item <?php echo $transfers_page == $i ? 'active' : ''; ?>">
                <a class="page-link" href="?transfers_page=<?php echo $i; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        
        <li class="page-item <?php echo $transfers_page >= $total_transfers_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?transfers_page=<?php echo $transfers_page + 1; ?>"><?php echo t('next');?></a>
        </li>
    </ul>
</nav>   
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>


           