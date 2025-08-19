<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once  'translate.php'; 
if (!isAdmin()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard-staff.php');
    exit();
  }
checkAuth();

// Only admin can access item control


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        $invoice_no = sanitizeInput($_POST['invoice_no']);
        $date = sanitizeInput($_POST['date']);
        
        try {
            $pdo->beginTransaction();
            
            // Loop through each item
            foreach ($_POST['name'] as $index => $name) {
                $item_code = sanitizeInput($_POST['item_code'][$index]);
$category_id = !empty($_POST['category_id'][$index]) ? (int)$_POST['category_id'][$index] : null;
                $location_id = (int)$_POST['location_id'][$index];
                $name = sanitizeInput($name);
                $quantity = (float)$_POST['quantity'][$index];
                $alert_quantity = (int)$_POST['alert_quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index]);
                $remark = sanitizeInput($_POST['remark'][$index]);
                $dupli=t('duplicate_itm2');
                $stmt = $pdo->prepare("SELECT id FROM items WHERE name = ? AND location_id = ?");
            $stmt->execute([$name, $location_id]);
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateItemModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("$dupli");
            }
                // Insert the item into the database
                $stmt = $pdo->prepare("INSERT INTO items (item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$item_code, $category_id, $invoice_no, $date, $name, $quantity, $alert_quantity, $size, $location_id, $remark]);
                $item_id = $pdo->lastInsertId();
                   // Also insert into addnewitems table
            $stmt = $pdo->prepare("INSERT INTO addnewitems 
            (item_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, added_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
$item_id,
$invoice_no,
$date,
$name,
$quantity,
$alert_quantity,
$size,
$location_id,
$remark,
$_SESSION['user_id']
]);
// In the add_item section, after inserting the item:
$stmt = $pdo->prepare("INSERT INTO stock_in_history 
    (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?)");
$stmt->execute([$item_id, $item_code, $category_id, $invoice_no, $date, $name, $quantity, $alert_quantity, $size, $location_id, $remark, $quantity, $_SESSION['user_id']]);
         // Replace the file upload section with this:
         if (!empty($_FILES['images']['name'][$index][0])) {
            foreach ($_FILES['images']['tmp_name'][$index] as $key => $tmp_name) {
                if ($_FILES['images']['error'][$index][$key] === UPLOAD_ERR_OK) {
                    // Validate image type
                    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($fileInfo, $tmp_name);
                    finfo_close($fileInfo);
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/jpg'];
                    
                    if (!in_array($mime, $allowedMimes)) {
                        throw new Exception("Invalid file type.");
                    }
                    
                    // Read the file content
                    $imageData = file_get_contents($tmp_name);
                    
                    // Insert into database
                    $stmt = $pdo->prepare("INSERT INTO item_images (item_id, image_path) VALUES (?, ?)");
                    $stmt->execute([$item_id, $imageData]);
                }
            }
        }     
                // Log activity for this item
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $log_message = "Added New Item: $name ($quantity $size) to {$location['name']}";
                logActivity($_SESSION['user_id'], 'Create New Item', $log_message);
            }
            
            $pdo->commit();
            $dupli2=t('duplicate_itm2');
            $dupli3=t('item_succ1');
            $dupli4=t('item_succ2');
            $_SESSION['success'] = "$dupli3";
            redirect('stock-in.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['error'] = "$dupli2";
            } else {
                $_SESSION['error'] = "$dupli4";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }elseif (isset($_POST['add_qty'])) {
        // Add quantity to items
        $invoice_no = sanitizeInput($_POST['invoice_no']);
        $date = sanitizeInput($_POST['date']);
        $location_id = (int)$_POST['location_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Loop through each item
            foreach ($_POST['item_id'] as $index => $item_id) {
                $item_id = (int)$item_id;
                $quantity = (float)$_POST['quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index] ?? '');
                $remark = sanitizeInput($_POST['remark'][$index] ?? '');
                
                // Get current quantity
                $stmt = $pdo->prepare("SELECT quantity, name FROM items WHERE id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                $old_qty = $item['quantity'];
                $item_name = $item['name'];
                
                // Update quantity
                $new_qty = $old_qty + $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ?,invoice_no = ?, date = ?, remark=? WHERE id = ?");
                $stmt->execute([$new_qty,$invoice_no,$date,$remark, $item_id]);
                 // Insert into addqtyitems table
            $stmt = $pdo->prepare("INSERT INTO addqtyitems 
            (item_id, invoice_no, date, added_quantity, size, remark, added_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
$item_id,
$invoice_no,
$date,
$quantity,
$size,
$remark,
$_SESSION['user_id']
]);
// After updating the item quantity, add to history:
$stmt = $pdo->prepare("INSERT INTO stock_in_history 
    (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
    SELECT 
        id, item_code, category_id, ?, ?, name, quantity, alert_quantity, size, location_id, remark, 'add', ?, ?
    FROM items 
    WHERE id = ?");
$stmt->execute([$invoice_no, $date, $quantity, $_SESSION['user_id'], $item_id]);
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log each item update
                logActivity($_SESSION['user_id'], 'Stock In', "Increase stock: $item_name($quantity $size) at {$location['name']} (Total: $old_qty+$quantity=$new_qty)");
            }
            
            $pdo->commit();
            $add_qty1=t('add_qty1');
            $add_qty2=t('add_qty2');
            
            $_SESSION['success'] = "$add_qty1";
            redirect('stock-in.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "$add_qty2";
        }
    } 
   
    }



$year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query for stock history
$query = "SELECT 
    si.id,
    si.item_id,
    si.item_code, 
    si.category_id,
    c.name as category_name,
    si.invoice_no,
    si.date,
    si.name,
    i.quantity as current_quantity,
    si.quantity as history_quantity,
    si.action_quantity,
    si.action_type,
    si.alert_quantity,
    si.size,
    si.location_id,
    l.name as location_name,
    si.remark,
    si.action_by,
    si.action_at,
    (SELECT id FROM item_images WHERE item_id = si.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    stock_in_history si
LEFT JOIN 
    items i ON si.item_id = i.id
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
WHERE 1=1";

$params = [];

// Add filters
if ($year_filter !== null) {
    $query .= " AND YEAR(si.date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $query .= " AND MONTH(si.date) = :month";
    $params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND si.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($search_query) {
    $query .= " AND (si.name LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Order by action date (newest first)
$query .= " ORDER BY si.action_at DESC";

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Count total records
$count_query = "SELECT COUNT(*) as total FROM stock_in_history si
                LEFT JOIN items i ON si.item_id = i.id
                LEFT JOIN categories c ON si.category_id = c.id
                JOIN locations l ON si.location_id = l.id
                WHERE 1=1";

// Apply same filters to count query
if ($year_filter !== null) $count_query .= " AND YEAR(si.date) = :year";
if ($month_filter !== null) $count_query .= " AND MONTH(si.date) = :month";
if ($location_filter) $count_query .= " AND si.location_id = :location_id";
if ($search_query) $count_query .= " AND (si.name LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";

$stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
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
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for add/deduct quantity dropdowns
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size,remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>\

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('item_management');?></h2>
    
    <div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><?php echo t('item_list');?></h5>
    <div>
        <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-circle"></i> <?php echo t('add_new_item');?>
        </button>
        <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addQtyModal">
            <i class="bi bi-plus-lg"></i> <?php echo t('add_qty');?>
        </button>
  
    </div>
</div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-8">
                    <form method="GET" class="row g-2">
                    <div class="col-md-2">
                            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search');?>..." value="<?php echo $search_query; ?>">
</div>

                        <div class="col-md-2">
                            <select name="location" class="form-select">
                                <option value=""><?php echo t('report_all_location');?></option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                        <?php echo $location['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
    <select name="month" class="form-select">
        <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month');?></option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
            </option>
        <?php endfor; ?>
    </select>
</div>
<div class="col-md-2">
    <select name="year" class="form-select">
        <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_year');?></option>
        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                <?php echo $y; ?>
            </option>
        <?php endfor; ?>
    </select>
</div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                        <div class="col-md-2">
            <a href="stock-in.php" class="btn btn-danger w-100">Reset</a>
        </div>
                    </form>
                </div>
            </div>
            
            <div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('item_no');?></th>
                <th><?php echo t('item_code');?></th>
                <th><?php echo t('category');?></th>
                <th><?php echo t('item_invoice');?></th>
                <th><?php echo t('item_date');?></th>
                <th><?php echo t('item_name');?></th>
                <th>Current Qty</th>
                <th>History Qty</th>
                <th>Action</th>
                <th><?php echo t('item_size');?></th>
                <th><?php echo t('item_location');?></th>
                <th><?php echo t('item_remark');?></th>
                <th><?php echo t('item_photo');?></th>
                <th>Action By</th>
                <th>Action At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="15" class="text-center">No stock history found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td><?php echo $index + 1 + $offset; ?></td>
                        <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                        <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                        <td><?php echo $item['invoice_no']; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                        <td><?php echo $item['name']; ?></td>
                        <td><?php echo $item['current_quantity']; ?></td>
                        <td class="<?php echo $item['action_type'] === 'deduct' ? 'text-danger' : 'text-success'; ?>">
                            <?php echo ($item['action_type'] === 'deduct' ? '-' : '+') . $item['action_quantity']; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $item['action_type'] === 'new' ? 'primary' : ($item['action_type'] === 'add' ? 'success' : 'danger'); ?>">
                                <?php echo ucfirst($item['action_type']); ?>
                            </span>
                        </td>
                        <td><?php echo $item['size']; ?></td>
                        <td><?php echo $item['location_name']; ?></td>
                        <td><?php echo $item['remark']; ?></td>
                        <td>
                            <?php if ($item['image_id']): ?>
                                <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                     class="img-thumbnail" width="50"
                                     data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                     data-item-id="<?php echo $item['item_id']; ?>">
                            <?php else: ?>
                                <span class="badge bg-secondary">No image</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                            $stmt->execute([$item['action_by']]);
                            echo $stmt->fetchColumn() ?: 'System';
                            ?>
                        </td>
                        <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
            
<?php if ($total_pages > 0): ?>
<!-- Pagination -->
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
    <?php echo t('page');?> <?php echo $page; ?> <?php echo t('page_of');?> <?php echo $total_pages; ?> 
</div>
<?php endif; ?>
<!-- View Item Modal -->
<div class="modal fade" id="viewItemModal" tabindex="-1" aria-labelledby="viewItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewItemModalLabel"><?php echo t('view_item_detail');?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Table (Basic Info) -->
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%"><?php echo t('item_invoice');?></th>
                                <td id="view_invoice_no"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_date');?></th>
                                <td id="view_date"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_name');?></th>
                                <td id="view_name"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_qty');?></th>
                                <td id="view_quantity"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_size');?></th>
                                <td id="view_size"></td>
                            </tr>
                             <tr>
                                <th><?php echo t('item_remark');?></th>
                                <td id="view_remark"></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Right Table (Code/Category) -->
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%"><?php echo t('item_code');?></th>
                                <td id="view_item_code"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('category');?></th>
                                <td id="view_category"></td>
                            </tr>
                            <tr>
                                <th><?php echo t('item_location');?></th>
                                <td id="view_location"></td>
                            </tr>
                           
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><?php echo t('item_photo');?></h5>
                        <div class="row g-2" id="view_images">
                            <!-- Images will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
            </div>
        </div>
    </div>
</div>
<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addItemModalLabel"><?php echo t('add_new_item');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" name="invoice_no" id="main_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="items-container">
                        <!-- First item row -->
                        <div class="item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="location_id" class="form-label"><?php echo t('location_column');?></label>
                                    <select class="form-select" id="location_id" name="location_id[]" required>
                                        <option value=""><?php echo t('item_locations');?></option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_code');?></label>
                                    <input type="text" class="form-control" name="item_code[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('category');?></label>
                                    <select class="form-select" name="category_id[]" required>
                                        <option value=""><?php echo t('select_category');?></option>
                                        <?php 
                                        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
                                        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo t('item_name');?></label>
                                    <input type="text" class="form-control" name="name[]" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo t('item_qty');?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('low_stock_title');?></label>
                                    <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_size');?></label>
                                    <input type="text" class="form-control" name="size[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_remark');?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label"><?php echo t('item_photo');?></label>
                                    <input type="file" class="form-control" name="images[0][]" multiple accept="image/*">
                                    <div class="image-preview-container mt-2 row g-1" id="image-preview-0"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_item" class="btn btn-primary"><?php echo t('form_save');?></button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_item');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_item1');?></h4>
                <p><?php echo t('del_usr2');?></p>
                <div id="deleteItemInfo" class="alert alert-light mt-3"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Quantity Modal -->
<div class="modal fade" id="addQtyModal" tabindex="-1" aria-labelledby="addQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addQtyModalLabel"><?php echo t('add_qty');?> </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" name="invoice_no" id="add_invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="add_date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="add_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="add_location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="add_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations');?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="add_qty_items_container">
               <!-- First item row -->
<div class="add-qty-item-row mb-3 border p-3">
    <div class="row">
    <div class="col-md-8 mb-3">
                                        <label class="form-label"><?php echo t('item_name');?></label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item');?>
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>

        <div class="col-md-4 mb-3">
            <label class="form-label"><?php echo t('item_qty');?></label>
            <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label"><?php echo t('item_size');?></label>
            <input type="text" class="form-control" name="size[]" readonly>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label"><?php echo t('item_remark');?></label>
            <input type="text" class="form-control" name="remark[]">
        </div>
    </div>
</div>
                    </div>
                    
                    <button type="button" id="add-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_qty" class="btn btn-success"><?php echo t('add');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Quantity Exceed Modal -->
<div class="modal fade" id="quantityExceedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('qty_issue1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('qty_issue2');?></h4>
                <p id="quantityExceedMessage" style="text-align:left;"><?php echo t('qty_issue3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Deduct Quantity Modal -->
<div class="modal fade" id="deductQtyModal" tabindex="-1" aria-labelledby="deductQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deductQtyModalLabel"><?php echo t('deduct_qty');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="deduct_invoice_no" class="form-label"><?php echo t('item_invoice');?></label>
                            <input type="text" class="form-control" id="deduct_invoice_no" name="invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="deduct_date" class="form-label"><?php echo t('item_date');?></label>
                            <input type="date" class="form-control" id="deduct_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="deduct_location_id" class="form-label"><?php echo t('location_column');?></label>
                        <select class="form-select" id="deduct_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations');?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="deduct_qty_items_container">
                        <!-- First item row -->
                        <div class="deduct-qty-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                        <label class="form-label"><?php echo t('item_name');?></label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item');?>
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_qty');?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_size');?></label>
                                    <input type="text" class="form-control" name="size[]"readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark');?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="deduct-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row');?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="deduct_qty" class="btn btn-danger"><?php echo t('deduct');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Add this modal to your HTML (place it near the other modals) -->
<div class="modal fade" id="duplicateItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_itm1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_itm2');?></h4>
                <p><?php echo t('duplicate_itm3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Add this modal to your HTML -->
<div class="modal fade" id="selectLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('warning_location1');?></h4>
                <p><?php echo t('warn_loc');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Image Delete Confirmation Modal -->
<div class="modal fade" id="deleteImageConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_pic1');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_pic2');?></h4>
                <p><?php echo t('del_pic3');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <button type="button" id="confirmImageDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Image Replace Confirmation Modal -->
<div class="modal fade" id="replaceImageConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning');?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-images text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('rep_pic1');?></h4>
                <p><?php echo t('rep_pic2');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <button type="button" id="confirmReplaceBtn" class="btn btn-warning">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
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
                <h5 class="modal-title"><?php echo t('item_photo');?></h5>
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
const itemsByLocation = <?php echo json_encode($items_by_location); ?>;
// Function to populate item dropdown
// Function to populate item dropdown
function populateItemDropdown(dropdownElement, locationId) {
    const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
    const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
    const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
    const hiddenInput = dropdownElement.querySelector('.item-id-input');
    
    // Clear previous items
    itemContainer.innerHTML = '';
    
    if (!locationId) {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('warning_location1');?></div>';
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
                        
                        const row = dropdownElement.closest('.add-qty-item-row, .deduct-qty-item-row');
                        const sizeInput = row.querySelector('input[name="size[]"]');
                        const remarkInput = row.querySelector('input[name="remark[]"]');
                        
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (remarkInput) remarkInput.value = this.dataset.remark;
                        
                        const quantityInput = row.querySelector('input[name="quantity[]"]');
                        if (quantityInput) {
                            quantityInput.max = this.dataset.maxQuantity;
                            if (row.classList.contains('deduct-qty-item-row')) {
            quantityInput.max = this.dataset.maxQuantity;
        }
        // For add modal, you might not need a max, but you can set one if needed
        if (row.classList.contains('add-qty-item-row')) {
            // Optional: Set a reasonable max for adding quantity
            quantityInput.removeAttribute('max'); // Or set a high limit
        }
                        }
                    });
                    
                    itemContainer.appendChild(itemElement);
                    hasVisibleItems = true;
                }
            });
            
            if (!hasVisibleItems) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item');?></div>';
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
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item_location');?></div>';
    }
}
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

    // Store the image to be deleted
let pendingImageDelete = null;
let pendingImageElement = null;

// Handle image delete button click
document.addEventListener('click', function(e) {
    if (e.target.closest('.btn-danger') && e.target.closest('[data-image-id]')) {
        e.preventDefault();
        const deleteBtn = e.target.closest('[data-image-id]');
        pendingImageDelete = deleteBtn.getAttribute('data-image-id');
        pendingImageElement = deleteBtn.closest('.col-md-3');
        
        // Show the confirmation modal
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteImageConfirmModal'));
        deleteModal.show();
    }
});


    // Delete confirmation modal handler
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let deleteUrl = '';
    
    // Handle delete button clicks
    document.querySelectorAll('.delete-item').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const itemId = this.getAttribute('data-id');
            const itemName = this.getAttribute('data-name');
            const itemLocation = this.getAttribute('data-location');
            
            // Set the delete URL
            deleteUrl = `stock-in.php?delete=${itemId}`;
            
            // Update modal content
            document.getElementById('deleteItemInfo').innerHTML = `
                <strong><?php echo t('item_name');?>:</strong> ${itemName}<br>
                <strong><?php echo t('location_column');?>:</strong> ${itemLocation}
            `;
            
            // Show the modal
            deleteModal.show();
        });
    });
    
    // Handle confirm delete button click
    document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
        if (deleteUrl) {
            window.location.href = deleteUrl;
        }
    });
});
    // Function to handle image preview for a specific input
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
                        // Remove the preview
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

// Initialize preview for the first row
document.addEventListener('DOMContentLoaded', function() {
    // Setup for the first row's image input
    const firstImageInput = document.querySelector('input[name="images[0][]"]');
    if (firstImageInput) {
        setupImagePreview(firstImageInput, 'image-preview-0');
    } document.querySelectorAll('.search-item-input').forEach(input => {
        const select = input.nextElementSibling;
        if (select && (select.classList.contains('add-item-select') || select.classList.contains('deduct-item-select'))) {
            setupSearchableDropdownForRow(input, select);
        }
    });
});

// Update the add-more-row event listener
document.getElementById('add-more-row').addEventListener('click', function() {
    const container = document.getElementById('items-container');
    const rowCount = container.querySelectorAll('.item-row').length;
    const locationSelect = container.querySelector('[name="location_id[]"]');
    const locationId = locationSelect ? locationSelect.value : '';
    
    if (!locationId) {
        var locationModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('location_column');?></label>
                <select class="form-select" name="location_id[]" required>
                    <option value=""><?php echo t('item_locations');?></option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" ${locationId == <?php echo $location['id']; ?> ? 'selected' : ''}>
                            <?php echo $location['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_code');?></label>
                <input type="text" class="form-control" name="item_code[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('category');?></label>
                <select class="form-select" name="category_id[]">
                    <option value=""><?php echo t('select_category');?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <label class="form-label"><?php echo t('item_name');?></label>
                <input type="text" class="form-control" name="name[]" required>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('low_stock_title');?></label>
                <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 mb-3">
                <label class="form-label"><?php echo t('item_photo');?></label>
                <input type="file" class="form-control item-images-input" name="images[${rowCount}][]" multiple accept="image/*">
                <div class="image-preview-container mt-2 row g-1" id="image-preview-${rowCount}"></div>
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
        </button>
    `;
    
    container.appendChild(newRow);
    
    // Setup image preview for the new row
    const newImageInput = newRow.querySelector('.item-images-input');
    setupImagePreview(newImageInput, `image-preview-${rowCount}`);
    
    // Add event listener for the remove button
    newRow.querySelector('.remove-row').addEventListener('click', function() {
        newRow.remove();
    });
});
// Handle location change for add quantity modal
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#add_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});
// For the initial add quantity row
document.querySelectorAll('.add-item-select').forEach(select => {
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const row = this.closest('.add-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    });
});

// For the initial deduct quantity row
document.querySelectorAll('.deduct-item-select').forEach(select => {
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const row = this.closest('.deduct-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    });
});
// Function to update item select dropdown
function updateItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}
// Handle location change for deduct quantity modal
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#deduct_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

// Function to update deduct item select dropdown
function updateDeductItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}


// Setup search functionality for a specific row
function setupSearchableDropdownForRow(searchInput, selectElement) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const options = selectElement.querySelectorAll('option');
        
        options.forEach(option => {
            if (option.value === '') {
                option.style.display = ''; // Always show the placeholder
                return;
            }
            
            const searchText = option.getAttribute('data-search');
            if (searchText.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    });
}
document.querySelectorAll('.search-item-input').forEach(input => {
    const select = input.nextElementSibling; // The select element follows the input
    if (select && select.classList.contains('add-item-select')) {
        setupSearchableDropdownForRow(input, select);
    }
});
// Initialize search functionality for existing rows
document.querySelectorAll('.search-item-input').forEach(input => {
    const select = input.nextElementSibling; // The select element follows the input
    setupSearchableDropdownForRow(input, select);
});

// Initialize the first row's select when location changes
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const firstSelect = document.querySelector('.deduct-item-select');
    if (firstSelect) {
        updateDeductItemSelect(firstSelect, locationId);
    }
});
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const container = document.getElementById('add_qty_items_container');
    const itemSelects = container.querySelectorAll('.add-item-select');
    
    // Update all existing item selects
    itemSelects.forEach(select => {
        updateItemSelect(select, locationId);
    });
});


// Function to update item select dropdown
function updateItemSelect(selectElement, locationId) {
    selectElement.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                option.setAttribute('data-search', `${item.name} ${item.size || ''}`.toLowerCase());
                option.setAttribute('data-size', item.size || '');
                option.setAttribute('data-remark', item.remark || '');
                selectElement.appendChild(option);
            });
        }
    }
}

// Handle add more row for add quantity modal
document.getElementById('add-qty-more-row').addEventListener('click', function() {
    const container = document.getElementById('add_qty_items_container');
    const locationId = document.getElementById('add_location_id').value;

    const rowCount = container.querySelectorAll('.add-qty-item-row').length;
    
if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'add-qty-item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label"><?php echo t('item_name');?></label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php echo t('select_item');?>
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item');?>...">
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="dropdown-item-container">
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty');?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size');?></label>
                <input type="text" class="form-control" name="size[]"readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark');?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row');?>
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
});

// Add event listener for item selection in add quantity modal
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('add-item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('.add-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    }
    
    // Same for deduct quantity modal
    if (e.target.classList.contains('deduct-item-select')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('.deduct-qty-item-row');
        
        if (row && selectedOption.value) {
            const sizeInput = row.querySelector('input[name="size[]"]');
            const remarkInput = row.querySelector('input[name="remark[]"]');
            
            if (sizeInput) {
                sizeInput.value = selectedOption.getAttribute('data-size') || '';
            }
            
            if (remarkInput) {
                remarkInput.value = selectedOption.getAttribute('data-remark') || '';
            }
        }
    }
});
// Store these variables at the top of your script
let pendingFileInput = null;
let pendingPreviewContainer = null;



// Handle confirm button click
document.getElementById('confirmReplaceBtn').addEventListener('click', function() {
    if (pendingFileInput && pendingPreviewContainer) {
        // Clear all existing images
        pendingPreviewContainer.innerHTML = '';
        
        // Process the new images
        processNewImages(pendingFileInput, pendingPreviewContainer);
        
        // Hide the modal
        const replaceModal = bootstrap.Modal.getInstance(document.getElementById('replaceImageConfirmModal'));
        replaceModal.hide();
    }
});

function processNewImages(fileInput, previewContainer) {
    Array.from(fileInput.files).forEach((file, index) => {
        if (!file.type.match('image.*')) return;
        
        const reader = new FileReader();
        const col = document.createElement('div');
        col.className = 'col-md-3 mb-3 position-relative';
        
        reader.onload = function(event) {
            const img = document.createElement('img');
            img.src = event.target.result;
            img.className = 'img-thumbnail w-100';
            img.style.height = '150px';
            img.style.objectFit = 'cover';
            
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-1';
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            removeBtn.title = 'Remove this preview';
            removeBtn.dataset.index = index;
            
            removeBtn.addEventListener('click', function() {
                col.remove();
                
                // Update file input
                const dt = new DataTransfer();
                const { files } = fileInput;
                
                for (let i = 0; i < files.length; i++) {
                    if (i !== parseInt(this.dataset.index)) {
                        dt.items.add(files[i]);
                    }
                }
                
                fileInput.files = dt.files;
            });
            
            col.appendChild(img);
            col.appendChild(removeBtn);
            previewContainer.appendChild(col);
        };
        
        reader.readAsDataURL(file);
    });
}
// Handle location change for add quantity
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const itemSelect = document.getElementById('add_item_id');
    
    itemSelect.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                itemSelect.appendChild(option);
            });
        }
    }
});

// Handle location change for deduct quantity
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const itemSelect = document.getElementById('deduct_item_id');
    
    itemSelect.innerHTML = '<option value=""><?php echo t('select_item');?></option>';
    
    if (locationId) {
        const items = <?php echo json_encode($items_by_location); ?>;
        
        if (items[locationId]) {
            items[locationId].forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.name} (${item.quantity} ${item.size || ''})`.trim();
                itemSelect.appendChild(option);
            });
        }
    }
});
// In your JavaScript, update the image gallery code to use display_image.php
document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
    img.addEventListener('click', function() {
        const itemId = this.getAttribute('data-item-id');
        fetch(`get_item_history_images.php?id=${itemId}`)
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
// Handle view item button click
document.querySelectorAll('.view-item').forEach(button => {
    button.addEventListener('click', function() {
        const itemId = this.getAttribute('data-id');
        
        // Show loading state
        document.getElementById('view_images').innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
        
        // Fetch item details
        fetch(`get_item_history_details.php?id=${itemId}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                // Update basic info - access the 'item' property
                const item = data.item;
                document.getElementById('view_item_code').textContent = item.item_code || 'N/A';
                document.getElementById('view_category').textContent = item.category_name || 'N/A';
                document.getElementById('view_invoice_no').textContent = item.invoice_no || 'N/A';
                document.getElementById('view_date').textContent = item.date || 'N/A';
                document.getElementById('view_name').textContent = item.name || 'N/A';
                document.getElementById('view_quantity').textContent = item.quantity || '0';
                document.getElementById('view_size').textContent = item.size || 'N/A';
                document.getElementById('view_location').textContent = item.location_name || 'N/A';
                document.getElementById('view_remark').textContent = item.remark || 'N/A';
                
                // Update images - access the 'images' property
                const imagesContainer = document.getElementById('view_images');
                imagesContainer.innerHTML = '';
                
                if (data.images && data.images.length > 0) {
                    data.images.forEach(image => {
                        const imgUrl = `display_image.php?id=${image.id}`;
                        
                        const col = document.createElement('div');
                        col.className = 'col-md-3 mb-3';
                        
                        const img = document.createElement('img');
                        img.src = imgUrl;
                        img.className = 'img-thumbnail w-100';
                        img.style.height = '150px';
                        img.style.objectFit = 'cover';
                        img.alt = item.name;
                        
                        col.appendChild(img);
                        imagesContainer.appendChild(col);
                    });
                } else {
                    imagesContainer.innerHTML = '<div class="col-12 text-muted"><?php echo t('no_photo');?></div>';
                }
                
                // Show modal
                const viewModal = new bootstrap.Modal(document.getElementById('viewItemModal'));
                viewModal.show();
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('view_images').innerHTML = '<div class="col-12 text-danger">Error loading item details</div>';
            });
    });
});
// Search functionality for item dropdowns
function setupSearchableDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control mb-2';
    searchInput.placeholder = '<?php echo t('search');?>...';
    
    dropdown.parentNode.insertBefore(searchInput, dropdown);
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const options = dropdown.getElementsByTagName('option');
        
        for (let i = 0; i < options.length; i++) {
            const option = options[i];
            const text = option.textContent.toLowerCase();
            
            if (text.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        }
    });
}
// Function to set today's date in a date input
function setTodayDate(inputId) {
    const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    document.getElementById(inputId).value = formattedDate;
}
// Add this event listener for the deduct quantity inputs
document.addEventListener('input', function(e) {
    if (e.target.matches('#deduct_qty_items_container input[name="quantity[]"]')) {
        const input = e.target;
        const max = parseFloat(input.max);
        const value = parseFloat(input.value);
        
        if (!isNaN(max) && !isNaN(value) && value > max) {
            const modal = new bootstrap.Modal(document.getElementById('quantityExceedModal'));
                    const message = document.getElementById('quantityExceedMessage');
                    
                    // Update the message with specific quantities
                    message.innerHTML = `
                        <?php echo t('qty_issue4');?> <strong>${max}</strong> ( <?php echo t('qty_issue5');?>)<br>
                         <?php echo t('qty_issue6');?>: <strong>${value}</strong>
                    `;
                    
                    modal.show();
        }
    }
});
// Set dates when modals are shown
document.getElementById('addItemModal').addEventListener('shown.bs.modal', function() {
    setTodayDate('date');
});

document.getElementById('addQtyModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('#add_qty_items_container .item-dropdown');
    const locationId = document.getElementById('add_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('add_date').valueAsDate = new Date();
});

document.getElementById('deductQtyModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('#deduct_qty_items_container .item-dropdown');
    const locationId = document.getElementById('deduct_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('deduct_date').valueAsDate = new Date();
});

// Initialize searchable dropdowns
setupSearchableDropdown('add_item_id');
setupSearchableDropdown('deduct_item_id');
</script>

<?php
require_once '../includes/footer.php';
?>