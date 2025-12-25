<?php
// bpms/api/tally.php
// Enable Error Reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../app/core/guard.php';
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
// HANDLE GET REQUEST: FETCH LIVE SCORES & AUDIT DATA
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

    // 2. Fetch Submitted Judge IDs for Progress Tracking
    $submitted_q = $conn->query("SELECT judge_id FROM judge_round_status WHERE round_id = $round_id AND status = 'Submitted'");
    $submitted_ids = [];
    while($r = $submitted_q->fetch_assoc()) {
        $submitted_ids[] = (int)$r['judge_id'];
    }

    // 3. Calculate Scores (Summary)
    $results = ScoreCalculator::calculate($round_id);

    // 4. [NEW] Fetch Detailed Audit Data for Option B
    $audit_data = ScoreCalculator::getAuditData($round_id);

    // 5. Return JSON with both Summary and Audit Payloads
    echo json_encode([
        'status' => 'success',
        'round_status' => $meta['round_status'],
        'judges' => $meta['judges'],
        'submitted_judges' => $submitted_ids,
        'ranking' => $results,
        'audit' => $audit_data // The detailed breakdown tree
    ]);
    exit();
}

// ==========================================================
// HANDLE POST REQUEST: LOCK ROUND (Tabulator Only)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
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
    if (!$check || $check['status'] !== 'Active') {
        echo json_encode(['error' => 'Round is already locked or completed.']);
        exit();
    }

    // 2. Perform Final Calculation
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