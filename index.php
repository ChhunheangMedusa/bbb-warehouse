<?php
// Include configuration and functions first
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/forgot.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize blocked users in session if not set
if (!isset($_SESSION['login_blocked_users'])) {
    $_SESSION['login_blocked_users'] = array();
}

$error = '';

// Regular login processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'] ?? ''; // Make password optional
    
    // First check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Check if user is blocked
        if ($user['is_blocked']) {
            $error = "គណនីអ្នកត្រូវបានរារាំង។ សូមទាក់ទងអ្នកគ្រប់គ្រង។";
        } 
        // Check if user is temporarily blocked
        else if (isset($_SESSION['login_blocked_users'][$user['id']])) {
            $block_time = $_SESSION['login_blocked_users'][$user['id']];
            if ($block_time > time()) {
                $remaining_time = $block_time - time();
                $minutes = ceil($remaining_time / 60);
                $error = "សូមរង់ចាំ $minutes នាទី មុនពេលព្យាយាមម្តងទៀត។";
            } else {
                unset($_SESSION['login_blocked_users'][$user['id']]);
            }
        } else {
            // Handle guest login (no password required)
            if ($user['user_type'] === 'guest') {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['picture'] = $user['picture'];
                $_SESSION['show_welcome'] = true;
                logActivity($user['id'], 'Login', "Guest user logged in: {$username} ");
                
                // Redirect guest to stock-in.php
                header("Location: Guest/remaining.php");
                exit();
            }
            // Handle regular users (with password)
            else {
                // Password is required for non-guest users
                if (empty($password)) {
                    $error = "សូមបញ្ចូលពាក្យសម្ងាត់។";
                } 
                // Validate password length
                else if (strlen($password) < 8) {
                    $error = "ពាក្យសម្ងាត់ត្រូវតែមានយ៉ាងហោចណាស់ ៨ តួអក្សរ។";
                } else if (password_verify($password, $user['password'])) {
                    // Reset login attempts for this user in database
                    $resetStmt = $pdo->prepare("UPDATE users SET login_attempts = 0, last_attempt_time = NULL WHERE id = ?");
                    $resetStmt->execute([$user['id']]);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['picture'] = $user['picture'];
                    $_SESSION['show_welcome'] = true;
                    logActivity($user['id'], 'Login', "User logged in: {$username} ");
                    
                    // Redirect based on user type
                    $dashboard = ($user['user_type'] == 'admin') ? 'Admin/dashboard.php' : 'Staff/dashboard-staff.php';
                    
                    if (isset($_SESSION['redirect_url'])) {
                        $redirect_url = $_SESSION['redirect_url'];
                        unset($_SESSION['redirect_url']);
                        header("Location: $redirect_url");
                    } else {
                        header("Location: $dashboard");
                    }
                    exit();
                } else {
                    // INCORRECT PASSWORD - Increment login attempts and show error
                    $error = "ឈ្មោះអ្នកប្រើប្រាស់ និងពាក្យសម្ងាត់មិនត្រឹមត្រូវ។";
                    
                    // Increment login attempts in database
                    $updateStmt = $pdo->prepare("UPDATE users SET login_attempts = login_attempts + 1, last_attempt_time = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    // Check if user should be blocked due to too many attempts
                    $stmt = $pdo->prepare("SELECT login_attempts FROM users WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    $attempts = $stmt->fetchColumn();
                    
                    if ($attempts >= 3) {
                        // Block user for 5 minutes after 3 failed attempts
                        $_SESSION['login_blocked_users'][$user['id']] = time() + 300; // 5 minutes
                        $error = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។ សូមរង់ចាំ 5 នាទី។";
                    }
                }
            }
        }
    } else {
        // User doesn't exist - we can't track attempts in DB for non-existent users
        // So we'll use session for unknown usernames
        if (!isset($_SESSION['unknown_user_attempts'][$username])) {
            $_SESSION['unknown_user_attempts'][$username] = 0;
        }
        $_SESSION['unknown_user_attempts'][$username]++;
        $current_attempts = $_SESSION['unknown_user_attempts'][$username];
        
        if ($current_attempts > 7) {
            $error = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។ សូមរង់ចាំ 24 ម៉ោង។";
        } elseif ($current_attempts == 7) {
            $error = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។ សូមរង់ចាំ 5 នាទី។";
        } elseif ($current_attempts >= 5) {
            $error = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។ សូមរង់ចាំ 3 នាទី។";
        } elseif ($current_attempts >= 3) {
            $error = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។ សូមរង់ចាំ 1 នាទី។";
        } else {
            $error = "ឈ្មោះអ្នកប្រើប្រាស់ និងពាក្យសម្ងាត់មិនត្រឹមត្រូវ។ អ្នកមាន ".(3 - $current_attempts)." ដងទៀតដើម្បីព្យាយាម។";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ចូលប្រព័ន្ធ | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
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
    margin: 0 auto;
    }

    .form-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        outline: none;
    }

    .input-icon {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--primary);
        cursor: pointer;
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
        color: var(--primary);
        text-decoration: none;
        transition: all 0.3s;
        font-size: 0.9rem;
    }

    .login-footer a:hover {
        color: var(--primary-dark);
        text-decoration: underline;
    }

    .alert {
        border-radius: 8px;
        margin-bottom: 1.5rem;
        color:red;
    }

    @media (max-width: 576px) {
        .login-box {
            border-radius: 0;
        }
        
        body {
            padding: 0;
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
        color: #dc3545;
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
    </style>
</head>
<body>

<div class="login-box">
        <div class="login-header">
            <img src="" alt="Logo">
            <h3>ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</h3>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="username" class="form-label">ឈ្មោះអ្នកប្រើប្រាស់</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                    <i class="bi bi-person input-icon" style="margin-top:17px;"></i>
                </div>
                
                <div class="form-group" id="passwordGroup">
                    <label for="password" class="form-label">ពាក្យសម្ងាត់</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8">
                    <i class="bi bi-eye-slash input-icon" id="togglePassword" style="margin-top:6px;"></i>
                    <a href="forgot-password.php" class="text-primary">ភ្លេចពាក្យសម្ងាត់?</a>
                </div>
                
                <button type="submit" class="btn-login" id="loginButton">
                    <i class="bi bi-box-arrow-in-right"></i> ចូល
                </button>
            </form>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal-overlay" id="errorModal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h3 class="modal-title">កំហុសក្នុងការចូល</h3>
            <p class="modal-message" id="modalMessage">ពាក្យសម្ងាត់មិនត្រឹមត្រូវ។</p>
            <div class="countdown-timer" id="countdownTimer" style="display: none;">
                សូមរង់ចាំ: <span id="countdown">05:00</span>
            </div>
            <p class="attempts-info" id="attemptsInfo"></p>
            <button class="modal-button" id="modalButton">យល់ព្រម</button>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
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

 // Update the username blur event to make an AJAX call to check user type
document.getElementById('username').addEventListener('blur', function() {
    const username = this.value.trim();
    if (username) {
        fetch('check_user_type.php?username=' + encodeURIComponent(username))
            .then(response => response.json())
            .then(data => {
                const passwordGroup = document.getElementById('passwordGroup');
                if (data.user_type === 'guest') {
                    passwordGroup.style.display = 'none';
                    document.getElementById('password').removeAttribute('required');
                } else {
                    passwordGroup.style.display = 'block';
                    document.getElementById('password').setAttribute('required', 'required');
                }
            })
            .catch(error => {
                console.error('Error checking user type:', error);
            });
    }
});

    // Show modal if there's an error
    <?php if ($error): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('errorModal');
            const modalMessage = document.getElementById('modalMessage');
            const countdownTimer = document.getElementById('countdownTimer');
            const attemptsInfo = document.getElementById('attemptsInfo');
            
            modalMessage.textContent = "<?php echo addslashes($error); ?>";
            
            <?php if (isset($user) && isset($_SESSION['login_blocked_users'][$user['id']])): ?>
                // Show countdown timer for blocked case (don't auto-hide)
                countdownTimer.style.display = 'block';
                attemptsInfo.textContent = "អ្នកបានព្យាយាមចូលច្រើនដងពេក។";
                
                // Start countdown
                const blockedUntil = <?php echo isset($user) ? $_SESSION['login_blocked_users'][$user['id']] : 0; ?>;
                const now = <?php echo time(); ?>;
                let remaining = blockedUntil - now;
                
                const updateCountdown = () => {
                    remaining--;
                    
                    if (remaining <= 0) {
                        clearInterval(countdownInterval);
                        countdownTimer.style.display = 'none';
                        window.location.reload();
                        return;
                    }
                    
                    const minutes = Math.floor(remaining / 60);
                    const seconds = remaining % 60;
                    document.getElementById('countdown').textContent = 
                        `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                };
                
                updateCountdown();
                const countdownInterval = setInterval(updateCountdown, 1000);
            <?php else: ?>
                // Show attempts info for regular errors
                const attempts = <?php echo isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] : 0; ?>;
                if (attempts > 0) {
                    attemptsInfo.textContent = `អ្នកមាន ${3 - attempts} ដងទៀតដើម្បីព្យាយាម។`;
                    
                    // Don't auto-hide for password/attempts messages
                    const isPasswordError = "<?php echo addslashes($error); ?>".includes("ពាក្យសម្ងាត់មិនត្រឹមត្រូវ") || 
                                          "<?php echo addslashes($error); ?>".includes("ឈ្មោះអ្នកប្រើប្រាស់");
                    
                    if (!isPasswordError) {
                        // Auto-hide after 5 seconds for other error messages
                        setTimeout(() => {
                            modal.classList.remove('active');
                        }, 5000);
                    }
                }
            <?php endif; ?>
            
            modal.classList.add('active');
            
            // Close modal when button is clicked
            document.getElementById('modalButton').addEventListener('click', function() {
                modal.classList.remove('active');
            });
        });
    <?php endif; ?>
    </script>
</body>
</html>