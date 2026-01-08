<?php
require_once __DIR__ . '/../config/database.php';

class ScoreCalculator {

    private static function db() { global $conn; return $conn; }

    public static function calculate($round_id) {
        $db = self::db();
        $round_id = (int)$round_id;

        // 1. Fetch Round Context
        // Use prepared statement
        $stmt_r = $db->prepare("SELECT ordering, event_id FROM rounds WHERE id = ?");
        $stmt_r->bind_param("i", $round_id);
        $stmt_r->execute();
        $r_data = $stmt_r->get_result()->fetch_assoc();

        if (!$r_data) return [];

        $is_prelim = ($r_data['ordering'] == 1);
        $event_id = (int)$r_data['event_id'];

        // 2. Fetch Active Judges
        // Query simplified, no params from user input here, but good practice to be safe
        $judges_result = $db->query("SELECT u.id, u.name
                              FROM users u
                              JOIN event_judges ej ON u.id = ej.judge_id
                              WHERE ej.event_id = $event_id
                              AND ej.status = 'Active'
                              AND ej.is_deleted = 0");
        $judges = $judges_result->fetch_all(MYSQLI_ASSOC);

        $judge_ids = array_column($judges, 'id');
        $total_judges = count($judge_ids);

        // 3. Fetch Contestants
        // Gatekeeper: Only show qualified or already scored contestants if not prelim
        $status_clause = "";
        if (!$is_prelim) {
            $status_clause = "AND (
                cd.status = 'Qualified' 
                OR EXISTS (SELECT 1 FROM scores s WHERE s.contestant_id = u.id AND s.round_id = $round_id)
            )";
        }

        $c_sql = "SELECT u.id as user_id, cd.id as detail_id, u.name, cd.contestant_number, cd.photo 
                  FROM users u
                  JOIN contestant_details cd ON u.id = cd.user_id
                  WHERE cd.event_id = $event_id
                  AND u.status = 'Active' 
                  AND cd.is_deleted = 0
                  $status_clause
                  ORDER BY cd.contestant_number ASC";
                  
        $contestants = $db->query($c_sql)->fetch_all(MYSQLI_ASSOC);

        // 4. Fetch Segments & Criteria
        $segments = $db->query("SELECT id, weight_percentage FROM segments WHERE round_id = $round_id ORDER BY ordering")->fetch_all(MYSQLI_ASSOC);
        
        $segment_ids = array_column($segments, 'id');
        $all_criteria = [];
        if (!empty($segment_ids)) {
            // Safe int cast via array_column/map before implode
            $ids_str = implode(',', array_map('intval', $segment_ids));
            $all_criteria = $db->query("SELECT id, segment_id, max_score FROM criteria WHERE segment_id IN ($ids_str)")->fetch_all(MYSQLI_ASSOC);
        }

        // Map criteria by Segment ID
        $criteria_by_segment = [];
        foreach ($all_criteria as $c) {
            $criteria_by_segment[$c['segment_id']][] = $c['id'];
        }

        // 5. Fetch ALL Scores for this round in one go
        // Eager Loading Optimization
        $stmt_s = $db->prepare("SELECT contestant_id, judge_id, criteria_id, score_value FROM scores WHERE round_id = ?");
        $stmt_s->bind_param("i", $round_id);
        $stmt_s->execute();
        $scores_raw = $stmt_s->get_result()->fetch_all(MYSQLI_ASSOC);

        $score_map = [];
        foreach ($scores_raw as $s) {
            // Use integers for keys to be faster/safer
            $score_map[(int)$s['contestant_id']][(int)$s['judge_id']][(int)$s['criteria_id']] = (float)$s['score_value'];
        }

        // 6. Compute Totals in Memory (No N+1 Queries)
        $ranking = [];

        foreach ($contestants as $c) {
            $uid = (int)$c['user_id'];

            $grand_total = 0;
            $judge_totals = [];
            $contestant_status = 'Completed';
            $judges_who_scored_count = 0;

            if ($total_judges === 0) {
                $contestant_status = 'Pending';
            }

            // Iterate through ALL active judges
            foreach ($judge_ids as $jid) {
                $jid = (int)$jid;
                $judge_round_total = 0;
                $judge_is_complete = true;
                
                // For this judge, calculate score based on Segments -> Criteria
                foreach ($segments as $seg) {
                    $sid = (int)$seg['id'];
                    $weight = (float)$seg['weight_percentage'] / 100;
                    
                    $criteria_ids = $criteria_by_segment[$sid] ?? [];
                    
                    $seg_score_sum = 0;
                    foreach ($criteria_ids as $crit_id) {
                        $crit_id = (int)$crit_id;

                        // STRICT CHECK: Missing score != 0.
                        if (!isset($score_map[$uid][$jid][$crit_id])) {
                            $judge_is_complete = false;
                            break; 2; // Break out of criteria loop AND segment loop
                        }

                        $seg_score_sum += $score_map[$uid][$jid][$crit_id];
                    }

                    // Sum weighted segment score
                    $judge_round_total += ($seg_score_sum * $weight);
                }

                if (!$judge_is_complete) {
                    $contestant_status = 'Pending';
                    $judge_totals[$jid] = 'Pending';
                } else {
                    $judge_totals[$jid] = $judge_round_total;
                    $grand_total += $judge_round_total;
                    $judges_who_scored_count++;
                }
            }

            // PENDING PROPAGATION LOGIC:
            // If ANY active judge is pending, the whole contestant result is pending.
            // We do NOT show partial averages.
            if ($contestant_status === 'Pending' || $judges_who_scored_count < $total_judges) {
                 $final_score = 0; // Internal value for sorting (will be low)
                 $contestant_status = 'Pending';
            } else {
                 $final_score = ($judges_who_scored_count > 0) ? $grand_total / $judges_who_scored_count : 0;
            }

            // Prepare Display Data
            $formatted_judge_scores = [];
            foreach($judge_totals as $j_id => $sc) {
                $formatted_judge_scores[$j_id] = ($sc === 'Pending') ? 'Pending' : number_format($sc, 2);
            }

            $ranking[] = [
                'contestant' => $c,
                'judge_scores' => $formatted_judge_scores, 
                'raw_score' => $final_score,               
                'final_score' => ($contestant_status === 'Pending') ? 'Pending' : number_format($final_score, 2),
                'status' => $contestant_status,
                'rank' => 0
            ];
        }

        // 7. Sort (Descending by Raw Score)
        usort($ranking, function($a, $b) { 
            // If both are pending (score 0), order doesn't matter much, maybe by number
            return $b['raw_score'] <=> $a['raw_score']; 
        });

        // 8. Rank Assignment (Handle ties)
        $rank = 1;
        foreach ($ranking as $key => $item) {
            if ($item['status'] === 'Pending') {
                $ranking[$key]['rank'] = '-';
                continue;
            }

            // Tie logic: If same score as previous, same rank.
            if ($key > 0 &&
                $item['final_score'] === $ranking[$key-1]['final_score'] &&
                $ranking[$key-1]['status'] !== 'Pending') {

                $ranking[$key]['rank'] = $ranking[$key-1]['rank'];
            } else {
                $ranking[$key]['rank'] = $rank;
            }
            $rank++;
        }

        return $ranking;
    }

    public static function getAuditData($round_id) { 
        $db = self::db();
        $round_id = (int)$round_id;
        
        $segments = $db->query("SELECT id, title, weight_percentage FROM segments WHERE round_id = $round_id ORDER BY ordering")->fetch_all(MYSQLI_ASSOC);

        $s_ids = implode(',', array_map(fn($s) => (int)$s['id'], $segments));
        $criteria = [];
        if($s_ids) {
            $criteria = $db->query("SELECT c.id, c.title, c.max_score, c.segment_id FROM criteria c WHERE c.segment_id IN ($s_ids) ORDER BY c.id")->fetch_all(MYSQLI_ASSOC);
        }

        $judges = $db->query("SELECT u.id, u.name FROM users u JOIN event_judges ej ON u.id = ej.judge_id WHERE ej.event_id = (SELECT event_id FROM rounds WHERE id=$round_id) AND ej.status='Active' AND ej.is_deleted=0")->fetch_all(MYSQLI_ASSOC);
        $scores = $db->query("SELECT judge_id, contestant_id, criteria_id, score_value FROM scores WHERE round_id = $round_id")->fetch_all(MYSQLI_ASSOC);
        
        $mapped_scores = [];
        foreach($scores as $s) {
            $mapped_scores[$s['contestant_id']][$s['judge_id']][$s['criteria_id']] = $s['score_value'];
        }

        return ['segments' => $segments, 'criteria' => $criteria, 'judges' => $judges, 'scores' => $mapped_scores];
    }
}
