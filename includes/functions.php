<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

function sendEmail($to, $subject, $body) {
    $mailConfigPath = __DIR__ . '/../config/mail.php';
    
    // Verify config file exists
    if (!file_exists($mailConfigPath)) {
        error_log("Mail configuration file not found at: $mailConfigPath");
        return false;
    }
    
    $mailConfig = include $mailConfigPath;
    
    // Verify config is valid
    if (!is_array($mailConfig) ){
        error_log("Invalid mail configuration format");
        return false;
    }
    
    // Verify required keys exist
    $requiredKeys = ['host', 'username', 'password', 'port', 'encryption', 'from_email', 'from_name'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $mailConfig)) {
            error_log("Missing required mail configuration key: $key");
            return false;
        }
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $mailConfig['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailConfig['username'];
        $mail->Password   = $mailConfig['password'];
        $mail->SMTPSecure = $mailConfig['encryption'];
        $mail->Port       = $mailConfig['port'];
        
        // Recipients
        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
function getLocationName($pdo, $locationId) {
    if (!$locationId) return 'N/A';
    
    $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
    $stmt->execute([$locationId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $location ? $location['name'] : 'N/A';
}
// General utility functions
function logActivity($userId, $activityType, $activityDetail) {
    global $pdo;
    
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, activity_type, activity_detail, ip_address) 
                          VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $activityType, $activityDetail, $ip]);
}

function financelog($user_id, $activity_type, $activity_detail) {
    global $pdo;
    
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $pdo->prepare("
            INSERT INTO finance_logs (user_id, activity_type, activity_detail, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $activity_type,
            $activity_detail,
            $ip_address,
            $user_agent
        ]);
        
        return true;
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

function getCategoryName($pdo, $category_id) {
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    return $category ? $category['name'] : 'Unknown';
}
function checkLowStock() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT i.id, i.name, i.quantity, i.size, l.name as location 
                         FROM items i 
                         JOIN locations l ON i.location_id = l.id 
                         WHERE i.quantity < 10 
                         AND NOT EXISTS (SELECT 1 FROM low_stock_alerts WHERE item_id = i.id AND notified = 1)");
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lowStockItems as $item) {
        $stmt = $pdo->prepare("INSERT INTO low_stock_alerts (item_id, threshold, notified) VALUES (?, ?, ?)");
        $stmt->execute([$item['id'], 10, 1]);
        
        logActivity(null, 'System', "Low Stock Alert: {$item['name']} ({$item['quantity']} {$item['size']}) at {$item['location']}");
    }
}


function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function redirect($url) {
    // Clear any previous output
    if (ob_get_length()) {
        ob_end_clean();
    }
    header("Location: $url");
    exit();
}
?>