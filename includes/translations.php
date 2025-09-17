<?php
// translations.php

// Initialize language settings if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'km'; // Default to Khmer
}
$translations = [
    'km' => [
        'dashboard' => 'ផ្ទាំងគ្រប់គ្រង',
        'user_management' => 'គ្រប់គ្រងអ្នកប្រើប្រាស់',
        'item_management' => 'គ្រប់គ្រងសម្ភារៈ',
        'location_management' => 'គ្រប់គ្រងទីតាំង',
        'stock_transfer' => 'ផ្ទេរសម្ភារៈ',
        'logs' => 'កំណត់ហេតុ',
        'reports' => 'របាយការណ៍',
        'low_stock' => 'ស្តុកទាប',
        'logout' => 'ចាកចេញ',
        'profile' => 'Profile',
        'system_title' => 'ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង',
        'stock_in'=>'សម្ភារៈចូលស្តុក',
        'stock_out'=>'សម្ភារៈចេញពីស្តុក',
        'repair'=>'សម្ភារៈជួសជុល',
        'transfer'=>'សម្ភារៈផ្ទេរចេញ',
        'item'=>'សម្ភារៈ',
        'category_management'=>'គ្រប់គ្រងប្រភេទសម្ភារៈ',
        'remaining'=>'សម្ភារៈនៅសល់',
        'store_inventory'=>'តារាងតម្លៃសម្ភារៈ',
        'broken_items'=>'សម្ភារៈខូច',
        'deporty_management'=>'គ្រប់គ្រងដេប៉ូ',
    ],
    'en' => [
        'deporty_management'=>'Deporty Management',
        'broken_items'=>'Broken Item',
        'store_inventory'=>'Price List',
        'remaining'=>'Remaining',
        'category_management'=>'Category Management',
        'dashboard' => 'Dashboard',
        'user_management' => 'User Management',
        'item_management' => 'Item Management',
        'location_management' => 'Location Management',
        'stock_transfer' => 'Stock Transfer',
        'logs' => 'Access Logs',
        'reports' => 'Reports',
        'low_stock' => 'Low Stock',
        'logout' => 'Logout',
        'profile' => 'Profile',
        'system_title' => 'Stock Management System',
        'stock_in'=>'Stock In',
        'stock_out'=>'Stock Out',
        'repair'=>'Repair',
        'transfer'=>'Transfer',
        'item'=>'Item'
    ]
];

if (!function_exists('t')) {
    function t($key) {
        global $translations;
        $lang = $_SESSION['language'] ?? 'km';
        return $translations[$lang][$key] ?? $key;
    }
}
  
?>