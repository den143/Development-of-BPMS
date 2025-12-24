<?php
require_once __DIR__ . '/../config/database.php';

class ScoreCalculator {

    // Main function to return the Tally Matrix
    public static function calculate($round_id) {
        global $conn;

        // 1. FETCH CONFIG: Segments & Weights
        // We need to know the structure of the round
        $sql_seg = "SELECT id, weight_percentage FROM segments WHERE round_id = ? ORDER BY ordering";
        $stmt = $conn->prepare($sql_seg);
        $stmt->bind_param("i", $round_id);
        $stmt->execute();
        $segments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 2. FETCH ACTIVE JUDGES
        // Only count judges who are currently assigned and active
        // Assuming round_id belongs to an event, we need the event_id first
        $evt_q = $conn->query("SELECT event_id FROM rounds WHERE id = $round_id");
        $event_id = $evt_q->fetch_assoc()['event_id'];

        $sql_judges = "SELECT u.id, u.name 
                       FROM event_judges ej 
                       JOIN users u ON ej.judge_id = u.id 
                       WHERE ej.event_id = ? AND ej.status = 'Active'";
        $stmt_j = $conn->prepare($sql_judges);
        $stmt_j->bind_param("i", $event_id);
        $stmt_j->execute();
        $judges = $stmt_j->get_result()->fetch_all(MYSQLI_ASSOC);
        $judge_ids = array_column($judges, 'id');
        $num_judges = count($judges);

        // 3. FETCH CONTESTANTS
        $sql_cont = "SELECT c.id, u.name, c.contestant_number, c.photo 
                     FROM contestant_details c 
                     JOIN users u ON c.user_id = u.id 
                     WHERE c.event_id = ? AND u.status = 'Active' 
                     ORDER BY c.contestant_number";
        $stmt_c = $conn->prepare($sql_cont);
        $stmt_c->bind_param("i", $event_id);
        $stmt_c->execute();
        $contestants = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);

        // 4. FETCH ALL RAW SCORES
        // We get every single score for this round in one query to save performance
        $sql_scores = "SELECT s.judge_id, s.contestant_id, s.score, c.segment_id 
                       FROM scores s 
                       JOIN criteria c ON s.criteria_id = c.id 
                       JOIN segments seg ON c.segment_id = seg.id 
                       WHERE seg.round_id = ?";
        $stmt_s = $conn->prepare($sql_scores);
        $stmt_s->bind_param("i", $round_id);
        $stmt_s->execute();
        $raw_data = $stmt_s->get_result();

        // ORGANIZE SCORES: $scores[contestant][judge][segment] = total_raw_segment_score
        $organized_scores = [];
        while ($row = $raw_data->fetch_assoc()) {
            if (!in_array($row['judge_id'], $judge_ids)) continue; // Skip inactive judges
            
            $cid = $row['contestant_id'];
            $jid = $row['judge_id'];
            $sid = $row['segment_id'];

            if (!isset($organized_scores[$cid][$jid][$sid])) {
                $organized_scores[$cid][$jid][$sid] = 0;
            }
            $organized_scores[$cid][$jid][$sid] += (float)$row['score'];
        }

        // 5. PERFORM CALCULATIONS (The Formula)
        $results = [];

        foreach ($contestants as $c) {
            $cid = $c['id'];
            $grand_total = 0;
            $judge_totals = [];

            foreach ($judges as $j) {
                $jid = $j['id'];
                $judge_round_total = 0;

                foreach ($segments as $seg) {
                    $sid = $seg['id'];
                    $weight = (float)$seg['weight_percentage'];
                    
                    // Step 1: Raw Segment Score
                    $raw_score = $organized_scores[$cid][$jid][$sid] ?? 0;
                    
                    // Step 2: Weighted Segment Score
                    $weighted_score = $raw_score * ($weight / 100);
                    
                    // Step 3: Judge's Total Score (Accumulate)
                    $judge_round_total += $weighted_score;
                }

                $judge_totals[$jid] = round($judge_round_total, 2); // Store for display
                $grand_total += $judge_round_total;
            }

            // Step 4: Final Ranking Score (Average)
            $final_score = ($num_judges > 0) ? ($grand_total / $num_judges) : 0;
            
            $results[] = [
                'contestant' => $c,
                'judge_scores' => $judge_totals,
                'final_score' => round($final_score, 4) // High precision for sorting
            ];
        }

        // 6. SORT & RANK
        usort($results, function($a, $b) {
            return $b['final_score'] <=> $a['final_score']; // DESC sort
        });

        // Assign Ranks (Handling Ties)
        $rank = 1;
        foreach ($results as $index => &$row) {
            // Check for tie with previous
            if ($index > 0 && $row['final_score'] == $results[$index-1]['final_score']) {
                $row['rank'] = $results[$index-1]['rank'];
            } else {
                $row['rank'] = $rank;
            }
            $rank++;
        }

        return $results;
    }
}
?>