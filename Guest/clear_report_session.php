<?php
session_start();
if (isset($_POST['clear_report'])) {
    unset($_SESSION['report_data']);
    unset($_SESSION['report_type']);
    unset($_SESSION['report_criteria']);
    echo "Session cleared";
}
?>