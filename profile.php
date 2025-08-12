<?php
ob_start();
require_once 'includes/header.php';
require_once  'translate.php'; 
checkAuth();

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id, username, email, phone_number, picture, user_type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle case where user data couldn't be retrieved
if (!$user) {
    $_SESSION['error_message'] = "User data not found!";
    header("Location: dashboard.php");
    exit;
}

// Set default role if not set
$user['user_type'] = $user['user_type'] ?? 'staff'; // Default to 'staff' if role is not set

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone_number = trim($_POST['phone_number']);
    
    // Validate username
    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif ($username !== $user['username']) {
        // Check if username is already taken
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = "Username already taken";
        }
    }
    
    // Validate email
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Handle file upload - store in database directly
    $picture = $user['picture']; // Keep current picture by default
    
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['picture'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = mime_content_type($file['tmp_name']);
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only JPG, PNG, or WEBP images are allowed";
        } else {
            // Read the file content
            $picture = file_get_contents($file['tmp_name']);
        }
    }
    
    // Update if no errors
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone_number = ?, picture = ? WHERE id = ?");
            $stmt->execute([$username, $email, $phone_number, $picture, $user_id]);
            
            logActivity($user_id, "Profile Update", "{$username} updated their profile information");
            
            $pdo->commit();
            $_SESSION['success_message'] = "Profile updated successfully!";
            header("Location: profile.php");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
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

    .profile-card {
        max-width: 600px;
        margin: 0 auto;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        border-radius: 0.35rem;
    }

    .profile-header {
        background-color: var(--primary);
        color: white;
        padding: 1.5rem;
        border-radius: 0.35rem 0.35rem 0 0;
        text-align: center;
    }

    .profile-avatar {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid white;
        margin-bottom: 1rem;
    }

    .profile-body {
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .role-badge {
        font-size: 0.9rem;
        padding: 0.5rem 1rem;
        margin-top: 0.5rem;
        display: inline-block;
    }

    /* Add responsive styles as needed */
    @media (max-width: 768px) {
        .profile-avatar {
            width: 80px;
            height: 80px;
        }
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

.table th {
  background-color: var(--light);
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  border-bottom-width: 1px;
  white-space: nowrap;
  text-overflow: ellipsis;
  max-width: 200px;
  overflow: hidden;
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

.pagination .page-item.disabled .page-link {
  color: var(--secondary);
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
    /* Make table display as cards on mobile */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table-striped {
        display: block;
        width: 100%;
    }
    
    .table-striped thead {
        display: none;
    }
    
    .table-striped tbody,
    .table-striped tr,
    .table-striped td {
        display: block;
        width: 100%;
    }
    
    .table-striped tr {
        margin-bottom: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 0.35rem;
        box-shadow: 0 0.15rem 0.75rem rgba(0, 0, 0, 0.1);
    }
    
    .table-striped td {
        padding: 0.75rem;
        border: none;
        border-bottom: 1px solid #dee2e6;
        position: relative;
        padding-left: 40%;
    }
    
    .table-striped td:before {
        content: attr(data-label);
        position: absolute;
        left: 0.75rem;
        width: 35%;
        padding-right: 1rem;
        font-weight: 600;
        text-align: left;
        color: #495057;
    }
    
    .table-striped td:last-child {
        border-bottom: none;
    }
    
    /* Adjust filter form for mobile */
    .card-body .row.g-2 {
        flex-direction: column;
    }
    
    .card-body .col-md-3,
    .card-body .col-md-2 {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    /* Make pagination more compact */
    .pagination .page-item .page-link {
        padding: 0.25rem 0.5rem;
        margin: 0 0.1rem;
        font-size: 0.875rem;
    }
    
    /* Adjust card padding */
    .card-body {
        padding: 1rem;
    }
}
@media (max-width: 576px) {
    /* Make header smaller */
    h2 {
        font-size: 1.5rem;
    }
    
    /* Adjust card header */
    .card-header h5 {
        font-size: 1.1rem;
    }
    
    /* Make table cells more compact */
    .table-striped td {
        padding-left: 35%;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
    }
    
    .table-striped td:before {
        width: 30%;
        font-size: 0.85rem;
    }
    
    /* Hide some less important columns if needed */
    .table-striped td:nth-child(3) {  /* activity type column */
        display: none;
    }
    
    /* Adjust filter dropdown */
    .form-select {
        font-size: 0.9rem;
    }
    
    /* Make pagination info single line */
    .text-center.text-muted {
        font-size: 0.8rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
}
@media (max-width: 768px) {
    /* Force table to not be a table anymore */
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
    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4"><?php echo t('acc_usr');?></h2>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="card profile-card">
                <div class="profile-header">
                    <img src="get_user_image.php?id=<?php echo $user_id; ?>" alt="Profile Image" class="profile-avatar">
                    <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                    <span class="role-badge badge bg-<?php echo $user['user_type'] === 'admin' ? 'danger' : 'primary'; ?>">
                        <?php echo ucfirst($user['user_type']); ?>
                    </span>
                </div>
                
                <div class="profile-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="username"><?php echo t('column_user');?></label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email"><?php echo t('column_email');?></label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_number"><?php echo t('column_phone');?></label>
                            <input type="text" class="form-control" id="phone_number" name="phone_number" 
                                   value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="picture"><?php echo t('form_picture');?></label>
                            <input type="file" class="form-control" id="picture" name="picture" accept="image/*" onchange="previewImage(this)">
                            <div class="mt-2">
                                <img id="imagePreview" src="get_user_image.php?id=<?php echo $user_id; ?>" 
                                     alt="Preview" class="img-thumbnail" width="100">
                            </div>
                          
                        </div>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> <?php echo t('return');?>
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> <?php echo t('form_save');?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
        };
        
        reader.readAsDataURL(file);
    } else {
        // Revert to current image if no file selected
        preview.src = `get_user_image.php?id=<?php echo $user_id; ?>`;
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>