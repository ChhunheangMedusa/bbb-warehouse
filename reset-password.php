<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/forgot.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

// Check if we have a valid token in session
if (!isset($_SESSION['reset_token'])) {
    header('Location: forgot-password.php');
    exit();
}

// Get the reset record
$stmt = $pdo->prepare("SELECT * FROM password_resets 
                      WHERE token = ? AND used = 0 AND expires_at > NOW()");
$stmt->execute([$_SESSION['reset_token']]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reset) {
    header('Location: forgot-password.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error = "The password must be at least 8 characters long";
    } else {
        // Check if new password is same as old password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$reset['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($password, $user['password'])) {
            $error = "The new password cannot be the same as the old password";
        } else {
            // Update password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed_password, $reset['user_id']]);
            
            // Mark token as used
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
            
            // Clear session
            unset($_SESSION['reset_token']);
            unset($_SESSION['reset_email']);
            
            $success = "Your password has been successfully reset </br></br>  You can now log in using your new password";
            
           
        }
    }
}
?>

<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
    /* Same styles as forgot-password.php */
    :root {
        --primary: #4e73df;
        --primary-light: #7e9eff;
        --primary-dark: #3a56c8;
        --secondary: #f8f9fc;
        --white: #ffffff;
        --light: #f8f9fa;
        --dark: #5a5c69;
        --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
    }

    body {
        font-family: "Khmer OS Siemreap", sans-serif;
        background-color: #f5f5f5;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 0;
        padding: 20px;
    }

    .login-container {
        display: flex;
        width: 100%;
        max-width: 900px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        min-height: 550px;
    }

    img {
        flex: 1;
        background: rgb(255, 255, 255);
        color: BLACK;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 2rem;
        text-align: center;
        height: 500px;
        margin-top: 50px;
    }

    .login-left h2 {
        margin: 0;
        font-weight: 700;
        font-size: 1.8rem;
        margin-bottom: 1rem;
    }

    .login-left p {
        font-size: 1rem;
        opacity: 0.9;
        max-width: 300px;
    }

    .login-right {
        flex: 1;
        padding: 3rem 2.5rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .login-header {
        text-align: center;
        margin-bottom: 2rem;
    }

    .login-header h3 {
        font-weight: 700;
        color: #333;
        margin-bottom: 0.5rem;
    }

    .login-header p {
        color: #666;
    }

    .form-group {
        margin-bottom: 1.5rem;
        position: relative;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        color: #333;
        font-weight: 600;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid #ddd;
        border-radius: 8px;
        transition: all 0.3s;
        font-size: 1rem;
    }

    .form-control:focus {
        border-color: #0A7885;
        box-shadow: 0 0 0 0.2rem rgba(10, 120, 133, 0.25);
        outline: none;
    }

    .input-icon {
        position: absolute;
        right: 15px;
        top: 70%;
        transform: translateY(-50%);
        color: #777;
        cursor: pointer;
    }

    .btn-login {
        width: 100%;
        padding: 0.75rem;
        background: rgb(0, 0, 0);
        border: none;
        color: white;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 1rem;
        margin-top: 0.5rem;
        font-family: "Khmer OS Siemreap", sans-serif;
    }

    .btn-login:hover {
        background: rgb(0, 0, 0);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(10, 120, 133, 0.3);
    }

    .remember-me {
        display: flex;
        align-items: center;
        margin: 1rem 0;
    }

    .remember-me input {
        margin-right: 0.5rem;
    }

    .login-footer {
        display: flex;
        justify-content: space-between;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #eee;
    }

    .login-footer a {
        color: rgb(0, 0, 0);
        text-decoration: none;
        transition: all 0.3s;
        font-size: 0.9rem;
    }

    .login-footer a:hover {
        color: #08626d;
        text-decoration: underline;
    }

    .alert {
        border-radius: 8px;
        margin-bottom: 1.5rem;
        color: red;
        background-color: rgba(255, 0, 0, 0.05);
        border: 1px solid rgba(255, 0, 0, 0.2);
        padding: 0.75rem 1rem;
    }

    .social-login {
        margin-top: 1.5rem;
        text-align: center;
    }

    .social-login p {
        margin-bottom: 1rem;
        color: #666;
        position: relative;
    }

    .social-login p::before,
    .social-login p::after {
        content: "";
        position: absolute;
        top: 50%;
        width: 30%;
        height: 1px;
        background-color: #ddd;
    }

    .social-login p::before {
        left: 0;
    }

    .social-login p::after {
        right: 0;
    }

    .social-icons {
        display: flex;
        justify-content: center;
        gap: 1rem;
    }

    .social-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f5f5f5;
        color: #333;
        transition: all 0.3s;
    }

    .social-icon:hover {
        background: #0A7885;
        color: white;
        transform: translateY(-2px);
    }

    @media (max-width: 768px) {
        .login-container {
            flex-direction: column;
            max-width: 450px;
        }
        
        .login-left {
            padding: 2rem 1rem;
        }
        
        .login-right {
            padding: 2rem 1.5rem;
        }
    }

    /* Modern Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1050;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background-color: white;
        padding: 2rem;
        border-radius: 12px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        transform: translateY(-20px);
        transition: all 0.3s ease;
    }

    .modal-overlay.active .modal-content {
        transform: translateY(0);
    }

    .modal-icon {
        font-size: 3rem;
        color: #dc3545;
        margin-bottom: 1rem;
    }

    .modal-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 1rem;
       
    }

    .modal-message {
        margin-bottom: 1.5rem;
        font-size: 1rem;
        color: #495057;
    }

    .modal-button {
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.3s;
        font-weight: 600;
    }

    .modal-button:hover {
        background-color: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
    }

    .countdown-timer {
        font-size: 1.2rem;
        font-weight: 700;
        color: #dc3545;
        margin: 1rem 0;
    }

    .attempts-info {
        font-size: 0.9rem;
        color: #6c757d;
        margin-top: 0.5rem;
    }

    /* Password strength indicator */
    .password-strength {
        height: 5px;
        background-color: #e9ecef;
        margin-top: 5px;
        border-radius: 3px;
        overflow: hidden;
    }

    .password-strength-bar {
        height: 100%;
        width: 0%;
        transition: width 0.3s;
    }

    /* Code input styling */
    .code-input {
        letter-spacing: 0.5rem;
        text-align: center;
    }

    </style>
</head>
<body>


<div class="login-container">
   
     <img src="assets/images/login.png" alt="">
    
    
    <div class="login-right">
        <div class="login-header">
            <h3>Reset Password</h3>
            
        </div>  

        <?php if ($success): ?>
                <div class="alert alert-success" style="color: green;"><?php echo $success; ?></div>
            <?php else: ?>
                <form method="POST" action="" id="resetForm">
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <i class="bi bi-eye-slash input-icon" id="togglePassword" style="margin-top:13px;"></i>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password-strength-bar"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <i class="bi bi-eye-slash input-icon" id="togglePassword2" style="margin-top:18px;"></i>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="bi bi-key"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>
        
        
    </div>
</div>



  

    <?php if ($error || $success): ?>
    <div class="modal fade show" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="false" style="display: block; background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header <?php echo $error ? 'text-danger' : 'text-success'; ?>">
                    <h5 class="modal-title" id="alertModalLabel">
                        <i class="bi <?php echo $error ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?>"></i>
                        <?php echo $error ? 'Error' : 'Success'; ?>
                    </h5>
                    <button type="button" class="btn-close <?php echo $error ? 'btn-close-white' : ''; ?>" onclick="closeModal()" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="modal-icon <?php echo $error ? 'text-danger' : 'text-success'; ?>">
                        <i class="bi <?php echo $error ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill'; ?>"></i>
                    </div>
                    <h4 class="<?php echo $error ? 'text-danger' : 'text-success'; ?> mb-3" style="font-size:18px;">
                        <?php echo $error ? $error : $success; ?>
                    </h4>
                </div>
                <div class="modal-footer justify-content-center">
                    <?php if ($error): ?>
                        <button type="button" class="btn btn-danger" onclick="closeModal()">
                            <i class="bi bi-check-circle"></i> Agree
                        </button>
                    <?php else: ?>
                        <a href="index.php" class="btn btn-success">
                            <i class="bi bi-arrow-right"></i> ទៅទំព័រចូល
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
            document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const passwordInput2 = document.getElementById('confirm_password');
        
        const icon = this;
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        }
    


    });
    document.getElementById('togglePassword2').addEventListener('click', function() {
        const passwordInput = document.getElementById('confirm_password');

        
        const icon = this;
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        }
      

    });
    // Password strength indicator
    document.getElementById('password').addEventListener('input', function(e) {
        const password = e.target.value;
        const strengthBar = document.getElementById('password-strength-bar');
        let strength = 0;
        
        if (password.length > 0) strength += 20;
        if (password.length >= 8) strength += 20;
        if (/[A-Z]/.test(password)) strength += 20;
        if (/[0-9]/.test(password)) strength += 20;
        if (/[^A-Za-z0-9]/.test(password)) strength += 20;
        
        strengthBar.style.width = strength + '%';
        
        if (strength < 40) {
            strengthBar.style.backgroundColor = '#dc3545';
        } else if (strength < 80) {
            strengthBar.style.backgroundColor = '#fd7e14';
        } else {
            strengthBar.style.backgroundColor = '#28a745';
        }
    });

    function closeModal() {
        const modal = document.getElementById('alertModal');
        modal.style.display = 'none';
        modal.classList.remove('show');
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('alertModal');
        if (modal && e.target === modal) {
            closeModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('alertModal');
        if (modal && modal.style.display === 'block' && e.key === 'Escape') {
            closeModal();
        }
    });
    </script>
</body>
</html>