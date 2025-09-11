<?php
session_start();

if (isset($_GET['lang']) && in_array($_GET['lang'], ['km', 'en'])) {
    $_SESSION['language'] = $_GET['lang'];
}

// Redirect back to the previous page
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
?>