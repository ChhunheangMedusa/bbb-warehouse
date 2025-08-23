<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header("Content-Type: image/png"); // Default to PNG if no image

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    try {
        $stmt = $pdo->prepare("SELECT picture FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['picture'])) {
            // Output the image data with correct content type
            // Note: In a real application, you should store and detect the actual image type
            echo $result['picture'];
            exit;
        }
    } catch (PDOException $e) {
        // Log error if needed
    }
}

// Fallback to default image if no image found
$default_image = file_get_contents('../assets/images/users/default.png');
header("Content-Type: image/png");
echo $default_image;
?>