<?php
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/translations.php'; 
// Initialize language settings
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'km'; // Default to Khmer
}

// Language translations

// Get the current user's data from database
$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'User';

// Fetch user's picture from database
$userPicture = null;
if ($userId) {
    $stmt = $pdo->prepare("SELECT picture FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData && !empty($userData['picture'])) {
        $userPicture = $userData['picture'];
    }
}

// Check if avatar exists in database, otherwise use default
$hasAvatar = ($userPicture !== null);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['language']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : t('system_title'); ?></title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <!-- Favicon -->
    <link rel="shortcut icon" href="../assets/images/favicon.ico" type="image/x-icon">
    <!-- Google Font - Khmer OS Siemreap -->
    <link href="https://fonts.googleapis.com/css2?family=Khmer+OS+Siemreap&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Datepicker CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<style>
     .avatar-img {
        width: 30px;
        height: 30px;
        object-fit: cover;
        border-radius: 50%;
    }
    .default-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background-color: #000; /* Black background */
        color: white;
        border: 1px solid #ddd; /* Light gray outline */
    }
    .sidebar-logo {
        color: #FFFFFF;
        width: 120px;
        height: auto;
        margin: 0 auto;
        display: block;
    }
    
    /* Mobile styles */
    @media (max-width: 768px) {
        .sidebar {
            width: 0;
            overflow: hidden;
            transition: width 0.3s ease;
            position: fixed;
            z-index: 1000;
            height: 100vh;
        }
        
        .sidebar.collapsed {
            width: 250px;
        }
        
        .main-content {
            margin-left: 0;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 250px;
        }
        
        .navbar {
            padding: 0.5rem 1rem;
        }
        
        .avatar-img {
            width: 25px;
            height: 25px;
        }
        
        .sidebar-logo {
            width: 80px;
        }
        
        .dropdown-menu {
            width: 100%;
        }
        
        .dropdown-item {
            padding: 0.75rem 1.5rem;
        }
        
        .nav-link {
            padding: 0.5rem 1rem;
        }
        
        .sidebar-nav .nav-item {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-nav .nav-link {
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }
    }
    
    @media (max-width: 576px) {
        .d-none.d-md-inline {
            display: none !important;
        }
        
        .alert {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
    }
</style>
<body class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar bg-gradient-primary">
        <div class="sidebar-brand text-center py-4">
        <img src="assets/images/white_logo.png" alt="Logo" class="sidebar-logo">
            <h4 class="mt-3 text-white" style="font-size:20px;font-weight: bold;"><?php echo t('system_title');?></h4>
        </div>
        <div class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard-staff.php' ? 'active' : ''; ?>" href="dashboard-staff.php">
                        <i class="bi bi-speedometer2 me-2"></i><?php echo t('dashboard');?>
                    </a>
                </li>
              
                
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'item-control-staff.php' ? 'active' : ''; ?>" href="item-control-staff.php">
                        <i class="bi bi-box-seam me-2"></i><?php echo t('item_management');?>
                    </a>
                </li>
                
               
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'stock-transfer-staff.php' ? 'active' : ''; ?>" href="stock-transfer-staff.php">
                        <i class="bi bi-arrow-left-right me-2"></i><?php echo t('stock_transfer');?>
                    </a>
                </li>
               
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'low-stock-alert-staff.php' ? 'active' : ''; ?>" href="low-stock-alert-staff.php">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo t('low_stock');?>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidebar-footer mt-auto p-3 text-center">
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i><?php echo t('logout');?>
            </a>
        </div>
    </div>

    <div class="main-content d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
    <div class="container-fluid">
        <div class="navbar-collapse justify-content-end">
            <ul class="navbar-nav">
                <li class="nav-item dropdown me-2">
                    <a class="nav-link dropdown-toggle" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-translate me-1"></i>
                        <?php echo $_SESSION['language'] == 'km' ? 'ភាសាខ្មែរ' : 'English'; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
                        <li><a class="dropdown-item" href="../change-language.php?lang=km">ភាសាខ្មែរ</a></li>
                        <li><a class="dropdown-item" href="../change-language.php?lang=en">English</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if ($hasAvatar): ?>
                            <img src="get_user_image.php?id=<?php echo $userId; ?>" class="avatar-img me-2" alt="<?php echo htmlspecialchars($username); ?>">
                        <?php else: ?>
                            <div class="default-avatar me-2">
                                <i class="bi bi-person-fill" style="font-size: 1rem;"></i>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars($username); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                        <li><a class="dropdown-item" href="profile-staff.php"><i class="bi bi-person me-2"></i><?php echo t('profile'); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i><?php echo t('logout'); ?></a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
    
    <!-- Main Content Area -->
    <div class="container-fluid flex-grow-1 py-3">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php
// Flush output buffer at the end of header
ob_end_flush();
?>
<script>
document.getElementById('sidebarToggle').addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('collapsed');
    document.querySelector('.main-content').classList.toggle('expanded');
});
</script>