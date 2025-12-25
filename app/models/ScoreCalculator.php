<?php
require_once __DIR__ . '/../config/database.php';

class ScoreCalculator {

    /**
     * Main function to return the Tally Matrix
     * Calculates weighted averages across all judges who have officially submitted.
     * Updated to handle User ID vs Detail ID mapping correctly.
     */
    public static function calculate($round_id) {
        global $conn;

        // 1. FETCH CONFIG: Segments & Weights
        $sql_seg = "SELECT id, weight_percentage FROM segments WHERE round_id = ? ORDER BY ordering";
        $stmt = $conn->prepare($sql_seg);
        $stmt->bind_param("i", $round_id);
        $stmt->execute();
        $segments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 2. FETCH ROUND CONTEXT (Event ID)
        $evt_q = $conn->prepare("SELECT event_id FROM rounds WHERE id = ?");
        $evt_q->bind_param("i", $round_id);
        $evt_q->execute();
        $round_data = $evt_q->get_result()->fetch_assoc();
        
        if (!$round_data) return [];
        $event_id = $round_data['event_id'];

        // 3. FETCH JUDGES WHO HAVE SUBMITTED
        $sql_judges = "SELECT u.id, u.name 
                       FROM event_judges ej 
                       JOIN users u ON ej.judge_id = u.id 
                       JOIN judge_round_status jrs ON (jrs.judge_id = u.id AND jrs.round_id = ?)
                       WHERE ej.event_id = ? AND ej.status = 'Active' AND jrs.status = 'Submitted'";
        $stmt_j = $conn->prepare($sql_judges);
        $stmt_j->bind_param("ii", $round_id, $event_id);
        $stmt_j->execute();
        $judges = $stmt_j->get_result()->fetch_all(MYSQLI_ASSOC);
        $judge_ids = array_column($judges, 'id');
        $num_judges = count($judges);

        // 4. FETCH CONTESTANTS (Correctly Mapping Detail ID to User ID)
        $sql_cont = "SELECT c.id as detail_id, u.id as user_id, u.name, c.contestant_number, c.photo 
                     FROM contestant_details c 
                     JOIN users u ON c.user_id = u.id 
                     WHERE c.event_id = ? AND u.status = 'Active' 
                     ORDER BY c.contestant_number";
        $stmt_c = $conn->prepare($sql_cont);
        $stmt_c->bind_param("i", $event_id);
        $stmt_c->execute();
        $contestants = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);

        // 5. FETCH ALL RAW SCORES
        $sql_scores = "SELECT s.judge_id, s.contestant_id as user_id, s.score_value, c.segment_id 
                       FROM scores s 
                       JOIN criteria c ON s.criteria_id = c.id 
                       JOIN segments seg ON c.segment_id = seg.id 
                       WHERE seg.round_id = ?";
        $stmt_s = $conn->prepare($sql_scores);
        $stmt_s->bind_param("i", $round_id);
        $stmt_s->execute();
        $raw_data = $stmt_s->get_result();

        // ORGANIZE SCORES: $organized_scores[user_id][judge][segment] = sum_of_criteria_scores
        $organized_scores = [];
        while ($row = $raw_data->fetch_assoc()) {
            if (!in_array($row['judge_id'], $judge_ids)) continue; 
            
            $uid = $row['user_id'];
            $jid = $row['judge_id'];
            $sid = $row['segment_id'];

            if (!isset($organized_scores[$uid][$jid][$sid])) {
                $organized_scores[$uid][$jid][$sid] = 0;
            }
            $organized_scores[$uid][$jid][$sid] += (float)$row['score_value'];
        }

        // 6. PERFORM CALCULATIONS
        $results = [];

        foreach ($contestants as $c) {
            $uid = $c['user_id'];
            $did = $c['detail_id'];
            $grand_total = 0;
            $judge_totals = [];

            foreach ($judges as $j) {
                $jid = $j['id'];
                $judge_round_total = 0;

                foreach ($segments as $seg) {
                    $sid = $seg['id'];
                    $weight = (float)$seg['weight_percentage'];
                    
                    // Match scores using the User ID
                    $raw_score = $organized_scores[$uid][$jid][$sid] ?? 0;
                    $weighted_score = $raw_score * ($weight / 100);
                    $judge_round_total += $weighted_score;
                }

                $judge_totals[$jid] = round($judge_round_total, 2); 
                $grand_total += $judge_round_total;
            }

            $final_score = ($num_judges > 0) ? ($grand_total / $num_judges) : 0;
            
            $results[] = [
                'contestant' => [
                    'id' => $did, // Return Detail ID for final ranking storage
                    'user_id' => $uid,
                    'name' => $c['name'],
                    'contestant_number' => $c['contestant_number'],
                    'photo' => $c['photo']
                ],
                'judge_scores' => $judge_totals,
                'final_score' => round($final_score, 4) 
            ];
        }

        // 7. SORT & RANK (Descending)
        usort($results, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });

        // Assign Ranks (Handling Ties)
        $rank = 1;
        foreach ($results as $index => &$row) {
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