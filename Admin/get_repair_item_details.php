<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $repair_item_id = (int)$_GET['id'];
    
    try {
        $stmt = $pdo->prepare("SELECT from_location_id, to_location_id, item_name FROM repair_items WHERE id = ?");
        $stmt->execute([$repair_item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            echo json_encode([
                'success' => true,
                'from_location_id' => $item['from_location_id'],
                'to_location_id' => $item['to_location_id'],
                'item_name' => $item['item_name']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Item not found'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No ID provided'
    ]);
}
?>