<?php
// bpms/api/tally.php
ini_set('display_errors', 0); 
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/../app/core/guard.php';
requireLogin(); 
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/ScoreCalculator.php';

// --- HELPER ---
function getRoundMetadata($conn, $round_id) {
    $r_query = $conn->query("SELECT event_id, status, contestants_to_advance FROM rounds WHERE id = $round_id");
    if ($r_query->num_rows === 0) return null;
    $round_data = $r_query->fetch_assoc();
    
    $j_sql = "SELECT u.id, u.name FROM event_judges ej JOIN users u ON ej.judge_id = u.id 
              WHERE ej.event_id = {$round_data['event_id']} AND ej.status = 'Active' ORDER BY u.id ASC";
    $judges = $conn->query($j_sql)->fetch_all(MYSQLI_ASSOC);

    return [
        'round_status' => $round_data['status'],
        'qualifiers' => (int)$round_data['contestants_to_advance'],
        'judges' => $judges
    ];
}

// ==========================================================
// GET REQUEST
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
    if ($round_id === 0) { echo json_encode(['error' => 'Round ID required']); exit; }

    $meta = getRoundMetadata($conn, $round_id);
    if (!$meta) { echo json_encode(['error' => 'Invalid Round']); exit; }

    // 1. Calculate Live Scores
    $results = ScoreCalculator::calculate($round_id);

    // 2. If Completed, OVERWRITE with official locked results
    if ($meta['round_status'] === 'Completed') {
        $sql_saved = "SELECT contestant_id, total_score, `rank` FROM round_rankings WHERE round_id = $round_id";
        $saved_q = $conn->query($sql_saved);
        
        $saved_map = [];
        while($row = $saved_q->fetch_assoc()) { $saved_map[$row['contestant_id']] = $row; }

        foreach ($results as $key => $row) {
            $cid = $row['contestant']['detail_id'] ?? $row['contestant']['id'] ?? 0;
            if (isset($saved_map[$cid])) {
                $results[$key]['final_score'] = number_format($saved_map[$cid]['total_score'], 2);
                $results[$key]['rank'] = (int)$saved_map[$cid]['rank'];
            }
        }
        
        usort($results, function($a, $b) { 
            return ($a['rank'] > 0 ? $a['rank'] : 999) <=> ($b['rank'] > 0 ? $b['rank'] : 999); 
        });
    }

    $submitted_ids = [];
    $submitted_q = $conn->query("SELECT judge_id FROM judge_round_status WHERE round_id = $round_id AND status = 'Submitted'");
    while($r = $submitted_q->fetch_assoc()) { $submitted_ids[] = (int)$r['judge_id']; }

    echo json_encode([
        'status' => 'success',
        'round_status' => $meta['round_status'],
        'qualifiers' => $meta['qualifiers'],
        'judges' => $meta['judges'],
        'submitted_judges' => $submitted_ids,
        'ranking' => $results,
        'audit' => ScoreCalculator::getAuditData($round_id)
    ]);
    exit();
}

// ==========================================================
// POST REQUEST (LOCK)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($_SESSION['role'], ['Tabulator', 'Event Manager'])) {
        echo json_encode(['error' => 'Unauthorized']); exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $round_id = (int)($input['round_id'] ?? 0);
    if ($round_id === 0) { echo json_encode(['error' => 'Invalid Round ID']); exit; }

    // --- [NEW] VALIDATION GATE ---
    
    // 1. Check Configuration (Segments)
    $seg_check = $conn->query("SELECT COUNT(*) as cnt FROM segments WHERE round_id = $round_id")->fetch_assoc();
    if ($seg_check['cnt'] == 0) {
        echo json_encode(['error' => 'Cannot Lock: No segments/criteria configured for this round.']);
        exit;
    }

    // 2. Check Judges & Submissions
    // Get Event ID
    $evt_id_q = $conn->query("SELECT event_id FROM rounds WHERE id = $round_id")->fetch_assoc();
    $event_id = $evt_id_q['event_id'];

    // Count Active Judges
    $j_total_q = $conn->query("SELECT COUNT(*) as cnt FROM event_judges WHERE event_id = $event_id AND status = 'Active' AND is_deleted = 0")->fetch_assoc();
    $total_judges = (int)$j_total_q['cnt'];

    if ($total_judges === 0) {
         echo json_encode(['error' => 'Cannot Lock: No active judges found.']);
         exit;
    }

    // Count Submitted Judges
    $j_sub_q = $conn->query("SELECT COUNT(*) as cnt FROM judge_round_status WHERE round_id = $round_id AND status = 'Submitted'")->fetch_assoc();
    $submitted_judges = (int)$j_sub_q['cnt'];

    // Compare
    if ($submitted_judges < $total_judges) {
        $remaining = $total_judges - $submitted_judges;
        echo json_encode(['error' => "Cannot Lock: Waiting for $remaining judge(s) to submit scores."]);
        exit;
    }

    // --- END VALIDATION ---

    $final_results = ScoreCalculator::calculate($round_id);

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM round_rankings WHERE round_id = $round_id");
        $stmt = $conn->prepare("INSERT INTO round_rankings (round_id, contestant_id, total_score, `rank`) VALUES (?, ?, ?, ?)");
        
        foreach ($final_results as $row) {
            $cid = $row['contestant']['detail_id'] ?? 0;
            $score = $row['final_score'];
            $rank = $row['rank'];
            $stmt->bind_param("iidi", $round_id, $cid, $score, $rank);
            $stmt->execute();
        }

        $conn->query("UPDATE rounds SET status = 'Completed' WHERE id = $round_id");
        $conn->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>