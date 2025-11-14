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
    <link rel='stylesheet' href='https://cdn-uicons.flaticon.com/3.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css'>
</head>
<style>
    :root {
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 80px;
        --primary-color: #6366f1;
        --primary-dark: #4f46e5;
        --primary-light: #a5b4fc;
        --sidebar-bg: #1e293b;
        --sidebar-hover: #334155;
        --sidebar-active: #3b82f6;
        --text-light: #f8fafc;
        --text-muted: #94a3b8;
        --transition-speed: 0.3s;
    }
    
    body {
        overflow-x: hidden;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f8fafc;
    }
    
    .avatar-img {
        width: 36px;
        height: 36px;
        object-fit: cover;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }
    .default-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: white;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }
    
    /* Modern Sidebar Styles */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: var(--sidebar-width);
        background: var(--sidebar-bg);
        z-index: 1000;
        overflow-y: auto;
        transition: all var(--transition-speed) ease;
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
    }
    
    .sidebar-brand {
        padding: 1.5rem 1.5rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 1rem;
    }
    
    .sidebar-brand h4 {
        font-weight: 700;
        font-size: 1.25rem;
        background: linear-gradient(90deg, #fff, var(--primary-light));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
    }
    
    .sidebar-nav {
        flex: 1;
        padding: 0 1rem;
    }
    
    .sidebar-nav .nav-link {
        color: var(--text-light);
        padding: 0.75rem 1rem;
        margin-bottom: 0.25rem;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        transition: all 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    
    .sidebar-nav .nav-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 4px;
        background: var(--primary-color);
        transform: scaleY(0);
        transition: transform 0.2s ease;
    }
    
    .sidebar-nav .nav-link:hover {
        background: var(--sidebar-hover);
        color: white;
        transform: translateX(5px);
    }
    
    .sidebar-nav .nav-link:hover::before {
        transform: scaleY(1);
    }
    
    .sidebar-nav .nav-link.active {
        background: var(--sidebar-active);
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
    
    .sidebar-nav .nav-link.active::before {
        transform: scaleY(1);
    }
    
    .sidebar-nav .nav-link i {
        margin-right: 0.75rem;
        font-size: 1.1rem;
        width: 24px;
        text-align: center;
    }
    
    /* Sidebar dropdown styles */
    .sidebar .nav-link[data-bs-toggle="collapse"] {
        position: relative;
        padding-right: 2.5rem;
    }

    .sidebar .nav-link[data-bs-toggle="collapse"] .bi-chevron-down {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.2s ease;
        font-size: 0.8rem;
    }

    .sidebar .nav-link[data-bs-toggle="collapse"].collapsed .bi-chevron-down {
        transform: translateY(-50%) rotate(-90deg);
    }

    .sidebar .collapse ul {
        background-color: rgba(0, 0, 0, 0.2);
        border-radius: 0.5rem;
        margin: 0.25rem 0;
        padding: 0.5rem 0;
    }
    
    .sidebar .collapse .nav-link {
        padding: 0.6rem 1rem 0.6rem 2.5rem;
        font-size: 0.9rem;
        margin-bottom: 0;
    }
    
    .sidebar .collapse .nav-link::before {
        width: 3px;
        left: 1.5rem;
    }
    
    .sidebar .collapse .nav-link:hover {
        transform: translateX(3px);
    }

    .sidebar-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .sidebar-footer .btn {
        width: 100%;
        border-radius: 0.5rem;
        padding: 0.5rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }
    
    .sidebar-footer .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    
    .main-content {
        margin-left: var(--sidebar-width);
        width: calc(100% - var(--sidebar-width));
        transition: all var(--transition-speed) ease;
    }
    
    /* Mobile styles */
    @media (max-width: 768px) {
        .sidebar {
            width: 0;
            overflow: hidden;
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-width);
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
        }
        
        .main-content.expanded {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
        }
        
        .navbar {
            padding: 0.5rem 1rem;
        }
        
        .avatar-img {
            width: 32px;
            height: 32px;
        }
        
        .sidebar-brand h4 {
            font-size: 1.1rem;
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
    
    /* Modern navbar styles */
    .navbar {
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        padding: 0.75rem 1.5rem;
    }
    
    .navbar .dropdown-toggle {
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        transition: all 0.2s ease;
    }
    
    .navbar .dropdown-toggle:hover {
        background-color: #f1f5f9;
    }
    
    /* Content area styling */
    .container-fluid {
        padding: 1.5rem;
    }
    
    /* Alert styling */
    .alert {
        border-radius: 0.75rem;
        border: none;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    /* Toggle button for mobile */
    .sidebar-toggle {
        display: none;
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #64748b;
        margin-right: 1rem;
    }
    
    @media (max-width: 768px) {
        .sidebar-toggle {
            display: block;
        }
    }
    
</style>
<body class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h4 class="text-white"><?php echo t('system_title'); ?></h4>
        </div>
        <div class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo $_SERVER['PHP_SELF'] == '/Admin/dashboard.php' ? 'active' : ''; ?>" href="../Admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i><?php echo t('dashboard'); ?>
                    </a>
                </li>
               
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/user-control.php' ? 'active' : ''; ?>" href="../Admin/user-control.php">
                        <i class="bi bi-people"></i><?php echo t('user_management'); ?>
                    </a>
                </li>
               
                
                <li class="nav-item">
                    <a class="nav-link collapsed" data-bs-toggle="collapse" href="#itemManagementCollapse" role="button" aria-expanded="false">
                        <i class="bi bi-box-seam"></i><?php echo t('item_management'); ?>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="itemManagementCollapse">
                        <ul class="nav flex-column">
                        <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/items.php' ? 'active' : ''; ?>" href="../Admin/items.php">
                                    <i class="bi bi-box-seam"></i><?php echo t('item'); ?>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/stock-transfer.php' ? 'active' : ''; ?>" href="../Admin/stock-transfer.php">
                                    <i class="bi bi-arrow-left-right"></i><?php echo t('transfer'); ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/remaining.php' ? 'active' : ''; ?>" href="../Admin/remaining.php">
                                  <i class="bi bi-archive"></i><?php echo t('remaining'); ?>
                                </a>
                            </li>

                        <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/store.php' ? 'active' : ''; ?>" href="../Admin/store.php">
                                    <i class="bi bi-currency-dollar"></i><?php echo t('store_inventory'); ?>
                                </a>
                            </li>
                          
                       
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/repair.php' ? 'active' : ''; ?>" href="../Admin/repair.php">
                                    <i class="bi bi-tools"></i><?php echo t('repair'); ?>
                                </a>
                            </li>
                            
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/broken-items.php' ? 'active' : ''; ?>" href="../Admin/broken-items.php">
                                    <i class="bi bi-exclamation-triangle"></i><?php echo t('broken_items'); ?>
                                </a>
                            </li>
                           
                        </ul>
                    </div>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/location-control.php' ? 'active' : ''; ?>" href="../Admin/location-control.php">
                        <i class="bi bi-pin-map"></i><?php echo t('location_management'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/category.php' ? 'active' : ''; ?>" href="../Admin/category.php">
                        <i class="bi bi-card-list"></i><?php echo t('category_management'); ?>
                    </a>
                </li>
       
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/deporty.php' ? 'active' : ''; ?>" href="../Admin/deporty.php">
                        <i class="bi bi-geo-alt"></i><?php echo t('deporty_management'); ?>
                    </a>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/access-log.php' ? 'active' : ''; ?>" href="../Admin/access-log.php">
                        <i class="bi bi-clock-history"></i><?php echo t('logs'); ?>
                    </a>
                </li>
            
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/report.php' ? 'active' : ''; ?>" href="../Admin/report.php">
                        <i class="bi bi-file-earmark-text"></i><?php echo t('reports'); ?>
                    </a>
                </li>
              
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_SERVER['PHP_SELF']) == '/Admin/low-stock-alert.php' ? 'active' : ''; ?>" href="../Admin/low-stock-alert.php">
                        <i class="bi bi-exclamation-triangle"></i><?php echo t('low_stock'); ?>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidebar-footer">
            <a href="../logout.php" class="btn btn-outline-light">
                <i class="bi bi-box-arrow-right me-2"></i><?php echo t('logout'); ?>
            </a>
        </div>
    </div>

    <div class="main-content d-flex flex-column min-vh-100">
        <nav class="navbar navbar-expand navbar-light bg-white">
            <div class="container-fluid">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="bi bi-list"></i>
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
                                <?php if ($hasAvatar): ?>
                                    <img src="get_user_image.php?id=<?php echo $userId; ?>" class="avatar-img me-2" alt="<?php echo htmlspecialchars($username); ?>">
                                <?php else: ?>
                                    <div class="default-avatar me-2">
                                        <i class="bi bi-person-fill" style="font-size: 1.1rem;"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($username); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../Admin/profile.php"><i class="bi bi-person me-2"></i><?php echo t('profile'); ?></a></li>
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
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768) {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickInsideToggle = sidebarToggle.contains(event.target);
            
            if (!isClickInsideSidebar && !isClickInsideToggle && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        }
    });
});
</script>