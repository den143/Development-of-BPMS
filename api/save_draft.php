<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/guard.php';

requireLogin();
if ($_SESSION['role'] !== 'Judge') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$judge_id = $_SESSION['user_id'];
$round_id = (int)$data['round_id'];
$contestant_id = (int)$data['contestant_id'];

$conn->begin_transaction();
try {
    // Save Scores
    if (isset($data['scores'])) {
        $stmt = $conn->prepare("INSERT INTO scores (round_id, segment_id, criteria_id, judge_id, contestant_id, score_value) 
                                VALUES (?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE score_value = VALUES(score_value)");
        foreach ($data['scores'] as $crit_id => $val) {
            // 1. Fetch criteria info to get max_score and segment_id
            $crit_stmt = $conn->prepare("SELECT segment_id, max_score FROM criteria WHERE id = ?");
            $crit_stmt->bind_param("i", $crit_id);
            $crit_stmt->execute();
            $crit_info = $crit_stmt->get_result()->fetch_assoc();
            
            if (!$crit_info) continue;

            $max_limit = (float)$crit_info['max_score'];
            $seg_id = $crit_info['segment_id'];
            $score_val = (float)$val;

            // 2. Server-side Validation: Cap the score if it exceeds the max allowed
            if ($score_val > $max_limit) {
                $score_val = $max_limit;
            } elseif ($score_val < 0) {
                $score_val = 0;
            }

            $stmt->bind_param("iiiiid", $round_id, $seg_id, $crit_id, $judge_id, $contestant_id, $score_val);
            $stmt->execute();
        }
    }

    // Save Comment
    if (isset($data['comment'])) {
        $stmt_c = $conn->prepare("INSERT INTO segment_comments (round_id, segment_id, judge_id, contestant_id, comment_text) 
                                  VALUES (?, ?, ?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text)");
        $stmt_c->bind_param("iiiis", $round_id, $data['segment_id'], $judge_id, $contestant_id, $data['comment']);
        $stmt_c->execute();
    }

    $conn->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['error' => $e->getMessage()]);
}