<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/forgot.php';
require 'vendor/autoload.php'; // Add this for PHPMailer

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Generate 6-digit code
        $token = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        // Delete any existing tokens for this user
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
        
        // Insert new token
        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expires_at]);
        
        // Send email using PHPMailer
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP server
            $mail->SMTPAuth   = true;
            $mail->Username   = 'chhunheangxiaowu@gmail.com'; // Your Gmail address
            $mail->Password   = 'hoyq ibde jjza ikde'; // Use App Password if 2FA is enabled
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('chhunheangxiaowu@gmail.com', 'B. Brilliant Builder Co., Ltd');
            $mail->addAddress($email, $user['username']);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code';
            $mail->Body    = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .code { 
                            font-size: 24px; 
                            font-weight: bold; 
                            color: #0A7885;
                            margin: 20px 0;
                            padding: 10px;
                            background: #f5f5f5;
                            display: inline-block;
                        }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <h2>Password Reset Request</h2>
                        <p>You requested to reset your password. Here is your verification code:</p>
                        <div class="code">' . $token . '</div>
                        <p>This code will expire in 30 minutes.</p>
                        <p>If you didn\'t request this, please ignore this email.</p>
                    </div>
                </body>
                </html>
            ';
            $mail->AltBody = "លេខកូដ​ផ្ទៀងផ្ទាត់​របស់​អ្នក​គឺ៖ $token\n\nលេខកូដ​នេះ​នឹង​ផុត​កំណត់​ក្នុង​រយៈ​ពេល ៣០ នាទី";
            
            $mail->send();
            
            // Store in session for verification
            $_SESSION['reset_token'] = $token;
            $_SESSION['reset_email'] = $email;

            $success = "លេខកូដ​ផ្ទៀងផ្ទាត់ ៦ ខ្ទង់ ត្រូវ​បាន​ផ្ញើ​ទៅ​អ៊ីមែល​របស់​អ្នក​ហើយ";
          
        } catch (Exception $e) {
            $error = "ការផ្ញើសារមិនបានសម្រេច។ កំហុសក្នុងការផ្ញើ: {$mail->ErrorInfo}";
        }
    } else {
        $error = "រកមិនឃើញគណនីណាមួយដែលមានអាសយដ្ឋានអ៊ីមែលនេះទេ";
    }
}
?>


<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    
    <style>
    /* Use the same styles as login page */
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
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-header">
            <img src="assets/images/white_logo.png" alt="Logo">
            <h3>ភ្លេចពាក្យសម្ងាត់</h3>
        </div>
        
        <div class="login-body">
           
            
        
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email" class="form-label">អ៊ីមែល</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="bi bi-send"></i> ផ្ញើលេខកូដបញ្ជាក់
                    </button>
                </form>
                
                <div class="text-center mt-3">
                    <a href="index.php" class="text-primary">ត្រឡប់ទៅចូលប្រព័ន្ធវិញ</a>
                </div>
      
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
                <div class="modal-icon <?php echo $error ? 'text-danger' : 'text-success'; ?>" style="font-size: 3rem;">
                    <i class="bi <?php echo $error ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill'; ?>"></i>
                </div>
                <h4 class="<?php echo $error ? 'text-danger' : 'text-success'; ?> mb-3"style="font-size:18px;">
                    <?php echo $error ? $error : $success; ?>
                </h4>
            </div>
            <div class="modal-footer justify-content-center">
    <?php if ($error): ?>
        <button type="button" class="btn btn-danger" onclick="closeModal()">
            <i class="bi bi-check-circle"></i> យល់ព្រម
        </button>
    <?php else: ?>
        <a href="verify-code.php" class="btn btn-success">
            <i class="bi bi-arrow-right"></i> បន្តទៅការបញ្ជាក់
        </a>
    <?php endif; ?>
</div>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
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