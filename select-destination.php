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
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['destination'])) {
    $destination = $_POST['destination'];
    
    // Store the selection in session (optional)
    $_SESSION['selected_destination'] = $destination;
    
    // Redirect based on selection AND user type
    if ($destination === 'stock') {
        // Redirect based on user type
        switch ($_SESSION['user_type']) {
            case 'admin':
                header("Location: Admin/dashboard.php");
                break;
            case 'finance_staff':
                header("Location: Finance/dashboard.php");
                break;
            case 'warehouse_staff':
            case 'staff':
            default:
                header("Location: Staff/dashboard.php");
                break;
        }
        exit();
    } elseif ($destination === 'other') {
        // For financial system, redirect to Finance dashboard
        // Note: If financial staff selects "other", they'll go to Finance/dashboard.php
        header("Location: Finance/dashboard.php");
        exit();
    }
}

// If user has already made a selection and is coming back, redirect them
if (isset($_SESSION['selected_destination'])) {
    if ($_SESSION['selected_destination'] === 'stock') {
        // Redirect based on user type
        switch ($_SESSION['user_type']) {
            case 'admin':
                header("Location: Admin/dashboard.php");
                break;
            case 'finance_staff':
                header("Location: Finance/dashboard.php");
                break;
            case 'warehouse_staff':
            case 'staff':
            default:
                header("Location: Staff/dashboard.php");
                break;
        }
        exit();
    } else {
        // For financial system
        header("Location: Finance/dashboard.php");
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #0A7885;
            --primary-dark: #08626d;
            --primary-light: rgba(10, 120, 133, 0.1);
            --secondary-color: #FF6B6B;
            --accent-color: #4ECDC4;
            --text-dark: #2C3E50;
            --text-light: #7F8C8D;
            --bg-light: #F8F9FA;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Khmer OS Siemreap", "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: var(--text-dark);
        }
        
        .container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }
        
        .selection-wrapper {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            animation: fadeIn 0.8s ease-out;
        }
        
        @media (min-width: 992px) {
            .selection-wrapper {
                grid-template-columns: 1fr 2fr;
            }
        }
        
        /* Sidebar Styles */
        .sidebar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        
        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
            z-index: 2;
        }
        
        .logo-area {
            display: flex;
            align-items: center;
            margin-bottom: 2.5rem;
        }
        
        .logo-icon {
            font-size: 2.2rem;
            color: var(--primary-color);
            margin-right: 15px;
        }
        
        .logo-text h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0;
        }
        
        .logo-text p {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 0;
        }
        
        .user-card {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .user-card h4 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .user-card p {
            color: var(--text-light);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .user-card p i {
            margin-right: 8px;
            color: var(--primary-color);
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .info-section {
            margin-top: auto;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background-color: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--primary-color);
            font-size: 1.2rem;
        }
        
        .info-text h6 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.2rem;
        }
        
        .info-text p {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0;
        }
        
        /* Main Content Styles */
        .main-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            position: relative;
        }
        
        .content-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .content-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='%2308616d' fill-opacity='0.1' fill-rule='evenodd'/%3E%3C/svg%3E");
            opacity: 0.2;
        }
        
        .content-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.8rem;
            position: relative;
            z-index: 1;
        }
        
        .content-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Options Grid */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            padding: 2.5rem;
        }
        
        .option-card {
            background: white;
            border-radius: 14px;
            padding: 2rem 1.5rem;
            text-align: center;
            border: 2px solid #f0f0f0;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .option-card:hover {
            transform: translateY(-10px);
            border-color: var(--primary-color);
            box-shadow: var(--hover-shadow);
        }
        
        .option-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(to bottom, white, var(--primary-light));
            box-shadow: var(--hover-shadow);
        }
        
        .option-card.selected::after {
            content: '✓';
            position: absolute;
            top: 15px;
            right: 15px;
            width: 30px;
            height: 30px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: bold;
        }
        
        .option-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            transition: var(--transition);
        }
        
        .option-card:nth-child(1) .option-icon {
            background: linear-gradient(135deg, var(--primary-color), #0da4b5);
        }
        
        .option-card:nth-child(2) .option-icon {
            background: linear-gradient(135deg, var(--secondary-color), #ff8e8e);
        }
        
        .option-card:nth-child(3) .option-icon {
            background: linear-gradient(135deg, #4CAF50, #66BB6A);
        }
        
        .option-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }
        
        .option-description {
            color: var(--text-light);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .option-features {
            text-align: left;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px dashed #eee;
        }
        
        .option-features ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .option-features li {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .option-features li i {
            color: var(--primary-color);
            margin-right: 10px;
            font-size: 0.8rem;
        }
        
        .option-radio {
            display: none;
        }
        
        /* Action Button */
        .action-area {
            padding: 0 2.5rem 2.5rem;
            text-align: center;
        }
        
        .continue-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            padding: 1.1rem 3rem;
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: "Khmer OS Siemreap", "Segoe UI", sans-serif;
            box-shadow: 0 10px 20px rgba(10, 120, 133, 0.2);
        }
        
        .continue-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(10, 120, 133, 0.3);
            background: linear-gradient(135deg, var(--primary-dark), #074a53);
        }
        
        .continue-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .continue-btn i {
            margin-left: 10px;
            font-size: 1.2rem;
            transition: transform 0.3s;
        }
        
        .continue-btn:hover i {
            transform: translateX(5px);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .sidebar {
                padding: 1.5rem;
            }
            
            .content-header {
                padding: 1.5rem;
            }
            
            .content-header h1 {
                font-size: 1.8rem;
            }
            
            .options-grid {
                grid-template-columns: 1fr;
                padding: 1.5rem;
                gap: 20px;
            }
            
            .action-area {
                padding: 0 1.5rem 1.5rem;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="selection-wrapper">
            <!-- Sidebar with user info -->
            <div class="sidebar">
                <div>
                    <div class="logo-area">
                        <div class="logo-icon">
                            <i class="bi bi-building-gear"></i>
                        </div>
                        <div class="logo-text">
                            <h2>StockPro System</h2>
                            <p>ប្រព័ន្ធគ្រប់គ្រងឃ្លាំង</p>
                        </div>
                    </div>
                    
                    <div class="user-card">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                        </div>
                        <h4>Hello, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>!</h4>
                        <?php
    $role_display = $_SESSION['user_type'] ?? 'User';
    // Convert role to display name
    switch ($role_display) {
        case 'admin':
            $role_display = 'Administrator';
            break;
        case 'finance_staff':
            $role_display = 'Finance Staff';
            break;
        case 'warehouse_staff':
            $role_display = 'Warehouse Staff';
            break;
        case 'staff':
            $role_display = 'Staff';
            break;
        case 'guest':
            $role_display = 'Guest';
            break;
    }
    ?>
                        <p><i class="bi bi-person-badge"></i> Role: <?php echo htmlspecialchars($_SESSION['user_type'] ?? 'User'); ?></p>
                        <p><i class="bi bi-calendar-check"></i> <?php echo date('F j, Y'); ?></p>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div class="info-text">
                                <h6>Secure Access</h6>
                                <p>Your session is protected</p>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="info-text">
                                <h6>Quick Selection</h6>
                                <p>Choose your destination below</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main content area -->
            <div class="main-content">
                <div class="content-header">
                    <h1>Select Your Destination</h1>
                    <p>Choose where you want to go in the system. Your selection will be remembered for future visits.</p>
                </div>
                
                <form method="POST" action="" id="destinationForm">
                    <div class="options-grid">
                        <!-- Option 1: Stock Management -->
                        <label class="option-card" for="option1">
                            <input type="radio" class="option-radio" id="option1" name="destination" value="stock" required>
                            <div class="option-icon">
                                <i class="bi bi-boxes"></i>
                            </div>
                            <div class="option-title">Stock Management</div>
                            <div class="option-description">
                                Comprehensive inventory tracking, stock transfers, reporting, and warehouse management system.
                            </div>
                            <div class="option-features">
                                <ul>
                                    <li><i class="bi bi-check-circle"></i> Real-time inventory tracking</li>
                                    <li><i class="bi bi-check-circle"></i> Stock transfers & adjustments</li>
                                    <li><i class="bi bi-check-circle"></i> Advanced reporting tools</li>
                                </ul>
                            </div>
                        </label>
                        
                        <!-- Option 2: Financial System -->
                        <label class="option-card" for="option2">
                            <input type="radio" class="option-radio" id="option2" name="destination" value="other" required>
                            <div class="option-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="option-title">Financial System</div>
                            <div class="option-description">
                                Expense tracking, financial reporting, budgeting, and comprehensive accounting tools.
                            </div>
                            <div class="option-features">
                                <ul>
                                    <li><i class="bi bi-check-circle"></i> Expense tracking & categorization</li>
                                    <li><i class="bi bi-check-circle"></i> Financial reporting & analytics</li>
                                    <li><i class="bi bi-check-circle"></i> Budget planning tools</li>
                                </ul>
                            </div>
                        </label>
                    
                      
                    </div>
                    
                    <div class="action-area">
                        <button type="submit" class="continue-btn" id="continueBtn" disabled>
                            Continue to Selected System <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const optionCards = document.querySelectorAll('.option-card');
            const continueBtn = document.getElementById('continueBtn');
            
            // Initialize selection if returning user
            const selectedDestination = '<?php echo $_SESSION['selected_destination'] ?? ''; ?>';
            if (selectedDestination) {
                const radio = document.querySelector(`input[value="${selectedDestination}"]`);
                if (radio) {
                    radio.checked = true;
                    radio.parentElement.classList.add('selected');
                    continueBtn.disabled = false;
                }
            }
            
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
                    
                    // Add subtle animation to button
                    continueBtn.classList.add('pulse');
                    setTimeout(() => {
                        continueBtn.classList.remove('pulse');
                    }, 500);
                });
            });
            
            // Handle form submission
            document.getElementById('destinationForm').addEventListener('submit', function(e) {
                const selectedOption = document.querySelector('input[name="destination"]:checked');
                if (!selectedOption) {
                    e.preventDefault();
                    // Show visual feedback
                    optionCards.forEach(card => {
                        card.style.animation = 'none';
                        setTimeout(() => {
                            card.style.animation = 'pulse 0.5s';
                        }, 10);
                    });
                    
                    // Show message
                    const header = document.querySelector('.content-header p');
                    const originalText = header.textContent;
                    header.innerHTML = '<span style="color:#FF6B6B">Please select a destination to continue</span>';
                    header.style.fontWeight = '600';
                    
                    setTimeout(() => {
                        header.textContent = originalText;
                        header.style.fontWeight = '';
                    }, 3000);
                }
            });
            
           // Auto-select based on user type
const userType = '<?php echo $_SESSION['user_type'] ?? ''; ?>';
const username = '<?php echo $_SESSION['username'] ?? ''; ?>';

// Set avatar to first letter of username
const avatar = document.querySelector('.user-avatar');
if (username && avatar) {
    avatar.textContent = username.charAt(0).toUpperCase();
}

// Auto-select based on user type
if (userType === 'admin' || userType === 'warehouse_staff' || userType === 'staff') {
    // Admin, warehouse staff, and regular staff default to stock management
    document.getElementById('option1').checked = true;
    document.getElementById('option1').parentElement.classList.add('selected');
    continueBtn.disabled = false;
    
    // Show tooltip
    const note = document.createElement('div');
    note.style.cssText = 'text-align:center; margin-top:15px; color:#0A7885; font-size:0.9rem; font-weight:500;';
    
    if (userType === 'admin') {
        note.innerHTML = '<i class="bi bi-info-circle"></i> Stock management auto-selected for administrator';
    } else if (userType === 'warehouse_staff') {
        note.innerHTML = '<i class="bi bi-info-circle"></i> Stock management auto-selected for warehouse staff';
    } else {
        note.innerHTML = '<i class="bi bi-info-circle"></i> Stock management auto-selected';
    }
    
    document.querySelector('.action-area').prepend(note);
}

// If user is finance_staff, auto-select financial system
if (userType === 'finance_staff') {
    document.getElementById('option2').checked = true;
    document.getElementById('option2').parentElement.classList.add('selected');
    continueBtn.disabled = false;
    
    // Show tooltip
    const financeNote = document.createElement('div');
    financeNote.style.cssText = 'text-align:center; margin-top:15px; color:#0A7885; font-size:0.9rem; font-weight:500;';
    financeNote.innerHTML = '<i class="bi bi-info-circle"></i> Financial system auto-selected for finance staff';
    document.querySelector('.action-area').prepend(financeNote);
}

// If user is guest, auto-select financial system
if (userType === 'guest') {
    document.getElementById('option2').checked = true;
    document.getElementById('option2').parentElement.classList.add('selected');
    continueBtn.disabled = false;
}
            
            // Add hover effects to cards
            optionCards.forEach(card => {
                const icon = card.querySelector('.option-icon');
                card.addEventListener('mouseenter', function() {
                    if (!this.classList.contains('selected')) {
                        icon.style.transform = 'scale(1.1) rotate(5deg)';
                    }
                });
                
                card.addEventListener('mouseleave', function() {
                    icon.style.transform = 'scale(1) rotate(0deg)';
                });
            });
        });
    </script>
</body>
</html>