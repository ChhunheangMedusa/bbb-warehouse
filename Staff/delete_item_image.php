<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid image ID']);
    exit;
}

$image_id = (int)$_GET['id'];

try {
    // Get image path first
    $stmt = $pdo->prepare("SELECT image_path FROM item_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($image) {
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM item_images WHERE id = ?");
        $stmt->execute([$image_id]);
        
        // Delete file
        $file_path = '../assets/images/items/' . $image['image_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Image not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>