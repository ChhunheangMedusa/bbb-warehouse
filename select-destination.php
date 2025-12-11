<?php
// Include configuration and start session
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['destination'])) {
    $destination = $_POST['destination'];
    
    // Store the selection in session (optional)
    $_SESSION['selected_destination'] = $destination;
    
    // Redirect based on selection
    if ($destination === 'stock') {
        // Redirect to regular dashboard
        if ($_SESSION['user_type'] == 'admin') {
            header("Location: Admin/dashboard.php");
        } else {
            header("Location: Staff/dashboard-staff.php");
        }
        exit();
    } elseif ($destination === 'other') {
        // Redirect to your other PHP file
        header("Location: your-other-file.php"); // Change this to your actual file
        exit();
    }
}

// If user has already made a selection and is coming back, redirect them
if (isset($_SESSION['selected_destination'])) {
    if ($_SESSION['selected_destination'] === 'stock') {
        if ($_SESSION['user_type'] == 'admin') {
            header("Location: Admin/dashboard.php");
        } else {
            header("Location: Staff/dashboard-staff.php");
        }
        exit();
    } else {
        header("Location: your-other-file.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Destination | ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        body {
            font-family: "Khmer OS Siemreap", sans-serif;
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 20px;
        }
        
        .selection-container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .selection-header {
            background: linear-gradient(135deg, #0A7885 0%, #08626d 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .selection-header h3 {
            margin: 0;
            font-weight: 700;
            font-size: 1.8rem;
        }
        
        .selection-header p {
            margin: 1rem 0 0;
            opacity: 0.9;
        }
        
        .selection-body {
            padding: 2.5rem;
        }
        
        .user-info {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #eee;
        }
        
        .user-info h4 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .user-info p {
            color: #666;
            margin: 0;
        }
        
        .selection-options {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .selection-options {
                flex-direction: row;
            }
        }
        
        .option-card {
            flex: 1;
            border: 2px solid #eee;
            border-radius: 10px;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .option-card:hover {
            border-color: #0A7885;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .option-card.selected {
            border-color: #0A7885;
            background-color: rgba(10, 120, 133, 0.05);
        }
        
        .option-icon {
            font-size: 3rem;
            color: #0A7885;
            margin-bottom: 1.5rem;
        }
        
        .option-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
        }
        
        .option-description {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        
        .option-radio {
            display: none;
        }
        
        .continue-btn {
            margin-top: 2rem;
            padding: 0.75rem 2rem;
            background: #0A7885;
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            font-family: "Khmer OS Siemreap", sans-serif;
            width: 100%;
        }
        
        .continue-btn:hover {
            background: #08626d;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(10, 120, 133, 0.3);
        }
        
        .continue-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .form-check {
            display: none;
        }
    </style>
</head>
<body>
    <div class="selection-container">
        <div class="selection-header">
            <h3>សូមជ្រើសរើសទិសដៅ</h3>
            <p>Please select your destination</p>
        </div>
        
        <div class="selection-body">
            <div class="user-info">
                <h4>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h4>
                <p>You are logged in as: <?php echo htmlspecialchars($_SESSION['user_type']); ?></p>
            </div>
            
            <form method="POST" action="" id="destinationForm">
                <div class="selection-options">
                    <label class="option-card" for="option1">
                        <input type="radio" class="option-radio" id="option1" name="destination" value="stock" required>
                        <div class="option-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="option-title">Stock Management</div>
                        <div class="option-description">
                            Access the main stock management system with inventory tracking, transfers, and reporting.
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="destination" id="stockRadio" value="stock">
                        </div>
                    </label>
                    
                    <label class="option-card" for="option2">
                        <input type="radio" class="option-radio" id="option2" name="destination" value="other" required>
                        <div class="option-icon">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                        <div class="option-title">Financial System</div>
                        <div class="option-description">
                            Access the finacial system of expense tracking and report.
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="destination" id="otherRadio" value="other">
                        </div>
                    </label>
                </div>
                
                <button type="submit" class="continue-btn" id="continueBtn" disabled>
                    <i class="bi bi-arrow-right"></i> Continue
                </button>
            </form>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const optionCards = document.querySelectorAll('.option-card');
            const continueBtn = document.getElementById('continueBtn');
            
            optionCards.forEach(card => {
                card.addEventListener('click', function() {
                    // Remove selected class from all cards
                    optionCards.forEach(c => c.classList.remove('selected'));
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                    
                    // Check the radio button inside
                    const radio = this.querySelector('.option-radio');
                    radio.checked = true;
                    
                    // Enable continue button
                    continueBtn.disabled = false;
                });
            });
            
            // Handle form submission
            document.getElementById('destinationForm').addEventListener('submit', function(e) {
                const selectedOption = document.querySelector('input[name="destination"]:checked');
                if (!selectedOption) {
                    e.preventDefault();
                    alert('Please select a destination');
                }
            });
            
            // Auto-select based on user type or previous choice
            const userType = '<?php echo $_SESSION['user_type'] ?? ''; ?>';
            
            // You can add logic here to auto-select based on user preferences
            // For example, if user is guest, auto-select other system
            if (userType === 'guest') {
                document.getElementById('option2').checked = true;
                document.querySelector('#option2').parentElement.classList.add('selected');
                continueBtn.disabled = false;
            }
        });
    </script>
</body>
</html>