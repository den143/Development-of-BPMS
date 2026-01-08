<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/guard.php';

requireLogin();
if ($_SESSION['role'] !== 'Judge') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Read JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

$judge_id = $_SESSION['user_id'];
$round_id = (int)($data['round_id'] ?? 0);
$contestant_id = (int)($data['contestant_id'] ?? 0);
$segment_id_input = (int)($data['segment_id'] ?? 0);

if ($round_id <= 0 || $contestant_id <= 0) {
    echo json_encode(['error' => 'Invalid Data']);
    exit();
}

$conn->begin_transaction();
try {
    // ---------------------------------------------------------
    // OPTIMIZATION 1: Fetch all Criteria Limits in ONE Query
    // ---------------------------------------------------------
    $criteria_limits = [];
    if (isset($data['scores']) && is_array($data['scores']) && !empty($data['scores'])) {
        // Extract all criteria IDs from the input
        $crit_ids = array_keys($data['scores']);
        
        // FIX: Use is_numeric because json_decode returns keys as strings
        $crit_ids = array_filter($crit_ids, 'is_numeric');

        if (!empty($crit_ids)) {
            // Create placeholders: ?,?,?
            $ids_placeholder = implode(',', array_fill(0, count($crit_ids), '?'));

            // Fetch max_score and segment_id for ALL sent criteria
            $sql = "SELECT id, segment_id, max_score FROM criteria WHERE id IN ($ids_placeholder)";
            $stmt_limits = $conn->prepare($sql);

            // Dynamically bind params
            $types = str_repeat('i', count($crit_ids));
            $stmt_limits->bind_param($types, ...$crit_ids);
            $stmt_limits->execute();
            $res = $stmt_limits->get_result();

            while ($row = $res->fetch_assoc()) {
                $criteria_limits[$row['id']] = $row;
            }
        }
    }

    // ---------------------------------------------------------
    // 2. Save Scores (Loop using cached limits)
    // ---------------------------------------------------------
    if (isset($data['scores']) && is_array($data['scores'])) {
        $stmt = $conn->prepare("INSERT INTO scores (round_id, segment_id, criteria_id, judge_id, contestant_id, score_value) 
                                VALUES (?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE score_value = VALUES(score_value)");
        
        foreach ($data['scores'] as $crit_id => $val) {
            // Skip if this criteria ID doesn't exist in our DB check (security)
            if (!isset($criteria_limits[$crit_id])) continue;

            $limit_info = $criteria_limits[$crit_id];
            $max_limit = (float)$limit_info['max_score'];
            $seg_id = $limit_info['segment_id']; // Use DB source of truth
            $score_val = (float)$val;

            // Cap the score
            if ($score_val > $max_limit) $score_val = $max_limit;
            if ($score_val < 0) $score_val = 0;

            $stmt->bind_param("iiiiid", $round_id, $seg_id, $crit_id, $judge_id, $contestant_id, $score_val);
            $stmt->execute();
        }
    }

    // ---------------------------------------------------------
    // 3. Save Comment
    // ---------------------------------------------------------
    if (isset($data['comment'])) {
        $comment = trim($data['comment']);
        // Sanitize? Prepared statement handles SQL injection.
        // We will sanitize output when displaying (htmlspecialchars).

        $stmt_c = $conn->prepare("INSERT INTO segment_comments (round_id, segment_id, judge_id, contestant_id, comment_text) 
                                  VALUES (?, ?, ?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text)");

        $stmt_c->bind_param("iiiis", $round_id, $segment_id_input, $judge_id, $contestant_id, $comment);
        $stmt_c->execute();
    }

    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $conn->rollback();
    // Log error internally, don't expose DB details to frontend
    error_log("Save Draft Error: " . $e->getMessage()); 
    echo json_encode(['error' => 'Save failed']); 
}
