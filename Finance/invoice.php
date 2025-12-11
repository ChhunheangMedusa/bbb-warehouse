<?php
ob_start();
require_once '../includes/header-finance.php';
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'translate.php';



// Check if user has permission (admin or finance staff only)
if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php'); // Redirect to login or home page
    exit();
}
// Check if user is authenticated
checkAuth();


// Get all locations from finance_location table
$location_stmt = $pdo->query("SELECT * FROM finance_location ORDER BY name");
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all suppliers
$supplier_stmt = $pdo->query("SELECT * FROM supplier ORDER BY name");
$suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter parameters
$search_filter = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$supplier_filter = isset($_GET['supplier']) ? (int)$_GET['supplier'] : null;
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$start_date_filter = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : '';
$end_date_filter = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Sort mapping
$sort_mapping = [
    'receipt_asc' => ['field' => 'fi.receipt_no', 'direction' => 'ASC'],
    'receipt_desc' => ['field' => 'fi.receipt_no', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'fi.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'fi.date', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'fl.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'fl.name', 'direction' => 'DESC'],
    'supplier_asc' => ['field' => 's.name', 'direction' => 'ASC'],
    'supplier_desc' => ['field' => 's.name', 'direction' => 'DESC'],
    'price_asc' => ['field' => 'fi.total_price', 'direction' => 'ASC'],
    'price_desc' => ['field' => 'fi.total_price', 'direction' => 'DESC'],
];

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
        $total_price = (float)$_POST['total_price'];
        $remark = sanitizeInput($_POST['remark'] ?? '');
        
        try {
            // Check for duplicate receipt number
            $stmt = $pdo->prepare("SELECT id FROM finance_invoice WHERE receipt_no = ?");
            $stmt->execute([$receipt_no]);
            
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateInvoiceModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("Duplicate receipt number");
            }
            
            // Insert invoice
            $stmt = $pdo->prepare("INSERT INTO finance_invoice (receipt_no, date, location_id, supplier_id, total_price, remark, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$receipt_no, $date, $location_id, $supplier_id, $total_price, $remark, $_SESSION['user_id']]);
            $invoice_id = $pdo->lastInsertId();
            
            // Handle file upload
            if (!empty($_FILES['photo']['name'])) {
                if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    // Validate image type
                    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($fileInfo, $_FILES['photo']['tmp_name']);
                    finfo_close($fileInfo);
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/jpg'];
                    
                    if (in_array($mime, $allowedMimes)) {
                        // Read the file content
                        $imageData = file_get_contents($_FILES['photo']['tmp_name']);
                        
                        // Insert into database
                        $stmt = $pdo->prepare("INSERT INTO invoice_images (invoice_id, image_data) VALUES (?, ?)");
                        $stmt->execute([$invoice_id, $imageData]);
                    }
                }
            }
            
            // Log activity
            $log_message = "Added New Invoice: $receipt_no for $" . number_format($total_price, 2);
            logActivity($_SESSION['user_id'], 'Create Invoice', $log_message);
            
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
        $total_price = (float)$_POST['total_price'];
        $remark = sanitizeInput($_POST['remark'] ?? '');
        
        try {
            // Check for duplicate receipt number (excluding current invoice)
            $stmt = $pdo->prepare("SELECT id FROM finance_invoice WHERE receipt_no = ? AND id != ?");
            $stmt->execute([$receipt_no, $invoice_id]);
            
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateInvoiceModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("Duplicate receipt number");
            }
            
            // Update invoice
            $stmt = $pdo->prepare("UPDATE finance_invoice SET receipt_no = ?, date = ?, location_id = ?, supplier_id = ?, total_price = ?, remark = ? WHERE id = ?");
            $stmt->execute([$receipt_no, $date, $location_id, $supplier_id, $total_price, $remark, $invoice_id]);
            
            // Handle file upload if new photo is provided
            if (!empty($_FILES['photo']['name'])) {
                if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    // Validate image type
                    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($fileInfo, $_FILES['photo']['tmp_name']);
                    finfo_close($fileInfo);
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/jpg'];
                    
                    if (in_array($mime, $allowedMimes)) {
                        // Read the file content
                        $imageData = file_get_contents($_FILES['photo']['tmp_name']);
                        
                        // Check if image already exists for this invoice
                        $stmt = $pdo->prepare("SELECT id FROM invoice_images WHERE invoice_id = ?");
                        $stmt->execute([$invoice_id]);
                        
                        if ($stmt->fetch()) {
                            // Update existing image
                            $stmt = $pdo->prepare("UPDATE invoice_images SET image_data = ? WHERE invoice_id = ?");
                            $stmt->execute([$imageData, $invoice_id]);
                        } else {
                            // Insert new image
                            $stmt = $pdo->prepare("INSERT INTO invoice_images (invoice_id, image_data) VALUES (?, ?)");
                            $stmt->execute([$invoice_id, $imageData]);
                        }
                    }
                }
            }
            
            // Log activity
            $log_message = "Updated Invoice: $receipt_no";
            logActivity($_SESSION['user_id'], 'Update Invoice', $log_message);
            
            $_SESSION['success'] = "Invoice updated successfully!";
            redirect('invoice.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
}

// Get entries per page options
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $per_page;
$offset = ($page - 1) * $limit;

// Build query for invoices
$query = "SELECT 
            fi.id,
            fi.receipt_no,
            fi.date,
            fi.location_id,
            fl.name as location_name,
            fi.supplier_id,
            s.name as supplier_name,
            fi.total_price,
            fi.remark,
            fi.created_at,
            fi.created_by,
            u.username as created_by_name,
            ii.id as image_id
          FROM finance_invoice fi
          LEFT JOIN finance_location fl ON fi.location_id = fl.id
          LEFT JOIN supplier s ON fi.supplier_id = s.id
          LEFT JOIN users u ON fi.created_by = u.id
          LEFT JOIN invoice_images ii ON fi.id = ii.invoice_id
          WHERE 1=1";

$params = [];
$count_params = [];

// Add filters
if ($search_filter) {
    $query .= " AND (fi.receipt_no LIKE :search OR fi.remark LIKE :search OR s.name LIKE :search)";
    $params[':search'] = "%$search_filter%";
    $count_params[':search'] = "%$search_filter%";
}

if ($location_filter) {
    $query .= " AND fi.location_id = :location_id";
    $params[':location_id'] = $location_filter;
    $count_params[':location_id'] = $location_filter;
}

if ($supplier_filter) {
    $query .= " AND fi.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
    $count_params[':supplier_id'] = $supplier_filter;
}

if ($month_filter && $month_filter != 0) {
    $query .= " AND MONTH(fi.date) = :month";
    $params[':month'] = $month_filter;
    $count_params[':month'] = $month_filter;
}

if ($year_filter && $year_filter != 0) {
    $query .= " AND YEAR(fi.date) = :year";
    $params[':year'] = $year_filter;
    $count_params[':year'] = $year_filter;
}

if ($start_date_filter) {
    $query .= " AND fi.date >= :start_date";
    $params[':start_date'] = $start_date_filter;
    $count_params[':start_date'] = $start_date_filter;
}

if ($end_date_filter) {
    $query .= " AND fi.date <= :end_date";
    $params[':end_date'] = $end_date_filter;
    $count_params[':end_date'] = $end_date_filter;
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM finance_invoice fi
                LEFT JOIN finance_location fl ON fi.location_id = fl.id
                LEFT JOIN supplier s ON fi.supplier_id = s.id
                WHERE 1=1";

// Add filters to count query
if ($search_filter) {
    $count_query .= " AND (fi.receipt_no LIKE :search OR fi.remark LIKE :search OR s.name LIKE :search)";
}

if ($location_filter) {
    $count_query .= " AND fi.location_id = :location_id";
}

if ($supplier_filter) {
    $count_query .= " AND fi.supplier_id = :supplier_id";
}

if ($month_filter && $month_filter != 0) {
    $count_query .= " AND MONTH(fi.date) = :month";
}

if ($year_filter && $year_filter != 0) {
    $count_query .= " AND YEAR(fi.date) = :year";
}

if ($start_date_filter) {
    $count_query .= " AND fi.date >= :start_date";
}

if ($end_date_filter) {
    $count_query .= " AND fi.date <= :end_date";
}

$count_stmt = $pdo->prepare($count_query);
foreach ($count_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Add sorting and pagination to main query
$query .= " ORDER BY $sort_by $sort_order";
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

<div class="container-fluid">
    <h2 class="mb-4">Invoice Management</h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Filter Options</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo $search_filter; ?>">
                </div>
                <div class="col-md-2">
                    <label for="location" class="form-label">Location</label>
                    <select name="location" class="form-select">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                <?php echo $location['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="supplier" class="form-label">Supplier</label>
                    <select name="supplier" class="form-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo $supplier['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="month" class="form-label">Month</label>
                    <select name="month" class="form-select">
                        <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>>All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="year" class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>>All Years</option>
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date_filter; ?>">
                </div>
                <div class="col-md-2">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date_filter; ?>">
                </div>
                <div class="col-md-2">
                    <label for="sort_option" class="form-label">Sort By</label>
                    <select name="sort_option" class="form-select">
                        <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>>Date (Newest First)</option>
                        <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest First)</option>
                        <option value="receipt_asc" <?php echo $sort_option === 'receipt_asc' ? 'selected' : ''; ?>>Receipt No (A-Z)</option>
                        <option value="receipt_desc" <?php echo $sort_option === 'receipt_desc' ? 'selected' : ''; ?>>Receipt No (Z-A)</option>
                        <option value="price_desc" <?php echo $sort_option === 'price_desc' ? 'selected' : ''; ?>>Price (High to Low)</option>
                        <option value="price_asc" <?php echo $sort_option === 'price_asc' ? 'selected' : ''; ?>>Price (Low to High)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="per_page" class="form-label">Show Entries</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ($limit_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                                <?php echo $option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Search</button>
                    <a href="invoice.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Data Table Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Invoices</h5>
            <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
                <i class="bi bi-plus-circle"></i> Add New Invoice
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Receipt No</th>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Supplier</th>
                            <th>Total Price</th>
                            <th>Remark</th>
                            <th>Photo</th>
                            <th>Created By</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="11" class="text-center">No invoices found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $index => $invoice): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $invoice['receipt_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($invoice['date'])); ?></td>
                                    <td><?php echo $invoice['location_name']; ?></td>
                                    <td><?php echo $invoice['supplier_name']; ?></td>
                                    <td>$<?php echo number_format($invoice['total_price'], 2); ?></td>
                                    <td><?php echo $invoice['remark']; ?></td>
                                    <td>
                                        <?php if ($invoice['image_id']): ?>
                                            <img src="display_invoice_image.php?id=<?php echo $invoice['image_id']; ?>" 
                                                 class="img-thumbnail" width="50"
                                                 data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                                 data-invoice-id="<?php echo $invoice['id']; ?>">
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $invoice['created_by_name']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($invoice['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning edit-invoice-btn" 
                                                data-id="<?php echo $invoice['id']; ?>"
                                                data-receipt="<?php echo $invoice['receipt_no']; ?>"
                                                data-date="<?php echo $invoice['date']; ?>"
                                                data-location="<?php echo $invoice['location_id']; ?>"
                                                data-supplier="<?php echo $invoice['supplier_id']; ?>"
                                                data-price="<?php echo $invoice['total_price']; ?>"
                                                data-remark="<?php echo $invoice['remark']; ?>">
                                            <i class="bi bi-pencil"></i> Edit
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
                            'search' => $search_filter,
                            'location' => $location_filter,
                            'supplier' => $supplier_filter,
                            'month' => $month_filter,
                            'year' => $year_filter,
                            'start_date' => $start_date_filter,
                            'end_date' => $end_date_filter,
                            'sort_option' => $sort_option,
                            'per_page' => $per_page
                        ];
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
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Invoice Modal -->
<div class="modal fade" id="addInvoiceModal" tabindex="-1" aria-labelledby="addInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addInvoiceModalLabel">Add New Invoice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Receipt Number *</label>
                            <input type="text" class="form-control" name="receipt_no" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="date" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Location *</label>
                            <select class="form-select" name="location_id" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Supplier *</label>
                            <select class="form-select" name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo $supplier['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Total Price *</label>
                            <input type="number" class="form-control" name="total_price" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Photo</label>
                            <input type="file" class="form-control" name="photo" accept="image/*">
                            <div class="image-preview-container mt-2 row g-1" id="add-photo-preview"></div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Remark</label>
                            <textarea class="form-control" name="remark" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_invoice" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Invoice Modal -->
<div class="modal fade" id="editInvoiceModal" tabindex="-1" aria-labelledby="editInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="invoice_id" id="edit_invoice_id">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title" id="editInvoiceModalLabel">Edit Invoice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Receipt Number *</label>
                            <input type="text" class="form-control" name="receipt_no" id="edit_receipt_no" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="date" id="edit_date" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Location *</label>
                            <select class="form-select" name="location_id" id="edit_location_id" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Supplier *</label>
                            <select class="form-select" name="supplier_id" id="edit_supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo $supplier['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Total Price *</label>
                            <input type="number" class="form-control" name="total_price" id="edit_total_price" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Photo</label>
                            <input type="file" class="form-control" name="photo" accept="image/*">
                            <div class="image-preview-container mt-2 row g-1" id="edit-photo-preview"></div>
                            <small class="text-muted">Leave empty to keep current photo</small>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Remark</label>
                            <textarea class="form-control" name="remark" id="edit_remark" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="edit_invoice" class="btn btn-warning">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Duplicate Invoice Modal -->
<div class="modal fade" id="duplicateInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> Duplicate Receipt Number
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3">Duplicate Receipt Number Found!</h4>
                <p>The receipt number you entered already exists in the system. Please use a different receipt number.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> OK
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Image Gallery Modal -->
<div class="modal fade" id="imageGalleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invoice Photo</h5>
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

<script>
// Set today's date when add modal is shown
document.getElementById('addInvoiceModal').addEventListener('shown.bs.modal', function() {
    const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    this.querySelector('input[name="date"]').value = formattedDate;
});

// Handle edit invoice button clicks
document.querySelectorAll('.edit-invoice-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_invoice_id').value = this.dataset.id;
        document.getElementById('edit_receipt_no').value = this.dataset.receipt;
        document.getElementById('edit_date').value = this.dataset.date;
        document.getElementById('edit_location_id').value = this.dataset.location;
        document.getElementById('edit_supplier_id').value = this.dataset.supplier;
        document.getElementById('edit_total_price').value = this.dataset.price;
        document.getElementById('edit_remark').value = this.dataset.remark;
        
        const editModal = new bootstrap.Modal(document.getElementById('editInvoiceModal'));
        editModal.show();
    });
});

// Function to setup image preview
function setupImagePreview(inputElement, previewContainerId) {
    const previewContainer = document.getElementById(previewContainerId);
    
    if (!previewContainer) return;
    
    inputElement.addEventListener('change', function(e) {
        previewContainer.innerHTML = '';
        
        if (this.files && this.files.length > 0) {
            Array.from(this.files).forEach((file, index) => {
                if (!file.type.match('image.*')) return;
                
                const reader = new FileReader();
                const previewWrapper = document.createElement('div');
                previewWrapper.className = 'col-3 image-preview-wrapper';
                
                reader.onload = function(event) {
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.className = 'image-preview';
                    img.alt = file.name;
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-preview';
                    removeBtn.innerHTML = '×';
                    removeBtn.title = 'Remove this image';
                    removeBtn.dataset.index = index;
                    
                    removeBtn.addEventListener('click', function() {
                        previewWrapper.remove();
                        
                        // Remove the file from the input
                        const dt = new DataTransfer();
                        const { files } = inputElement;
                        
                        for (let i = 0; i < files.length; i++) {
                            if (i !== parseInt(this.dataset.index)) {
                                dt.items.add(files[i]);
                            }
                        }
                        
                        inputElement.files = dt.files;
                    });
                    
                    previewWrapper.appendChild(img);
                    previewWrapper.appendChild(removeBtn);
                    previewContainer.appendChild(previewWrapper);
                };
                
                reader.readAsDataURL(file);
            });
        }
    });
}

// Setup image preview for add modal
document.addEventListener('DOMContentLoaded', function() {
    const addImageInput = document.querySelector('#addInvoiceModal input[name="photo"]');
    const editImageInput = document.querySelector('#editInvoiceModal input[name="photo"]');
    
    if (addImageInput) {
        setupImagePreview(addImageInput, 'add-photo-preview');
    }
    
    if (editImageInput) {
        setupImagePreview(editImageInput, 'edit-photo-preview');
    }
});

// Image gallery functionality
document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
    img.addEventListener('click', function() {
        const invoiceId = this.getAttribute('data-invoice-id');
        fetch(`get_invoice_images.php?id=${invoiceId}`)
            .then(response => response.json())
            .then(images => {
                const carouselInner = document.getElementById('carousel-inner');
                carouselInner.innerHTML = '';
                
                if (images.length > 0) {
                    images.forEach((image, index) => {
                        const item = document.createElement('div');
                        item.className = `carousel-item ${index === 0 ? 'active' : ''}`;
                        
                        const imgElement = document.createElement('img');
                        imgElement.src = `display_invoice_image.php?id=${image.id}`;
                        imgElement.className = 'd-block w-100';
                        imgElement.alt = 'Invoice Image';
                        imgElement.style.maxHeight = '70vh';
                        imgElement.style.objectFit = 'contain';
                        
                        item.appendChild(imgElement);
                        carouselInner.appendChild(item);
                    });
                } else {
                    carouselInner.innerHTML = `
                        <div class="carousel-item active">
                            <img src="assets/images/no-image.png" 
                                 class="d-block w-100" 
                                 alt="No image"
                                 style="max-height: 70vh; object-fit: contain;">
                        </div>
                    `;
                }
            });
    });
});

// Auto-hide success messages after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>

<?php
require_once '../includes/footer.php';
?>