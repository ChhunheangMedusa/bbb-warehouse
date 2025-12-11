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
checkAdminAccess();



// Get filter parameters
$username_filter = isset($_GET['username']) ? sanitizeInput($_GET['username']) : '';
$type_filter = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$month_filter = isset($_GET['month']) ? sanitizeInput($_GET['month']) : '';
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'username_asc';

// Validate and parse sort option
// Update your sort mapping
$sort_mapping = [
    'username_asc' => ['field' => 'username', 'direction' => 'ASC'],
    'username_desc' => ['field' => 'username', 'direction' => 'DESC'],
    'type_asc' => ['field' => "CASE 
        WHEN user_type = 'admin' THEN 1 
        WHEN user_type = 'warehouse_staff' THEN 2 
        WHEN user_type = 'finance_staff' THEN 3 
        WHEN user_type = 'guest' THEN 4 
        ELSE 5 END", 'direction' => 'ASC'],
    'type_desc' => ['field' => "CASE 
        WHEN user_type = 'admin' THEN 1 
        WHEN user_type = 'warehouse_staff' THEN 2 
        WHEN user_type = 'finance_staff' THEN 3 
        WHEN user_type = 'guest' THEN 4 
        ELSE 5 END", 'direction' => 'DESC'],
    'date_asc' => ['field' => 'created_at', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'created_at', 'direction' => 'DESC']
];

// Default to username_asc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'username_asc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
  // Validate required fields
  $required = ['username', 'user_type'];
  foreach ($required as $field) {
      if (empty($_POST[$field])) {
          $_SESSION['error'] = "Please fill in all required fields";
          redirect('user-control.php');
          exit();
      }
  }

  $user_type = sanitizeInput($_POST['user_type']);
  
  // Only validate password if not guest
  if ($user_type !== 'guest') {
      if (empty($_POST['password'])) {
          $_SESSION['error'] = "Please fill in all required fields";
          redirect('user-control.php');
          exit();
      }

      // Check password match
      if ($_POST['password'] !== $_POST['confirm_password']) {
          $_SESSION['error'] = "Passwords do not match";
          redirect('user-control.php');
          exit();
      }
      
      $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
  } else {
      // For guest users, set a default password or leave it empty if your system allows
      $password = password_hash('guest123', PASSWORD_DEFAULT); // Example default password
  }

  $username = sanitizeInput($_POST['username']);
  $phone_number = $user_type !== 'guest' ? sanitizeInput($_POST['phone_number'] ?? '') : '';
  $email = $user_type !== 'guest' ? sanitizeInput($_POST['email']) : '';
  
  // Check for duplicate username
  $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
  $stmt->execute([$username]);
  if ($stmt->fetch()) {
      $_SESSION['error'] = "Username already exists";
      redirect('user-control.php');
      exit();
  }

  // Only check email if not guest
  if ($user_type !== 'guest') {
      // Check for duplicate email
      $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
          $_SESSION['error'] = "Email already exists";
          redirect('user-control.php');
          exit();
      }
  }

  // Handle file upload only if not guest
  $picture = null;
  if ($user_type !== 'guest' && isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['picture'];
      
      // Validate file type
      $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
      $file_type = mime_content_type($file['tmp_name']);
      
      if (!in_array($file_type, $allowed_types)) {
          $_SESSION['error'] = "Only image files are allowed (JPEG, PNG, GIF)";
          redirect('user-control.php');
          exit();
      }
      
      // Validate file size (e.g., 2MB max)
      if ($file['size'] > 2097152) {
          $_SESSION['error'] = "File size must be less than 2MB";
          redirect('user-control.php');
          exit();
      }
      
      $picture = file_get_contents($file['tmp_name']);
  }
  
  // Save to database
  try {
      $stmt = $pdo->prepare("INSERT INTO users (username, password, user_type, phone_number, email, picture) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->execute([$username, $password, $user_type, $phone_number, $email, $picture]);
      $succs= t('usr_scc');
      $_SESSION['success'] = "$succs";
      logActivity($_SESSION['user_id'], 'Create new user', "Created new user: $username");
      
      redirect('user-control.php');
  } catch (PDOException $e) {
      $_SESSION['error'] = "Database error: " . $e->getMessage();
      redirect('user-control.php');
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
  // Validate required fields
  $required = ['id', 'username', 'user_type'];
  foreach ($required as $field) {
      if (empty($_POST[$field])) {
          $_SESSION['error'] = "Please fill in all required fields";
          redirect('user-control.php');
          exit();
      }
  }

  $id = $_POST['id'];
  $user_type = sanitizeInput($_POST['user_type']);
  
  // Get current user data
  $stmt = $pdo->prepare("SELECT username, password, user_type, email, phone_number, picture FROM users WHERE id = ?");
  $stmt->execute([$id]);
  $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$current_user) {
      $_SESSION['error'] = "User not found";
      redirect('user-control.php');
      exit();
  }

  $username = sanitizeInput($_POST['username']);
  $password = $current_user['password']; // Keep current password
  
  // Handle other fields
  $phone_number = $user_type !== 'guest' ? sanitizeInput($_POST['phone_number'] ?? '') : '';
  $email = $user_type !== 'guest' ? sanitizeInput($_POST['email'] ?? '') : '';
  
  // Handle picture upload only for non-guest users
  $picture = null;
  $update_picture = false;
  if ($user_type !== 'guest' && isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['picture'];
      
      // Validate file type
      $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
      $file_type = mime_content_type($file['tmp_name']);
      
      if (!in_array($file_type, $allowed_types)) {
          $_SESSION['error'] = "Only image files are allowed (JPEG, PNG, GIF)";
          redirect('user-control.php');
          exit();
      }
      
      // Validate file size
      if ($file['size'] > 2097152) {
          $_SESSION['error'] = "File size must be less than 2MB";
          redirect('user-control.php');
          exit();
      }
      
      $picture = file_get_contents($file['tmp_name']);
      $update_picture = true;
  }
  
  // Prepare log messages for changes
  $log_messages = [];
  $has_changes = false;
  
  // Check for username change
  if ($current_user['username'] !== $username) {
      $log_messages[] = " Username ( {$current_user['username']} → {$username} )";
      $has_changes = true;
  }
  
  // Check for user type change
  if ($current_user['user_type'] !== $user_type) {
      $log_messages[] = " Role ( {$current_user['user_type']} → {$user_type} )";
      $has_changes = true;
  }
  
  // Check for email change (only if not guest)
  if ($user_type !== 'guest' && isset($current_user['email']) && $current_user['email'] !== $email) {
      $log_messages[] = " Email ( {$current_user['email']} → {$email} )";
      $has_changes = true;
  }
  
  // Check for phone number change (only if not guest)
  if ($user_type !== 'guest' && isset($current_user['phone_number']) && $current_user['phone_number'] !== $phone_number) {
      $log_messages[] = " Phone Number ( {$current_user['phone_number']} → {$phone_number} )";
      $has_changes = true;
  }
  
  // Check for picture change
  if ($update_picture) {
      $log_messages[] = "Update Profile picture ";
      $has_changes = true;
  }
  
  // Update database
  try {
      if ($update_picture) {
          $stmt = $pdo->prepare("UPDATE users SET 
                               username = ?,
                               user_type = ?,
                               phone_number = ?,
                               email = ?,
                               picture = ?
                               WHERE id = ?");
          $stmt->execute([$username, $user_type, $phone_number, $email, $picture, $id]);
      } else {
          $stmt = $pdo->prepare("UPDATE users SET 
                               username = ?,
                               user_type = ?,
                               phone_number = ?,
                               email = ?
                               WHERE id = ?");
          $stmt->execute([$username, $user_type, $phone_number, $email, $id]);
      }
      $upt_scc=t('upt_scc');
      $_SESSION['success'] = "$upt_scc";
      
      // Log activity based on changes
      if ($has_changes) {
          if (count($log_messages) > 1) {
              // Multiple changes - log as a single activity with all changes
              $log_message = "Updated user ( {$current_user['username']} ) : " . implode(", ", $log_messages);
              logActivity($_SESSION['user_id'], 'Edit User', $log_message);
          } else {
              // Single change - log each change separately
              foreach ($log_messages as $message) {
               
                  logActivity($_SESSION['user_id'], 'Edit User', "Updated user ( {$current_user['username']} ) : " . implode(", ", $log_messages));
              }
          }
      } else {
          // No changes detected except possibly picture
          logActivity($_SESSION['user_id'], 'Edit User', "Viewed user details for '{$username}' (ID: {$id})");
      }
      
      redirect('user-control.php');
  } catch (PDOException $e) {
      $_SESSION['error'] = "Error updating user: " . $e->getMessage();
      redirect('user-control.php');
  }
}

if (isset($_GET['unblock'])) {
  $id = $_GET['unblock'];
  $succ= t('unblock_success');
  $fail= t('unblock_success');
  try {
      $stmt = $pdo->prepare("UPDATE users SET is_blocked = FALSE, block_reason = NULL WHERE id = ?");
      $stmt->execute([$id]);
      
      logActivity($_SESSION['user_id'], 'User', "Unblocked user ID: $id");
      $_SESSION['success'] = "$succ";
  } catch (PDOException $e) {
      $_SESSION['error'] = "$fail";
  }
  
  redirect('user-control.php');
}
// Handle delete request
// Inside the delete section
if (isset($_GET['delete'])) {
  $id = $_GET['delete'];
  $succ= t('del_success');
  $fail=t('del_fail');
  // First get user info
  $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
  $stmt->execute([$id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if ($user) {
      // Delete the user
      $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
      $stmt->execute([$id]);
      
      logActivity($_SESSION['user_id'], 'Delete user', "Deleted user: {$user['username']} ");
      $_SESSION['success'] = "$succ";
  } else {
      $_SESSION['error'] = "$fail";
  }
  
  redirect('user-control.php');
}

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query for users with filters
$query = "SELECT id, username, user_type, phone_number, email, picture, is_blocked, created_at FROM users WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM users WHERE 1=1";
$params = [];

if ($username_filter) {
    $query .= " AND username LIKE :username";
    $count_query .= " AND username LIKE :username";
    $params[':username'] = "%$username_filter%";
}

if ($type_filter && $type_filter !== 'all') {
    $query .= " AND user_type = :type";
    $count_query .= " AND user_type = :type";
    $params[':type'] = $type_filter;
}

// Add month filter condition
if ($month_filter && $month_filter !== 'all') {
    $query .= " AND MONTH(created_at) = :month";
    $count_query .= " AND MONTH(created_at) = :month";
    $params[':month'] = $month_filter;
}

// Add year filter condition
if ($year_filter && $year_filter !== 'all') {
    $query .= " AND YEAR(created_at) = :year";
    $count_query .= " AND YEAR(created_at) = :year";
    $params[':year'] = $year_filter;
}

// Add sorting
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
if (!$stmt->execute()) {
    throw new Exception("Count query failed: " . implode(" ", $stmt->errorInfo()));
}
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";

// Get users
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

if (!$stmt->execute()) {
    throw new Exception("Main query failed: " . implode(" ", $stmt->errorInfo()));
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct user types for filter
$stmt = $pdo->query("SELECT DISTINCT user_type FROM users ORDER BY user_type");
$user_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years from the users
$stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) as year FROM users ORDER BY year DESC");
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Month names for the dropdown
$months = [
    '1' => t('jan'),
    '2' => t('feb'),
    '3' => t('mar'),
    '4' => t('apr'),
    '5' => t('may'),
    '6' => t('jun'),
    '7' => t('jul'),
    '8' => t('aug'),
    '9' => t('sep'),
    '10' => t('oct'),
    '11' => t('nov'),
    '12' => t('dec'),
];
?>
<style>
    :root {
  --primary: #4E73DF;
  --primary-dark: #0D63FD;
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
  background-color: var(--primary-dark);
  border-color: var(--primary-dark);
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
  --primary-dark: #0D63FD;
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
  background-color: var(--primary-dark);
  border-color: var(--primary-dark);
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
.table-responsive .btn {
    white-space: nowrap;
    margin: 2px;
}
@media (max-width: 767.98px) {

    /* For modal footer buttons on mobile only */
    .modal-footer .btn {
        display: block;
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    /* For action buttons in cards on mobile */
    .card .action-buttons .btn {
        display: block;
        width: 100%;
        margin-bottom: 0.5rem;
    }
}
@media (max-width: 767.98px) {
    .table-responsive .btn {
        font-size: 0.8rem;
        padding: 0.25rem 0.5rem;
    }
    
    /* Stack buttons vertically on very small screens */
    @media (max-width: 575.98px) {
        .table-responsive .btn {
            display: block;
            width: 100%;
            margin: 2px 0;
        }
    }
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
    
    /* Make buttons full width 
    .btn {
        display: block;
        width: 100%;
        margin-bottom: 0.5rem;
    }
    */
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
@media (max-width: 768px) {
    #addUserModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #addUserModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #addUserModal .modal-footer form {
        flex: 1;
    }
}

@media (max-width: 768px) {
    #deleteConfirmModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #deleteConfirmModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #deleteConfirmModal .modal-footer form {
        flex: 1;
    }
}
@media (max-width: 768px) {
    #editUserModal .modal-footer {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 10px;
    }
    
    #editUserModal .modal-footer .btn {
        flex: 1;
        min-width: auto;
        margin-bottom: 0;
    }
    
    #editUserModal .modal-footer form {
        flex: 1;
    }
}
.edit-user-click {
    cursor: pointer;
    font-weight: 500;
    transition: color 0.2s ease;
}
/* Validation styles */
.is-valid {
    border-color: #198754 !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.is-invalid {
    border-color: #dc3545 !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 3.6.4.4.4-.4'/%3e%3cpath d='M6 7v2'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875em;
    color: #dc3545;
}

.valid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875em;
    color: #198754;
}
/* Table action buttons - keep in one line */
.btn-group-vertical.btn-group-sm {
    display: inline-flex !important;
    flex-direction: row !important;
    gap: 4px;
}

.btn-group-vertical.btn-group-sm .btn {
    white-space: nowrap;
    margin: 0 !important;
}

/* Ensure table cells don't force wrapping */
.table td {
    white-space: nowrap !important;}
    .d-inline-flex {
    display: inline-flex !important;
    flex-wrap: nowrap !important;
    white-space: nowrap !important;
}
* Ensure buttons don't wrap */
.btn {
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

/* Remove any mobile breakpoints that force stacking */
@media (max-width: 768px) {
    .table .btn {
        display: inline-block !important;
        width: auto !important;
        margin-bottom: 0 !important;
    }
    
    .d-inline-flex {
        flex-direction: row !important;
    }
    
    /* Make table horizontally scrollable on mobile instead of stacking buttons */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}

/* For very small screens, reduce button padding but keep in one line */
@media (max-width: 480px) {
    .btn-sm {
        padding: 0.2rem 0.4rem !important;
        font-size: 0.75rem !important;
    }
    
    .badge {
        font-size: 0.7rem !important;
    }
}
/* Responsive behavior for very small screens */
@media (max-width: 768px) {
    .btn-group-vertical.btn-group-sm {
        flex-direction: column !important;
    }
    
    .btn-group-vertical.btn-group-sm .btn {
        width: 100%;
        margin-bottom: 2px !important;
    }
}

/* Specific fix for action buttons */
.table .btn {
    margin: 1px;
    white-space: nowrap;
}
/* Ensure unblock modal buttons stay in one line on all screens */
#unblockConfirmModal .modal-footer {
    display: flex !important;
    flex-direction: row !important;
    justify-content: center !important;
    gap: 10px !important;
}

#unblockConfirmModal .modal-footer .btn {
    flex: 1 !important;
    min-width: auto !important;
    margin-bottom: 0 !important;
    white-space: nowrap !important;
}

#unblockConfirmModal .modal-footer form {
    flex: 1 !important;
}
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('dashboard_titles');?></h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header text-white" style="background-color:#ce7e00;">
            <h5 class="mb-0"><?php echo t('filter_options');?></h5>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('form_user');?></label>
                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($username_filter); ?>" placeholder="<?php echo t('search');?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('form_type');?></label>
                        <select name="type" class="form-select">
                            <option value="all"><?php echo t('type_all');?></option>
                            <?php foreach ($user_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>>
                                    <?php echo $type; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                   
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('sort');?></label>
                        <select name="sort_option" class="form-select">
                            <option value="username_asc" <?php echo $sort_option == 'username_asc' ? 'selected' : ''; ?>>
                                <?php echo t('name_a_to_z'); ?>
                            </option>
                            <option value="username_desc" <?php echo $sort_option == 'username_desc' ? 'selected' : ''; ?>>
                                <?php echo t('name_z_to_a'); ?>
                            </option>
                            <option value="type_asc" <?php echo $sort_option == 'type_asc' ? 'selected' : ''; ?>>
                                <?php echo t('type'); ?>
                            </option>
                            
                            
                        </select>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> <?php echo t('search');?>
                    </button>
                    <a href="user-control.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> <?php echo t('reset');?>
                    </a>
                </div>
                
                <input type="hidden" name="page" value="1">
            </form>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header  text-white" style="background-color:#ce7e00;">
            <button class="btn btn-light btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-plus-circle"></i> <?php echo t('add_user');?>
            </button>
            <h5 class="mb-0"><?php echo t('list_user');?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($username_filter) || ($type_filter && $type_filter !== 'all') || ($month_filter && $month_filter !== 'all') || ($year_filter && $year_filter !== 'all')): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo t('showing_filtered_results');?>
                    <?php if (!empty($username_filter)): ?>
                        <span class="badge bg-secondary"><?php echo t('form_user');?>: <?php echo htmlspecialchars($username_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($type_filter && $type_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('form_type');?>: <?php echo $type_filter; ?></span>
                    <?php endif; ?>
                    <?php if ($month_filter && $month_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('month');?>: <?php echo $months[$month_filter]; ?></span>
                    <?php endif; ?>
                    <?php if ($year_filter && $year_filter !== 'all'): ?>
                        <span class="badge bg-secondary"><?php echo t('year');?>: <?php echo $year_filter; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('item_no');?></th>
                            <th><?php echo t('column_picture');?></th>
                            <th><?php echo t('column_user');?></th>
                            <th><?php echo t('column_type');?></th>
                            <th><?php echo t('column_phone');?></th>
                            <th><?php echo t('column_email');?></th>
                            <th><?php echo t('column_action');?></th>
                            <th><?php echo t('column_status');?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" class="text-center"><?php echo t('no_users_found');?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $index => $user): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td>
                                        <?php
                                        $image_src = 'get_user_image.php?id=' . $user['id'];
                                        ?>
                                        <img src="<?php echo $image_src; ?>" 
                                             alt="<?php echo htmlspecialchars($user['username']); ?>" 
                                             class="rounded-circle" 
                                             width="50" 
                                             height="50"
                                             style="object-fit: cover;"
                                             onerror="this.src='data:image/svg+xml;charset=UTF-8,%3Csvg%20width%3D%2250%22%20height%3D%2250%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2050%2050%22%20preserveAspectRatio%3D%22none%22%3E%3Cdefs%3E%3Cstyle%20type%3D%22text%2Fcss%22%3E%23holder_18a5f8a8b0a%20text%20%7B%20fill%3A%23AAAAAA%3Bfont-weight%3Abold%3Bfont-family%3AArial%2C%20Helvetica%2C%20Open%20Sans%2C%20sans-serif%2C%20monospace%3Bfont-size%3A10pt%20%7D%20%3C%2Fstyle%3E%3C%2Fdefs%3E%3Cg%20id%3D%22holder_18a5f8a8b0a%22%3E%3Crect%20width%3D%2250%22%20height%3D%2250%22%20fill%3D%22%23EEEEEE%22%3E%3C%2Frect%3E%3Cg%3E%3Ctext%20x%3D%2210%22%20y%3D%2227%22%3E50x50%3C%2Ftext%3E%3C%2Fg%3E%3C%2Fg%3E%3C%2Fsvg%3E'">
                                    </td>
                                    <td>
    <a href="#" class="text-black text-decoration-none edit-user-click" 
       data-id="<?php echo $user['id']; ?>"
       data-username="<?php echo $user['username']; ?>"
       data-user_type="<?php echo $user['user_type']; ?>"
       data-phone_number="<?php echo $user['phone_number']; ?>"
       data-email="<?php echo $user['email']; ?>"
       data-picture="<?php echo isset($user['picture']) ? '1' : '0'; ?>">
        <?php echo $user['username']; ?>
    </a>
</td>
                                    <td><?php echo $user['user_type']; ?></td>
                                    <td><?php echo $user['phone_number'] ?? 'N/A'; ?></td>
                                    <td><?php echo $user['email']; ?></td>
                                    <td>
    <div class="d-inline-flex gap-1 align-items-center">
        <button class="btn btn-sm btn-warning edit-user" 
            data-id="<?php echo $user['id']; ?>"
            data-username="<?php echo $user['username']; ?>"
            data-user_type="<?php echo $user['user_type']; ?>"
            data-phone_number="<?php echo $user['phone_number']; ?>"
            data-email="<?php echo $user['email']; ?>"
            data-picture="<?php echo isset($user['picture']) ? '1' : '0'; ?>">
            <i class="bi bi-pencil"></i> <?php echo t('update_button')?>
        </button>
        <button class="btn btn-sm btn-danger delete-user" 
            data-id="<?php echo $user['id']; ?>"
            data-username="<?php echo htmlspecialchars($user['username']); ?>">
            <i class="bi bi-trash"></i> <?php echo t('delete_button')?>
        </button>
    </div>
</td>
<td>
    <div class="d-inline-flex gap-1 align-items-center">
        <?php 
        $is_blocked = $user['is_blocked'] ?? false;
        if ($is_blocked): ?>
            <span class="badge bg-danger"><?php echo t('block_status')?></span>
            <button class="btn btn-sm btn-success unblock-user" 
                    data-id="<?php echo $user['id']; ?>"
                    data-username="<?php echo htmlspecialchars($user['username']); ?>">
                <i class="bi bi-unlock"></i> <?php echo t('unblock_button')?>
            </button>
        <?php else: ?>
            <span class="badge bg-success"><?php echo t('active_status')?></span>
        <?php endif; ?>
    </div>
</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" aria-label="Previous">
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
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1) {
                        echo '<li class="page-item"><span class="page-link">...</span></li>';
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor;
                    
                    if ($end_page < $total_pages) {
                        echo '<li class="page-item"><span class="page-link">...</span></li>';
                    }
                    ?>

                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" aria-label="Next">
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
                <?php echo t('page');?> <?php echo $current_page; ?> <?php echo t('page_of');?> <?php echo $total_pages; ?> 
            </div>
        </div>
    </div>
</div>

<!-- Duplicate Email Modal -->
<div class="modal fade" id="duplicateEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('error_email');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-envelope-exclamation-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('email_duplicate1');?></h4>
                <p><?php echo t('email_duplicate2');?></p>
                <div class="alert alert-danger mt-3">
                    <i class="bi bi-info-circle-fill"></i> <?php echo t('email_duplicate3');?>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Duplicate Username Modal (Reusable) -->
<div class="modal fade" id="duplicateUsernameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('error_user');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-person-x-fill text-danger " style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger  mb-3"><?php echo t('user_duplicate1');?></h4>
                <p><?php echo t('user_duplicate2');?></p>
                <div class="alert alert-danger  mt-3">
                    <i class="bi bi-info-circle-fill"></i> <?php echo t('user_duplicate3');?>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger " data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Password Mismatch Modal -->
<div class="modal fade" id="passwordMismatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('error_psw');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('psw_error1');?></h4>
                <p><?php echo t('psw_error2');?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('agree');?>
                </button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addUserModalLabel"><?php echo t('add_user');?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label"><?php echo t('form_user');?></label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3" id="password_field">
                        <label for="password" class="form-label"><?php echo t('form_psw');?></label>
                        <input type="password" class="form-control" id="password" name="password">
                    </div>
                    <div class="mb-3" id="confirm_password_field">
                        <label for="confirm_password" class="form-label"><?php echo t('form_cpsw');?></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                    </div>
                    <div class="mb-3">
                        <label for="user_type" class="form-label"><?php echo t('form_type');?></label>
                        <select class="form-select" id="user_type" name="user_type" required>
    <option value="admin"><?php echo t('form_admin');?></option>
    <option value="warehouse_staff"><?php echo t('form_warehouse_staff');?></option>
    <option value="finance_staff"><?php echo t('form_finance_staff');?></option>
    <option value="guest"><?php echo t('form_guest');?></option>
</select>
                    </div>
                    <div class="mb-3" id="phone_number_field">
                        <label for="phone_number" class="form-label"><?php echo t('form_phone');?></label>
                        <input type="text" class="form-control" id="phone_number" name="phone_number">
                    </div>
                    <div class="mb-3" id="email_field">
                        <label for="email" class="form-label"><?php echo t('form_email');?></label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    <div class="mb-3" id="picture_field">
                        <label for="picture" class="form-label"><?php echo t('form_picture');?></label>
                        <input type="file" class="form-control" id="picture" name="picture" accept="image/*" onchange="previewAddImage(this)">
                        <div class="mt-2">
                            <img id="addPreviewImage" src="../assets/images/users/default.png" alt="Preview" class="img-thumbnail" width="100" style="display: none;">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="add_user" class="btn btn-primary"><?php echo t('form_save');?></button>
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
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('del_usr');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('del_usr1');?></h4>
                <p><?php echo t('del_usr2');?></p>
                <div id="deleteUserInfo" class="alert alert-light mt-3">
                    <!-- User info will be inserted here -->
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> <?php echo t('delete_button');?>
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Unblock Confirmation Modal -->
<div class="modal fade" id="unblockConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle-fill"></i> <?php echo t('unblock_usr');?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-question-circle-fill text-success" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-success mb-3" id="unblockModalMessage"><?php echo t('unblock_usr1');?></h4>
                <p><?php echo t('unblock_usr3');?></p>
                <div id="unblockUserInfo" class="alert alert-light mt-3">
                    <!-- User info will be inserted here -->
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo t('form_close');?>
                </button>
                <a href="#" id="confirmUnblockBtn" class="btn btn-success">
                    <i class="bi bi-unlock"></i> <?php echo t('unblock_button');?>
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editUserModalLabel"><?php echo t('form_updateusr');?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label"><?php echo t('form_user')?></label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_user_type" class="form-label"><?php echo t('form_type')?></label>
                        <select class="form-select" id="edit_user_type" name="user_type" required>
    <option value="admin"><?php echo t('form_admin')?></option>
    <option value="warehouse_staff"><?php echo t('form_warehouse_staff')?></option>
    <option value="finance_staff"><?php echo t('form_finance_staff')?></option>
    <option value="guest"><?php echo t('form_guest')?></option>
</select>
                    </div>
                    
                    <div class="mb-3" id="edit_phone_number_field">
                        <label for="edit_phone_number" class="form-label"><?php echo t('form_phone')?></label>
                        <input type="text" class="form-control" id="edit_phone_number" name="phone_number">
                    </div>
                    
                    <div class="mb-3" id="edit_email_field">
                        <label for="edit_email" class="form-label"><?php echo t('form_email')?></label>
                        <input type="email" class="form-control" id="edit_email" name="email">
                    </div>
                    
                    <div class="mb-3" id="edit_picture_field">
                        <label for="edit_picture" class="form-label"><?php echo t('form_picture')?></label>
                        <input type="file" class="form-control" id="edit_picture" name="picture" accept="image/*" onchange="previewEditImage(this)">
                        <div class="mt-2">
                            <img id="editPreviewImage" src="" alt="Current Picture" class="img-thumbnail" width="100">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('form_close');?></button>
                    <button type="submit" name="edit_user" class="btn btn-warning"><?php echo t('form_update');?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>

  






// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add User Modal
    document.getElementById('user_type').addEventListener('change', () => toggleFieldsByUserType('add'));
    document.getElementById('addUserModal').addEventListener('shown.bs.modal', () => toggleFieldsByUserType('add'));
    
    // Edit User Modal
    document.getElementById('edit_user_type').addEventListener('change', () => toggleFieldsByUserType('edit'));
    document.getElementById('editUserModal').addEventListener('shown.bs.modal', () => toggleFieldsByUserType('edit'));
    
    // Initialize on page load for add modal (in case it's pre-opened)
    toggleFieldsByUserType('add');
});
  // Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Unblock user confirmation
    const unblockButtons = document.querySelectorAll('.unblock-user');
    const unblockModal = new bootstrap.Modal(document.getElementById('unblockConfirmModal'));
    
    unblockButtons.forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');
     
            document.getElementById('unblockModalMessage').textContent = 
                `<?php echo t('unblock_usr1');?>`;
            
            document.getElementById('unblockUserInfo').innerHTML = `
                <strong><?php echo t('form_user');?>:</strong> ${username}<br>
               
            `;
            
            
            document.getElementById('confirmUnblockBtn').href = `user-control.php?unblock=${userId}`;
            
            unblockModal.show();
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
// Delete confirmation modal handling
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let deleteUrl = '';
    
    // Handle delete button clicks
    document.querySelectorAll('.delete-user').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const userId = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');
            
            // Set the delete URL
            deleteUrl = `user-control.php?delete=${userId}`;
            
           
            
            document.getElementById('deleteUserInfo').innerHTML = `
                <strong><?php echo t('form_user');?>:</strong> ${username}
            `;
            
            // Show the modal
            deleteModal.show();
        });
    });
    
    // Handle confirm delete button click
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        window.location.href = deleteUrl;
    });
});
// Image preview for Add User Modal
function previewAddImage(input) {
    const preview = document.getElementById('addPreviewImage');
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
        preview.src = '../assets/images/users/default.png';
    }
}

// Reset preview when modal is closed
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('addPreviewImage').style.display = 'none';
    document.getElementById('addPreviewImage').src = '../assets/images/users/default.png';
    document.getElementById('picture').value = '';
});
// Handle edit user button click
document.querySelectorAll('.edit-user').forEach(button => {
    button.addEventListener('click', function() {
        const id = this.getAttribute('data-id');
        const username = this.getAttribute('data-username');
        const user_type = this.getAttribute('data-user_type');
        const phone_number = this.getAttribute('data-phone_number');
        const email = this.getAttribute('data-email');
        const has_picture = this.getAttribute('data-picture') === '1';
        
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_user_type').value = user_type;
        document.getElementById('edit_phone_number').value = phone_number || '';
        document.getElementById('edit_email').value = email;
        
        // Set the preview image
        const imgElement = document.getElementById('editPreviewImage');
        if (has_picture) {
            imgElement.src = 'get_user_image.php?id=' + id;
        } else {
            imgElement.src = 'assets/images/users/default.png';
        }
        
        const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
        editModal.show();
    });
});
document.getElementById('editUserModal').addEventListener('hidden.bs.modal', function() {
    const preview = document.getElementById('editPreviewImage');
    const currentSrc = preview.getAttribute('data-current-src');
    preview.src = currentSrc;
    document.getElementById('edit_picture').value = '';
});
// Password confirmation validation

document.querySelector('#addUserModal form').addEventListener('submit', async function(e) {
    const userType = document.getElementById('user_type').value;
    const isGuest = userType === 'guest';
    
    // Only check password match for non-guest users
    if (!isGuest) {
        const password = document.getElementById('password').value;
        const confirm_password = document.getElementById('confirm_password').value;
        
        if (password !== confirm_password) {
            e.preventDefault();
            const mismatchModal = new bootstrap.Modal(document.getElementById('passwordMismatchModal'));
            mismatchModal.show();
            return;
        }
    }

    // Validate username and email (email only for non-guest)
    const username = document.getElementById('username').value.trim();
    const email = document.getElementById('email').value.trim();
    
    try {
        const usernameValid = await validateUsername(username);
        let emailValid = true;
        
        // Only validate email for non-guest users
        if (!isGuest && email) {
            emailValid = await validateEmail(email);
        }
        
        if (!usernameValid || !emailValid) {
            e.preventDefault();
            return;
        }
        
        // If all validations pass, allow the form to submit normally
    } catch (error) {
        console.error('Validation error:', error);
        e.preventDefault();
    }
});
function toggleFieldsByUserType(modalType = 'add') {
    const prefix = modalType === 'edit' ? 'edit_' : '';
    const userType = document.getElementById(`${prefix}user_type`).value;
    const isGuest = userType === 'guest';
    
    // Fields to show/hide - including password fields for add modal
    const fieldsToToggle = ['phone_number', 'email', 'picture'];
    
    // For add modal only, also hide password fields
    if (modalType === 'add') {
        fieldsToToggle.push('password', 'confirm_password');
    }
    
    fieldsToToggle.forEach(fieldId => {
        const fieldGroup = document.getElementById(`${prefix}${fieldId}_field`) || 
                          document.getElementById(`${prefix}${fieldId}`)?.closest('.mb-3');
        if (fieldGroup) {
            fieldGroup.style.display = isGuest ? 'none' : 'block';
        }
    });
    
    // Handle preview image
    const previewImage = document.getElementById(`${prefix}PreviewImage`);
    if (previewImage) {
        previewImage.style.display = isGuest ? 'none' : 'block';
    }
    
    // Make password fields optional for guest users in add modal
    if (modalType === 'add') {
        const passwordField = document.getElementById('password');
        const confirmPasswordField = document.getElementById('confirm_password');
        
        if (isGuest) {
            passwordField.removeAttribute('required');
            confirmPasswordField.removeAttribute('required');
            passwordField.value = '';
            confirmPasswordField.value = '';
        } else {
            passwordField.setAttribute('required', 'required');
            confirmPasswordField.setAttribute('required', 'required');
        }
    }
}

// Remove password validation from form submission
document.querySelector('#editUserModal form').addEventListener('submit', async function(e) {
    const username = document.getElementById('edit_username').value.trim();
    const currentId = document.getElementById('edit_id').value;
    const email = document.getElementById('edit_email').value.trim();
    
    try {
        const [usernameValid, emailValid] = await Promise.all([
            validateUsername(username, currentId),
            validateEmail(email, currentId)
        ]);
        
        if (!usernameValid || !emailValid) {
            e.preventDefault();
            return;
        }
    } catch (error) {
        console.error('Validation error:', error);
        e.preventDefault();
    }
});
// Real-time validation for Add User form
document.getElementById('username').addEventListener('blur', async function() {
    const username = this.value.trim();
    if (username) {
        await validateUsername(username);
    }
});

// Real-time validation for Edit User form
document.getElementById('edit_username').addEventListener('blur', async function() {
    const username = this.value.trim();
    const currentId = document.getElementById('edit_id').value;
    if (username) {
        await validateUsername(username, currentId);
    }
});
// Real-time validation for Add User form email
document.getElementById('email').addEventListener('blur', async function() {
    const email = this.value.trim();
    if (email) {
        await validateEmail(email);
    }
});

// Real-time validation for Edit User form email
document.getElementById('edit_email').addEventListener('blur', async function() {
    const email = this.value.trim();
    const currentId = document.getElementById('edit_id').value;
    if (email) {
        await validateEmail(email, currentId);
    }
});

async function checkUsernameExists(username, currentId = null) {
    try {
        const response = await fetch('check_username.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `username=${encodeURIComponent(username)}&current_id=${currentId || ''}`
        });
        
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking username:', error);
        return false;
    }
}

// Function to check email and show modal if duplicate exists
async function validateEmail(email, currentId = null) {
    if (!email) return true; // Skip if empty
    
    const exists = await checkEmailExists(email, currentId);
    if (exists) {
        const duplicateModal = new bootstrap.Modal(document.getElementById('duplicateEmailModal'));
        
        // Center the modal within the current open modal
        const currentModal = document.querySelector('.modal.show');
        if (currentModal) {
            duplicateModal._element.style.zIndex = currentModal.style.zIndex + 1;
        }
        
        duplicateModal.show();
        return false;
    }
    return true;
}

async function checkEmailExists(email, currentId = null) {
    try {
        const response = await fetch('check_email.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `email=${encodeURIComponent(email)}&current_id=${currentId || ''}`
        });
        
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking email:', error);
        return false;
    }
}
// Function to check username and show modal if duplicate exists
async function validateUsername(username, currentId = null) {
    if (!username) return true; // Skip if empty
    
    const exists = await checkUsernameExists(username, currentId);
    if (exists) {
        const duplicateModal = new bootstrap.Modal(document.getElementById('duplicateUsernameModal'));
        
        // Center the modal within the current open modal
        const currentModal = document.querySelector('.modal.show');
        if (currentModal) {
            duplicateModal._element.style.zIndex = currentModal.style.zIndex + 1;
        }
        
        duplicateModal.show();
        return false;
    }
    return true;
}
function previewEditImage(input) {
    const preview = document.getElementById('editPreviewImage');
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
        };
        
        reader.readAsDataURL(file);
    } else {
        // If no file selected, revert to original image
        preview.src = preview.getAttribute('data-original-src');
    }
}
// Handle username click to open edit modal
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-user-click').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            const id = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');
            const user_type = this.getAttribute('data-user_type');
            const phone_number = this.getAttribute('data-phone_number');
            const email = this.getAttribute('data-email');
            const has_picture = this.getAttribute('data-picture') === '1';
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_user_type').value = user_type;
            document.getElementById('edit_phone_number').value = phone_number || '';
            document.getElementById('edit_email').value = email;
            
            // Set the preview image
            const imgElement = document.getElementById('editPreviewImage');
            if (has_picture) {
                imgElement.src = 'get_user_image.php?id=' + id;
                imgElement.setAttribute('data-original-src', 'get_user_image.php?id=' + id);
            } else {
                imgElement.src = '../assets/images/users/default.png';
                imgElement.setAttribute('data-original-src', '../assets/images/users/default.png');
            }
            
            const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
            editModal.show();
        });
    });
});
// Prevent spaces in username field for Add User Modal
document.getElementById('username').addEventListener('input', function(e) {
    this.value = this.value.replace(/\s/g, '');
});

// Prevent spaces in username field for Edit User Modal
document.getElementById('edit_username').addEventListener('input', function(e) {
    this.value = this.value.replace(/\s/g, '');
});
// Add validation feedback for username fields
function validateUsernameNoSpaces(inputField) {
    const value = inputField.value;
    const hasSpaces = /\s/.test(value);
    
    if (hasSpaces) {
        inputField.classList.add('is-invalid');
        inputField.classList.remove('is-valid');
        showUsernameError(inputField, 'Spaces are not allowed in username');
        return false;
    } else if (value.length > 0) {
        inputField.classList.remove('is-invalid');
        inputField.classList.add('is-valid');
        return true;
    } else {
        inputField.classList.remove('is-invalid');
        inputField.classList.remove('is-valid');
        return false;
    }
}

function showUsernameError(inputField, message) {
    // Remove existing error message
    let existingError = inputField.parentNode.querySelector('.invalid-feedback');
    if (existingError) {
        existingError.remove();
    }
    
    // Add new error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback';
    errorDiv.textContent = message;
    inputField.parentNode.appendChild(errorDiv);
}

// Add event listeners for real-time validation
document.getElementById('username').addEventListener('input', function() {
    validateUsernameNoSpaces(this);
});

document.getElementById('edit_username').addEventListener('input', function() {
    validateUsernameNoSpaces(this);
});
// Add User Modal form submission validation
document.querySelector('#addUserModal form').addEventListener('submit', function(e) {
    const usernameField = document.getElementById('username');
    
    if (/\s/.test(usernameField.value)) {
        e.preventDefault();
        usernameField.classList.add('is-invalid');
        showUsernameError(usernameField, 'Please remove spaces from username before submitting');
        usernameField.focus();
    }
});

// Edit User Modal form submission validation
document.querySelector('#editUserModal form').addEventListener('submit', function(e) {
    const usernameField = document.getElementById('edit_username');
    
    if (/\s/.test(usernameField.value)) {
        e.preventDefault();
        usernameField.classList.add('is-invalid');
        showUsernameError(usernameField, 'Please remove spaces from username before submitting');
        usernameField.focus();
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>