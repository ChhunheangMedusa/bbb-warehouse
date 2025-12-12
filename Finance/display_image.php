<?php
require_once '../config/database.php';

if(isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT image_path FROM item_images WHERE id = ?");
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    
    if($image) {
        header("Content-Type: image/jpeg"); // Adjust if storing other image types
        echo $image['image_path'];
        exit;
    }
}

// Return a default image if not found
header("Content-Type: image/png");
readfile('path/to/default-image.png');
?>