<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    // Get user info from database using the session user_id
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    

    
    if ($user) {
        logActivity($_SESSION['user_id'], 'Logout', "User logged out: {$user['username']} $logoutReason");
    }
    
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Delete remember me cookie if exists
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }
}

// Redirect based on logout reason
if (isset($_GET['inactivity']) && $_GET['inactivity'] === 'true') {
    redirect('login.php?inactivity=true');
} else {
    redirect('index.php');
}
?>