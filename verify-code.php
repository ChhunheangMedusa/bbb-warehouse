<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/forgot.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$email = isset($_GET['email']) ? $_GET['email'] : (isset($_SESSION['reset_email']) ? $_SESSION['reset_email'] : '');

if (!$email) {
    header('Location: forgot-password.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = sanitizeInput($_POST['code']);
    
    // Check if code is valid
    $stmt = $pdo->prepare("SELECT * FROM password_resets 
                           WHERE token = ? AND user_id = (SELECT id FROM users WHERE email = ?) 
                           AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$code, $email]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reset) {
        $_SESSION['reset_token'] = $code;
        header('Location: reset-password.php');
        exit();
    } else {
        $error = "កូដ​ផ្ទៀងផ្ទាត់​មិនត្រឹមត្រូវ ឬ​អស់​សុពលភាព";
    }
}
?>

<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
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
        padding: 0.75rem 1rem;
        border: 1px solid #d1d3e2;
        border-radius: 8px;
        transition: all 0.3s;
        font-size: 1rem;
        text-align: center;
        letter-spacing: 0.5rem;
        box-sizing:border-box;
    }

    .form-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        outline: none;
    }

    .btn-login {
        width: 100%;
        padding: 0.75rem;
        background:#0A7885;
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
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-header">
            <img src="assets/images/white_logo.png" alt="Logo">
            <h3>បញ្ជាក់លេខកូដ</h3>
        </div>
        
        <div class="login-body">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="code" class="form-label">លេខកូដបញ្ជាក់ 6 ខ្ទង់</label>
                    <input type="text" class="form-control" id="code" name="code" 
                           maxlength="6" pattern="\d{6}" required
                           placeholder="••••••">
                </div>
                
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                
                <button type="submit" class="btn-login">
                    <i class="bi bi-check-circle"></i> បញ្ជាក់
                </button>
            </form>
            
            <div class="text-center mt-3">
                <a href="forgot-password.php" class="text-primary">ផ្ញើលេខកូដឡើងវិញ</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="modal fade show" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="false" style="display: block; background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="alertModalLabel">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        កំហុស
                    </h5>
                    <button type="button" class="btn-close btn-close-white" onclick="closeModal()" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="modal-icon text-danger" style="font-size: 3rem;">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                    </div>
                    <h4 class="text-danger mb-3"style="font-size:18px;">
                        <?php echo $error; ?>
                    </h4>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" onclick="closeModal()">
                        <i class="bi bi-check-circle"></i> យល់ព្រម
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
    // Auto focus and move between digits
    document.getElementById('code').focus();
    
    document.getElementById('code').addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    function closeModal() {
        const modal = document.getElementById('alertModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
        }
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