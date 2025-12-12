<?php
require_once '../config/database.php';

if(isset($_GET['id'])) {
    $image_id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT image_path, item_id FROM item_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($image && !empty($image['image_path'])) {
        header('Content-Type: image/jpeg');
        echo $image['image_path'];
        exit;
    }
}

// Return a placeholder image if no image found
header('Content-Type: image/svg+xml');
echo '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14" fill="#6c757d">No Image</text></svg>';
?>