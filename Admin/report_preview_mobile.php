<?php
session_start();
// Check if report data exists in session
if (!isset($_SESSION['report_data']) || !isset($_SESSION['report_type']) || !isset($_SESSION['report_criteria'])) {
    die('No report data found');
}

// Store data in JavaScript-accessible format
echo '<script>';
echo 'sessionStorage.setItem("reportData", ' . json_encode($_SESSION['report_data']) . ');';
echo 'sessionStorage.setItem("reportType", "' . $_SESSION['report_type'] . '");';
echo 'sessionStorage.setItem("reportCriteria", ' . json_encode($_SESSION['report_criteria']) . ');';
echo '</script>';
?>