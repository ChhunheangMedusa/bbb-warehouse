<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php'; // Include functions.php first

// Authentication functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function checkAuth() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        $_SESSION['error'] = "សូមចូលប្រើប្រាស់ដើម្បីបន្ត";
        header('Location: index.php');
        exit();
    }
}
function checkRememberMe() {
    global $pdo;
    
    if (isset($_COOKIE['remember_token'])) {
        list($userId, $token) = explode(':', $_COOKIE['remember_token'], 2);
        
        // Look up token in database
        $stmt = $pdo->prepare("SELECT * FROM remember_tokens WHERE user_id = ? AND expires_at > NOW()");
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tokens as $storedToken) {
            if (password_verify($token, $storedToken['token_hash'])) {
                // Token is valid - log the user in
                $user = getUserById($userId);
                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['picture'] = $user['picture'];
                    
                    logActivity($user['id'], 'Login', "{$user['username']} logged in via remember me");
                    
                    // Rotate token (optional security measure)
                    $newToken = bin2hex(random_bytes(32));
                    $newHashedToken = password_hash($newToken, PASSWORD_DEFAULT);
                    $expiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
                    
                    $stmt = $pdo->prepare("UPDATE remember_tokens SET token_hash = ?, expires_at = ? WHERE id = ?");
                    $stmt->execute([$newHashedToken, $expiry, $storedToken['id']]);
                    
                    setcookie(
                        'remember_token', 
                        $userId . ':' . $newToken, 
                        time() + (30 * 24 * 60 * 60), 
                        "/", 
                        "", 
                        true,  // Secure
                        true   // HttpOnly
                    );
                    
                    return true;
                }
            }
        }
        
        // Invalid token - clear cookie
        setcookie('remember_token', '', time() - 3600, "/");
    }
    
    return false;
}
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'staff';
}

function checkAdminAccess() {
    if (!isAdmin()) {
        $_SESSION['error'] = "អ្នកមិនមានសិទ្ធិប្រើប្រាស់ទំព័រនេះទេ";
        header('Location: dashboard.php');
        exit();
    }
}

// Handle remember me functionality
if (isset($_COOKIE['remember_token']) && !isLoggedIn()) {
    $token = $_COOKIE['remember_token'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && strtotime($user['reset_token_expiry']) > time()) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['picture'] = $user['picture'];
        
        logActivity($user['id'], 'Login', "User logged in via remember token");
    } else {
        // Clear invalid remember token
        setcookie('remember_token', '', time() - 3600, '/');
    }
}
?>