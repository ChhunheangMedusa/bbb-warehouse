<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header-staff.php';
require_once 'translate.php';

if (!isStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard.php');
    exit();
  }
checkAuth();
// Get active tab from URL
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'in';

// Get filter parameters - separate for each tab
if ($active_tab === 'in') {
    $name_filter = isset($_GET['name']) ? sanitizeInput($_GET['name']) : '';
    $category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
    $action_filter = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
    $location_filter = isset($_GET['location']) ? sanitizeInput($_GET['location']) : '';
    $month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
    $year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
    $search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';
} else {
    // For out tab, use different parameter names or session to store separate values
    $name_filter = isset($_GET['out_name']) ? sanitizeInput($_GET['out_name']) : '';
    $category_filter = isset($_GET['out_category']) ? (int)$_GET['out_category'] : null;
    $action_filter = isset($_GET['out_action']) ? sanitizeInput($_GET['out_action']) : '';
    $location_filter = isset($_GET['out_location']) ? sanitizeInput($_GET['out_location']) : '';
    $month_filter = isset($_GET['out_month']) ? sanitizeInput($_GET['out_month']) : '';
    $year_filter = isset($_GET['out_year']) ? sanitizeInput($_GET['out_year']) : '';
    $search_query = isset($_GET['out_search']) ? sanitizeInput($_GET['out_search']) : '';
    $sort_option = isset($_GET['out_sort_option']) ? sanitizeInput($_GET['out_sort_option']) : 'date_desc';
}

// Update the sort mapping to include category sorting
$sort_mapping = [
    'name_asc' => ['field' => 'si.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'si.name', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'si.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'si.date', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'action_asc' => ['field' => 'si.action_type', 'direction' => 'ASC'],
    'action_desc' => ['field' => 'si.action_type', 'direction' => 'DESC'],
    'action_by_asc' => ['field' => 'u.username', 'direction' => 'ASC'],
    'action_by_desc' => ['field' => 'u.username', 'direction' => 'DESC']
];

// Default to date_desc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];
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
                $price = (float)$_POST['price'][$index]; // ADD THIS LINE
                
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
                
                // Also insert into store_items table - FIXED
                $stmt = $pdo->prepare("INSERT INTO store_items (item_id, item_code, category_id, invoice_no, date, name, quantity, price, alert_quantity, size, location_id, remark) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$item_id, $item_code, $category_id, $invoice_no, $date, $name, $quantity, $price, $alert_quantity, $size, $location_id, $remark]);
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
            redirect('items.php');
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
    } elseif (isset($_POST['add_qty'])) {
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
                $price = (float)$_POST['price'][$index]; // ADD THIS LINE
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
                
                // FIXED: Insert into store_items with price
                $stmt = $pdo->prepare("INSERT INTO store_items (item_id, item_code, category_id, invoice_no, date, name, quantity, price, alert_quantity, size, location_id, remark) 
                SELECT id, item_code, category_id, ?, ?, name, ?, ?, alert_quantity, size, location_id, remark 
                FROM items WHERE id = ?");
                $stmt->execute([$invoice_no, $date, $quantity, $price, $item_id]);
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
            redirect('items.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "$add_qty2";
        }
    } elseif (isset($_POST['deduct_qty'])) {
        // Deduct quantity from items
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
                $deduct_qty1=t('deduct_qty1');
                
                if ($quantity > $old_qty) {
                    throw new Exception("$deduct_qty1: $item_name");
                }
                
                // Update quantity
                $new_qty = $old_qty - $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ?, invoice_no = ?, date = ?, remark = ? WHERE id = ?");
                $stmt->execute([$new_qty, $invoice_no, $date, $remark, $item_id]);
                
                // Insert into deductqtyitems table
                $stmt = $pdo->prepare("INSERT INTO deductqtyitems 
                    (item_id, invoice_no, date, deducted_quantity, size, remark, deducted_by) 
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
                
                // Insert into stock_out_history
                $stmt = $pdo->prepare("INSERT INTO stock_out_history 
                    (item_id, item_code, category_id, invoice_no, date, name, quantity, alert_quantity, size, location_id, remark, action_type, action_quantity, action_by)
                    SELECT 
                        id, item_code, category_id, ?, ?, name, quantity, alert_quantity, size, location_id, remark, 'deduct', ?, ?
                    FROM items 
                    WHERE id = ?");
                $stmt->execute([$invoice_no, $date, $quantity, $_SESSION['user_id'], $item_id]);
                
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log each item update
                logActivity($_SESSION['user_id'], 'Stock Out', "Decrease stock: $item_name ($quantity $size) at {$location['name']} (Total: $old_qty-$quantity=$new_qty)");
            }
            
            $pdo->commit();
            $deduct_qty2=t('deduct_qty2');
            $deduct_qty3=t('deduct_qty3');
            $_SESSION['success'] = "$deduct_qty2";
            redirect('items.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "$deduct_qty3";
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
   
    }
    
// Add entries per page options
$in_limit_options = [10, 25, 50, 100];
$in_per_page = isset($_GET['in_per_page']) ? (int)$_GET['in_per_page'] : 10;
if (!in_array($in_per_page, $in_limit_options)) {
    $in_per_page = 10;
}

$out_limit_options = [10, 25, 50, 100];
$out_per_page = isset($_GET['out_per_page']) ? (int)$_GET['out_per_page'] : 10;
if (!in_array($out_per_page, $out_limit_options)) {
    $out_per_page = 10;
}

// Get pagination parameters for stock in history
$in_page = isset($_GET['in_page']) ? (int)$_GET['in_page'] : 1;
$in_limit = $in_per_page;
$in_offset = ($in_page - 1) * $in_limit;
// Get filters for stock in
$in_year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$in_month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$in_location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$in_category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$in_action_filter = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$in_search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$in_sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Get filters for stock out
$out_year_filter = isset($_GET['out_year']) && $_GET['out_year'] != 0 ? (int)$_GET['out_year'] : null;
$out_month_filter = isset($_GET['out_month']) && $_GET['out_month'] != 0 ? (int)$_GET['out_month'] : null;
$out_location_filter = isset($_GET['out_location']) ? (int)$_GET['out_location'] : null;
$out_category_filter = isset($_GET['out_category']) ? (int)$_GET['out_category'] : null;
$out_action_filter = isset($_GET['out_action']) ? sanitizeInput($_GET['out_action']) : '';
$out_search_query = isset($_GET['out_search']) ? sanitizeInput($_GET['out_search']) : '';
$out_sort_option = isset($_GET['out_sort_option']) ? sanitizeInput($_GET['out_sort_option']) : 'date_desc';

// Build query for stock in history
$in_query = "SELECT 
    si.id,
    si.item_id,
    si.item_code, 
    si.category_id,
    c.name as category_name,
    si.invoice_no,
    si.date,
    si.name,
    si.quantity as history_quantity,
    si.action_quantity,
    si.action_type,
    si.size,
    si.location_id,
    l.name as location_name,
    si.remark,
    si.action_by,
    u.username as action_by_name,
    si.action_at,
    (SELECT id FROM item_images WHERE item_id = si.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    stock_in_history si
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
JOIN
    users u ON si.action_by = u.id
WHERE 1=1";

// Add this condition to exclude 'broken' records:
$in_query .= " AND si.action_type != 'broken'";

$in_params = [];

// Add filters for stock in
if ($in_year_filter !== null) {
    $in_query .= " AND YEAR(si.date) = :year";
    $in_params[':year'] = $in_year_filter;
}

if ($in_month_filter !== null) {
    $in_query .= " AND MONTH(si.date) = :month";
    $in_params[':month'] = $in_month_filter;
}

if ($in_location_filter) {
    $in_query .= " AND si.location_id = :location_id";
    $in_params[':location_id'] = $in_location_filter;
}

if ($in_category_filter) {
    $in_query .= " AND si.category_id = :category_id";
    $in_params[':category_id'] = $in_category_filter;
}

if ($in_action_filter) {
    $in_query .= " AND si.action_type = :action_type";
    $in_params[':action_type'] = $in_action_filter;
}

if ($in_search_query) {
    $in_query .= " AND (si.name LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";
    $in_params[':search'] = "%$in_search_query%";
}

// Order by
$in_query .= " ORDER BY $sort_by $sort_order";

// Get total count for in history pagination
$in_count_query = "SELECT COUNT(*) as total FROM stock_in_history si
                LEFT JOIN categories c ON si.category_id = c.id
                JOIN locations l ON si.location_id = l.id
                WHERE 1=1";
// Add this condition to the count query too
$in_count_query .= " AND si.action_type != 'broken'";
$in_count_params = [];

if ($in_year_filter !== null) {
    $in_count_query .= " AND YEAR(si.date) = :year";
    $in_count_params[':year'] = $in_year_filter;
}

if ($in_month_filter !== null) {
    $in_count_query .= " AND MONTH(si.date) = :month";
    $in_count_params[':month'] = $in_month_filter;
}

if ($in_location_filter) {
    $in_count_query .= " AND si.location_id = :location_id";
    $in_count_params[':location_id'] = $in_location_filter;
}

if ($in_category_filter) {
    $in_count_query .= " AND si.category_id = :category_id";
    $in_count_params[':category_id'] = $in_category_filter;
}

if ($in_action_filter) {
    $in_count_query .= " AND si.action_type = :action_type";
    $in_count_params[':action_type'] = $in_action_filter;
}

if ($in_search_query) {
    $in_count_query .= " AND (si.name LIKE :search OR si.invoice_no LIKE :search OR si.remark LIKE :search)";
    $in_count_params[':search'] = "%$in_search_query%";
}

$in_stmt = $pdo->prepare($in_count_query);
foreach ($in_count_params as $key => $value) {
    $in_stmt->bindValue($key, $value);
}
$in_stmt->execute();
$total_in_items = $in_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_in_pages = ceil($total_in_items / $in_limit);

// Get paginated results for in history
$in_query .= " LIMIT :limit OFFSET :offset";
$in_stmt = $pdo->prepare($in_query);
foreach ($in_params as $key => $value) {
    $in_stmt->bindValue($key, $value);
}
$in_stmt->bindValue(':limit', $in_limit, PDO::PARAM_INT);
$in_stmt->bindValue(':offset', $in_offset, PDO::PARAM_INT);
$in_stmt->execute();
$in_history = $in_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pagination parameters for stock out history
$out_page = isset($_GET['out_page']) ? (int)$_GET['out_page'] : 1;
$out_limit = $out_per_page;
$out_offset = ($out_page - 1) * $out_limit;

// Build query for stock out history
$out_query = "SELECT 
    so.id,
    so.item_id,
    so.item_code, 
    so.category_id,
    c.name as category_name,
    so.invoice_no,
    so.date,
    so.name,
    so.quantity as history_quantity,
    so.action_quantity,
    so.action_type,
    so.size,
    so.location_id,
    l.name as location_name,
    so.remark,
    so.action_by,
    u.username as action_by_name,
    so.action_at,
    (SELECT id FROM item_images WHERE item_id = so.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    stock_out_history so
LEFT JOIN 
    categories c ON so.category_id = c.id
JOIN 
    locations l ON so.location_id = l.id
JOIN
    users u ON so.action_by = u.id
WHERE 1=1";

$out_params = [];

// Add filters for stock out
// Add filters for stock out
if ($out_year_filter !== null) {
    $out_query .= " AND YEAR(so.date) = :year";
    $out_params[':year'] = $out_year_filter;
}

if ($out_month_filter !== null) {
    $out_query .= " AND MONTH(so.date) = :month";
    $out_params[':month'] = $out_month_filter;
}

if ($out_location_filter) {
    $out_query .= " AND so.location_id = :location_id";
    $out_params[':location_id'] = $out_location_filter;
}

if ($out_category_filter) {
    $out_query .= " AND so.category_id = :category_id";
    $out_params[':category_id'] = $out_category_filter;
}

if ($out_action_filter) {
    $out_query .= " AND so.action_type = :action_type";
    $out_params[':action_type'] = $out_action_filter;
}

if ($out_search_query) {
    $out_query .= " AND (so.name LIKE :search OR so.invoice_no LIKE :search OR so.remark LIKE :search)";
    $out_params[':search'] = "%$out_search_query%";
}
$out_sort_mapping = [
    'name_asc' => ['field' => 'so.name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'so.name', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'l.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'l.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'so.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'so.date', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'action_asc' => ['field' => 'so.action_type', 'direction' => 'ASC'],
    'action_desc' => ['field' => 'so.action_type', 'direction' => 'DESC'],
    'action_by_asc' => ['field' => 'u.username', 'direction' => 'ASC'],
    'action_by_desc' => ['field' => 'u.username', 'direction' => 'DESC']
];
if (!array_key_exists($out_sort_option, $out_sort_mapping)) {
    $out_sort_option = 'date_desc';
}

$out_sort_by = $out_sort_mapping[$out_sort_option]['field'];
$out_sort_order = $out_sort_mapping[$out_sort_option]['direction'];
// Order by action date (newest first)
$out_query .= " ORDER BY $out_sort_by $out_sort_order";

// Get total count for out history pagination
$out_count_query = "SELECT COUNT(*) as total FROM stock_out_history so
                LEFT JOIN categories c ON so.category_id = c.id
                JOIN locations l ON so.location_id = l.id
                WHERE 1=1";

$out_count_params = [];

if ($out_year_filter !== null) {
    $out_count_query .= " AND YEAR(so.date) = :year";
    $out_count_params[':year'] = $out_year_filter;
}

if ($out_month_filter !== null) {
    $out_count_query .= " AND MONTH(so.date) = :month";
    $out_count_params[':month'] = $out_month_filter;
}

if ($out_location_filter) {
    $out_count_query .= " AND so.location_id = :location_id";
    $out_count_params[':location_id'] = $out_location_filter;
}

if ($out_category_filter) {
    $out_count_query .= " AND so.category_id = :category_id";
    $out_count_params[':category_id'] = $out_category_filter;
}

if ($out_action_filter) {
    $out_count_query .= " AND so.action_type = :action_type";
    $out_count_params[':action_type'] = $out_action_filter;
}

if ($out_search_query) {
    $out_count_query .= " AND (so.name LIKE :search OR so.invoice_no LIKE :search OR so.remark LIKE :search)";
    $out_count_params[':search'] = "%$out_search_query%";
}

$out_stmt = $pdo->prepare($out_count_query);
foreach ($out_count_params as $key => $value) {
    $out_stmt->bindValue($key, $value);
}
$out_stmt->execute();
$total_out_items = $out_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_out_pages = ceil($total_out_items / $out_limit);

// Get paginated results for out history
$out_query .= " LIMIT :limit OFFSET :offset";
$out_stmt = $pdo->prepare($out_query);
foreach ($out_params as $key => $value) {
    $out_stmt->bindValue($key, $value);
}
$out_stmt->bindValue(':limit', $out_limit, PDO::PARAM_INT);
$out_stmt->bindValue(':offset', $out_offset, PDO::PARAM_INT);
$out_stmt->execute();
$out_history = $out_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$stmt = $pdo->query("SELECT * FROM locations WHERE type !='repair' ORDER BY name");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for add/deduct quantity dropdowns
$items_by_location = [];
if ($locations) {
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("SELECT id, name, quantity, size, remark FROM items WHERE location_id = ? ORDER BY name");
        $stmt->execute([$location['id']]);
        $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
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
.form-label{
    margin-top:10px;
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
@media (max-width: 768px) {
    table th {
        padding: 0.5rem 0.3rem;
        font-size: 0.85rem;
    }
}



/* Make all table cells single line by default */
.table td {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}

/* Specifically allow the action column to expand */
.table td:last-child {
    overflow: visible;
    white-space: normal;
    text-overflow: clip;
    max-width: none;
    min-width: 150px; /* Ensure enough space for buttons */
}
table th{
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0.75rem 0.5rem;
    line-height: 1.2;
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
/* Duplicate Item Modal Styles */
#duplicateItemModal .modal-content {
    border: 2px solid #dc3545;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(220, 53, 69, 0.3);
}

#duplicateItemModal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

#duplicateItemModal .modal-footer {
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

#duplicateItemModal .btn-danger {
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
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
/* Select Location Modal Styles */
#selectLocationModal .modal-content {
    border: 2px solid #ffc107;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(255, 193, 7, 0.3);
}

#selectLocationModal .modal-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

#selectLocationModal .modal-footer {
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

#selectLocationModal .btn-warning {
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
    color: #212529;
}
.form-control-file:hover::before {
  background: #e9ecef;
}
/* Image Preview Styles */
.image-preview-container {
    min-height: 150px;
}

.image-preview-wrapper {
    position: relative;
    width: 150px;
    height: 150px;
    margin-right: 5px;
    margin-bottom: 5px;
    display: inline-block;
}

.image-preview {
   
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
}

.remove-preview {
    position: absolute;
    top: 0;
    right: 0;
    background: rgba(255,0,0,0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
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
    min-width: 120px;
    padding: 8px 20px;
    font-weight: 600;
}

#deleteItemInfo {
    text-align: left;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    padding: 1rem;
}
@media (max-width: 768px) {
    .modal-dialog {
        margin: 0.5rem;
        width: auto;
    }
}
@media (max-width: 768px) {
    .form-row > .col, 
    .form-row > [class*="col-"] {
        padding-right: 0;
        padding-left: 0;
        margin-bottom: 10px;
    }
    
    .form-control, .form-select {
        width: 100%;
    }
}
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        z-index: 1000;
        width: 250px;
        height: 100vh;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    #sidebarToggle {
        display: block !important;
    }
}
/* Mobile-specific styles */
@media (max-width: 768px) {
    /* Make buttons full width */
    .btn {
        width: 100%;
        margin-bottom: 5px;
    }
    
    /* Adjust card padding */
    .card-body {
        padding: 1rem;
    }
    
    /* Make form controls easier to tap */
    .form-control, .form-select {
        padding: 0.75rem;
        font-size: 16px; /* Prevent iOS zoom */
    }
    
    /* Adjust modal padding */
    .modal-body {
        padding: 1rem;
    }
    
    /* Make pagination more compact */
    .pagination .page-item .page-link {
        padding: 0.375rem 0.5rem;
        font-size: 0.875rem;
    }
    
    /* Stack filter form elements */
    .filter-form .col-md-3, 
    .filter-form .col-md-2 {
        margin-bottom: 10px;
    }
}

/* Prevent text input zoom on iOS */
@media screen and (-webkit-min-device-pixel-ratio:0) {
    select:focus,
    textarea:focus,
    input:focus {
        font-size: 16px;
    }
}
@media (max-width: 576px) {
    .card-header h5 {
        font-size: 1rem;
        white-space: nowrap;
       
        display: block;
        margin-top:1px;
        width: 100%;
    }
}
.custom-dropdown-menu {
        width: 100%;
        min-width: 15rem;
    }

    .dropdown-item-container {
        max-height: 200px;
        overflow-y: auto;
    }

    .dropdown-item-container .dropdown-item {
        white-space: normal;
        padding: 0.5rem 1rem;
    }

    .dropdown-item-container .dropdown-item:hover {
        background-color: #f8f9fa;
        color: #000;
    }

    .dropdown-item-container .dropdown-item.active {
        background-color: var(--primary);
        color: white;
    }
    #quantityExceedModal{
        z-index: 1060 !important;
    }
    /* Button group styling in card headers */
.card-header .btn-group {
    margin-left: auto;
}

/* Responsive adjustments for buttons */
@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .card-header .btn {
        margin-top: 0.5rem;
        width: 100%;
    }
}
/* Show entries styling - Right aligned */
.entries-per-page {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    padding: 0.5rem;
    background-color: #f8f9fa;
    border-radius: 0.35rem;
    justify-content: flex-end; /* Changed from default to push to right */
    margin-left: auto; /* Push to the right side */
    width: fit-content; /* Only take needed width */
}

.entries-per-page label {
    margin-bottom: 0;
    margin-right: 0.5rem;
    font-weight: 500;
    color: #5a5c69;
}

.entries-per-page select {
    width: auto;
    min-width: 70px;
    margin: 0 0.5rem;
}

/* For the specific show entries section in your code */
.row.mb-3 .col-md-6 {
    display: flex;
    align-items: center;
    justify-content: flex-end; /* Align content to the right */
}

.row.mb-3 .col-md-6 > .d-flex {
    background-color: #f8f9fa;
    padding: 0.5rem 1rem;
    border-radius: 0.35rem;
    border: 1px solid #e3e6f0;
    margin-left: auto; /* Push to the right */
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .entries-per-page {
        flex-wrap: wrap;
        justify-content: center; /* Center on mobile */
        text-align: center;
        margin-left: 0; /* Reset margin on mobile */
        width: 100%; /* Full width on mobile */
    }
    
    .entries-per-page label,
    .entries-per-page select,
    .entries-per-page span {
        margin: 0.25rem;
    }
    
    .row.mb-3 .col-md-6 {
        justify-content: center; /* Center on mobile */
    }
    
    .row.mb-3 .col-md-6 > .d-flex {
        margin-left: 0; /* Reset margin on mobile */
    }
}
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('item_history'); ?></h2>
    
    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="itemTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'in' ? 'active' : ''; ?>" id="in-tab" data-bs-toggle="tab" data-bs-target="#in-tab-pane" type="button" role="tab" aria-controls="in-tab-pane" aria-selected="<?php echo $active_tab === 'in' ? 'true' : 'false'; ?>">
                <?php echo t('stock_in_history'); ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'out' ? 'active' : ''; ?>" id="out-tab" data-bs-toggle="tab" data-bs-target="#out-tab-pane" type="button" role="tab" aria-controls="out-tab-pane" aria-selected="<?php echo $active_tab === 'out' ? 'true' : 'false'; ?>">
                <?php echo t('stock_out_history'); ?>
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="itemTabsContent">
      <!-- Stock In History Tab -->
      <div class="tab-pane fade <?php echo $active_tab === 'in' ? 'show active' : ''; ?>" id="in-tab-pane" role="tabpanel" aria-labelledby="in-tab" tabindex="0">
          <!-- Filter Card -->
          <div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex align-items-center entries-per-page">
            <span class="me-2"><?php echo t('show_entries'); ?></span>
            <select class="form-select form-select-sm" id="in_per_page_select">
                <?php foreach ($in_limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $in_per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ms-2"><?php echo t('entries'); ?></span>
        </div>
    </div>
</div>
          <div class="card mb-4">
              <div class="card-header bg-primary text-white">
                  <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
              </div>
              <div class="card-body">
                  <div class="row mb-3">
                      <div class="col-md-12">
                          <form method="GET" class="row g-2">
                              <input type="hidden" name="tab" value="in">
                              <div class="col-md-2">
                                  <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $in_search_query; ?>">
                              </div>
                              <div class="col-md-2">
                                  <select name="location" class="form-select">
                                      <option value=""><?php echo t('report_all_location'); ?></option>
                                      <?php foreach ($locations as $location): ?>
                                          <option value="<?php echo $location['id']; ?>" <?php echo $in_location_filter == $location['id'] ? 'selected' : ''; ?>>
                                              <?php echo $location['name']; ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="category" class="form-select">
                                      <option value=""><?php echo t('all_categories'); ?></option>
                                      <?php foreach ($categories as $category): ?>
                                          <option value="<?php echo $category['id']; ?>" <?php echo $in_category_filter == $category['id'] ? 'selected' : ''; ?>>
                                              <?php echo $category['name']; ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                     
                              <div class="col-md-2">
                                  <select name="month" class="form-select">
                                      <option value="0" <?php echo $in_month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month'); ?></option>
                                      <?php for ($m = 1; $m <= 12; $m++): ?>
                                          <option value="<?php echo $m; ?>" <?php echo $in_month_filter == $m ? 'selected' : ''; ?>>
                                              <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                          </option>
                                      <?php endfor; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="year" class="form-select">
                                      <option value="0" <?php echo $in_year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                                      <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                          <option value="<?php echo $y; ?>" <?php echo $in_year_filter == $y ? 'selected' : ''; ?>>
                                              <?php echo $y; ?>
                                          </option>
                                      <?php endfor; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="sort_option" class="form-select">
                                      <option value="date_desc" <?php echo $in_sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                                      <option value="date_asc" <?php echo $in_sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                      <option value="name_asc" <?php echo $in_sort_option === 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                                      <option value="name_desc" <?php echo $in_sort_option === 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                                      <option value="category_asc" <?php echo $in_sort_option === 'category_asc' ? 'selected' : ''; ?>><?php echo t('category_az'); ?></option>
                                      <option value="category_desc" <?php echo $in_sort_option === 'category_desc' ? 'selected' : ''; ?>><?php echo t('category_za'); ?></option>
                                  </select>
                              </div>
                             
                              <div class="action-buttons">
                                  <button type="submit" class="btn btn-primary">
                                  <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                                  </button>
                                  <a href="items.php?tab=in" class="btn btn-outline-secondary">
                                  <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
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
                  <h5 class="mb-0"><?php echo t('stock_in_history'); ?></h5>
                  <div>
                      <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addItemModal">
                          <i class="bi bi-plus-circle"></i> <?php echo t('add_new_item'); ?>
                      </button>
                      <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addQtyModal">
                          <i class="bi bi-plus-lg"></i> <?php echo t('add_qty'); ?>
                      </button>
                  </div>
              </div>
              <div class="card-body">
                    
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?php echo t('item_no'); ?></th>
                                    <th><?php echo t('item_code'); ?></th>
                                    <th><?php echo t('category'); ?></th>
                                    <th><?php echo t('item_invoice'); ?></th>
                                    <th><?php echo t('item_date'); ?></th>
                                    <th><?php echo t('item_name'); ?></th>
                                    <th><?php echo t('history_qty'); ?></th>
                                    <th><?php echo t('item_size'); ?></th>
                                    <th><?php echo t('item_location'); ?></th>
                                    <th><?php echo t('item_remark'); ?></th>
                                    <th><?php echo t('item_photo'); ?></th>
                                    <th><?php echo t('item_addby'); ?></th>
                                    <th><?php echo t('action_at'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($in_history)): ?>
                                    <tr>
                                        <td colspan="14" class="text-center"><?php echo t('no_stock_in_history'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($in_history as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1 + $in_offset; ?></td>
                                            <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['invoice_no']; ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                            <td><?php echo $item['name']; ?>
                                            <span class="badge bg-<?php echo $item['action_type'] === 'new' ? 'primary' : 'success'; ?>">
    <?php 
    if ($item['action_type'] === 'new') {
        echo t('status_new');
    } elseif ($item['action_type'] === 'add') {
        echo t('status_add');
    } else {
        echo ucfirst($item['action_type']);
    }
    ?>
</span>
                                            </td>
                                            <td class="text-success">+<?php echo $item['action_quantity']; ?></td>
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
<!-- Pagination for Stock In -->
<?php if ($total_in_pages >= 1): ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <?php 
            // Create a parameter array for in filters
            $in_params_for_pagination = [
                'tab' => 'in',
                'search' => $in_search_query,
                'location' => $in_location_filter,
                'category' => $in_category_filter,
                'action' => $in_action_filter,
                'month' => $in_month_filter,
                'year' => $in_year_filter,
                'sort_option' => $in_sort_option
            ];
            
            // Merge with existing GET parameters but prioritize our in parameters
            $pagination_params = array_merge($_GET, $in_params_for_pagination);
            ?>
            
            <?php if ($in_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['in_page' => 1])); ?>" aria-label="First">
                        <span aria-hidden="true">&laquo;&laquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['in_page' => $in_page - 1])); ?>" aria-label="Previous">
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
            $start_page = max(1, $in_page - 2);
            $end_page = min($total_in_pages, $in_page + 2);
            
            if ($start_page > 1) {
                echo '<li class="page-item"><span class="page-link">...</span></li>';
            }
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?php echo $i == $in_page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['in_page' => $i])); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor;
            
            if ($end_page < $total_in_pages) {
                echo '<li class="page-item"><span class="page-link">...</span></li>';
            }
            ?>

            <?php if ($in_page < $total_in_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['in_page' => $in_page + 1])); ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['in_page' => $total_in_pages])); ?>" aria-label="Last">
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
        <?php echo t('page'); ?> <?php echo $in_page; ?> <?php echo t('page_of'); ?> <?php echo $total_in_pages; ?> 
    </div>
<?php endif; ?>
                    </div>
                    
                    
            </div>
        </div>
        <!-- Stock Out History Tab -->
      <div class="tab-pane fade <?php echo $active_tab === 'out' ? 'show active' : ''; ?>" id="out-tab-pane" role="tabpanel" aria-labelledby="out-tab" tabindex="0">
          <!-- Filter Card for Stock Out -->
          <div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex align-items-center entries-per-page">
            <span class="me-2"><?php echo t('show_entries'); ?></span>
            <select class="form-select form-select-sm" id="out_per_page_select">
                <?php foreach ($out_limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $out_per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ms-2"><?php echo t('entries'); ?></span>
        </div>
    </div>
</div>
          <div class="card mb-4">
              <div class="card-header bg-primary text-white">
                  <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
              </div>

  
              <div class="card-body">
                  <div class="row mb-3">
                      <div class="col-md-12">
                          <form method="GET" class="row g-2">
                              <input type="hidden" name="tab" value="out">
                              <div class="col-md-2">
                                  <input type="text" name="out_search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $out_search_query; ?>">
                              </div>
                              <div class="col-md-2">
                                  <select name="out_location" class="form-select">
                                      <option value=""><?php echo t('report_all_location'); ?></option>
                                      <?php foreach ($locations as $location): ?>
                                          <option value="<?php echo $location['id']; ?>" <?php echo $out_location_filter == $location['id'] ? 'selected' : ''; ?>>
                                              <?php echo $location['name']; ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="out_category" class="form-select">
                                      <option value=""><?php echo t('all_categories'); ?></option>
                                      <?php foreach ($categories as $category): ?>
                                          <option value="<?php echo $category['id']; ?>" <?php echo $out_category_filter == $category['id'] ? 'selected' : ''; ?>>
                                              <?php echo $category['name']; ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          
                              <div class="col-md-2">
                                  <select name="out_month" class="form-select">
                                      <option value="0" <?php echo $out_month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month'); ?></option>
                                      <?php for ($m = 1; $m <= 12; $m++): ?>
                                          <option value="<?php echo $m; ?>" <?php echo $out_month_filter == $m ? 'selected' : ''; ?>>
                                              <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                          </option>
                                      <?php endfor; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="out_year" class="form-select">
                                      <option value="0" <?php echo $out_year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                                      <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                          <option value="<?php echo $y; ?>" <?php echo $out_year_filter == $y ? 'selected' : ''; ?>>
                                              <?php echo $y; ?>
                                          </option>
                                      <?php endfor; ?>
                                  </select>
                              </div>
                              <div class="col-md-2">
                                  <select name="out_sort_option" class="form-select">
                                      <option value="date_desc" <?php echo $out_sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                                      <option value="date_asc" <?php echo $out_sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                      <option value="name_asc" <?php echo $out_sort_option === 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                                      <option value="name_desc" <?php echo $out_sort_option === 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                                      <option value="category_asc" <?php echo $out_sort_option === 'category_asc' ? 'selected' : ''; ?>><?php echo t('category_az'); ?></option>
                                      <option value="category_desc" <?php echo $out_sort_option === 'category_desc' ? 'selected' : ''; ?>><?php echo t('category_za'); ?></option>
                                  </select>
                              </div>
                             
                              <div class="action-buttons">
                                  <button type="submit" class="btn btn-primary">
                                      <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                                  </button>
                                  <a href="items.php?tab=out" class="btn btn-outline-secondary">
                                      <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                                  </a>
                              </div>
                          </form>
                      </div>
                  </div>
              </div>
          </div>
          
          <!-- Data Table Card for Stock Out -->
          <div class="card mb-4">
              <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                  <h5 class="mb-0"><?php echo t('stock_out_history'); ?></h5>
                  <div>
                      <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#deductQtyModal">
                          <i class="bi bi-dash-lg"></i> <?php echo t('deduct_qty'); ?>
                      </button>
                  </div>
              </div>
              <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?php echo t('item_no'); ?></th>
                                    <th><?php echo t('item_code'); ?></th>
                                    <th><?php echo t('category'); ?></th>
                                    <th><?php echo t('item_invoice'); ?></th>
                                    <th><?php echo t('item_date'); ?></th>
                                    <th><?php echo t('item_name'); ?></th>
                                    <th><?php echo t('history_qty'); ?></th>
                                 
                                    <th><?php echo t('item_size'); ?></th>
                                    <th><?php echo t('item_location'); ?></th>
                                    <th><?php echo t('item_remark'); ?></th>
                                    <th><?php echo t('item_photo'); ?></th>
                                    <th><?php echo t('item_addby'); ?></th>
                                    <th><?php echo t('action_at'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($out_history)): ?>
                                    <tr>
                                        <td colspan="14" class="text-center"><?php echo t('no_stock_out_history'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($out_history as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1 + $out_offset; ?></td>
                                            <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['invoice_no']; ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                            <td><?php echo $item['name']; ?>
                                            <span class="badge bg-danger">
    <?php 
    if ($item['action_type'] === 'deduct') {
        echo t('status_deduct');
    } else {
        echo ucfirst($item['action_type']);
    }
    ?>
</span>
                                        </td>
                                            <td class="text-danger">-<?php echo $item['action_quantity']; ?></td>
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
                      <!-- Pagination for Stock Out -->
<?php if ($total_out_pages >= 1): ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <?php 
            // Create a parameter array for out filters
            $out_params_for_pagination = [
                'tab' => 'out',
                'out_search' => $out_search_query,
                'out_location' => $out_location_filter,
                'out_category' => $out_category_filter,
                'out_action' => $out_action_filter,
                'out_month' => $out_month_filter,
                'out_year' => $out_year_filter,
                'out_sort_option' => $out_sort_option
            ];
            
            // Merge with existing GET parameters but prioritize our out parameters
            $pagination_params = array_merge($_GET, $out_params_for_pagination);
            ?>
            
            <?php if ($out_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['out_page' => 1])); ?>" aria-label="First">
                        <span aria-hidden="true">&laquo;&laquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['out_page' => $out_page - 1])); ?>" aria-label="Previous">
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
            $start_page = max(1, $out_page - 2);
            $end_page = min($total_out_pages, $out_page + 2);
            
            if ($start_page > 1) {
                echo '<li class="page-item"><span class="page-link">...</span></li>';
            }
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?php echo $i == $out_page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['out_page' => $i])); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor;
            
            if ($end_page < $total_out_pages) {
                echo '<li class="page-item"><span class="page-link">...</span></li>';
            }
            ?>

            <?php if ($out_page < $total_out_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['out_page' => $out_page + 1])); ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['out_page' => $total_out_pages])); ?>" aria-label="Last">
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
        <?php echo t('page'); ?> <?php echo $out_page; ?> <?php echo t('page_of'); ?> <?php echo $total_out_pages; ?> 
    </div>
<?php endif; ?>
                    </div>
                    </div>
                    </div>
                    </div>
                
                </div>
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
                    <h5 class="modal-title" id="addItemModalLabel"><?php echo t('add_new_item'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="main_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="items-container">
                        <!-- First item row -->
                        <div class="item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="location_id" class="form-label"><?php echo t('location_column'); ?></label>
                                    <select class="form-select" id="location_id" name="location_id[]" required>
                                        <option value=""><?php echo t('item_locations'); ?></option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_code'); ?></label>
                                    <input type="text" class="form-control" name="item_code[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('category'); ?></label>
                                    <select class="form-select" name="category_id[]" required>
                                        <option value=""><?php echo t('select_category'); ?></option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_name'); ?></label>
                                    <input type="text" class="form-control" name="name[]" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
                                </div>
                                <div class="col-md-4">
        <label class="form-label"><?php echo t('price'); ?></label>
        <input type="number" class="form-control" name="price[]" step="0.0001" min="0" value="0">
    </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('low_stock_title'); ?></label>
                                    <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_size'); ?></label>
                                    <input type="text" class="form-control" name="size[]">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label"><?php echo t('item_photo'); ?></label>
                                    <input type="file" class="form-control" name="images[0][]" multiple accept="image/*">
                                    <div class="image-preview-container mt-2 row g-1" id="image-preview-0"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="add_item" class="btn btn-primary"><?php echo t('form_save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Quantity Modal -->
<div class="modal fade" id="addQtyModal" tabindex="-1" aria-labelledby="addQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addQtyModalLabel"><?php echo t('add_qty'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="add_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="add_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="add_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="add_location_id" class="form-label"><?php echo t('location_column'); ?></label>
                        <select class="form-select" id="add_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations'); ?></option>
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
                                <div class="col-md-4 mb-3">
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
                                                <div class="px-2 py-1 text-muted"><?php echo t('warning_location1'); ?></div>
                                            </div>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                                <div class="col-md-4 mb-3">
        <label class="form-label"><?php echo t('price'); ?></label>
        <input type="number" class="form-control" name="price[]" step="0.0001" min="0" value="0">
    </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_size'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="add_qty" class="btn btn-success"><?php echo t('add'); ?></button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('qty_issue1'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('qty_issue2'); ?></h4>
                <p id="quantityExceedMessage" style="text-align:left;"><?php echo t('qty_issue3'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree'); ?>
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
                    <h5 class="modal-title" id="deductQtyModalLabel"><?php echo t('deduct_qty'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="deduct_invoice_no" class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" id="deduct_invoice_no" name="invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="deduct_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="deduct_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="deduct_location_id" class="form-label"><?php echo t('location_column'); ?></label>
                        <select class="form-select" id="deduct_location_id" name="location_id" required>
                            <option value=""><?php echo t('item_locations'); ?></option>
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
                                                <div class="px-2 py-1 text-muted"><?php echo t('warning_location1'); ?></div>
                                            </div>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_size'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="deduct-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="deduct_qty" class="btn btn-danger"><?php echo t('deduct'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Duplicate Item Modal -->
<div class="modal fade" id="duplicateItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_itm1'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_itm2'); ?></h4>
                <p><?php echo t('duplicate_itm3'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree'); ?>
                </button>
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

<script>
    // Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Handle entries per page change for stock in
    const inPerPageSelect = document.getElementById('in_per_page_select');
    if (inPerPageSelect) {
        inPerPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('in_per_page', this.value);
            url.searchParams.set('in_page', '1'); // Reset to first page
            window.location.href = url.toString();
        });
    }

    // Handle entries per page change for stock out
    const outPerPageSelect = document.getElementById('out_per_page_select');
    if (outPerPageSelect) {
        outPerPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('out_per_page', this.value);
            url.searchParams.set('out_page', '1'); // Reset to first page
            window.location.href = url.toString();
        });
    }
});
    // Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Handle tab clicks to preserve pagination
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('click', function(e) {
            const targetTab = e.target.getAttribute('data-bs-target');
            const url = new URL(window.location);
            
            // Update the tab parameter
            if (targetTab === '#in-tab-pane') {
                url.searchParams.set('tab', 'in');
            } else if (targetTab === '#out-tab-pane') {
                url.searchParams.set('tab', 'out');
            }
            
            // Update URL without reloading
            window.history.replaceState({}, '', url);
        });
    });
    
    // Ensure correct tab is active on page load
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    
    if (activeTab === 'out') {
        const outTab = new bootstrap.Tab(document.getElementById('out-tab'));
        outTab.show();
    } else {
        const inTab = new bootstrap.Tab(document.getElementById('in-tab'));
        inTab.show();
    }
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
            
            // Remove the element after fade out
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 5000); // 5000 milliseconds = 5 seconds
    });
});

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
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('warning_location1'); ?></div>';
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
                        
                        const row = dropdownElement.closest('.add-qty-item-row');
                        const sizeInput = row.querySelector('input[name="size[]"]');
                        const remarkInput = row.querySelector('input[name="remark[]"]');
                        
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (remarkInput) remarkInput.value = this.dataset.remark;
                        
                        const quantityInput = row.querySelector('input[name="quantity[]"]');
                        if (quantityInput) {
                            quantityInput.removeAttribute('max'); // Remove max for adding quantity
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

// Handle location change for add quantity modal
document.getElementById('add_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#add_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

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
            <div class="col-md-4 mb-3">
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
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty'); ?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
             <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('price'); ?></label>
                <input type="number" class="form-control" name="price[]" step="0.0001" min="0" value="0">
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size'); ?></label>
                <input type="text" class="form-control" name="size[]" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark'); ?></label>
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
    }
});

// Update the add-more-row event listener to check for location selection first
document.getElementById('add-more-row').addEventListener('click', function() {
    // Check if a location is selected in the first row
    const firstLocationSelect = document.querySelector('#items-container select[name="location_id[]"]');
    
    if (!firstLocationSelect || !firstLocationSelect.value) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const container = document.getElementById('items-container');
    const rowCount = container.querySelectorAll('.item-row').length;
    
    const newRow = document.createElement('div');
    newRow.className = 'item-row mb-3 border p-3';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-4">
                <label for="location_id" class="form-label"><?php echo t('location_column'); ?></label>
                <select class="form-select" name="location_id[]" required>
                    <option value=""><?php echo t('item_locations'); ?></option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_code'); ?></label>
                <input type="text" class="form-control" name="item_code[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('category'); ?></label>
                <select class="form-select" name="category_id[]" required>
                    <option value=""><?php echo t('select_category'); ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_name'); ?></label>
                <input type="text" class="form-control" name="name[]" required>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_qty'); ?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('price'); ?></label>
                <input type="number" class="form-control" name="price[]" step="0.0001" min="0" value="0">
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="form-label"><?php echo t('low_stock_title'); ?></label>
                <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10" required>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_size'); ?></label>
                <input type="text" class="form-control" name="size[]">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo t('item_remark'); ?></label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 mb-3">
                <label class="form-label"><?php echo t('item_photo'); ?></label>
                <input type="file" class="form-control item-images-input" name="images[${rowCount}][]" multiple accept="image/*">
                <div class="image-preview-container mt-2 row g-1" id="image-preview-${rowCount}"></div>
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> <?php echo t('del_row'); ?>
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
    
    // Copy the location selection from the first row to the new row
    const newLocationSelect = newRow.querySelector('select[name="location_id[]"]');
    if (newLocationSelect && firstLocationSelect.value) {
        newLocationSelect.value = firstLocationSelect.value;
    }
});

// Set today's date when modals are shown
document.getElementById('addItemModal').addEventListener('shown.bs.modal', function() {
    const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    document.getElementById('date').value = formattedDate;
});

document.getElementById('addQtyModal').addEventListener('shown.bs.modal', function() {
    const today = new Date();
    const formattedDate = today.toISOString().split('T')[0];
    document.getElementById('add_date').value = formattedDate;
    
    // Initialize the first dropdown
    const dropdown = document.querySelector('#add_qty_items_container .item-dropdown');
    const locationId = document.getElementById('add_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
});
// Handle location change for deduct quantity modal
document.getElementById('deduct_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#deduct_qty_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

// Handle add more row for deduct quantity modal
document.getElementById('deduct-qty-more-row').addEventListener('click', function() {
    const container = document.getElementById('deduct_qty_items_container');
    const locationId = document.getElementById('deduct_location_id').value;
    const rowCount = container.querySelectorAll('.deduct-qty-item-row').length;

    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'deduct-qty-item-row mb-3 border p-3';
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
                            <!-- Items will be populated here -->
                        </div>
                    </ul>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?php echo t('item_qty'); ?></label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_size'); ?></label>
                <input type="text" class="form-control" name="size[]" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo t('item_remark'); ?></label>
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
                        alert('You cannot deduct more than the available quantity');
                    }
                }
            }
        });
    }
});

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
                        <?php echo t('qty_issue4'); ?> <strong>${max}</strong> ( <?php echo t('qty_issue5'); ?>)<br>
                         <?php echo t('qty_issue6'); ?>: <strong>${value}</strong>
                    `;
                    
                    modal.show();
        }
    }
});

// Set up deduct modal when shown
document.getElementById('deductQtyModal').addEventListener('shown.bs.modal', function() {
    const dropdown = document.querySelector('#deduct_qty_items_container .item-dropdown');
    const locationId = document.getElementById('deduct_location_id').value;
    if (dropdown) {
        populateItemDropdown(dropdown, locationId);
    }
    // Set today's date
    document.getElementById('deduct_date').valueAsDate = new Date();
});
</script>

<?php
require_once '../includes/footer.php';
?>