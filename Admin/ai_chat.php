<?php
// ai_chat.php → FULL KHMER AI CHAT 2025 – LOCATION + SUPPLIER + LOW STOCK + REPAIR
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['reply' => 'សូមចូលប្រព័ន្ធជាមុនសិន។']));
}

$user_message = trim($_POST['message'] ?? '');
if ($user_message === '') {
    die(json_encode(['reply' => 'សួស្តីបង! 🏗️ ខ្ញុំជាជំនួយការ AI សម្រាប់សម្ភារៈសំណង់
សួរអ្វីក៏បានជាភាសាខ្មែរ 100%!']));
}

$msg = mb_strtolower($user_message);

try {
    // Extract equipment name
    $clean = preg_replace('/\b(មាន|ប៉ុន្មាន|ទៀត|នៅ|ឯណា|ណា|ពី|supplier|ក្រុមហ៊ុន|ខ្លះ|ឬ|និង|ជិតអស់|ខូច|ទេ|\?)\b/i', ' ', $user_message);
    $equipment = trim(preg_replace('/\s+/', ' ', $clean));

    // 1. Location search
    if (strpos($msg, 'នៅណា') !== false || strpos($msg, 'នៅឯណា') !== false || strpos($msg, 'ទីតាំង') !== false) {
        $stmt = $pdo->prepare("SELECT i.name, i.quantity, l.name AS location 
                               FROM items i 
                               JOIN locations l ON i.location_id = l.id 
                               WHERE i.name LIKE ? AND i.quantity > 0");
        $stmt->execute(["%$equipment%"]);
        $rows = $stmt->fetchAll();

        $reply = empty($rows) ? "រក «$equipment» មិនឃើញទេ។" 
                 : "📍 ទីតាំង «$equipment» (សរុប " . array_sum(array_column($rows,'quantity')) . " គ្រឿង):\n" . formatLocation($rows);
    }

    // 2. Supplier search
    elseif (strpos($msg, 'supplier') !== false || strpos($msg, 'ក្រុមហ៊ុន') !== false || strpos($msg, 'deporty') !== false) {
        $stmt = $pdo->prepare("SELECT i.quantity, COALESCE(d.name, 'មិនទាន់កត់') AS supplier 
                               FROM items i 
                               LEFT JOIN deporty d ON i.deporty_id = d.id 
                               WHERE i.name LIKE ?");
        $stmt->execute(["%$equipment%"]);
        $rows = $stmt->fetchAll();

        $reply = empty($rows) ? "គ្មានទិន្នន័យ supplier សម្រាប់ «$equipment»" 
                 : "🏢 Supplier របស់ «$equipment»:\n" . formatSupplier($rows);
    }

    // 3. Low stock
    elseif (strpos($msg, 'ជិតអស់') !== false || strpos($msg, 'low') !== false) {
        $stmt = $pdo->query("SELECT i.name, i.quantity, i.alert_quantity, l.name AS location 
                             FROM items i 
                             JOIN locations l ON i.location_id = l.id 
                             WHERE i.quantity <= i.alert_quantity AND i.quantity > 0 
                             ORDER BY i.quantity ASC LIMIT 20");
        $rows = $stmt->fetchAll();
        $reply = empty($rows) ? "គ្មានសម្ភារៈជិតអស់ទេ។ ល្អណាស់! 👍" 
                 : "⚠️ សម្ភារៈជិតអស់:\n" . formatLowStock($rows);
    }

    // 4. Broken items
    elseif (strpos($msg, 'ខូច') !== false || strpos($msg, 'repair') !== false) {
        $stmt = $pdo->query("SELECT item_name AS name, quantity, l.name AS location 
                             FROM repair_items r 
                             JOIN locations l ON r.to_location_id = l.id");
        $rows = $stmt->fetchAll();
        $reply = empty($rows) ? "គ្មានសម្ភារៈខូចទេ។" 
                 : "🔧 សម្ភារៈកំពុងជួសជុល:\n" . formatSimple($rows);
    }

    // 5. General search
    else {
        $stmt = $pdo->prepare("SELECT i.name, i.quantity, l.name AS location, COALESCE(d.name,'-') AS supplier 
                               FROM items i 
                               JOIN locations l ON i.location_id = l.id 
                               LEFT JOIN deporty d ON i.deporty_id = d.id 
                               WHERE i.name LIKE ? AND i.quantity > 0 
                               LIMIT 15");
        $stmt->execute(["%$equipment%"]);
        $rows = $stmt->fetchAll();

        $reply = empty($rows) ? "រក «$equipment» មិនឃើញទេ។ សាកសួរឈ្មោះផ្សេងបានទេ? 😊" 
                 : "រកឃើញ «$equipment»:\n" . formatGeneral($rows);
    }

} catch (Exception $e) {
    $reply = "មានបញ្ហាបន្តិច។ សាកម្តងទៀតបានទេ? 🙏";
}

// Format functions
function formatLocation($rows) { $l = []; foreach ($rows as $r) $l[$r['location']] = ($l[$r['location']] ?? 0) + $r['quantity']; $lines = []; foreach ($l as $loc => $q) $lines[] = "• $loc: $q គ្រឿង"; return implode("\n", $lines); }
function formatSupplier($rows) { $s = []; foreach ($rows as $r) $s[$r['supplier']] = ($s[$r['supplier']] ?? 0) + $r['quantity']; $lines = []; foreach ($s as $sup => $q) $lines[] = "• $sup: $q គ្រឿង"; return implode("\n", $lines); }
function formatLowStock($rows) { $lines = []; foreach ($rows as $r) $lines[] = "• {$r['name']}: {$r['quantity']}/{$r['alert_quantity']} → {$r['location']} ⚠️"; return implode("\n", $lines); }
function formatSimple($rows) { $lines = []; foreach ($rows as $r) $lines[] = "• {$r['name']}: {$r['quantity']} គ្រឿង → {$r['location']}"; return implode("\n", $lines); }
function formatGeneral($rows) { $lines = []; foreach ($rows as $r) $lines[] = "• {$r['quantity']} គ្រឿង → {$r['location']} (ពី {$r['supplier']})"; return implode("\n", $lines); }

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['reply' => $reply]);
?>