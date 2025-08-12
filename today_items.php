<?php
// File: today_all_items.php
require_once 'includes/header.php';
require_once  'translate.php'; 

if (!isAdmin()) {
  $_SESSION['error'] = "You don't have permission to access this page";
  header('Location: dashboard-staff.php');
  exit();
}
checkAuth();

$items_per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $items_per_page;

// Get total count of today's items from both tables
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total FROM (
        SELECT id FROM addnewitems WHERE DATE(created_at) = CURDATE()
        UNION ALL
        SELECT id FROM addqtyitems WHERE DATE(added_at) = CURDATE()
    ) as combined
");
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $items_per_page);

// Fetch today's items with pagination and proper joins
$stmt = $pdo->prepare("
    (SELECT 
        'new' as type,
        an.id,
        an.item_id,
        an.invoice_no,
        i.name as item_name,
        an.quantity,
        an.alert_quantity,
        an.size,
        l.name as location,
        u.username as added_by,
        an.created_at,
        an.remark,
        NULL as old_quantity
    FROM addnewitems an
    JOIN items i ON an.item_id = i.id
    JOIN locations l ON an.location_id = l.id
    JOIN users u ON an.added_by = u.id
    WHERE DATE(an.created_at) = CURDATE())
    
    UNION ALL
    
    (SELECT 
        'qty' as type,
        aq.id,
        aq.item_id,
        aq.invoice_no,
        i.name as item_name,
        aq.added_quantity as quantity,
        NULL as alert_quantity,
        aq.size,
        (SELECT name FROM locations WHERE id = i.location_id) as location,
        u.username as added_by,
        aq.added_at as created_at,
        aq.remark,
        (i.quantity - aq.added_quantity) as old_quantity
    FROM addqtyitems aq
    JOIN items i ON aq.item_id = i.id
    JOIN users u ON aq.added_by = u.id
    WHERE DATE(aq.added_at) = CURDATE())
    
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$today_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
  color: black;
  font-size:16px;
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
@media (max-width: 576px) {
    /* Adjust card and table layout */
    .card-body {
        padding: 0.5rem;
    }
    
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Adjust table font sizes */
    .table th, 
    .table td {
        padding: 0.5rem;
        font-size: 0.8rem;
    }
    
    /* Adjust header sizes */
    .h3, h1 {
        font-size: 1.3rem;
    }
    
    /* Stack pagination items on small screens */
    .pagination {
        flex-wrap: wrap;
    }
    
    .page-item {
        margin: 2px 0;
    }
    
    /* Adjust the "back" button */
    .btn-secondary {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }
    
    /* Hide less important columns on mobile */
    .table td:nth-child(8), 
    .table th:nth-child(8),
    .table td:nth-child(9), 
    .table th:nth-child(9) {
        display: none;
    }
    
    /* Adjust card header padding */
    .card-header {
        padding: 0.75rem;
    }
}
/* Add this to your existing CSS */
.table th {
    white-space: nowrap;      /* Prevent text wrapping */
    overflow: hidden;         /* Hide overflow */
    text-overflow: ellipsis;  /* Show ... if text is too long */
    max-width: 150px;         /* Set a maximum width */
    padding: 0.5rem;          /* Adjust padding to be more compact */
}

/* For mobile devices */
@media (max-width: 576px) {
    .table th {
        font-size: 0.7rem;    /* Slightly smaller font */
        padding: 0.3rem;      /* More compact padding */
        max-width: 100px;     /* Smaller max width on mobile */
    }
}
/* Force all table cells to stay on one line */
.table td {
    white-space: normal;       /* Allow text to wrap */
    overflow: visible;         /* Show all content */
    text-overflow: clip;       /* Default behavior */
    max-width: none;          /* Remove max-width restriction */
    padding: 0.5rem;          /* Keep your desired padding */
}

/* Mobile-specific adjustments */
@media (max-width: 768px) {
  .table td {
        white-space: normal;   /* Allow wrapping on mobile */
        max-width: none;       /* Remove max-width restriction */
        padding: 0.3rem;       /* Keep your desired padding */
        font-size: 0.85rem;    /* Keep font size adjustment if needed */
    }
}

/* For very small screens */
@media (max-width: 576px) {
  .table td {
        white-space: normal;  /* Allow wrapping on small screens */
        max-width: none;      /* Remove max-width restriction */
        font-size: 0.8rem;    /* Keep font size adjustment if needed */
    }
    
    /* If you still want to hide some columns on mobile */
    .table td:nth-child(8),  /* Location */
    .table td:nth-child(9) { /* Remark */
        display: table-cell;  /* Show these columns if you want */
    }
}
</style>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><?php echo t('total_item');?> (<?php echo $total_items; ?> <?php echo t('total');?>)</h1>
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> <?php echo t('return');?>
        </a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('total_item');?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no');?></th>
                            <th><?php echo t('item_type');?></th>
                            <th><?php echo t('item_date');?></th>
                            <th><?php echo t('item_invoice');?></th>
                            <th><?php echo t('item_name');?></th>
                            <th><?php echo t('item_qty');?></th>
                            <th><?php echo t('item_size');?></th>
                            <th><?php echo t('item_location');?></th>
                            <th><?php echo t('item_remark');?></th>
                            <th><?php echo t('item_addby');?></th>
                            
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($today_items) > 0): ?>
                            <?php foreach ($today_items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td>
                                        <?php if ($item['type'] == 'new'): ?>
                                            <span class="badge badge-success"><?php echo t('new_items_card');?></span>
                                        <?php else: ?>
                                            <span class="badge badge-primary"><?php echo t('add_qty');?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($item['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($item['invoice_no']); ?></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td>
                                        <?php echo $item['quantity']; ?>
                                        <?php if ($item['type'] == 'qty' && $item['old_quantity'] !== null): ?>
                                            <small class="text-muted">(<?php echo t('from');?> <?php echo $item['old_quantity']; ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['size']); ?></td>
                                    <td><?php echo htmlspecialchars($item['location']); ?></td>
                                    <td><?php echo htmlspecialchars($item['remark']); ?></td>
                                    <td><?php echo htmlspecialchars($item['added_by']); ?></td>
                                    
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center"><?php echo t('item_info');?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mt-3">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>"><?php echo t('previous');?></a>
                    </li>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>"><?php echo t('next');?></a>
                    </li>
                </ul>
            </nav>
           
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>