<?php
// Enable Error Reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../app/core/guard.php';
// We allow 'Event Manager' to view, but only 'Tabulator' to Lock.
requireLogin(); 
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/ScoreCalculator.php';

// --- HELPER: Fetch Metadata (Judges & Segments) ---
function getRoundMetadata($conn, $round_id) {
    // 1. Get Event ID from Round
    $r_query = $conn->query("SELECT event_id, status FROM rounds WHERE id = $round_id");
    if ($r_query->num_rows === 0) return null;
    $round_data = $r_query->fetch_assoc();
    $event_id = $round_data['event_id'];

    // 2. Get Active Judges (for Table Columns)
    // Ordered by ID or Name to ensure columns stay in same order
    $j_sql = "SELECT u.id, u.name 
              FROM event_judges ej 
              JOIN users u ON ej.judge_id = u.id 
              WHERE ej.event_id = $event_id AND ej.status = 'Active' 
              ORDER BY u.id ASC";
    $judges = $conn->query($j_sql)->fetch_all(MYSQLI_ASSOC);

    return [
        'round_status' => $round_data['status'],
        'judges' => $judges
    ];
}

// ==========================================================
// HANDLE GET REQUEST: FETCH LIVE SCORES
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['round_id'])) {
        echo json_encode(['error' => 'Round ID is required']);
        exit();
    }

    $round_id = (int)$_GET['round_id'];

    // 1. Get Metadata (Judges List)
    $meta = getRoundMetadata($conn, $round_id);
    if (!$meta) {
        echo json_encode(['error' => 'Invalid Round']);
        exit();
    }

    // 2. Calculate Scores (Using our new Model)
    $results = ScoreCalculator::calculate($round_id);

    // 3. Return JSON
    echo json_encode([
        'status' => 'success',
        'round_status' => $meta['round_status'],
        'judges' => $meta['judges'],
        'ranking' => $results
    ]);
    exit();
}

// ==========================================================
// HANDLE POST REQUEST: LOCK ROUND (Tabulator Only)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Security: Only Tabulator or Event Manager can lock
    if ($_SESSION['role'] !== 'Tabulator' && $_SESSION['role'] !== 'Event Manager') {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $round_id = (int)($input['round_id'] ?? 0);

    if ($round_id === 0) {
        echo json_encode(['error' => 'Invalid Round ID']);
        exit();
    }

    // 1. Verify Round is Active
    $check = $conn->query("SELECT status FROM rounds WHERE id = $round_id")->fetch_assoc();
    if ($check['status'] !== 'Active') {
        echo json_encode(['error' => 'Round is already locked or completed.']);
        exit();
    }

    // 2. Perform Final Calculation (The "Atomic" Calc)
    $final_results = ScoreCalculator::calculate($round_id);

    // 3. Start Transaction to Save & Lock
    $conn->begin_transaction();
    try {
        // A. Insert into round_rankings
        $stmt_ins = $conn->prepare("INSERT INTO round_rankings (round_id, contestant_id, total_score, rank) VALUES (?, ?, ?, ?)");
        
        foreach ($final_results as $row) {
            $cid = $row['contestant']['id'];
            $score = $row['final_score'];
            $rank = $row['rank'];
            
            $stmt_ins->bind_param("iidi", $round_id, $cid, $score, $rank);
            $stmt_ins->execute();
        }

        // B. Update Round Status to 'Completed'
        $conn->query("UPDATE rounds SET status = 'Completed' WHERE id = $round_id");

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Round successfully locked and saved.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}
?>