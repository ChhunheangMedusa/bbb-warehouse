<?php
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .code { 
            font-size: 24px; 
            font-weight: bold; 
            letter-spacing: 3px; 
            color: #0A7885;
            padding: 10px 15px;
            background: #f0f0f0;
            display: inline-block;
            margin: 10px 0;
        }
        .footer { margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Password Reset Request</h2>
        <p>You requested a password reset for your account.</p>
        <p>Your verification code is: <span class="code"><?php echo $token; ?></span></p>
        <p>This code is valid for 5 minutes.</p>
        <div class="footer">
            <p>If you didn't request this, please ignore this email.</p>
            <p>© <?php echo date('Y'); ?> Inventory Management System</p>
        </div>
    </div>
</body>
</html>
<?php
return ob_get_clean();