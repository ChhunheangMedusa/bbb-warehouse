<?php
ob_start();
require_once __DIR__ . '/auth.php';
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
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង'; ?></title>
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
    <!-- Custom CSS -->
   
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
        width: 100px;
        height: 30px;
        border-radius: 50%;
        background-color: #4e73df;
        color: white;
        font-weight: bold;
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
