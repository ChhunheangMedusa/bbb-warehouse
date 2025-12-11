<?php
ob_start();
session_start(); // Add this at the beginning

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/translations.php';

// Add database connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

// Initialize language settings
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'km'; // Default to Khmer
}

// Get the current user's data from database
$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'User';
$userType = 'guest'; // Default value

// Fetch user's picture from database
$userPicture = null;
if ($userId && isset($pdo)) { // Check if $pdo exists
    try {
        $stmt = $pdo->prepare("SELECT picture, user_type FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userData) {
            if (!empty($userData['picture'])) {
                $userPicture = $userData['picture'];
            }
            if (!empty($userData['user_type'])) {
                $userType = $userData['user_type'];
            }
        }
    } catch (Exception $e) {
        // Silently continue
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
    
    <!-- Fix path issues - use absolute or correct relative paths -->
    <link href="../../assets/css/style.css" rel="stylesheet">
    <!-- Favicon -->
    <link rel="shortcut icon" href="../../assets/images/favicon.ico" type="image/x-icon">
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
    <link rel='stylesheet' href='https://cdn-uicons.flaticon.com/3.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css'>
</head>
<style>
    body {
        overflow-x: hidden;
    }
    
    .avatar-img {
        width: 100px;
        height: 100px;
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
    
    /* Fixed sidebar styles */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: 250px; /* Add fixed width */
        z-index: 1000;
        overflow-y: auto;
        background: linear-gradient(180deg, #4e73df 0%, #224abe 100%);
    }
    
    .main-content {
        margin-left: 250px; /* Match sidebar width */
        width: calc(100% - 250px);
        padding: 20px;
    }
    
    /* Mobile styles */
    @media (max-width: 768px) {
        .sidebar {
            width: 0;
            overflow: hidden;
            transition: width 0.3s ease;
        }
        
        .sidebar.collapsed {
            width: 250px;
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 15px;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 250px;
            width: calc(100% - 250px);
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
    }
    
    /* Sidebar styles */
    .sidebar-nav {
        padding: 15px;
    }
    
    .sidebar-nav .nav-link {
        color: rgba(255, 255, 255, 0.8);
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 5px;
    }
    
    .sidebar-nav .nav-link:hover,
    .sidebar-nav .nav-link.active {
        background-color: rgba(255, 255, 255, 0.1);
        color: white;
    }
</style>
<body class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand text-center py-4">
            <h4 class="mt-3 text-white" style="font-size:20px;font-weight: bold;"><?php echo t('system_title'); ?></h4>
            <div class="mt-3">
                <?php if ($hasAvatar): ?>
                    <img src="../get_user_image.php?id=<?php echo $userId; ?>" class="avatar-img" alt="<?php echo htmlspecialchars($username); ?>">
                <?php else: ?>
                    <div class="default-avatar mx-auto" style="width: 80px; height: 80px;">
                        <i class="bi bi-person-fill" style="font-size: 2rem;"></i>
                    </div>
                <?php endif; ?>
                <p class="mt-2 text-white" style="text-transform:uppercase;">
                    <?php echo htmlspecialchars($username); ?><br>
                    <span class="badge 
                        <?php 
                        switch(strtolower($userType)) {
                            case 'admin': echo 'bg-danger'; break;
                            case 'staff': echo 'bg-primary'; break;
                            case 'guest': echo 'bg-success'; break;
                            default: echo 'bg-secondary';
                        }
                        ?>" 
                        style="font-size:14px;">
                        <?php echo ucfirst(htmlspecialchars($userType)); ?>
                    </span>
                </p>
            </div>
        </div>
        <div class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                        <i class="bi bi-speedometer2 me-2"></i><?php echo t('dashboard'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoice.php' ? 'active' : ''; ?>" href="invoice.php">
                        <i class="bi bi-receipt me-2"></i><?php echo t('invoice'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'access-log.php' ? 'active' : ''; ?>" href="access-log.php">
                        <i class="bi bi-clock-history me-2"></i><?php echo t('logs'); ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="main-content d-flex flex-column min-vh-100">
        <nav class="navbar navbar-expand navbar-light bg-white shadow-sm mb-3">
            <div class="container-fluid">
                <button class="navbar-toggler d-md-none" type="button" id="sidebarToggle">
                    <span class="navbar-toggler-icon"></span>
                </button>
                
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
                                <i class="bi bi-gear me-1"></i>
                                <span class="d-none d-md-inline"><?php echo t('settings') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../Admin/profile.php"><i class="bi bi-person me-2"></i><?php echo t('profile'); ?></a></li>
                                <li><a class="dropdown-item" href="../select-destination.php"><i class="bi bi-arrow-left-right me-2"></i><?php echo t('switch'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i><?php echo t('logout'); ?></a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Main Content Area -->
        <div class="container-fluid flex-grow-1">
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