
<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isAdmin() && !isFinanceStaff()) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT image_data FROM finance_invoice WHERE id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice && $invoice['image_data']) {
        header("Content-Type: image/jpeg");
        echo $invoice['image_data'];
        exit();
    }
}

// Default no image
header("Content-Type: image/png");
echo file_get_contents('../assets/images/no-image.png');
?>
