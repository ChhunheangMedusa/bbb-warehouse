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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_for_repair'])) {
        try {
            $pdo->beginTransaction();
            
            $invoice_no = sanitizeInput($_POST['invoice_no']);
            $date = sanitizeInput($_POST['date']);
            $from_location_id = (int)$_POST['from_location_id'];
            $to_location_id = (int)$_POST['to_location_id'];
            
            foreach ($_POST['item_id'] as $index => $item_id) {
                $item_id = (int)$item_id;
                $quantity = (float)$_POST['quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index] ?? '');
                $remark = sanitizeInput($_POST['remark'][$index] ?? '');
                
                // Get item details
                $stmt = $pdo->prepare("SELECT i.id, i.item_code, i.category_id, i.name, i.quantity, i.size, i.location_id 
                                      FROM items i 
                                      WHERE i.id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$item) {
                    throw new Exception("Item not found");
                }
                
                // Check if quantity is available
                if ($quantity > $item['quantity']) {
                    throw new Exception("Not enough quantity available for item: {$item['name']}");
                }
                
                // Insert into repair_items table
                $stmt = $pdo->prepare("INSERT INTO repair_items 
                    (item_code, category_id, invoice_no, date, item_name, quantity, action_type, size, 
                    from_location_id, to_location_id, remark, action_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'send_for_repair', ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $item['item_code'],
                    $item['category_id'],
                    $invoice_no,
                    $date,
                    $item['name'],
                    $quantity,
                    $size,
                    $from_location_id,
                    $to_location_id,
                    $remark,
                    $_SESSION['user_id']
                ]);
                
                // Record history
                $stmt = $pdo->prepare("INSERT INTO repair_history 
                    (repair_item_id, item_code, category_id, invoice_no, date, item_name, quantity, 
                    action_type, size, from_location_id, to_location_id, remark, action_by, history_action)
                    SELECT id, item_code, category_id, invoice_no, date, item_name, quantity, 
                    action_type, size, from_location_id, to_location_id, remark, action_by, 'created'
                    FROM repair_items WHERE id = ?");
                $stmt->execute([$pdo->lastInsertId()]);
                
                // Update item quantity
                $new_qty = $item['quantity'] - $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
                $stmt->execute([$new_qty, $item_id]);
                
                // Log activity
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$from_location_id]);
                $from_location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$to_location_id]);
                $to_location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $log_message = "Sent for repair: {$item['name']} ($quantity $size) from {$from_location['name']} to {$to_location['name']}";
                logActivity($_SESSION['user_id'], 'Repair Item', $log_message);
            }
            
            $pdo->commit();
            $_SESSION['success'] = t('repair_sent_success');
            redirect('repair.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['return_from_repair'])) {
        try {
            $pdo->beginTransaction();
            
            $invoice_no = sanitizeInput($_POST['invoice_no']);
            $date = sanitizeInput($_POST['date']);
            $to_location_id = (int)$_POST['to_location_id'];
            
            foreach ($_POST['repair_item_id'] as $index => $repair_item_id) {
                $repair_item_id = (int)$repair_item_id;
                $quantity = (float)$_POST['quantity'][$index];
                $remark = sanitizeInput($_POST['remark'][$index] ?? '');
                
                // Get repair item details
                $stmt = $pdo->prepare("SELECT * FROM repair_items WHERE id = ?");
                $stmt->execute([$repair_item_id]);
                $repair_item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$repair_item) {
                    throw new Exception("Repair item not found");
                }
                
                // Check if quantity is valid
                if ($quantity > $repair_item['quantity']) {
                    throw new Exception("Cannot return more than was sent for repair");
                }
                
                // Record history BEFORE deleting
                $stmt = $pdo->prepare("INSERT INTO repair_history 
                (repair_item_id, item_code, category_id, invoice_no, date, item_name, quantity, 
                action_type, size, from_location_id, to_location_id, remark, action_by, history_action)
                SELECT id, item_code, category_id, invoice_no, date, item_name, quantity, 
                'return_from_repair', size, from_location_id, to_location_id, remark, action_by, 'updated'
                FROM repair_items WHERE id = ?");
                $stmt->execute([$repair_item_id]);
                
                // Find original item and update quantity
                $stmt = $pdo->prepare("SELECT id, quantity FROM items 
                    WHERE name = ? AND location_id = ?");
                $stmt->execute([$repair_item['item_name'], $to_location_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    $new_qty = $item['quantity'] + $quantity;
                    $stmt = $pdo->prepare("UPDATE items SET quantity = ? WHERE id = ?");
                    $stmt->execute([$new_qty, $item['id']]);
                } else {
                    // If item doesn't exist at destination, create it
                    $stmt = $pdo->prepare("INSERT INTO items 
                        (item_code, category_id, invoice_no, date, name, quantity, size, location_id, remark)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $repair_item['item_code'],
                        $repair_item['category_id'],
                        $invoice_no,
                        $date,
                        $repair_item['item_name'],
                        $quantity,
                        $repair_item['size'],
                        $to_location_id,
                        $remark
                    ]);
                }
                
                // DELETE the repair item after successful return
                $stmt = $pdo->prepare("DELETE FROM repair_items WHERE id = ?");
                $stmt->execute([$repair_item_id]);
                
                // Log activity
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$repair_item['from_location_id']]);
                $from_location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$to_location_id]);
                $to_location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $log_message = "Returned from repair and deleted repair record: {$repair_item['item_name']} ($quantity {$repair_item['size']}) from {$from_location['name']} to {$to_location['name']}";
                logActivity($_SESSION['user_id'], 'Return Repair', $log_message);
            }
            
            $pdo->commit();
            $_SESSION['success'] = t('repair_return_success');
            redirect('repair.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['delete_repair_item'])) {
        try {
            $pdo->beginTransaction();
            
            $repair_item_id = (int)$_POST['delete_id'];
            
            // First record history before deleting
            $stmt = $pdo->prepare("INSERT INTO repair_history 
                (repair_item_id, item_code, category_id, invoice_no, date, item_name, quantity, 
                action_type, size, from_location_id, to_location_id, remark, action_by, history_action)
                SELECT id, item_code, category_id, invoice_no, date, item_name, quantity, 
                action_type, size, from_location_id, to_location_id, remark, action_by, 'deleted'
                FROM repair_items WHERE id = ?");
            $stmt->execute([$repair_item_id]);
            
            // Then delete the item
            $stmt = $pdo->prepare("DELETE FROM repair_items WHERE id = ?");
            $stmt->execute([$repair_item_id]);
            
            $pdo->commit();
            $_SESSION['success'] = t('repair_item_deleted');
            redirect('repair.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }
}
// Get active tab from request or default to 'current'
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'current';
// Get all repair locations (locations with type 'repair')
$repair_locations = $pdo->query("SELECT * FROM locations WHERE type = 'repair' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get all non-repair locations
$non_repair_locations = $pdo->query("SELECT * FROM locations WHERE type != 'repair' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);


$current_limit_options = [10, 25, 50, 100];
$current_per_page = isset($_GET['current_per_page']) ? (int)$_GET['current_per_page'] : 10;
if (!in_array($current_per_page, $current_limit_options)) {
    $current_per_page = 10;
}

// Get pagination parameters for current repairs
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $current_per_page;
$offset = ($page - 1) * $limit;

// Get filters for current repairs
$year_filter = isset($_GET['year']) && $_GET['year'] != 0 ? (int)$_GET['year'] : null;
$month_filter = isset($_GET['month']) && $_GET['month'] != 0 ? (int)$_GET['month'] : null;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$action_type_filter = isset($_GET['action_type']) ? $_GET['action_type'] : null;
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Get sort option for current repairs
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'date_desc';

// Validate and parse sort option for current repairs
$sort_mapping = [
    'name_asc' => ['field' => 'r.item_name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'r.item_name', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'fl.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'fl.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'r.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'r.date', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'action_by_asc' => ['field' => 'u.username', 'direction' => 'ASC'],
    'action_by_desc' => ['field' => 'u.username', 'direction' => 'DESC'],
    'action_asc' => ['field' => 'r.action_type', 'direction' => 'ASC'],
    'action_desc' => ['field' => 'r.action_type', 'direction' => 'DESC']
];

// Default to date_desc if invalid option for current repairs
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'date_desc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Build query for current repairs
$query = "SELECT 
    r.id,
    r.item_code, 
    r.category_id,
    c.name as category_name,
    r.invoice_no,
    r.date,
    r.item_name,
    r.quantity,
    r.action_type,
    r.size,
    r.from_location_id,
    fl.name as from_location_name,
    r.to_location_id,
    tl.name as to_location_name,
    r.remark,
    r.action_by,
    u.username as action_by_name,
    r.action_at,
    (SELECT id FROM item_images WHERE item_id = (SELECT id FROM items WHERE name = r.item_name LIMIT 1) ORDER BY id DESC LIMIT 1) as image_id
FROM 
    repair_items r
LEFT JOIN 
    categories c ON r.category_id = c.id
JOIN 
    locations fl ON r.from_location_id = fl.id
JOIN 
    locations tl ON r.to_location_id = tl.id
JOIN
    users u ON r.action_by = u.id
WHERE 1=1";

$params = [];

// Add filters for current repairs
if ($year_filter !== null) {
    $query .= " AND YEAR(r.date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter !== null) {
    $query .= " AND MONTH(r.date) = :month";
    $params[':month'] = $month_filter;
}

if ($location_filter) {
    $query .= " AND (r.from_location_id = :location_id OR r.to_location_id = :location_id)";
    $params[':location_id'] = $location_filter;
}

if ($category_filter) {
    $query .= " AND r.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($action_type_filter) {
    $query .= " AND r.action_type = :action_type";
    $params[':action_type'] = $action_type_filter;
}

if ($search_query) {
    $query .= " AND (r.item_name LIKE :search OR r.invoice_no LIKE :search OR r.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Add sorting for current repairs
$query .= " ORDER BY $sort_by $sort_order";

// Get total count for pagination for current repairs
$count_query = "SELECT COUNT(*) as total FROM repair_items r
                LEFT JOIN categories c ON r.category_id = c.id
                JOIN locations fl ON r.from_location_id = fl.id
                JOIN locations tl ON r.to_location_id = tl.id
                WHERE 1=1";

if ($year_filter !== null) $count_query .= " AND YEAR(r.date) = :year";
if ($month_filter !== null) $count_query .= " AND MONTH(r.date) = :month";
if ($location_filter) $count_query .= " AND (r.from_location_id = :location_id OR r.to_location_id = :location_id)";
if ($category_filter) $count_query .= " AND r.category_id = :category_id";
if ($action_type_filter) $count_query .= " AND r.action_type = :action_type";
if ($search_query) $count_query .= " AND (r.item_name LIKE :search OR r.invoice_no LIKE :search OR r.remark LIKE :search)";

$stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get paginated results for current repairs
$query .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$repair_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$all_locations = $pdo->query("SELECT * FROM locations WHERE  type != 'repair' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get all categories for filter dropdown
$all_categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get items by location for dropdowns
$items_by_location = [];
foreach ($non_repair_locations as $location) {
    $stmt = $pdo->prepare("SELECT id, name, quantity, size, remark FROM items WHERE location_id = ? ORDER BY name");
    $stmt->execute([$location['id']]);
    $items_by_location[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get items currently in repair for return dropdown
$items_in_repair = [];
foreach ($repair_locations as $location) {
    $stmt = $pdo->prepare("SELECT r.id, r.item_name, r.quantity, r.size 
                          FROM repair_items r 
                          WHERE r.to_location_id = ? AND r.action_type = 'send_for_repair'
                          ORDER BY r.item_name");
    $stmt->execute([$location['id']]);
    $items_in_repair[$location['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$history_limit_options = [10, 25, 50, 100];
$history_per_page = isset($_GET['history_per_page']) ? (int)$_GET['history_per_page'] : 10;
if (!in_array($history_per_page, $history_limit_options)) {
    $history_per_page = 10;
}

// Get pagination parameters for history
$history_page = isset($_GET['history_page']) ? (int)$_GET['history_page'] : 1;
$history_limit = $history_per_page;
$history_offset = ($history_page - 1) * $history_limit;

// Get filters for history
$history_year_filter = isset($_GET['history_year']) && $_GET['history_year'] != 0 ? (int)$_GET['history_year'] : null;
$history_month_filter = isset($_GET['history_month']) && $_GET['history_month'] != 0 ? (int)$_GET['history_month'] : null;
$history_location_filter = isset($_GET['history_location']) ? (int)$_GET['history_location'] : null;
$history_category_filter = isset($_GET['history_category']) ? (int)$_GET['history_category'] : null;
$history_action_type_filter = isset($_GET['history_action_type']) ? $_GET['history_action_type'] : null;
$history_search_query = isset($_GET['history_search']) ? sanitizeInput($_GET['history_search']) : '';

// Get sort option for history
$history_sort_option = isset($_GET['history_sort_option']) ? sanitizeInput($_GET['history_sort_option']) : 'date_desc';

// Validate and parse sort option for history
$history_sort_mapping = [
    'name_asc' => ['field' => 'rh.item_name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'rh.item_name', 'direction' => 'DESC'],
    'location_asc' => ['field' => 'fl.name', 'direction' => 'ASC'],
    'location_desc' => ['field' => 'fl.name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'rh.date', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'rh.date', 'direction' => 'DESC'],
    'category_asc' => ['field' => 'c.name', 'direction' => 'ASC'],
    'category_desc' => ['field' => 'c.name', 'direction' => 'DESC'],
    'action_by_asc' => ['field' => 'u.username', 'direction' => 'ASC'],
    'action_by_desc' => ['field' => 'u.username', 'direction' => 'DESC'],
    'action_asc' => ['field' => 'rh.action_type', 'direction' => 'ASC'],
    'action_desc' => ['field' => 'rh.action_type', 'direction' => 'DESC'],
    'history_action_asc' => ['field' => 'rh.history_action', 'direction' => 'ASC'],
    'history_action_desc' => ['field' => 'rh.history_action', 'direction' => 'DESC']
];

// Default to date_desc if invalid option for history
if (!array_key_exists($history_sort_option, $history_sort_mapping)) {
    $history_sort_option = 'date_desc';
}

$history_sort_by = $history_sort_mapping[$history_sort_option]['field'];
$history_sort_order = $history_sort_mapping[$history_sort_option]['direction'];

// Build query for repair history
$history_query = "SELECT 
    rh.*,
    c.name as category_name,
    fl.name as from_location_name,
    tl.name as to_location_name,
    u.username as action_by_name,
    (SELECT id FROM item_images WHERE item_id = (SELECT id FROM items WHERE name = rh.item_name LIMIT 1) ORDER BY id DESC LIMIT 1) as image_id
FROM 
    repair_history rh
LEFT JOIN 
    categories c ON rh.category_id = c.id
JOIN 
    locations fl ON rh.from_location_id = fl.id
JOIN 
    locations tl ON rh.to_location_id = tl.id
JOIN
    users u ON rh.action_by = u.id
WHERE 1=1";

$history_params = [];

// Add filters for history
if ($history_year_filter !== null) {
    $history_query .= " AND YEAR(rh.date) = :history_year";
    $history_params[':history_year'] = $history_year_filter;
}

if ($history_month_filter !== null) {
    $history_query .= " AND MONTH(rh.date) = :history_month";
    $history_params[':history_month'] = $history_month_filter;
}

if ($history_location_filter) {
    $history_query .= " AND (rh.from_location_id = :history_location_id OR rh.to_location_id = :history_location_id)";
    $history_params[':history_location_id'] = $history_location_filter;
}

if ($history_category_filter) {
    $history_query .= " AND rh.category_id = :history_category_id";
    $history_params[':history_category_id'] = $history_category_filter;
}

if ($history_action_type_filter) {
    $history_query .= " AND rh.action_type = :history_action_type";
    $history_params[':history_action_type'] = $history_action_type_filter;
}

if ($history_search_query) {
    $history_query .= " AND (rh.item_name LIKE :history_search OR rh.invoice_no LIKE :history_search OR rh.remark LIKE :history_search)";
    $history_params[':history_search'] = "%$history_search_query%";
}

// Add sorting for history
$history_query .= " ORDER BY $history_sort_by $history_sort_order";

// Get total count for pagination for history
$history_count_query = "SELECT COUNT(*) as total FROM repair_history rh
                        LEFT JOIN categories c ON rh.category_id = c.id
                        JOIN locations fl ON rh.from_location_id = fl.id
                        JOIN locations tl ON rh.to_location_id = tl.id
                        WHERE 1=1";

if ($history_year_filter !== null) $history_count_query .= " AND YEAR(rh.date) = :history_year";
if ($history_month_filter !== null) $history_count_query .= " AND MONTH(rh.date) = :history_month";
if ($history_location_filter) $history_count_query .= " AND (rh.from_location_id = :history_location_id OR rh.to_location_id = :history_location_id)";
if ($history_category_filter) $history_count_query .= " AND rh.category_id = :history_category_id";
if ($history_action_type_filter) $history_count_query .= " AND rh.action_type = :history_action_type";
if ($history_search_query) $history_count_query .= " AND (rh.item_name LIKE :history_search OR rh.invoice_no LIKE :history_search OR rh.remark LIKE :history_search)";

$history_stmt = $pdo->prepare($history_count_query);
foreach ($history_params as $key => $value) {
    $history_stmt->bindValue($key, $value);
}
$history_stmt->execute();
$history_total_items = $history_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$history_total_pages = ceil($history_total_items / $history_limit);

// Get paginated results for history
$history_query .= " LIMIT :history_limit OFFSET :history_offset";
$history_stmt = $pdo->prepare($history_query);
foreach ($history_params as $key => $value) {
    $history_stmt->bindValue($key, $value);
}
$history_stmt->bindValue(':history_limit', $history_limit, PDO::PARAM_INT);
$history_stmt->bindValue(':history_offset', $history_offset, PDO::PARAM_INT);
$history_stmt->execute();
$history_items = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
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
/* Add your existing styles here */
.repair-badge {
    padding: 0.35em 0.65em;
    font-weight: 500;
    border-radius: 0.25rem;
}

.badge-send {
    background-color: #f6c23e;
    color: #000;
}

.badge-return {
    background-color: #1cc88a;
    color: #fff;
}

.badge-complete {
    background-color: #4e73df;
    color: #fff;
}
.action-buttons {
    display: flex;
    flex-wrap: nowrap;
    gap: 4px;
}
.action-buttons .btn {
    margin: 0;
    white-space: nowrap;

}

@media (max-width: 768px) {
    .action-buttons .btn {
        width: 100%;
    }
}
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
@media (max-width: 768px) {
    #sendForRepairModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #sendForRepairModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #sendForRepairModal .modal-footer form {
        flex: 1;
    }
}
@media (max-width: 768px) {
    #returnFromRepairModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #returnFromRepairModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #returnFromRepairModal .modal-footer form {
        flex: 1;
    }
}

</style>
<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('repair_management'); ?></h2>
    
    <!-- Display success/error messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="repairTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'current' ? 'active' : ''; ?>" id="current-tab" data-bs-toggle="tab" data-bs-target="#current-repairs" type="button" role="tab" aria-controls="current-repairs" aria-selected="<?php echo $active_tab === 'current' ? 'true' : 'false'; ?>">
                <?php echo t('current_repairs'); ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'history' ? 'active' : ''; ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#repair-history" type="button" role="tab" aria-controls="repair-history" aria-selected="<?php echo $active_tab === 'history' ? 'true' : 'false'; ?>">
                <?php echo t('repair_history'); ?>
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="repairTabsContent">
        
        <!-- Current Repairs Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'current' ? 'show active' : ''; ?>" id="current-repairs" role="tabpanel" aria-labelledby="current-tab">
        <div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex align-items-center entries-per-page">
            <span class="me-2"><?php echo t('show_entries'); ?></span>
            <select class="form-select form-select-sm" id="current_per_page_select">
                <?php foreach ($current_limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $current_per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ms-2"><?php echo t('entries'); ?></span>
        </div>
    </div>
</div>
        <!-- Filter Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
                </div>
                
                <div class="card-body">
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="tab" value="current">
                        <div class="row mb-3">
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('search'); ?></label>
                                <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $search_query; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('location'); ?></label>
                                <select name="location" class="form-select">
                                    <option value=""><?php echo t('report_all_location'); ?></option>
                                    <?php foreach ($all_locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                            <?php echo $location['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('category'); ?></label>
                                <select name="category" class="form-select">
                                    <option value=""><?php echo t('all_categories'); ?></option>
                                    <?php foreach ($all_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo $category['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('action_type'); ?></label>
                                <select name="action_type" class="form-select">
                                    <option value=""><?php echo t('all_actions'); ?></option>
                                    <option value="send_for_repair" <?php echo $action_type_filter == 'send_for_repair' ? 'selected' : ''; ?>>
                                        <?php echo t('send_for_repair'); ?>
                                    </option>
                                    <option value="return_from_repair" <?php echo $action_type_filter == 'return_from_repair' ? 'selected' : ''; ?>>
                                        <?php echo t('return_back'); ?>
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('month'); ?></label>
                                <select name="month" class="form-select">
                                    <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month'); ?></option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('year'); ?></label>
                                <select name="year" class="form-select">
                                    <option value="0" <?php echo $year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label"><?php echo t('sort'); ?></label>
                                <select name="sort_option" class="form-select">
                                    <option value="name_asc" <?php echo $sort_option == 'name_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('name_a_to_z'); ?>
                                    </option>
                                    <option value="name_desc" <?php echo $sort_option == 'name_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('name_z_to_a'); ?>
                                    </option>
                                    <option value="date_asc" <?php echo $sort_option == 'date_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('date_oldest_first'); ?>
                                    </option>
                                    <option value="date_desc" <?php echo $sort_option == 'date_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('date_newest_first'); ?>
                                    </option>
                                    <option value="category_asc" <?php echo $sort_option == 'category_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('category_az'); ?>
                                    </option>
                                    <option value="category_desc" <?php echo $sort_option == 'category_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('category_za'); ?>
                                    </option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                            <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                            </button>
                            <a href="repair.php?tab=current" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                            </a>
                        </div>
                        
                        <input type="hidden" name="page" value="1">
                    </form>
                </div>
            </div>
            
            <!-- Data Card -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo t('current_repairs'); ?></h5>
                    <div>
                        <button class="btn btn-dark btn-sm me-2" data-bs-toggle="modal" data-bs-target="#sendForRepairModal">
                            <i class="bi bi-tools"></i> <?php echo t('send_for_repair'); ?>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($search_query) || $location_filter || $category_filter || $action_type_filter || $month_filter || $year_filter): ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> 
                            <?php echo t('showing_filtered_results'); ?>
                            <?php if (!empty($search_query)): ?>
                                <span class="badge bg-secondary"><?php echo t('search'); ?>: <?php echo htmlspecialchars($search_query); ?></span>
                            <?php endif; ?>
                            <?php if ($location_filter): ?>
                                <?php 
                                $location_name = '';
                                foreach ($all_locations as $location) {
                                    if ($location['id'] == $location_filter) {
                                        $location_name = $location['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="badge bg-secondary"><?php echo t('location'); ?>: <?php echo $location_name; ?></span>
                            <?php endif; ?>
                            <?php if ($category_filter): ?>
                                <?php 
                                $category_name = '';
                                foreach ($all_categories as $category) {
                                    if ($category['id'] == $category_filter) {
                                        $category_name = $category['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="badge bg-secondary"><?php echo t('category'); ?>: <?php echo $category_name; ?></span>
                            <?php endif; ?>
                            <?php if ($action_type_filter): ?>
                                <span class="badge bg-secondary"><?php echo t('action'); ?>: <?php echo $action_type_filter; ?></span>
                            <?php endif; ?>
                            <?php if ($month_filter && $month_filter != 0): ?>
                                <span class="badge bg-secondary"><?php echo t('month'); ?>: <?php echo date('F', mktime(0, 0, 0, $month_filter, 1)); ?></span>
                            <?php endif; ?>
                            <?php if ($year_filter && $year_filter != 0): ?>
                                <span class="badge bg-secondary"><?php echo t('year'); ?>: <?php echo $year_filter; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
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
                                    <th><?php echo t('item_qty'); ?></th>
                                    <th><?php echo t('action'); ?></th>
                                    <th><?php echo t('unit'); ?></th>
                                    <th><?php echo t('from_location'); ?></th>
                                    <th><?php echo t('to_location'); ?></th>
                                    <th><?php echo t('item_remark'); ?></th>
                                    <th><?php echo t('item_photo'); ?></th>
                                    <th><?php echo t('action_by'); ?></th>
                                    <th><?php echo t('action_at'); ?></th>
                                    <th><?php echo t('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($repair_items)): ?>
                                    <tr>
                                        <td colspan="16" class="text-center"><?php echo t('no_repair_items'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($repair_items as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1 + $offset; ?></td>
                                            <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['invoice_no']; ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                            <td><?php echo $item['item_name']; ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>
                                                <span class="repair-badge badge-<?php echo str_replace('_', '-', $item['action_type']); ?>">
                                                    <?php 
                                                    if ($item['action_type'] == 'send_for_repair') {
                                                        echo t('send_for_repair');
                                                    } elseif ($item['action_type'] == 'return_from_repair') {
                                                        echo t('return_back');
                                                    } else {
                                                        echo t('repair_complete');
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo $item['size']; ?></td>
                                            <td><?php echo $item['from_location_name']; ?></td>
                                            <td><?php echo $item['to_location_name']; ?></td>
                                            <td><?php echo $item['remark']; ?></td>
                                            <td>
                                                <?php if ($item['image_id']): ?>
                                                    <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                                         class="img-thumbnail" width="50"
                                                         data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                                         data-item-id="<?php echo $item['id']; ?>">
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $item['action_by_name']; ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
                                            <td class="action-buttons">
                                                <?php if ($item['action_type'] == 'send_for_repair'): ?>
                                                    <button class="btn btn-success btn-sm return-btn" 
                                                            data-id="<?php echo $item['id']; ?>"
                                                            data-name="<?php echo $item['item_name']; ?>"
                                                            data-quantity="<?php echo $item['quantity']; ?>"
                                                            data-size="<?php echo $item['size']; ?>"
                                                            data-from-location="<?php echo $item['from_location_id']; ?>">
                                                        <i class="bi bi-arrow-return-left"></i> <?php echo t('return_back'); ?>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm delete-btn" 
                                                        data-id="<?php echo $item['id']; ?>"
                                                        data-name="<?php echo $item['item_name']; ?>">
                                                    <i class="bi bi-trash"></i> <?php echo t('delete_button'); ?>
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
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1, 'tab' => 'current'])); ?>" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1, 'tab' => 'current'])); ?>" aria-label="Previous">
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
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i, 'tab' => 'current'])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor;
                                
                                if ($end_page < $total_pages) {
                                    echo '<li class="page-item"><span class="page-link">...</span></li>';
                                }
                                ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1, 'tab' => 'current'])); ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages, 'tab' => 'current'])); ?>" aria-label="Last">
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
        
        <!-- Repair History Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'history' ? 'show active' : ''; ?>" id="repair-history" role="tabpanel" aria-labelledby="history-tab">
        <div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex align-items-center entries-per-page">
            <span class="me-2"><?php echo t('show_entries'); ?></span>
            <select class="form-select form-select-sm" id="history_per_page_select">
                <?php foreach ($history_limit_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $history_per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ms-2"><?php echo t('entries'); ?></span>
        </div>
    </div>
</div>
        <!-- Filter Card for History -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="tab" value="history">
                        <div class="row mb-3">
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('search'); ?></label>
                                <input type="text" name="history_search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo $history_search_query; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('location'); ?></label>
                                <select name="history_location" class="form-select">
                                    <option value=""><?php echo t('report_all_location'); ?></option>
                                    <?php foreach ($all_locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" <?php echo $history_location_filter == $location['id'] ? 'selected' : ''; ?>>
                                            <?php echo $location['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('category'); ?></label>
                                <select name="history_category" class="form-select">
                                    <option value=""><?php echo t('all_categories'); ?></option>
                                    <?php foreach ($all_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $history_category_filter == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo $category['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('action_type'); ?></label>
                                <select name="history_action_type" class="form-select">
                                    <option value=""><?php echo t('all_actions'); ?></option>
                                    <option value="send_for_repair" <?php echo $history_action_type_filter == 'send_for_repair' ? 'selected' : ''; ?>>
                                        <?php echo t('send_for_repair'); ?>
                                    </option>
                                    <option value="return_from_repair" <?php echo $history_action_type_filter == 'return_from_repair' ? 'selected' : ''; ?>>
                                        <?php echo t('return_back'); ?>
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('month'); ?></label>
                                <select name="history_month" class="form-select">
                                    <option value="0" <?php echo $history_month_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_month'); ?></option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $history_month_filter == $m ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo t('year'); ?></label>
                                <select name="history_year" class="form-select">
                                    <option value="0" <?php echo $history_year_filter == 0 ? 'selected' : ''; ?>><?php echo t('all_years'); ?></option>
                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $history_year_filter == $y ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label"><?php echo t('sort'); ?></label>
                                <select name="history_sort_option" class="form-select">
                                    <option value="name_asc" <?php echo $history_sort_option == 'name_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('name_a_to_z'); ?>
                                    </option>
                                    <option value="name_desc" <?php echo $history_sort_option == 'name_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('name_z_to_a'); ?>
                                    </option>
                                    <option value="date_asc" <?php echo $history_sort_option == 'date_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('date_oldest_first'); ?>
                                    </option>
                                    <option value="date_desc" <?php echo $history_sort_option == 'date_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('date_newest_first'); ?>
                                    </option>
                                    <option value="category_asc" <?php echo $history_sort_option == 'category_asc' ? 'selected' : ''; ?>>
                                        <?php echo t('category_az'); ?>
                                    </option>
                                    <option value="category_desc" <?php echo $history_sort_option == 'category_desc' ? 'selected' : ''; ?>>
                                        <?php echo t('category_za'); ?>
                                    </option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                            <i class="bi bi-filter"></i> <?php echo t('search'); ?>
                            </button>
                            <a href="repair.php?tab=history" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> <?php echo t('reset'); ?>
                            </a>
                        </div>
                        
                        <input type="hidden" name="history_page" value="1">
                    </form>
                </div>
            </div>
            
            <!-- Data Card for History -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><?php echo t('repair_history'); ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($history_search_query) || $history_location_filter || $history_category_filter || $history_action_type_filter || $history_month_filter || $history_year_filter): ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> 
                            <?php echo t('showing_filtered_results'); ?>
                            <?php if (!empty($history_search_query)): ?>
                                <span class="badge bg-secondary"><?php echo t('search'); ?>: <?php echo htmlspecialchars($history_search_query); ?></span>
                            <?php endif; ?>
                            <?php if ($history_location_filter): ?>
                                <?php 
                                $location_name = '';
                                foreach ($all_locations as $location) {
                                    if ($location['id'] == $history_location_filter) {
                                        $location_name = $location['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="badge bg-secondary"><?php echo t('location'); ?>: <?php echo $location_name; ?></span>
                            <?php endif; ?>
                            <?php if ($history_category_filter): ?>
                                <?php 
                                $category_name = '';
                                foreach ($all_categories as $category) {
                                    if ($category['id'] == $history_category_filter) {
                                        $category_name = $category['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="badge bg-secondary"><?php echo t('category'); ?>: <?php echo $category_name; ?></span>
                            <?php endif; ?>
                            <?php if ($history_action_type_filter): ?>
                                <span class="badge bg-secondary"><?php echo t('action'); ?>: <?php echo $history_action_type_filter; ?></span>
                            <?php endif; ?>
                            <?php if ($history_month_filter && $history_month_filter != 0): ?>
                                <span class="badge bg-secondary"><?php echo t('month'); ?>: <?php echo date('F', mktime(0, 0, 0, $history_month_filter, 1)); ?></span>
                            <?php endif; ?>
                            <?php if ($history_year_filter && $history_year_filter != 0): ?>
                                <span class="badge bg-secondary"><?php echo t('year'); ?>: <?php echo $history_year_filter; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
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
                                    <th><?php echo t('item_qty'); ?></th>
                                    <th><?php echo t('action'); ?></th>
                                    <th><?php echo t('unit'); ?></th>
                                    <th><?php echo t('from_location'); ?></th>
                                    <th><?php echo t('to_location'); ?></th>
                                    <th><?php echo t('item_remark'); ?></th>
                                    <th><?php echo t('item_photo'); ?></th>
                                    <th><?php echo t('action_by'); ?></th>
                                    <th><?php echo t('action_at'); ?></th>
                                    <th><?php echo t('history_action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_items)): ?>
                                    <tr>
                                        <td colspan="16" class="text-center"><?php echo t('no_repair_history'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($history_items as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1 + $history_offset; ?></td>
                                            <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                            <td><?php echo $item['invoice_no']; ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                            <td><?php echo $item['item_name']; ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>
                                                <span class="repair-badge badge-<?php echo str_replace('_', '-', $item['action_type']); ?>">
                                                <?php 
                                                    if ($item['action_type'] == 'send_for_repair') {
                                                        echo t('send_for_repair');
                                                    } elseif ($item['action_type'] == 'return_from_repair') {
                                                        echo t('return_back');
                                                    } else {
                                                        echo t('repair_complete');
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo $item['size']; ?></td>
                                            <td><?php echo $item['from_location_name']; ?></td>
                                            <td><?php echo $item['to_location_name']; ?></td>
                                            <td><?php echo $item['remark']; ?></td>
                                            <td>
                                                <?php if ($item['image_id']): ?>
                                                    <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                                         class="img-thumbnail" width="50"
                                                         data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                                         data-item-id="<?php echo $item['id']; ?>">
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $item['action_by_name']; ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($item['history_action_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $item['history_action'] == 'created' ? 'success' : 
                                                         ($item['history_action'] == 'updated' ? 'warning' : ''); 
                                                ?>">
                                                    <?php echo t($item['history_action']); ?>
                                                </span>
                                                <small class="text-muted d-block"><?php echo date('d/m/Y H:i', strtotime($item['history_action_at'])); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination for History -->
                    <?php if ($history_total_pages >= 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php if ($history_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['history_page' => 1, 'tab' => 'history'])); ?>" aria-label="First">
                                            <span aria-hidden="true">&laquo;&laquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['history_page' => $history_page - 1, 'tab' => 'history'])); ?>" aria-label="Previous">
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
                                $start_page = max(1, $history_page - 2);
                                $end_page = min($history_total_pages, $history_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<li class="page-item"><span class="page-link">...</span></li>';
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $history_page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['history_page' => $i, 'tab' => 'history'])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor;
                                
                                if ($end_page < $history_total_pages) {
                                    echo '<li class="page-item"><span class="page-link">...</span></li>';
                                }
                                ?>

                                <?php if ($history_page < $history_total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['history_page' => $history_page + 1, 'tab' => 'history'])); ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['history_page' => $history_total_pages, 'tab' => 'history'])); ?>" aria-label="Last">
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
                            <?php echo t('page'); ?> <?php echo $history_page; ?> <?php echo t('page_of'); ?> <?php echo $history_total_pages; ?> 
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Send for Repair Modal -->
<div class="modal fade" id="sendForRepairModal" tabindex="-1" aria-labelledby="sendForRepairModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="sendForRepairModalLabel"><?php echo t('send_for_repair'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="send_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="send_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="send_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="send_from_location_id" class="form-label"><?php echo t('from_location'); ?></label>
                            <select class="form-select" id="send_from_location_id" name="from_location_id" required>
                                <option value=""><?php echo t('select_location'); ?></option>
                                <?php foreach ($non_repair_locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="send_to_location_id" class="form-label"><?php echo t('to_location'); ?></label>
                            <select class="form-select" id="send_to_location_id" name="to_location_id" required>
                                <option value=""><?php echo t('select_location'); ?></option>
                                <?php foreach ($repair_locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="send_repair_items_container">
                        <!-- First item row -->
                        <div class="send-repair-item-row mb-3 border p-3">
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
                                                    <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search'); ?>...">
                                                </div>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <div class="dropdown-item-container">
                                                <div class="px-2 py-1 text-muted"><?php echo t('select_location_first'); ?></div>
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
                                    <label class="form-label"><?php echo t('unit'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="send-repair-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_transfer_row'); ?>
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="send_for_repair" class="btn btn-warning"><?php echo t('send'); ?></button>
                </div>
            </form>
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
<!-- Return from Repair Modal -->
<div class="modal fade" id="returnFromRepairModal" tabindex="-1" aria-labelledby="returnFromRepairModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="returnFromRepairModalLabel"><?php echo t('return_back'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo t('item_invoice'); ?></label>
                            <input type="text" class="form-control" name="invoice_no" id="return_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="return_date" class="form-label"><?php echo t('item_date'); ?></label>
                            <input type="date" class="form-control" id="return_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="return_from_location_id" class="form-label"><?php echo t('from_location'); ?></label>
                            <select class="form-select" id="return_from_location_id" name="from_location_id" required>
                                <option value=""><?php echo t('select_location'); ?></option>
                                <?php foreach ($repair_locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="return_to_location_id" class="form-label"><?php echo t('to_location'); ?></label>
                            <select class="form-select" id="return_to_location_id" name="to_location_id" required>
                                <option value=""><?php echo t('select_location'); ?></option>
                                <?php foreach ($non_repair_locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="return_repair_items_container">
                        <!-- First item row -->
                        <div class="return-repair-item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label"><?php echo t('item_name'); ?></label>
                                    <div class="dropdown item-dropdown">
                                        <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <?php echo t('select_item'); ?>
                                        </button>
                                        <input type="hidden" name="repair_item_id[]" class="repair-item-id-input" value="">
                                        <ul class="dropdown-menu custom-dropdown-menu p-2">
                                            <li>
                                                <div class="px-2 mb-2">
                                                    <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search'); ?>...">
                                                </div>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <div class="dropdown-item-container">
                                                <div class="px-2 py-1 text-muted"><?php echo t('select_location_first'); ?></div>
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
                                    <label class="form-label"><?php echo t('unit'); ?></label>
                                    <input type="text" class="form-control" name="size[]" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                  
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close'); ?></button>
                    <button type="submit" name="return_from_repair" class="btn btn-success"><?php echo t('return_button'); ?></button>
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
                <h4 class="text-danger mb-3"><?php echo t('confirm_delete_question'); ?></h4>
                <div id="deleteItemInfo" class="mb-3"></div>
                <p><?php echo t('delete_warning'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="delete_id" id="delete_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php echo t('form_close'); ?>
                    </button>
                    <button type="submit" name="delete_repair_item" class="btn btn-danger">
                        <i class="bi bi-trash"></i> <?php echo t('delete_button'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Image Gallery Modal (same as in items.php) -->
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
document.addEventListener('DOMContentLoaded', function() {
    // Handle entries per page change for current repairs
    const currentPerPageSelect = document.getElementById('current_per_page_select');
    if (currentPerPageSelect) {
        currentPerPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('current_per_page', this.value);
            url.searchParams.set('page', '1'); // Reset to first page
            url.searchParams.set('tab', 'current'); // Keep on current tab
            window.location.href = url.toString();
        });
    }
    // Handle entries per page change for repair history
    const historyPerPageSelect = document.getElementById('history_per_page_select');
    if (historyPerPageSelect) {
        historyPerPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('history_per_page', this.value);
            url.searchParams.set('history_page', '1'); // Reset to first page
            url.searchParams.set('tab', 'history'); // Keep on history tab
            window.location.href = url.toString();
        });
    }
});
// Store items by location data
const itemsByLocation = <?php echo json_encode($items_by_location); ?>;
const itemsInRepair = <?php echo json_encode($items_in_repair); ?>;

// Function to populate item dropdown
function populateItemDropdown(dropdownElement, locationId, isRepair = false) {
    const dropdownMenu = dropdownElement.querySelector('.dropdown-menu');
    const itemContainer = dropdownElement.querySelector('.dropdown-item-container');
    const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
    const hiddenInput = dropdownElement.querySelector(isRepair ? '.repair-item-id-input' : '.item-id-input');
    
    // Clear previous items
    itemContainer.innerHTML = '';
    
    if (!locationId) {
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('select_location_first'); ?></div>';
        return;
    }
    
    const items = isRepair ? 
        (itemsInRepair[locationId] || []) : 
        (itemsByLocation[locationId] || []);
    
    if (items.length > 0) {
        // Store the original items for this location
        const originalItems = items;
        
        // Function to render items based on search term
        const renderItems = (searchTerm = '') => {
            itemContainer.innerHTML = '';
            let hasVisibleItems = false;
            
            originalItems.forEach(item => {
                const itemText = `${isRepair ? item.item_name : item.name} (${item.quantity} ${item.size || ''})`.trim().toLowerCase();
                
                if (!searchTerm || itemText.includes(searchTerm.toLowerCase())) {
                    const itemElement = document.createElement('button');
                    itemElement.className = 'dropdown-item';
                    itemElement.type = 'button';
                    itemElement.textContent = `${isRepair ? item.item_name : item.name} (${item.quantity} ${item.size || ''})`.trim();
                    itemElement.dataset.id = item.id;
                    itemElement.dataset.quantity = item.quantity;
                    itemElement.dataset.size = item.size || '';
                    
                    itemElement.addEventListener('click', function() {
                        dropdownToggle.textContent = this.textContent;
                        hiddenInput.value = this.dataset.id;
                        
                        const row = dropdownElement.closest(isRepair ? '.return-repair-item-row' : '.send-repair-item-row');
                        const sizeInput = row.querySelector('input[name="size[]"]');
                        const quantityInput = row.querySelector('input[name="quantity[]"]');
                        
                        if (sizeInput) sizeInput.value = this.dataset.size;
                        if (quantityInput) {
                            quantityInput.value = '';
                            quantityInput.max = this.dataset.quantity;
                        }
                    });
                    
                    itemContainer.appendChild(itemElement);
                    hasVisibleItems = true;
                }
            });
            
            if (!hasVisibleItems) {
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_items_available'); ?></div>';
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
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted"><?php echo t('no_items_available'); ?></div>';
    }
}

// Handle location change for send repair modal
document.getElementById('send_from_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#send_repair_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId);
    });
});

// Handle add more row for send repair modal
document.getElementById('send-repair-more-row').addEventListener('click', function() {
    const container = document.getElementById('send_repair_items_container');
    const locationId = document.getElementById('send_from_location_id').value;
    const rowCount = container.querySelectorAll('.send-repair-item-row').length;
    
    if (!locationId) {
        const locationAlertModal = new bootstrap.Modal(document.getElementById('selectLocationModal'));
        locationAlertModal.show();
        return;
    }
    
    const newRow = document.createElement('div');
    newRow.className = 'send-repair-item-row mb-3 border p-3';
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
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="<?php echo t('search'); ?>...">
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
                <label class="form-label"><?php echo t('unit'); ?></label>
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

// Handle location change for return repair modal
document.getElementById('return_from_location_id').addEventListener('change', function() {
    const locationId = this.value;
    const dropdowns = document.querySelectorAll('#return_repair_items_container .item-dropdown');
    
    dropdowns.forEach(dropdown => {
        populateItemDropdown(dropdown, locationId, true);
    });
});



// Set today's date when modals are shown
document.getElementById('sendForRepairModal').addEventListener('shown.bs.modal', function() {
    document.getElementById('send_date').valueAsDate = new Date();
});

document.getElementById('returnFromRepairModal').addEventListener('shown.bs.modal', function() {
    document.getElementById('return_date').valueAsDate = new Date();
});

// Delete confirmation
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      
        const name = this.getAttribute('data-name');
        
       
        document.getElementById('deleteItemInfo').innerHTML = `
            <p><strong><?php echo t('item_name'); ?>:</strong> ${name}</p>
        
        `;
        
        const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        modal.show();
    });
});

// Quick return button
document.querySelectorAll('.return-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.getAttribute('data-id');
        const name = this.getAttribute('data-name');
        const quantity = this.getAttribute('data-quantity');
        const size = this.getAttribute('data-size');
        const fromLocation = this.getAttribute('data-from-location');
        
        // Set the modal values
        document.getElementById('return_invoice_no').value = '';
        document.getElementById('return_date').valueAsDate = new Date();
        document.getElementById('return_from_location_id').value = '';
        document.getElementById('return_to_location_id').value = fromLocation;
        
        // Clear and add one row with the item
        const container = document.getElementById('return_repair_items_container');
        container.innerHTML = '';
        
        const newRow = document.createElement('div');
        newRow.className = 'return-repair-item-row mb-3 border p-3';
        newRow.innerHTML = `
            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label"><?php echo t('item_name'); ?></label>
                    <input type="text" class="form-control" value="${name} (${quantity} ${size})" readonly>
                    <input type="hidden" name="repair_item_id[]" value="${id}">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label"><?php echo t('item_qty'); ?></label>
                    <input type="number" class="form-control" name="quantity[]" value="${quantity}" step="0.5" min="0.5" max="${quantity}" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo t('unit'); ?></label>
                    <input type="text" class="form-control" name="size[]" value="${size}" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo t('item_remark'); ?></label>
                    <input type="text" class="form-control" name="remark[]">
                </div>
            </div>
        `;
        
        container.appendChild(newRow);
        
        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('returnFromRepairModal'));
        modal.show();
    });
});

// Image gallery functionality (same as in items.php)
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
</script>

<?php
require_once '../includes/footer.php';
?>