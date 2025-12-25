<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/AwardCalculator.php';

// 1. GET REQUEST: Fetch Award List & Status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['event_id'])) {
        echo json_encode(['error' => 'Event ID required']);
        exit();
    }
    
    $data = AwardCalculator::getAwardsList((int)$_GET['event_id']);
    
    // Also fetch all contestants for the Manual Dropdown
    $cont_sql = "SELECT cd.id, u.name FROM contestant_details cd JOIN users u ON cd.user_id = u.id WHERE cd.event_id = " . (int)$_GET['event_id'];
    $contestants = $conn->query($cont_sql)->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'awards' => $data, 'contestants' => $contestants]);
    exit();
}

// 2. POST REQUEST: Save Manual Winner
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $award_id = (int)$input['award_id'];
    $contestant_id = (int)$input['contestant_id']; // This should be Detail ID
    $event_id = (int)$input['event_id'];

    if (!$award_id || !$contestant_id) {
        echo json_encode(['error' => 'Invalid Input']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO award_winners (award_id, contestant_id, event_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE contestant_id = VALUES(contestant_id)");
    $stmt->bind_param("iii", $award_id, $contestant_id, $event_id);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['error' => 'Database error']);
    }
    exit();
}
?>