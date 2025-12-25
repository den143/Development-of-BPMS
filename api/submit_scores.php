<?php
// bpms/api/submit_scores.php
session_start();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/guard.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Security check: Only a logged-in Judge can submit scores
requireLogin();
if ($_SESSION['role'] !== 'Judge') {
    header("Location: ../public/index.php?error=Unauthorized Access");
    exit();
}

// 2. Handle the Final Lock (Triggered by GET request from dashboard)
// The scores were already saved via AJAX/save_draft.php
$round_id = (int)($_GET['round_id'] ?? 0);
$judge_id = $_SESSION['user_id'];

if ($round_id > 0) {
    try {
        // First, check if the round is still active
        $stmt_check = $conn->prepare("SELECT status FROM rounds WHERE id = ?");
        $stmt_check->bind_param("i", $round_id);
        $stmt_check->execute();
        $round = $stmt_check->get_result()->fetch_assoc();

        if (!$round || $round['status'] !== 'Active') {
            throw new Exception("This round is no longer active and cannot be submitted.");
        }

        // Update Judge Submission Status to 'Submitted' (The "Lock")
        $stmt_status = $conn->prepare("INSERT INTO judge_round_status (round_id, judge_id, status, submitted_at) 
                                       VALUES (?, ?, 'Submitted', NOW()) 
                                       ON DUPLICATE KEY UPDATE status = 'Submitted', submitted_at = NOW()");
        
        if (!$stmt_status) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }

        $stmt_status->bind_param("ii", $round_id, $judge_id);
        
        if ($stmt_status->execute()) {
            // Success: Redirect back with success message
            header("Location: ../public/judge_dashboard.php?success=Scorecard locked successfully");
            exit();
        } else {
            throw new Exception("Failed to lock scorecard: " . $stmt_status->error);
        }

    } catch (Exception $e) {
        // Redirect with the specific error message to avoid white screen
        header("Location: ../public/judge_dashboard.php?error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    // Fallback if no Round ID is found in the URL
    header("Location: ../public/judge_dashboard.php?error=Invalid Round ID");
    exit();
}