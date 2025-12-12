<?php
ob_start();
require_once '../includes/header-finance.php';
// Add authentication check
require_once '../includes/auth.php';

// Check if user is authenticated
checkAuth();
// Check if user has permission (admin or finance staff only)
if (!isAdmin() && !isFinanceStaff()) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../index.php'); // Redirect to login or home page
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'translate.php';

// Get filter parameters
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$sort_option = isset($_GET['sort_option']) ? sanitizeInput($_GET['sort_option']) : 'name_asc';

// Sort mapping
$sort_mapping = [
    'name_asc' => ['field' => 'name', 'direction' => 'ASC'],
    'name_desc' => ['field' => 'name', 'direction' => 'DESC'],
    'date_asc' => ['field' => 'created_at', 'direction' => 'ASC'],
    'date_desc' => ['field' => 'created_at', 'direction' => 'DESC']
];

// Default to name_asc if invalid option
if (!array_key_exists($sort_option, $sort_mapping)) {
    $sort_option = 'name_asc';
}

$sort_by = $sort_mapping[$sort_option]['field'];
$sort_order = $sort_mapping[$sort_option]['direction'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_location'])) {
        $name = sanitizeInput($_POST['name']);
        
        try {
            // Check for duplicate location name
            $stmt = $pdo->prepare("SELECT id FROM finance_location WHERE name = ?");
            $stmt->execute([$name]);
            
            if ($stmt->fetch()) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var duplicateModal = new bootstrap.Modal(document.getElementById("duplicateModal"));
                        duplicateModal.show();
                    });
                </script>';
                throw new Exception("Location with this name already exists!");
            }
            
            // Insert location
            $stmt = $pdo->prepare("INSERT INTO finance_location (name) VALUES (?)");
            $stmt->execute([$name]);
            
            // Log activity
            $log_message = "Added new location: $name";
            logActivity($_SESSION['user_id'], 'Add Location', $log_message);
            
            $_SESSION['success'] = "Location added successfully!";
            redirect('location.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['edit_location'])) {
        $location_id = (int)$_POST['location_id'];
        $name = sanitizeInput($_POST['name']);
        
        try {
            // Check for duplicate location name (excluding current location)
            $stmt = $pdo->prepare("SELECT id FROM finance_location WHERE name = ? AND id != ?");
            $stmt->execute([$name, $location_id]);
            
            if ($stmt->fetch()) {
                throw new Exception("Location with this name already exists!");
            }
            
            // Get old location name for logging
            $stmt = $pdo->prepare("SELECT name FROM finance_location WHERE id = ?");
            $stmt->execute([$location_id]);
            $old_location = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update location
            $stmt = $pdo->prepare("UPDATE finance_location SET name = ? WHERE id = ?");
            $stmt->execute([$name, $location_id]);
            
            // Log activity
            $log_message = "Updated location: {$old_location['name']} to $name";
            logActivity($_SESSION['user_id'], 'Edit Location', $log_message);
            
            $_SESSION['success'] = "Location updated successfully!";
            redirect('location.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['delete_location'])) {
        $location_id = (int)$_POST['location_id'];
        
        try {
            // Check if location is used in invoices
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM finance_invoice WHERE location = ?");
            $stmt->execute([$location_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception("Cannot delete location. It is being used in invoices.");
            }
            
            // Get location details before deletion for logging
            $stmt = $pdo->prepare("SELECT name FROM finance_location WHERE id = ?");
            $stmt->execute([$location_id]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($location) {
                // Delete location
                $stmt = $pdo->prepare("DELETE FROM finance_location WHERE id = ?");
                $stmt->execute([$location_id]);
                
                // Log activity
                $log_message = "Deleted location: " . $location['name'];
                logActivity($_SESSION['user_id'], 'Delete Location', $log_message);
                
                $_SESSION['success'] = "Location deleted successfully!";
            }
            
            redirect('location.php');
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            redirect('location.php');
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            redirect('location.php');
        }
    }
}

// Pagination setup
$limit_options = [10, 25, 50, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $limit_options)) {
    $per_page = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = $per_page;
$offset = ($page - 1) * $limit;

// Build query for locations
$query = "SELECT 
    id,
    name,
    created_at
FROM 
    finance_location
WHERE 1=1";

$params = [];
$count_params = [];

// Add search filter
if ($search_query) {
    $query .= " AND name LIKE :search";
    $params[':search'] = "%$search_query%";
    $count_params[':search'] = "%$search_query%";
}

// Order by
$query .= " ORDER BY $sort_by $sort_order";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM finance_location WHERE 1=1";

if ($search_query) {
    $count_query .= " AND name LIKE :search";
}

$stmt = $pdo->prepare($count_query);
foreach ($count_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_items / $limit);

// Get paginated results
$query .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
    /* Keep the existing styles from invoice.php */
    :root {
        --primary: #0d6efd;
        --primary-dark: #0d6efd;
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

    .location-count {
        font-weight: bold;
        color: var(--primary);
    }
</style>

<div class="container-fluid">
    <h2 class="mb-4"><?php echo t('location_management'); ?></h2>
    
    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo t('filter_options'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-2">
                        <div class="col-md-4">
                            <label for="search" class="form-label"><?php echo t('search'); ?></label>
                            <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_location_name'); ?>..." value="<?php echo $search_query; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="sort" class="form-label"><?php echo t('sort'); ?></label>
                            <select name="sort_option" class="form-select">
                                <option value="name_asc" <?php echo $sort_option === 'name_asc' ? 'selected' : ''; ?>><?php echo t('name_a_to_z'); ?></option>
                                <option value="name_desc" <?php echo $sort_option === 'name_desc' ? 'selected' : ''; ?>><?php echo t('name_z_to_a'); ?></option>
                                <option value="date_asc" <?php echo $sort_option === 'date_asc' ? 'selected' : ''; ?>><?php echo t('date_oldest_first'); ?></option>
                                <option value="date_desc" <?php echo $sort_option === 'date_desc' ? 'selected' : ''; ?>><?php echo t('date_newest_first'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="per_page" class="form-label"><?php echo t('show_entries'); ?></label>
                            <select name="per_page" class="form-select form-select-sm" id="per_page_select">
                                <?php foreach ($limit_options as $option): ?>
                                    <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                                        <?php echo $option; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <?php echo t('search'); ?>
                            </button>
                            <a href="location.php" class="btn btn-secondary">
                                <?php echo t('reset'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Data Table Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo t('location_list'); ?> <span class="location-count">(<?php echo $total_items; ?>)</span></h5>
            <div class="d-inline-flex gap-2 align-items-center flex-nowrap">
                <button class="btn btn-light btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                    <i class="bi bi-plus-circle"></i> <?php echo t('add_location'); ?>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo t('no'); ?></th>
                            <th><?php echo t('location_name'); ?></th>
                            <th><?php echo t('created_at'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($locations)): ?>
                            <tr>
                                <td colspan="4" class="text-center"><?php echo t('no_locations_found'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($locations as $index => $location): ?>
                                <tr>
                                    <td><?php echo $index + 1 + $offset; ?></td>
                                    <td><?php echo $location['name']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($location['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning edit-location-btn" 
                                                data-id="<?php echo $location['id']; ?>"
                                                data-name="<?php echo $location['name']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-location-btn" 
                                                data-id="<?php echo $location['id']; ?>"
                                                data-name="<?php echo $location['name']; ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages >= 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php 
                        // Create parameter array for pagination
                        $pagination_params = [
                            'search' => $search_query,
                            'sort_option' => $sort_option,
                            'per_page' => $per_page
                        ];
                        
                        // Remove empty values
                        $pagination_params = array_filter($pagination_params);
                        ?>
                        
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => 1])); ?>" aria-label="First">
                                    <span aria-hidden="true">&laquo;&laquo;</span>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $page - 1])); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;&laquo;</span>
                            </li>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;</span>
                            </li>
                        <?php endif; ?>

                        <?php 
                        // Show page numbers
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;
                        
                        if ($end_page < $total_pages) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $page + 1])); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($pagination_params, ['page' => $total_pages])); ?>" aria-label="Last">
                                    <span aria-hidden="true">&raquo;&raquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;</span>
                            </li>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;&raquo;</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <div class="text-center text-muted">
                    <?php echo t('page'); ?> <?php echo $page; ?> <?php echo t('page_of'); ?> <?php echo $total_pages; ?> 
                    (<?php echo t('total_records'); ?>: <?php echo $total_items; ?>)
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1" aria-labelledby="addLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addLocationModalLabel"><?php echo t('add_location'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('location_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required maxlength="255">
                            <div class="form-text"><?php echo t('location_name_hint'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="add_location" class="btn btn-primary"><?php echo t('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1" aria-labelledby="editLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="location_id" id="edit_location_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editLocationModalLabel"><?php echo t('edit_location'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo t('location_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_location_name" required maxlength="255">
                            <div class="form-text"><?php echo t('location_name_hint'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="edit_location" class="btn btn-warning"><?php echo t('update'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('confirm_delete'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('delete_location'); ?></h4>
                <p id="deleteLocationMessage"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <form method="POST" id="deleteLocationForm" style="display: inline;">
                    <input type="hidden" name="location_id" id="delete_location_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                    <button type="submit" name="delete_location" class="btn btn-danger"><?php echo t('delete'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Duplicate Modal -->
<div class="modal fade" id="duplicateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('duplicate_location'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3rem;"></i>
                </div>
                <h4 class="text-danger mb-3"><?php echo t('duplicate_location_title'); ?></h4>
                <p><?php echo t('duplicate_location_message'); ?></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> <?php echo t('ok'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit location button click
    document.querySelectorAll('.edit-location-btn').forEach(button => {
        button.addEventListener('click', function() {
            const locationId = this.dataset.id;
            const locationName = this.dataset.name;

            // Set values in edit modal
            document.getElementById('edit_location_id').value = locationId;
            document.getElementById('edit_location_name').value = locationName;

            // Show edit modal
            const editModal = new bootstrap.Modal(document.getElementById('editLocationModal'));
            editModal.show();
        });
    });

    // Delete location button click
    document.querySelectorAll('.delete-location-btn').forEach(button => {
        button.addEventListener('click', function() {
            const locationId = this.dataset.id;
            const locationName = this.dataset.name;

            document.getElementById('delete_location_id').value = locationId;
            document.getElementById('deleteLocationMessage').textContent = 
                'Are you sure you want to delete location "' + locationName + '"? This action cannot be undone.';

            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            deleteModal.show();
        });
    });

    // Handle entries per page change
    const perPageSelect = document.getElementById('per_page_select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        });
    }

    // Auto-hide success messages after 5 seconds
    const successMessages = document.querySelectorAll('.alert-success');
    successMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease';
            message.style.opacity = '0';
            
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 5000);
    });

    // Auto-hide error messages after 10 seconds
    const errorMessages = document.querySelectorAll('.alert-danger');
    errorMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease';
            message.style.opacity = '0';
            
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 10000);
    });
});
</script>

<?php
require_once '../includes/footer.php';
?>