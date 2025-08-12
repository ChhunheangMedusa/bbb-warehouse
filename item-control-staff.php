<?php
ob_start();

// Includes in correct order
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/header-staff.php';
if (!isStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard.php');
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
                $location_id = (int)$_POST['location_id'][$index];
                $name = sanitizeInput($name);
                $quantity = (float)$_POST['quantity'][$index];
                $alert_quantity = (int)$_POST['alert_quantity'][$index];
                $size = sanitizeInput($_POST['size'][$index]);
                $remark = sanitizeInput($_POST['remark'][$index]);

                $stmt = $pdo->prepare("SELECT id FROM items WHERE name = ? AND location_id = ?");
            $stmt->execute([$name, $location_id]);
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateItemModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("ទំនិញមានឈ្មោះដូចគ្នានៅទីតាំងនេះរួចហើយ");
            }
                // Insert the item into the database
                $stmt = $pdo->prepare("INSERT INTO items (invoice_no, date, name, quantity, alert_quantity, size, location_id, remark) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$invoice_no, $date, $name, $quantity, $alert_quantity, $size, $location_id, $remark]);
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
            
            $_SESSION['success'] = "ទំនិញថ្មីត្រូវបានបន្ថែមដោយជោគជ័យ។";
            redirect('item-control-staff.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['error'] = "ទំនិញមានឈ្មោះដូចគ្នានៅទីតាំងនេះរួចហើយ។";
            } else {
                $_SESSION['error'] = "មានបញ្ហាក្នុងការបន្ថែមទំនិញ។";
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
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log each item update
                logActivity($_SESSION['user_id'], 'Stock In', "Increase stock: $item_name($quantity $size) at {$location['name']} (Total: $old_qty+$quantity=$new_qty)");
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "បរិមាណទំនិញត្រូវបានបន្ថែមដោយជោគជ័យ។";
            redirect('item-control-staff.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "មានបញ្ហាក្នុងការបន្ថែមបរិមាណទំនិញ។";
        }
    } 
    
    elseif (isset($_POST['deduct_qty'])) {
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
                
                if ($quantity > $old_qty) {
                    throw new Exception("បរិមាណដកចេញលើសពីបរិមាណស្តុកសម្រាប់ទំនិញ: $item_name");
                }
                
                // Update quantity
                $new_qty = $old_qty - $quantity;
                $stmt = $pdo->prepare("UPDATE items SET quantity = ?, invoice_no = ?, date = ?, remark = ? WHERE id = ?");
                $stmt->execute([$new_qty, $invoice_no, $date, $remark, $item_id]);
                
                // Get location name for log
                $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
                $stmt->execute([$location_id]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Log each item update
                logActivity($_SESSION['user_id'], 'Stock Out', "Decrease stock: $item_name ($quantity $size) at {$location['name']} (Total: $old_qty-$quantity=$new_qty)");
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "បរិមាណទំនិញត្រូវបានដកចេញដោយជោគជ័យ។";
            redirect('item-control-staff.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "មានបញ្ហាក្នុងការដកបរិមាណទំនិញ។";
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
    // In the edit_item section of your PHP code
    elseif (isset($_POST['edit_item'])) {
        // Edit item
        $id = (int)$_POST['id'];
        $invoice_no = sanitizeInput($_POST['invoice_no']);
        $date = sanitizeInput($_POST['date']);
        $name = sanitizeInput($_POST['name']);
        $quantity = (float)$_POST['quantity'];
        $size = sanitizeInput($_POST['size']);
        $location_id = (int)$_POST['location_id'];
        $remark = sanitizeInput($_POST['remark']);
        
        try {
            $pdo->beginTransaction();
            
            // Get old item data for logging
            $stmt = $pdo->prepare("SELECT i.*, l.name as location_name 
                                  FROM items i 
                                  JOIN locations l ON i.location_id = l.id 
                                  WHERE i.id = ?");
            $stmt->execute([$id]);
            $old_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get old images
            $stmt = $pdo->prepare("SELECT * FROM item_images WHERE item_id = ?");
            $stmt->execute([$id]);
            $old_images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
    // In the edit_item section, replace the image handling with this:
    if (!empty($_FILES['images']['name'][0])) {
        try {
            // Delete all old image records first
            $stmt = $pdo->prepare("DELETE FROM item_images WHERE item_id = ?");
            $stmt->execute([$id]);
            
            // Handle new image uploads
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    // Validate and process image (same as add item)
                    $imageData = file_get_contents($tmp_name);
                    $stmt = $pdo->prepare("INSERT INTO item_images (item_id, image_path) VALUES (?, ?)");
                    $stmt->execute([$id, $imageData]);
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Image upload failed: " . $e->getMessage();
            redirect('item-control-staff.php');
        }
    }
            // Get new location name for comparison
            $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
            $stmt->execute([$location_id]);
            $new_location = $stmt->fetch(PDO::FETCH_ASSOC);
    
            // Update item details
            $stmt = $pdo->prepare("UPDATE items SET invoice_no=?, date=?, name=?, quantity=?, size=?, location_id=?, remark=? WHERE id=?");
            $stmt->execute([$invoice_no, $date, $name, $quantity, $size, $location_id, $remark, $id]);
    
   
          
            
            $pdo->commit();
            
            // Prepare log message with changes
            $log_message = "";
            $changes = [];
            
            if ($old_item['invoice_no'] != $invoice_no) {
                $old_invoice = $old_item['invoice_no'] ?: 'N/A';
                $new_invoice = $invoice_no ?: 'N/A';
                $changes[] = "Updated item invoice ($name) : $old_invoice → $new_invoice";
            }
            if ($old_item['date'] != $date) {
                $changes[] = "Updated item date ($name) : {$old_item['date']} → $date";
            }
            if ($old_item['name'] != $name) {
                $changes[] = "Updated item name ({$old_item['name']}) : {$old_item['name']} → $name";
            }
            if ($old_item['quantity'] != $quantity) {
                $changes[] = "Updated item quantity ({$old_item['name']}) : {$old_item['quantity']} → $quantity";
            }
            if ($old_item['size'] != $size) {
                $old_size = $old_item['size'] ?: 'N/A';
                $new_size = $size ?: 'N/A';
                $changes[] = "Updated item size ($name) : $old_size → $new_size";
            }
            if ($old_item['location_id'] != $location_id) {
                $changes[] = "Updated item location ($name) : {$old_item['location_name']} → {$new_location['name']}";
            }
            if ($old_item['remark'] != $remark) {
                $old_remark = $old_item['remark'] ?: 'N/A';
                $new_remark = $remark ?: 'N/A';
                $changes[] = "Updated item remark ($name) : $old_remark → $new_remark";
            }
            
            if (!empty($_POST['delete_images'])) {
                $changes[] = "Deleted " . count($_POST['delete_images']) . " images";
            }
            
            if (!empty($_FILES['images']['name'][0])) {
                $changes[] = "Updated item ({$old_item['name']})  " .  " new images";
            }
            
            if (empty($changes)) {
                $log_message .= "No changes detected";
            } else {
                $log_message .= implode(', ', $changes);
            }
            
            $_SESSION['success'] = "ទំនិញត្រូវបានកែប្រែដោយជោគជ័យ។";
            logActivity($_SESSION['user_id'], 'Edit Item', $log_message);
            
            redirect('item-control-staff.php');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "មានបញ្ហាក្នុងការកែប្រែទំនិញ: " . $e->getMessage();
        }
    }
    }


// Handle delete request
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // Get item info for log
        $stmt = $pdo->prepare("SELECT i.name,i.quantity, l.name as location 
                              FROM items i 
                              JOIN locations l ON i.location_id = l.id 
                              WHERE i.id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            // Delete item images first
            $stmt = $pdo->prepare("DELETE FROM item_images WHERE item_id = ?");
            $stmt->execute([$id]);

            // Delete from addnewitems table
            $stmt = $pdo->prepare("DELETE FROM addnewitems WHERE item_id = ?");
            $stmt->execute([$id]);
            
            // Delete from addqtyitems table
            $stmt = $pdo->prepare("DELETE FROM addqtyitems WHERE item_id = ?");
            $stmt->execute([$id]);
            
            // Then delete the item from main table
            $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
            $stmt->execute([$id]);
            
            logActivity($_SESSION['user_id'], 'Delete Item', "Removed item: {$item['name']} ({$item['quantity']}) from {$item['location']} ");
            $_SESSION['success'] = "ទំនិញត្រូវបានលុបដោយជោគជ័យ។";
        } else {
            $_SESSION['error'] = "រកមិនឃើញទំនិញ។";
        }
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "មានបញ្ហាក្នុងការលុបទំនិញ។";
    }
    
    redirect('item-control-staff.php');
}

// Get filter parameters
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$month_filter = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year_filter = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query for items
$query = "SELECT i.*, l.name as location_name 
          FROM items i 
          JOIN locations l ON i.location_id = l.id 
          WHERE YEAR(i.date) = :year";
$params = [':year' => $year_filter];

// Add month filter if not "All Months"
if ($month_filter && $month_filter != 0) {
    $query .= " AND MONTH(i.date) = :month";
    $params[':month'] = $month_filter;
}


if ($location_filter) {
    $query .= " AND i.location_id = :location_id";
    $params[':location_id'] = $location_filter;
}

if ($search_query) {
    $query .= " AND (i.name LIKE :search OR i.invoice_no LIKE :search OR i.remark LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$query .= " ORDER BY i.date DESC, i.created_at DESC";

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$stmt = $pdo->prepare(str_replace('SELECT i.*, l.name as location_name', 'SELECT COUNT(*) as total', $query));
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get items with pagination
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
</style>

<div class="container-fluid">
    <h2 class="mb-4">គ្រប់គ្រងទំនិញ</h2>
    
    <div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <h5 class="mb-0">បញ្ជីទំនិញ</h5>
    <div>
        <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-circle"></i> បន្ថែមទំនិញថ្មី
        </button>
        <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addQtyModal">
            <i class="bi bi-plus-lg"></i> បន្ថែមបរិមាណ
        </button>
        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#deductQtyModal">
            <i class="bi bi-dash-lg"></i> បន្ថយបរិមាណ
        </button>
    </div>
</div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-8">
                    <form method="GET" class="row g-2">
                    <div class="col-md-2">
                            <input type="text" name="search" class="form-control" placeholder="ស្វែងរក..." value="<?php echo $search_query; ?>">
</div>

                        <div class="col-md-2">
                            <select name="location" class="form-select">
                                <option value="">ទីតាំងទាំងអស់</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>" <?php echo $location_filter == $location['id'] ? 'selected' : ''; ?>>
                                        <?php echo $location['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
    <select name="month" class="form-select">
        <option value="0" <?php echo $month_filter == 0 ? 'selected' : ''; ?>>ខែទាំងអស់</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
            </option>
        <?php endfor; ?>
    </select>
</div>
                        <div class="col-md-2">
                            <select name="year" class="form-select">
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
            <a href="item-control-staff.php" class="btn btn-danger w-100">Reset</a>
        </div>
                    </form>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ល.រ</th>
                            <th>លេខវិក័យប័ត្រ</th>
                            <th>កាលបរិច្ឆេទ</th>
                            <th>ឈ្មោះទំនិញ</th>
                            <th>បរិមាណ</th>
                            <th>ទំហំ</th>
                            <th>ទីតាំង</th>
                            <th>ផ្សេងៗ</th>
                            <th>រូបភាព</th>
                            <th>សកម្មភាព</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="10" class="text-center">មិនមានទំនិញ</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $item['invoice_no']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                    <td><?php echo $item['name']; ?></td>
                                    <td class="<?php echo $item['quantity'] <= $item['alert_quantity'] ? 'text-danger fw-bold' : ''; ?>">
    <?php echo $item['quantity']; ?>
    <?php if ($item['quantity'] <= $item['alert_quantity']): ?>
        <span class="badge bg-danger">ស្តុកទាប</span>
    <?php endif; ?>
</td>
                                    <td><?php echo $item['size']; ?></td>
                                    <td><?php echo $item['location_name']; ?></td>
                                    <td><?php echo $item['remark']; ?></td>

                 

                                    <td>
    <?php 
    $stmt = $pdo->prepare("SELECT id FROM item_images WHERE item_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$item['id']]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($image): ?>
        <img src="display_image.php?id=<?php echo $image['id']; ?>" 
             alt="<?php echo htmlspecialchars($item['name']); ?>" 
             class="img-thumbnail" 
             width="50"
             data-bs-toggle="modal" 
             data-bs-target="#imageGalleryModal"
             data-item-id="<?php echo $item['id']; ?>">
    <?php else: ?>
        <span class="badge bg-secondary">No image</span>
    <?php endif; ?>
</td>
                                    <td>
                                    <button class="btn btn-sm btn-info view-item" 
            data-id="<?php echo $item['id']; ?>">
        <i class="bi bi-eye"></i> មើល
    </button>
                                        <button class="btn btn-sm btn-warning edit-item" 
                                                data-id="<?php echo $item['id']; ?>"
                                                data-invoice_no="<?php echo $item['invoice_no']; ?>"
                                                data-date="<?php echo $item['date']; ?>"
                                                data-name="<?php echo $item['name']; ?>"
                                                data-quantity="<?php echo $item['quantity']; ?>"
                                                data-size="<?php echo $item['size']; ?>"
                                                data-location_id="<?php echo $item['location_id']; ?>"
                                                data-remark="<?php echo $item['remark']; ?>">
                                            <i class="bi bi-pencil"></i> កែប្រែ
                                        </button>
                                        <?php if (isAdmin()): ?>
                                        <a href="#" class="btn btn-sm btn-danger delete-item" 
   data-id="<?php echo $item['id']; ?>"
   data-name="<?php echo htmlspecialchars($item['name']); ?>"
   data-location="<?php echo htmlspecialchars($item['location_name']); ?>">
    <i class="bi bi-trash"></i> លុប
</a><?php endif?>
                                    </td>
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
        ទំព័រ <?php echo $page; ?> នៃ <?php echo $total_pages; ?> 
    </div>
<?php endif; ?>
<!-- View Item Modal -->
<div class="modal fade" id="viewItemModal" tabindex="-1" aria-labelledby="viewItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewItemModalLabel">ព័ត៌មានលម្អិតអំពីទំនិញ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th width="40%">លេខវិក័យប័ត្រ</th>
                                <td id="view_invoice_no"></td>
                            </tr>
                            <tr>
                                <th>កាលបរិច្ឆេទ</th>
                                <td id="view_date"></td>
                            </tr>
                            <tr>
                                <th>ឈ្មោះទំនិញ</th>
                                <td id="view_name"></td>
                            </tr>
                            <tr>
                                <th>បរិមាណ</th>
                                <td id="view_quantity"></td>
                            </tr>
                            <tr>
                                <th>ទំហំ</th>
                                <td id="view_size"></td>
                            </tr>
                            <tr>
                                <th>ទីតាំង</th>
                                <td id="view_location"></td>
                            </tr>
                            <tr>
                                <th>ផ្សេងៗ</th>
                                <td id="view_remark"></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>រូបភាព</h5>
                        <div class="row g-2" id="view_images">
                            <!-- Images will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">បិទ</button>
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
                    <h5 class="modal-title" id="addItemModalLabel">បន្ថែមទំនិញថ្មី</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">លេខវិក័យប័ត្រ (សម្រាប់ទំនិញទាំងអស់)</label>
                            <input type="text" class="form-control" name="invoice_no" id="main_invoice_no">
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label">កាលបរិច្ឆេទ</label>
                            <input type="date" class="form-control" id="date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Items container -->
                    <div id="items-container">
                        <!-- First item row -->
                        <div class="item-row mb-3 border p-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="location_id" class="form-label">ទីតាំង</label>
                                    <select class="form-select" id="location_id" name="location_id[]" required>
                                        <option value="">ជ្រើសរើសទីតាំង</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ឈ្មោះទំនិញ</label>
                                    <input type="text" class="form-control" name="name[]" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">បរិមាណ</label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
                                </div>
                                <div class="col-md-4">
                                <label class="form-label">ស្តុកទាប</label>
                                 <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10" required>
                                 </div>
                                <div class="col-md-6">
                                    <label class="form-label">ទំហំ</label>
                                    <input type="text" class="form-control" name="size[]">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-10 mb-3">
                                    <label class="form-label">ផ្សេងៗ</label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                                
                            </div>
                            <div class="row">
                            <div class="col-md-2 mb-3">
                                    <label class="form-label">រូបភាព</label>
                                    <input type="file" class="form-control" name="images[0][]" multiple accept="image/*">
                                    <div class="image-preview-container mt-2 row g-1" id="image-preview-0"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> បន្ថែមជួរថ្មី
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">បិទ</button>
                    <button type="submit" name="add_item" class="btn btn-primary">រក្សាទុក</button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> បញ្ជាក់ការលុប
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3">តើអ្នកពិតជាចង់លុបទំនិញនេះមែនទេ?</h4>
                <p>ការលុបនេះមិនអាចត្រឡប់វិញបានទេ។</p>
                <div id="deleteItemInfo" class="alert alert-light mt-3"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> បោះបង់
                </button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> លុប
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editItemModalLabel">កែប្រែទំនិញ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_invoice_no" class="form-label">លេខវិក័យប័ត្រ</label>
                            <input type="text" class="form-control" id="edit_invoice_no" name="invoice_no" >
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_date" class="form-label">កាលបរិច្ឆេទ</label>
                            <input type="date" class="form-control" id="edit_date" name="date" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">ឈ្មោះទំនិញ</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="edit_quantity" class="form-label">បរិមាណ</label>
                            <input type="number" class="form-control" id="edit_quantity" name="quantity" step="0.5" min="0.5" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="edit_size" class="form-label">ទំហំ</label>
                            <input type="text" class="form-control" id="edit_size" name="size">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_location_id" class="form-label">ទីតាំង</label>
                            <select class="form-select" id="edit_location_id" name="location_id" required>
                                <option value="">ជ្រើសរើសទីតាំង</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo $location['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_remark" class="form-label">ផ្សេងៗ</label>
                            <input type="text" class="form-control" id="edit_remark" name="remark">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_images" class="form-label">រូបភាព</label>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> ការផ្ទុករូបថ្មីនឹងលុបរូបចាស់ទាំងអស់។
                        </div>
                        <input type="file" class="form-control" id="edit_images" name="images[]" multiple accept="image/*">
                        <small class="text-muted">អាចជ្រើសរើសច្រើនរូប</small>
                        <div class="mt-3 row" id="current_images">
                            <!-- Current images will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">បិទ</button>
                    <button type="submit" name="edit_item" class="btn btn-warning">រក្សាទុកការកែប្រែ</button>
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
                    <h5 class="modal-title" id="addQtyModalLabel">បន្ថែមបរិមាណទំនិញ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">លេខវិក័យប័ត្រ (សម្រាប់ទំនិញទាំងអស់)</label>
                            <input type="text" class="form-control" name="invoice_no" id="add_invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="add_date" class="form-label">កាលបរិច្ឆេទ</label>
                            <input type="date" class="form-control" id="add_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="add_location_id" class="form-label">ទីតាំង</label>
                        <select class="form-select" id="add_location_id" name="location_id" required>
                            <option value="">ជ្រើសរើសទីតាំង</option>
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
                                        <label class="form-label">ឈ្មោះទំនិញ</label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                ជ្រើសរើសទំនិញ
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="ស្វែងរកទំនិញ...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted">សូមជ្រើសរើសទីតាំងជាមុនសិន</div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>

        <div class="col-md-4 mb-3">
            <label class="form-label">បរិមាណ</label>
            <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">ទំហំ</label>
            <input type="text" class="form-control" name="size[]"readonly>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">ផ្សេងៗ</label>
            <input type="text" class="form-control" name="remark[]">
        </div>
    </div>
</div>
                    </div>
                    
                    <button type="button" id="add-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> បន្ថែមជួរថ្មី
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">បិទ</button>
                    <button type="submit" name="add_qty" class="btn btn-success">បន្ថែម</button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> បញ្ហាបរិមាណ
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-cart-x-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3">មិនអាចដកចេញលើសពីបរិមាណស្តុក!</h4>
                <p id="quantityExceedMessage">អ្នកមិនអាចដកចេញលើសពីបរិមាណដែលមានស្តុកបានទេ។</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> យល់ព្រម
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
                    <h5 class="modal-title" id="deductQtyModalLabel">បន្ថយបរិមាណទំនិញ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Common fields (invoice and date) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="deduct_invoice_no" class="form-label">លេខវិក័យប័ត្រ</label>
                            <input type="text" class="form-control" id="deduct_invoice_no" name="invoice_no" >
                        </div>
                        <div class="col-md-6">
                            <label for="deduct_date" class="form-label">កាលបរិច្ឆេទ</label>
                            <input type="date" class="form-control" id="deduct_date" name="date" required>
                        </div>
                    </div>
                    
                    <!-- Location selection -->
                    <div class="mb-3">
                        <label for="deduct_location_id" class="form-label">ទីតាំង</label>
                        <select class="form-select" id="deduct_location_id" name="location_id" required>
                            <option value="">ជ្រើសរើសទីតាំង</option>
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
                                        <label class="form-label">ឈ្មោះទំនិញ</label>
                                        <div class="dropdown item-dropdown">
                                            <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                ជ្រើសរើសទំនិញ
                                            </button>
                                            <input type="hidden" name="item_id[]" class="item-id-input" value="">
                                            <ul class="dropdown-menu custom-dropdown-menu p-2">
                                                <li>
                                                    <div class="px-2 mb-2">
                                                        <input type="text" class="form-control form-control-sm search-item-input" placeholder="ស្វែងរកទំនិញ...">
                                                    </div>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <div class="dropdown-item-container">
                                                    <div class="px-2 py-1 text-muted">សូមជ្រើសរើសទីតាំងជាមុនសិន</div>
                                                </div>
                                            </ul>
                                        </div>
                                    </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">បរិមាណ</label>
                                    <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ទំហំ</label>
                                    <input type="text" class="form-control" name="size[]"readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ផ្សេងៗ</label>
                                    <input type="text" class="form-control" name="remark[]">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="deduct-qty-more-row" class="btn btn-secondary btn-sm mb-3">
                        <i class="bi bi-plus-circle"></i> បន្ថែមជួរថ្មី
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">បិទ</button>
                    <button type="submit" name="deduct_qty" class="btn btn-danger">ដកចេញ</button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> មានបញ្ហា
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3">ទំនិញមានឈ្មោះដូចគ្នានៅទីតាំងនេះរួចហើយ!</h4>
                <p>សូមពិនិត្យឈ្មោះទំនិញ និងទីតាំងម្តងទៀត។</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> យល់ព្រម
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
                    <i class="bi bi-exclamation-triangle-fill"></i> ការជូនដំណឹង
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-geo-alt-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3">សូមជ្រើសរើសទីតាំងជាមុនសិន!</h4>
                <p>អ្នកត្រូវតែជ្រើសរើសទីតាំងមុនពេលបន្ត។</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> យល់ព្រម
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
                    <i class="bi bi-exclamation-triangle-fill"></i> ការបញ្ជាក់
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3">លុបរូបភាពនេះ?</h4>
                <p>អ្នកនឹងមិនអាចយកវាត្រឡប់មកវិញបានទេ។</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> បោះបង់
                </button>
                <button type="button" id="confirmImageDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> លុប
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
                    <i class="bi bi-exclamation-triangle-fill"></i> ការបញ្ជាក់
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-images text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-dark mb-3">ជំនួសរូបភាពចាស់?</h4>
                <p>រូបភាពចាស់នឹងត្រូវបានលុបជាអចិន្ត្រៃយ៍។ តើអ្នកពិតជាចង់បន្តមែនទេ?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> បោះបង់
                </button>
                <button type="button" id="confirmReplaceBtn" class="btn btn-warning">
                    <i class="bi bi-check-circle"></i> យល់ព្រម
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
                <h5 class="modal-title">រូបភាពទំនិញ</h5>
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
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted">សូមជ្រើសរើសទីតាំងជាមុនសិន</div>';
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
                itemContainer.innerHTML = '<div class="px-2 py-1 text-muted">រកមិនឃើញទំនិញ</div>';
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
        itemContainer.innerHTML = '<div class="px-2 py-1 text-muted">មិនមានទំនិញនៅទីតាំងនេះ</div>';
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

// Handle confirm delete button click
document.getElementById('confirmImageDeleteBtn').addEventListener('click', function() {
    if (pendingImageDelete && pendingImageElement) {
        // Create hidden input to mark image for deletion
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_images[]';
        deleteInput.value = pendingImageDelete;
        document.querySelector('#editItemModal form').appendChild(deleteInput);
        
        // Remove the image element
        pendingImageElement.remove();
        
        // Hide the modal
        const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteImageConfirmModal'));
        deleteModal.hide();
        
        // Reset variables
        pendingImageDelete = null;
        pendingImageElement = null;
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
            deleteUrl = `item-control-staff.php?delete=${itemId}`;
            
            // Update modal content
            document.getElementById('deleteItemInfo').innerHTML = `
                <strong>ឈ្មោះទំនិញ:</strong> ${itemName}<br>
                <strong>ទីតាំង:</strong> ${itemLocation}
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
            <div class="col-md-6">
                <label class="form-label">ទីតាំង</label>
                <select class="form-select" name="location_id[]" required>
                    <option value="">ជ្រើសរើសទីតាំង</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" ${locationId == <?php echo $location['id']; ?> ? 'selected' : ''}>
                            <?php echo $location['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">ឈ្មោះទំនិញ</label>
                <input type="text" class="form-control" name="name[]" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="form-label">បរិមាណ</label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" value="0" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">ស្តុកទាប</label>
                <input type="number" class="form-control" name="alert_quantity[]" min="0" value="10">
            </div>
            <div class="col-md-4">
                <label class="form-label">ទំហំ</label>
                <input type="text" class="form-control" name="size[]">
            </div>
        </div>
        <div class="row">
            <div class="col-md-10 mb-3">
                <label class="form-label">ផ្សេងៗ</label>
                <input type="text" class="form-control" name="remark[]">
            </div>
            
        </div>
        <div class="row">

        <div class="col-md-2 mb-3">
                <label class="form-label">រូបភាព</label>
                <input type="file" class="form-control item-images-input" name="images[${rowCount}][]" multiple accept="image/*">
                <div class="image-preview-container mt-2 row g-1" id="image-preview-${rowCount}"></div>
            </div>

            </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> លុបជួរ
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
    selectElement.innerHTML = '<option value="">ជ្រើសរើសទំនិញ</option>';
    
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
    selectElement.innerHTML = '<option value="">ជ្រើសរើសទំនិញ</option>';
    
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
                <label class="form-label">ឈ្មោះទំនិញ</label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        ជ្រើសរើសទំនិញ
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="ស្វែងរកទំនិញ...">
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
                <label class="form-label">បរិមាណ</label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">ទំហំ</label>
                <input type="text" class="form-control" name="size[]"readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">ផ្សេងៗ</label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> លុបជួរ
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
    selectElement.innerHTML = '<option value="">ជ្រើសរើសទំនិញ</option>';
    
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
                <label class="form-label">ឈ្មោះទំនិញ</label>
                <div class="dropdown item-dropdown">
                    <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        ជ្រើសរើសទំនិញ
                    </button>
                    <input type="hidden" name="item_id[]" class="item-id-input" value="">
                    <ul class="dropdown-menu custom-dropdown-menu p-2">
                        <li>
                            <div class="px-2 mb-2">
                                <input type="text" class="form-control form-control-sm search-item-input" placeholder="ស្វែងរកទំនិញ...">
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
                <label class="form-label">បរិមាណ</label>
                <input type="number" class="form-control" name="quantity[]" step="0.5" min="0.5" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">ទំហំ</label>
                <input type="text" class="form-control" name="size[]"readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">ផ្សេងៗ</label>
                <input type="text" class="form-control" name="remark[]">
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-sm remove-row">
            <i class="bi bi-trash"></i> លុបជួរ
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

// Handle edit item button click - fixed version
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-item').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const invoice_no = this.getAttribute('data-invoice_no');
            const date = this.getAttribute('data-date');
            const name = this.getAttribute('data-name');
            const quantity = this.getAttribute('data-quantity');
            const size = this.getAttribute('data-size');
            const location_id = this.getAttribute('data-location_id');
            const remark = this.getAttribute('data-remark');
            
            // Set basic form values
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_invoice_no').value = invoice_no;
            document.getElementById('edit_date').value = date;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_quantity').value = quantity;
            document.getElementById('edit_size').value = size;
            document.getElementById('edit_location_id').value = location_id;
            document.getElementById('edit_remark').value = remark || '';
            
            // Clear previous images
            const container = document.getElementById('current_images');
            container.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
            
            // Initialize the modal
            const editModal = new bootstrap.Modal(document.getElementById('editItemModal'));
            editModal.show();
            
            // Fetch and display images
            fetch(`get_item_images.php?id=${id}`)
                .then(response => response.json())
                .then(images => {
                    const container = document.getElementById('current_images');
                    container.innerHTML = '';
                    
                    if (images.length === 0) {
                        container.innerHTML = '<div class="col-12 text-muted">No images available</div>';
                        return;
                    }
                    
                    images.forEach(image => {
                        const col = document.createElement('div');
                        col.className = 'col-md-3 mb-3 position-relative';
                        col.style.width = '150px';
                        col.style.height = '150px';
                        
                        const img = document.createElement('img');
                        img.src = `display_image.php?id=${image.id}`;
                        img.className = 'img-thumbnail w-100 h-100';
                        img.style.objectFit = 'cover';
                        
                        const deleteBtn = document.createElement('button');
                        deleteBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-1';
                        deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
                        deleteBtn.title = 'Delete this image';
                        deleteBtn.dataset.imageId = image.id;
                        
                        deleteBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const imageId = this.getAttribute('data-image-id');
                            pendingImageDelete = imageId;
                            pendingImageElement = this.closest('.col-md-3');
                            
                            // Show the confirmation modal
                            const deleteModal = new bootstrap.Modal(document.getElementById('deleteImageConfirmModal'));
                            deleteModal.show();
                        });
                        
                        col.appendChild(img);
                        col.appendChild(deleteBtn);
                        container.appendChild(col);
                    });
                })
                .catch(error => {
                    console.error('Error fetching images:', error);
                    const container = document.getElementById('current_images');
                    container.innerHTML = '<div class="col-12 text-danger">Error loading images</div>';
                });
        });
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

document.getElementById('edit_images').addEventListener('change', function(e) {
    const previewContainer = document.getElementById('current_images');
    
    // Clear only previews (not existing images)
    const previews = previewContainer.querySelectorAll('img[src^="blob:"]');
    previews.forEach(preview => preview.parentNode.remove());
    
    // Check if there are existing images
    const existingImages = previewContainer.querySelectorAll('img:not([src^="blob:"])');
    
    if (existingImages.length > 0 && this.files.length > 0) {
        // Store references for later use
        pendingFileInput = this;
        pendingPreviewContainer = previewContainer;
        
        // Show confirmation modal
        const replaceModal = new bootstrap.Modal(document.getElementById('replaceImageConfirmModal'));
        replaceModal.show();
        
        // Don't proceed further yet - wait for user confirmation
        return;
    }
    
    // If no existing images, process immediately
    processNewImages(this, previewContainer);
});

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
    
    itemSelect.innerHTML = '<option value="">ជ្រើសរើសទំនិញ</option>';
    
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
    
    itemSelect.innerHTML = '<option value="">ជ្រើសរើសទំនិញ</option>';
    
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
// Handle view item button click
document.querySelectorAll('.view-item').forEach(button => {
    button.addEventListener('click', function() {
        const itemId = this.getAttribute('data-id');
        
        // Show loading state
        document.getElementById('view_images').innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
        
        // Fetch item details
        fetch(`get_item_details.php?id=${itemId}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                // Update basic info - access the 'item' property
                const item = data.item;
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
                    imagesContainer.innerHTML = '<div class="col-12 text-muted">មិនមានរូបភាព</div>';
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
    searchInput.placeholder = 'ស្វែងរក...';
    
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
                        អ្នកមិនអាចដកចេញលើសពី <strong>${max}</strong> (បរិមាណស្តុកបច្ចុប្បន្ន)<br>
                        អ្នកបានបញ្ចូល: <strong>${value}</strong>
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
require_once 'includes/footer.php';
?>