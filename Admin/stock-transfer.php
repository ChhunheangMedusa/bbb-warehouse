<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once 'translate.php';

if (!isAdmin()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard-staff.php');
    exit();
}
checkAuth();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_items'])) {
    $invoice_no = sanitizeInput($_POST['invoice_no']);
    $date = sanitizeInput($_POST['date']);
    $from_location_id = (int)$_POST['from_location_id'];
    $to_location_id = (int)$_POST['to_location_id'];
    
    // Validate locations are different
    if ($from_location_id === $to_location_id) {
        $_SESSION['error'] = t('cannot_transfer_to_same_location');
        redirect('stock-transfer.php');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Loop through each item
        foreach ($_POST['item_id'] as $index => $item_id) {
            $item_id = (int)$item_id;
            $quantity = (float)$_POST['quantity'][$index];
            $size = sanitizeInput($_POST['size'][$index] ?? '');
            $remark = sanitizeInput($_POST['remark'][$index] ?? '');
            
            // Get current item details
            $stmt = $pdo->prepare("SELECT quantity, name, item_code, category_id, size FROM items WHERE id = ? AND location_id = ?");
            $stmt->execute([$item_id, $from_location_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                throw new Exception(t('item_not_found_in_location'));
            }
            
            $old_qty = $item['quantity'];
            $item_name = $item['name'];
            
            if ($quantity > $old_qty) {
                throw new Exception(t('cannot_transfer_more_than_available') . ": $item_name");
            }
            
            // Update quantity in source location
            $new_qty = $old_qty - $quantity;
            $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
            $stmt->execute([$new_qty, $item_id]);
            
            // Check if item exists in destination location
            $stmt = $pdo->prepare("SELECT id, quantity FROM items WHERE name = ? AND location_id = ?");
            $stmt->execute([$item_name, $to_location_id]);
            $dest_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dest_item) {
                // Update quantity in destination
                $dest_new_qty = $dest_item['quantity'] + $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
                $stmt->execute([$dest_new_qty, $dest_item['id']]);
            } else {
                // Insert new item in destination
                $stmt = $pdo->prepare("INSERT INTO items 
                    (item_code, category_id, name, quantity, size, location_id, remark, alert_quantity) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 10)");
                $stmt->execute([
                    $item['item_code'],
                    $item['category_id'],
                    $item_name,
                    $quantity,
                    $item['size'],
                    $to_location_id,
                    $remark
                ]);
            }
            
            // Record in transfer history
            $stmt = $pdo->prepare("INSERT INTO transfer_history 
                (item_id, item_code, category_id, invoice_no, date, name, quantity, size, from_location_id, to_location_id, remark, action_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $item_id,
                $item['item_code'],
                $item['category_id'],
                $invoice_no,
                $date,
                $item_name,
                $quantity,
                $item['size'],
                $from_location_id,
                $to_location_id,
                $remark,
                $_SESSION['user_id']
            ]);
            
            // Log activity
            $stmt = $pdo->prepare("SELECT name FROM locations WHERE id IN (?, ?)");
            $stmt->execute([$from_location_id, $to_location_id]);
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $log_message = "Transferred $item_name ($quantity $size) from {$locations[0]['name']} to {$locations[1]['name']}";
            logActivity($_SESSION['user_id'], 'Transfer', $log_message);
        }
        
        $pdo->commit();
        $_SESSION['success'] = t('transfer_success');
        redirect('stock-transfer.php');
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = t('transfer_error') . ": " . $e->getMessage();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
}

// Get all locations
$stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for dropdowns
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size, remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get transfer history with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT 
    t.id,
    t.item_id,
    t.item_code, 
    t.category_id,
    c.name as category_name,
    t.invoice_no,
    t.date,
    t.name,
    t.quantity,
    t.size,
    t.from_location_id,
    fl.name as from_location_name,
    t.to_location_id,
    tl.name as to_location_name,
    t.remark,
    t.action_by,
    u.username as action_by_name,
    t.action_at,
    (SELECT id FROM item_images WHERE item_id = t.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    transfer_history t
LEFT JOIN 
    categories c ON t.category_id = c.id
JOIN 
    locations fl ON t.from_location_id = fl.id
JOIN 
    locations tl ON t.to_location_id = tl.id
JOIN
    users u ON t.action_by = u.id
ORDER BY t.action_at DESC
LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transfer_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM transfer_history";
$stmt = $pdo->query($count_query);
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('stock_transfer'); ?></title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Your existing styles from items.php */
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

        body {
            font-family: var(--font-family);
            background-color: var(--light);
            color: var(--dark);
        }

        .card {
            border: none;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        }

        .card-header {
            background-color: var(--white);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.35rem;
            font-weight: 600;
        }

        .table th {
            background-color: var(--light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        .img-thumbnail {
            padding: 0.25rem;
            background-color: var(--white);
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
            max-width: 100%;
            height: auto;
        }

        /* Transfer specific styles */
        .transfer-item-row {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f8f9fa;
        }

        @media (max-width: 768px) {
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h2 class="mb-4"><?php echo t('stock_transfer'); ?></h2>
        
        <div class="card mb-4">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?php echo t('transfer_items'); ?></h5>
                <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#transferItemModal">
    <i class="bi bi-arrow-left-right"></i> <?php echo t('new_transfer'); ?>
</button>
                
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th><?php echo t('no'); ?></th>
                                <th><?php echo t('item_code'); ?></th>
                                <th><?php echo t('category'); ?></th>
                                <th><?php echo t('invoice_no'); ?></th>
                                <th><?php echo t('date'); ?></th>
                                <th><?php echo t('item_name'); ?></th>
                                <th><?php echo t('quantity'); ?></th>
                                <th><?php echo t('unit'); ?></th>
                                <th><?php echo t('from_location'); ?></th>
                                <th><?php echo t('to_location'); ?></th>
                                <th><?php echo t('remark'); ?></th>
                                <th><?php echo t('photo'); ?></th>
                                <th><?php echo t('action_by'); ?></th>
                                <th><?php echo t('action_at'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transfer_history)): ?>
                                <tr>
                                    <td colspan="14" class="text-center"><?php echo t('no_transfer_history'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transfer_history as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1 + $offset; ?></td>
                                        <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['invoice_no']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                        <td><?php echo $item['name']; ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo $item['size']; ?></td>
                                        <td><?php echo $item['from_location_name']; ?></td>
                                        <td><?php echo $item['to_location_name']; ?></td>
                                        <td><?php echo $item['remark']; ?></td>
                                        <td>
                                            <?php if ($item['image_id']): ?>
                                                <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                                     class="img-thumbnail" width="50"
                                                     data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                                     data-item-id="<?php echo $item['item_id']; ?>">
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $item['action_by_name']; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1" aria-label="First">
                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
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
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor;
                            
                            if ($end_page < $total_pages) {
                                echo '<li class="page-item"><span class="page-link">...</span></li>';
                            }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?>" aria-label="Last">
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
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<!-- Transfer Item Modal -->
<div class="modal fade" id="transferItemModal" tabindex="-1" aria-labelledby="transferItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="transferItemModalLabel"><?php echo t('transfer_items'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="transfer_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="transfer_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="transfer_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- From Location selection -->
                    <div class="mb-3">
                        <label for="from_location_id" class="form-label"><?php echo t('from_location'); ?></label>
                        <select class="form-select" id="from_location_id" name="from_location_id" required>
                            <option value=""><?php echo t('select_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- To Location selection -->
                    <div class="mb-3">
                        <label for="to_location_id" class="form-label"><?php echo t('to_location'); ?></label>
                        <select class="form-select" id="to_location_id" name="to_location_id" required>
                            <option value=""><?php echo t('select_location'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Items container -->
                    <div id="transfer_items_container">
                        <!-- First item row -->
                        <div class="transfer-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label"><?php echo t('item_name'); ?></label>
                                    <div class="dropdown item-dropdown">
                                        <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item'); ?>
                                        </button>
                                        <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                        <ul class="dropdown-menu custom-dropdown-menu p-2">
                                            <li>
                                                <div class="px-2 mb-2">
                                                    <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item'); ?>...">
                                                </div>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <div class="dropdown-item-container">
                                                <div class="px-2 py-1 text-muted"><?php echo t('select_from_location_first'); ?></div>
                                            </div>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('quantity'); ?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('unit'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="transfer-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="transfer_items" class="btn btn-info"><?php echo t('transfer'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Image Gallery Modal -->
    <div class="modal fade" id="imageGalleryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo t('item_photo'); ?></h5>
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
<!-- Select Location Modal -->
<div class="modal fade" id="selectLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3"><?php echo t('warning_location1'); ?></h4>
                <p><?php echo t('warn_loc'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
    <!-- Quantity Exceed Modal -->
    <div class="modal fade" id="quantityExceedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('warning'); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <h4 class="text-danger mb-3"><?php echo t('quantity_exceeded'); ?></h4>
                    <p id="quantityExceedMessage" style="text-align:left;"></p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                        <i class="bi bi-check-circle"></i> <?php echo t('understand'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
    <script>

    // Store items by location data
    const itemsByLocation = <?php echo json_encode($items_by_location); ?>;

    // Function to populate item dropdown
    function populateItemDropdown(dropdownElement, locationId) {
        const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
        const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
        const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
        const hiddenInput = dropdownElement.querySelector('.item-id-input');
        
        // Clear previous items
        itemContainer.innerHTML = '';
        
        if (!locationId) {
            itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('select_from_location_first'); ?></div>';
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
                            
                            const row = dropdownElement.closest('.transfer-item-row');
                            const sizeInput = row.querySelector('input[name="size[]"]');
                            const remarkInput = row.querySelector('input[name="remark[]"]');
                            
                            if (sizeInput) sizeInput.value = this.dataset.size;
                            if (remarkInput) remarkInput.value = this.dataset.remark;
                            
                            const quantityInput = row.querySelector('input[name="quantity[]"]');
                            if (quantityInput) {
                                quantityInput.setAttribute('max', this.dataset.maxQuantity);
                            }
                        });
                        
                        itemContainer.appendChild(itemElement);
                        hasVisibleItems = true;
                    }
                });
                
                if (!hasVisibleItems) {
                    itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item'); ?></div>';
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
            itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_item_location'); ?></div>';
        }
    }

    // Handle from location change for transfer modal
    document.getElementById('from_location_id').addEventListener('change', function() {
        const locationId = this.value;
        const dropdowns = document.querySelectorAll('#transfer_items_container .item-dropdown');
        
        dropdowns.forEach(dropdown => {
            populateItemDropdown(dropdown, locationId);
        });
    });

    // Handle add more row for transfer modal
    document.getElementById('transfer-more-row').addEventListener('click', function() {
    const container = document.getElementById('transfer_items_container');
    const locationId = document.getElementById('from_location_id').value;
    const rowCount = container.querySelectorAll('.transfer-item-row').length;

    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'transfer-item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label"><?php echo t('item_name'); ?></label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php echo t('select_item'); ?>
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search_item'); ?>...">
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="dropdown-item-container">
                            <div class="px-2 py-1 text-muted"><?php echo t('select_from_location_first'); ?></div>
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('quantity'); ?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('unit'); ?></label>
                <input type="text" class="form-control" name="size[]" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('remark'); ?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row'); ?>
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

    // Add validation for quantity input
    const newQuantityInput = newRow.querySelector('input[name="quantity[]"]');
    if (newQuantityInput) {
        newQuantityInput.addEventListener('input', function() {
            const itemIdInput = newRow.querySelector('.item-id-input');
            if (itemIdInput && itemIdInput.value) {
                const selectedItem = itemsByLocation[locationId].find(item => item.id == itemIdInput.value);
                if (selectedItem) {
                    const max = parseFloat(selectedItem.quantity);
                    const value = parseFloat(this.value);
                    
                    if (!isNaN(max) && !isNaN(value) && value > max) {
                        this.value = max;
                        const modal = new bootstrap.Modal(document.getElementById('quantityExceedModal'));
                        const message = document.getElementById('quantityExceedMessage');
                        
                        message.innerHTML = `
                            <?php echo t('cannot_transfer_more_than_available'); ?>: <strong>${max}</strong><br>
                            <?php echo t('attempted_to_transfer'); ?>: <strong>${value}</strong>
                        `;
                        
                        modal.show();
                    }
                }
            }
        });
    }
});

    // Set up transfer modal when shown
    document.getElementById('transferItemModal').addEventListener('shown.bs.modal', function() {
        // Set today's date
        document.getElementById('transfer_date').valueAsDate = new Date();
    });

    // Image gallery functionality
    document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
        img.addEventListener('click', function() {
            const itemId = this.getAttribute('data-item-id');
            fetch(`get_item_images.php?id=${itemId}`)
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
                                <img src="../assets/images/no-image.png" 
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
                
                // Remove the element after fade out
                setTimeout(() => {
                    message.remove();
                }, 500);
            }, 5000); // 5000 milliseconds = 5 seconds
        });
    });
    </script>
</body>
</html>