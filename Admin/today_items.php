<?php
ob_start();

// Includes in correct order
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once 'translate.php';

if (!isAdmin()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: dashboard-staff.php');
    exit();
}
checkAuth();

// Get today's date in YYYY-MM-DD format
$today = date('Y-m-d');

// Get today's stock in history (both add_item and add_qty)
$query = "SELECT 
    si.id,
    si.item_id,
    si.item_code, 
    si.category_id,
    c.name as category_name,
    si.invoice_no,
    si.date,
    si.name,
    si.quantity as history_quantity,
    si.action_quantity,
    si.action_type,
    si.size,
    si.location_id,
    l.name as location_name,
    si.remark,
    si.action_by,
    u.username as action_by_name,
    si.action_at,
    (SELECT id FROM item_images WHERE item_id = si.item_id ORDER BY id DESC LIMIT 1) as image_id
FROM 
    stock_in_history si
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
JOIN
    users u ON si.action_by = u.id
WHERE 
    DATE(si.action_at) = :today
    AND si.action_type IN ('new', 'add')
ORDER BY si.action_at DESC";

$stmt = $pdo->prepare($query);
$stmt->bindParam(':today', $today);
$stmt->execute();
$stock_in_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('todays_stock_in'); ?></title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4e73df;
            --primary-dark: #2e59d9;
            --primary-light: #f8f9fc;
            --secondary: #858796;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fa;
            --dark: #5a5c69;
            --white: #ffffff;
            --gray: #b7b9cc;
            --gray-dark: #7b7d8a;
            --font-family: "Khmer OS Siemreap", sans-serif;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--light);
            color: var(--dark);
        }

        .card {
            border: none;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        }

        .card-header {
            background-color: var(--white);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.35rem;
            font-weight: 600;
        }

        .table th {
            background-color: var(--light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        .img-thumbnail {
            padding: 0.25rem;
            background-color: var(--white);
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
            max-width: 100%;
            height: auto;
        }

        @media (max-width: 768px) {
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.85rem;
            }
        }
        
        .print-only {
            display: none;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            .print-only {
                display: block;
            }
            body {
                background-color: white;
                color: black;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
            }
            .table th, .table td {
                border: 1px solid #ddd;
                padding: 8px;
            }
            .table th {
                background-color: #f2f2f2;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h2><?php echo t('todays_stock_in'); ?></h2>
            <div>
               
                <a href="dashboard.php" class="btn btn-info">
                    <i class="bi bi-arrow-left"></i> <?php echo t('back_to_items'); ?>
                </a>
            </div>
        </div>
        
        <div class="print-only text-center mb-3">
            <h3><?php echo t('todays_stock_in'); ?></h3>
            <p><?php echo date('F j, Y'); ?></p>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><?php echo t('todays_stock_in_history'); ?></h5>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th><?php echo t('item_no'); ?></th>
                                <th><?php echo t('item_code'); ?></th>
                                <th><?php echo t('category'); ?></th>
                                <th><?php echo t('item_invoice'); ?></th>
                                <th><?php echo t('item_date'); ?></th>
                                <th><?php echo t('item_name'); ?></th>
                                <th><?php echo t('item_qty'); ?></th>
                                <th><?php echo t('action'); ?></th>
                                <th><?php echo t('unit'); ?></th>
                                <th><?php echo t('location'); ?></th>
                                <th><?php echo t('item_remark'); ?></th>
                                <th><?php echo t('item_photo'); ?></th>
                                <th><?php echo t('action_by'); ?></th>
                                <th><?php echo t('action_at'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stock_in_history)): ?>
                                <tr>
                                    <td colspan="14" class="text-center"><?php echo t('no_stock_in_today'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stock_in_history as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo $item['item_code'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['category_name'] ?: 'N/A'; ?></td>
                                        <td><?php echo $item['invoice_no']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                                        <td><?php echo $item['name']; ?></td>
                                        <td class="text-success">+<?php echo $item['action_quantity']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $item['action_type'] === 'new' ? 'primary' : 'success'; ?>">
                                                <?php echo ucfirst($item['action_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $item['size']; ?></td>
                                        <td><?php echo $item['location_name']; ?></td>
                                        <td><?php echo $item['remark']; ?></td>
                                        <td>
                                            <?php if ($item['image_id']): ?>
                                                <img src="display_image.php?id=<?php echo $item['image_id']; ?>" 
                                                     class="img-thumbnail" width="50"
                                                     data-bs-toggle="modal" data-bs-target="#imageGalleryModal"
                                                     data-item-id="<?php echo $item['item_id']; ?>">
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo t('no_image'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $item['action_by_name']; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($item['action_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Gallery Modal -->
    <div class="modal fade" id="imageGalleryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo t('item_photo'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="carouselExample" class="carousel slide">
                        <div class="carousel-inner" id="carousel-inner">
                            <!-- Images will be loaded here via JavaScript -->
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselExample" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#carouselExample" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Image gallery functionality
        document.querySelectorAll('[data-bs-target="#imageGalleryModal"]').forEach(img => {
            img.addEventListener('click', function() {
                const itemId = this.getAttribute('data-item-id');
                fetch(`get_item_images.php?id=${itemId}`)
                    .then(response => response.json())
                    .then(images => {
                        const carouselInner = document.getElementById('carousel-inner');
                        carouselInner.innerHTML = '';
                        
                        if (images.length > 0) {
                            images.forEach((image, index) => {
                                const item = document.createElement('div');
                                item.className = `carousel-item ${index === 0 ? 'active' : ''}`;
                                
                                const imgElement = document.createElement('img');
                                imgElement.src = `display_image.php?id=${image.id}`;
                                imgElement.className = 'd-block w-100';
                                imgElement.alt = 'Item Image';
                                imgElement.style.maxHeight = '70vh';
                                imgElement.style.objectFit = 'contain';
                                
                                item.appendChild(imgElement);
                                carouselInner.appendChild(item);
                            });
                        } else {
                            carouselInner.innerHTML = `
                                <div class="carousel-item active">
                                    <img src="../assets/images/no-image.png" 
                                         class="d-block w-100" 
                                         alt="No image"
                                         style="max-height: 70vh; object-fit: contain;">
                                </div>
                            `;
                        }
                    });
            });
        });

        // Auto-hide success messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successMessages = document.querySelectorAll('.alert-success');
            
            successMessages.forEach(message => {
                setTimeout(() => {
                    message.style.transition = 'opacity 0.5s ease';
                    message.style.opacity = '0';
                    
                    // Remove the element after fade out
                    setTimeout(() => {
                        message.remove();
                    }, 500);
                }, 5000); // 5000 milliseconds = 5 seconds
            });
        });
    </script>
</body>
</html>
<?php
require_once '../includes/footer.php';
?>