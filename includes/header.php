<?php
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/translations.php'; 
// Initialize language settings
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'km'; // Default to Khmer
}



// Get the current user's data from database
$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'User';

// Fetch user's picture from database
$userPicture = null;
if ($userId) {
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
        z-index: 1000;
        overflow-y: auto;
    }
    
    .main-content {
        margin-left: 221.5px; /* Adjust this based on your sidebar width */
        width: calc(100% - 250px);
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
    
    /* Sidebar dropdown styles */
    .sidebar .nav-link[data-bs-toggle="collapse"] {
        position: relative;
        padding-right: 2rem;
    }

    .sidebar .nav-link[data-bs-toggle="collapse"] .bi-chevron-down {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.2s ease;
    }

    .sidebar .nav-link[data-bs-toggle="collapse"].collapsed .bi-chevron-down {
        transform: translateY(-50%) rotate(-90deg);
    }

    .sidebar .nav-link.collapsed {
        color: rgba(255, 255, 255, 0.8);
    }

    .sidebar .collapse ul {
        background-color: rgba(0, 0, 0, 0.1);
        border-radius: 0.25rem;
        margin: 0.25rem 0;
    }

    .sidebar .nav-item .nav-link {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    
    /* Ensure sidebar content doesn't overflow */
    .sidebar-nav {
        max-height: calc(100vh - 180px);
        overflow-y: auto;
    }
    
    /* Custom scrollbar for sidebar */
    .sidebar-nav::-webkit-scrollbar {
        width: 5px;
    }
    
    .sidebar-nav::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 5px;
    }
    
    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }
    
</style>
<body class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar bg-gradient-primary">
        <div class="sidebar-brand text-center py-4">
         
            <h4 class="mt-3 text-white" style="font-size:20px;font-weight: bold;"><?php echo t('system_title'); ?></h4>
          
                            <a href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if ($hasAvatar): ?>
                                    <img src="get_user_image.php?id=<?php echo $userId; ?>" class="avatar-img me-2" alt="<?php echo htmlspecialchars($username); ?>">
                                <?php else: ?>
                                    <div class="default-avatar me-2">
                                        <i class="bi bi-person-fill" style="font-size: 1rem;"></i>
                                    </div>
                                <?php endif; ?>
                                <br>
                            </a>
                            <p style="color:white; text-decoration:none;text-transform:uppercase;margin-top:5px;"><?php echo htmlspecialchars($username); ?><br>
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
        <div class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo $_SERVER['PHP_SELF'] == '/Admin/dashboard.php' ? 'active' : ''; ?>" href="../Admin/dashboard.php">
                        <i class="bi bi-speedometer2 me-2"></i><?php echo t('dashboard'); ?>
                    </a>
                </li>
               
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/user-control.php' ? 'active' : ''; ?>" href="../Admin/user-control.php">
                        <i class="bi bi-people me-2"></i><?php echo t('user_management'); ?>
                    </a>
                </li>
               
                
                <li class="nav-item">
                    <a class="nav-link collapsed" data-bs-toggle="collapse" href="#itemManagementCollapse" role="button" aria-expanded="false">
                        <i class="bi bi-box-seam me-2"></i><?php echo t('item_management'); ?>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
                    </a>
                    <div class="collapse" id="itemManagementCollapse">
                        <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/items.php' ? 'active' : ''; ?>" href="../Admin/items.php">
                                    <i class="bi bi-box-seam me-2"></i><?php echo t('item'); ?>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/stock-transfer.php' ? 'active' : ''; ?>" href="../Admin/stock-transfer.php">
                                    <i class="bi bi-arrow-left-right me-2"></i><?php echo t('transfer'); ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/remaining.php' ? 'active' : ''; ?>" href="../Admin/remaining.php">
                                  <i class="bi bi-archive me-2"></i><?php echo t('remaining'); ?>
                                </a>
                            </li>

                        <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/store.php' ? 'active' : ''; ?>" href="../Admin/store.php">
                                    <i class="bi bi-currency-dollar me-2"></i><?php echo t('store_inventory'); ?>
                                </a>
                            </li>
                          
                       
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/repair.php' ? 'active' : ''; ?>" href="../Admin/repair.php">
                                    <i class="bi bi-tools me-2"></i><?php echo t('repair'); ?>
                                </a>
                            </li>
                            
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/broken-items.php' ? 'active' : ''; ?>" href="../Admin/broken-items.php">
                                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo t('broken_items'); ?>
                                </a>
                            </li>
                           
                        </ul>
                    </div>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/location-control.php' ? 'active' : ''; ?>" href="../Admin/location-control.php">
                        <i class="bi bi-pin-map me-2"></i><?php echo t('location_management'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/category.php' ? 'active' : ''; ?>" href="../Admin/category.php">
                        <i class="bi bi-card-list me-2"></i><?php echo t('category_management'); ?>
                    </a>
                </li>
       
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/deporty.php' ? 'active' : ''; ?>" href="../Admin/deporty.php">
                        <i class="bi bi-geo-alt me-2"></i><?php echo t('deporty_management'); ?>
                    </a>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/access-log.php' ? 'active' : ''; ?>" href="../Admin/access-log.php">
                        <i class="bi bi-clock-history me-2"></i><?php echo t('logs'); ?>
                    </a>
                </li>
            
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/report.php' ? 'active' : ''; ?>" href="../Admin/report.php">
                        <i class="bi bi-file-earmark-text me-2"></i><?php echo t('reports'); ?>
                    </a>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/low-stock-alert.php' ? 'active' : ''; ?>" href="../Admin/low-stock-alert.php">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo t('low_stock'); ?>
                    </a>
                </li>
            </ul>
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
                               
                                <span class="d-none d-md-inline"><?php echo t('settings') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../Admin/profile.php"><i class="bi bi-person me-2"></i><?php echo t('profile'); ?></a></li>
                                <li><a class="dropdown-item" href="../Admin/switch.php"><i class="bi bi-person me-2"></i><?php echo t('profile'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                
                                <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i><?php echo t('logout'); ?></a></li>
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

</script>