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
        'item_management' => 'គ្រប់គ្រងទំនិញ',
        'location_management' => 'គ្រប់គ្រងទីតាំង',
        'stock_transfer' => 'ផ្ទេរទំនិញ',
        'logs' => 'កំណត់ហេតុ',
        'reports' => 'របាយការណ៍',
        'low_stock' => 'ស្តុកទាប',
        'logout' => 'ចាកចេញ',
        'profile' => 'Profile',
        'system_title' => 'ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង'
    ],
    'en' => [
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
        'system_title' => 'Stock Management System'
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