<?php
require_once '../config/database.php';

$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

if ($location_id) {
    $stmt = $pdo->prepare("SELECT DISTINCT name FROM items WHERE location_id = ? ORDER BY name");
    $stmt->execute([$location_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($items);
}