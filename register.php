<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $user_type = 'staff'; // Default to staff, admin can change later
    $email = sanitizeInput($_POST['email']);
    
    if ($password !== $confirm_password) {
        $error = "ពាក្យសម្ងាត់មិនត្រូវគ្នាទេ។";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            // Check if admin exists
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // First user becomes admin
            if ($result['count'] == 0) {
                $user_type = 'admin';
            }
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password, user_type, email) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $user_type, $email]);
            
            $_SESSION['success'] = "គណនីរបស់អ្នកត្រូវបានបង្កើតដោយជោគជ័យ។ សូមចូលឥឡូវនេះ។";
            redirect('index.php');
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = "ឈ្មោះអ្នកប្រើប្រាស់មានរួចហើយ។";
            } else {
                $error = "មានបញ្ហាក្នុងការបង្កើតគណនី។";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ចុះឈ្មោះ | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">

    <style>
        
        body {
            font-family: 'Khmer OS Siemreap', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .register-container {
            max-width: 400px;
            width: 100%;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        .logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-container mx-auto">
            <div class="text-center mb-4">
                <img src="assets/images/logo.png" alt="Logo" class="logo">
                <h3>ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</h3>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">ឈ្មោះអ្នកប្រើប្រាស់</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">អ៊ីមែល</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">ពាក្យសម្ងាត់</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">បញ្ជាក់ពាក្យសម្ងាត់</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">ចុះឈ្មោះ</button>
                <div class="text-center mt-3">
                    <a href="index.php">ចូលប្រើប្រាស់</a>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>