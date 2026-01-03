<?php
// tests/test_scoring_logic.php

// 1. CONNECT
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/ScoreCalculator.php';

// Helper for assertions
function assert_true($condition, $message) {
    if ($condition) {
        echo "<div style='color:green;'>[PASS] $message</div>";
    } else {
        echo "<div style='color:red;'>[FAIL] $message</div>";
    }
}

function assert_equals($expected, $actual, $message) {
    if ($expected === $actual) {
        echo "<div style='color:green;'>[PASS] $message (Got: " . htmlspecialchars(print_r($actual, true)) . ")</div>";
    } else {
        echo "<div style='color:red;'>[FAIL] $message (Expected: " . htmlspecialchars(print_r($expected, true)) . ", Got: " . htmlspecialchars(print_r($actual, true)) . ")</div>";
    }
}

// 2. SETUP
echo "<h2>Running Integration Test: ScoreCalculator</h2>";

// Create Dummy Users (Admin, Judges, Contestant)
$timestamp = time();
$admin_email = "admin_test_$timestamp@example.com";
$judge_emails = ["judgeA_$timestamp@example.com", "judgeB_$timestamp@example.com", "judgeC_$timestamp@example.com"];
$contestant_email = "contestant_$timestamp@example.com";

// Helper to create user
function create_user($conn, $name, $email, $role) {
    $conn->query("INSERT INTO users (name, email, password, role, status) VALUES ('$name', '$email', 'hashedpassword', '$role', 'Active')");
    return $conn->insert_id;
}

$admin_id = create_user($conn, "Test Admin", $admin_email, "Event Manager");
$judge_ids = [];
foreach ($judge_emails as $i => $email) {
    $judge_ids[] = create_user($conn, "Judge " . ($i + 1), $email, "Judge");
}
$contestant_user_id = create_user($conn, "Test Contestant", $contestant_email, "Contestant");

echo "<div>Created Users: Admin ($admin_id), Judges (" . implode(',', $judge_ids) . "), Contestant ($contestant_user_id)</div>";

// Create Event
$conn->query("INSERT INTO events (user_id, name, event_date, venue, status) VALUES ($admin_id, 'Test Event $timestamp', NOW(), 'Test Venue', 'Active')");
$event_id = $conn->insert_id;
echo "<div>Created Event: $event_id</div>";

// Assign Judges to Event
foreach ($judge_ids as $jid) {
    $conn->query("INSERT INTO event_judges (event_id, judge_id, status) VALUES ($event_id, $jid, 'Active')");
}

// Register Contestant Details
$conn->query("INSERT INTO contestant_details (user_id, event_id, contestant_number, status) VALUES ($contestant_user_id, $event_id, 1, 'Active')");
$contestant_detail_id = $conn->insert_id; // Note: We need user_id for scoring

// Create Round
$conn->query("INSERT INTO rounds (event_id, title, ordering, status, advancement_rule) VALUES ($event_id, 'Test Round', 1, 'Active', 'top_n')");
$round_id = $conn->insert_id;
echo "<div>Created Round: $round_id</div>";

// Create Segment (100% weight)
$conn->query("INSERT INTO segments (round_id, title, weight_percentage, ordering) VALUES ($round_id, 'Test Segment', 100.00, 1)");
$segment_id = $conn->insert_id;

// Create Criteria (Beauty 50, Poise 50)
// Note: Based on discussion, max_score sums to intended total.
$conn->query("INSERT INTO criteria (segment_id, title, max_score, ordering) VALUES ($segment_id, 'Beauty', 50.00, 1)");
$crit_beauty_id = $conn->insert_id;
$conn->query("INSERT INTO criteria (segment_id, title, max_score, ordering) VALUES ($segment_id, 'Poise', 50.00, 2)");
$crit_poise_id = $conn->insert_id;

echo "<div>Setup Complete. Segment: $segment_id. Criteria: $crit_beauty_id, $crit_poise_id</div>";
echo "<hr>";

// 3. TEST CASE 1: Happy Path (All Judges Score)
echo "<h3>Test Case 1: Happy Path</h3>";

// Insert Scores
// Judge 1: 40 + 40 = 80
// Judge 2: 45 + 45 = 90
// Judge 3: 50 + 50 = 100
// Expected Average: (80 + 90 + 100) / 3 = 90

$scores_data = [
    $judge_ids[0] => ['beauty' => 40, 'poise' => 40],
    $judge_ids[1] => ['beauty' => 45, 'poise' => 45],
    $judge_ids[2] => ['beauty' => 50, 'poise' => 50],
];

foreach ($scores_data as $jid => $s) {
    $conn->query("INSERT INTO scores (round_id, segment_id, criteria_id, judge_id, contestant_id, score_value) VALUES ($round_id, $segment_id, $crit_beauty_id, $jid, $contestant_user_id, {$s['beauty']})");
    $conn->query("INSERT INTO scores (round_id, segment_id, criteria_id, judge_id, contestant_id, score_value) VALUES ($round_id, $segment_id, $crit_poise_id, $jid, $contestant_user_id, {$s['poise']})");
}

$result = ScoreCalculator::calculate($round_id);
// Extract our test contestant
$contestant_result = null;
foreach ($result as $r) {
    if ($r['contestant']['user_id'] == $contestant_user_id) {
        $contestant_result = $r;
        break;
    }
}

if ($contestant_result) {
    assert_equals("Completed", $contestant_result['status'], "Status should be Completed");
    assert_equals("90.00", $contestant_result['final_score'], "Final Score should be 90.00");
} else {
    assert_true(false, "Contestant not found in result!");
}

echo "<hr>";

// 4. TEST CASE 2: The Edge Case (Delete Judge C's scores)
echo "<h3>Test Case 2: Missing Judge Score</h3>";

// Delete scores for Judge 3
$conn->query("DELETE FROM scores WHERE round_id=$round_id AND judge_id={$judge_ids[2]}");

$result_edge = ScoreCalculator::calculate($round_id);

$contestant_result_edge = null;
foreach ($result_edge as $r) {
    if ($r['contestant']['user_id'] == $contestant_user_id) {
        $contestant_result_edge = $r;
        break;
    }
}

if ($contestant_result_edge) {
    assert_equals("Pending", $contestant_result_edge['status'], "Status should be Pending");
    assert_equals("Pending", $contestant_result_edge['final_score'], "Final Score should be displayed as 'Pending'");
    assert_equals(0, $contestant_result_edge['raw_score'], "Raw Score should be 0 (or low) for sorting");

    // Check individual judge scores
    $judge_scores = $contestant_result_edge['judge_scores'];
    assert_equals("Pending", $judge_scores[$judge_ids[2]], "Judge 3 score should be Pending");
    assert_equals("80.00", $judge_scores[$judge_ids[0]], "Judge 1 score should still be 80.00");
} else {
    assert_true(false, "Contestant not found in result!");
}

echo "<hr>";

// 5. CLEANUP
echo "<h3>Cleanup</h3>";
// Delete Event (Cascades to Rounds, Scores, etc.)
$conn->query("DELETE FROM events WHERE id = $event_id");
// Delete Users
$all_user_ids = array_merge([$admin_id, $contestant_user_id], $judge_ids);
$ids_str = implode(',', $all_user_ids);
$conn->query("DELETE FROM users WHERE id IN ($ids_str)");

echo "<div>Cleanup Complete. Deleted Event $event_id and Users $ids_str.</div>";
?>