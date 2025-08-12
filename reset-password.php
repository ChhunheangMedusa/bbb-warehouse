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
        $error = "ពាក្យសម្ងាត់មិនដូចគ្នាទេ";
    } elseif (strlen($password) < 8) {
        $error = "ពាក្យសម្ងាត់ត្រូវតែមានយ៉ាងហោចណាស់ ៨ តួអក្សរ";
    } else {
        // Check if new password is same as old password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$reset['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($password, $user['password'])) {
            $error = "ពាក្យសម្ងាត់ថ្មីមិនអាចដូចគ្នានឹងពាក្យសម្ងាត់ចាស់បានទេ";
        } else {
            // Update password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed_password, $reset['user_id']]);
            
            // Mark token as used
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
            
            // Clear session
            unset($_SESSION['reset_token']);
            unset($_SESSION['reset_email']);
            
            $success = "ពាក្យសម្ងាត់របស់អ្នកត្រូវបានកំណត់ឡើងវិញដោយជោគជ័យ </br></br>  អ្នកអាចចូលប្រើប្រាស់ជាមួយនឹងពាក្យសម្ងាត់ថ្មីរបស់អ្នកបានហើយ";
            
           
        }
    }
}
?>

<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
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
        background-color: #005064;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 0;
        padding: 20px;
        background-image: 
            radial-gradient(circle at 10% 20%, rgba(78, 115, 223, 0.05) 0%, transparent 20%),
            radial-gradient(circle at 90% 80%, rgba(78, 115, 223, 0.05) 0%, transparent 20%);
    }

    .login-box {
        width: 100%;
        max-width: 420px;
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(78, 115, 223, 0.15);
        overflow: hidden;
        position: relative;
    }

    .login-header {
        background: #0A7885;
        color: white;
        padding: 1.5rem;
        text-align: center;
    }

    .login-header img {
        height: 150px;
        margin-bottom: 1rem;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
    }

    .login-header h3 {
        margin: 0;
        font-weight: 700;
        font-size: 1.5rem;
    }

    .login-body {
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
        position: relative;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--dark);
        font-weight: 600;
    }

    .form-control {
    width: 100%;
    padding: 0.75rem 2.5rem 0.75rem 1rem; /* Added more right padding */
    border: 1px solid #d1d3e2;
    border-radius: 8px;
    transition: all 0.3s;
    font-size: 1rem;
    box-sizing: border-box;
}
    .form-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        outline: none;
    }

    .btn-login {
        width: 100%;
        padding: 0.75rem;
        background: #0A7885;
        border: none;
        color: white;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 1rem;
        margin-top: 0.5rem;
        font-family:"Khmer OS Siemreap", sans-serif;
    }

    .btn-login:hover {
        background-position: right center;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(78, 115, 223, 0.3);
    }

    .alert {
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }

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

    /* Modal styles */
    .modal-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
    .input-icon {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--primary);
    cursor: pointer;
}

    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-header">
            <img src="assets/images/white_logo.png" alt="Logo">
            <h3>កំណត់ពាក្យសម្ងាត់ថ្មី</h3>
        </div>
        
        <div class="login-body">
            <?php if ($success): ?>
                <div class="alert alert-success" style="color: green;"><?php echo $success; ?></div>
            <?php else: ?>
                <form method="POST" action="" id="resetForm">
                    <div class="form-group">
                        <label for="password" class="form-label">ពាក្យសម្ងាត់ថ្មី</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <i class="bi bi-eye-slash input-icon" id="togglePassword" style="margin-top:13px;"></i>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password-strength-bar"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">បញ្ជាក់ពាក្យសម្ងាត់ថ្មី</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <i class="bi bi-eye-slash input-icon" id="togglePassword2" style="margin-top:18px;"></i>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="bi bi-key"></i> កំណត់ពាក្យសម្ងាត់ថ្មី
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error || $success): ?>
    <div class="modal fade show" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="false" style="display: block; background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header <?php echo $error ? 'bg-danger text-white' : 'bg-success text-white'; ?>">
                    <h5 class="modal-title" id="alertModalLabel">
                        <i class="bi <?php echo $error ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?>"></i>
                        <?php echo $error ? 'កំហុស' : 'ជោគជ័យ'; ?>
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
                            <i class="bi bi-check-circle"></i> យល់ព្រម
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