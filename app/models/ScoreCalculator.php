<?php
require_once __DIR__ . '/../config/database.php';

class ScoreCalculator {

    private static function db() { global $conn; return $conn; }

    public static function calculate($round_id) {
        $db = self::db();
        $round_id = (int)$round_id;

        // 1. Fetch Active Judges (Including is_deleted check)
        $judges = $db->query("SELECT u.id, u.name 
                              FROM users u
                              JOIN event_judges ej ON u.id = ej.judge_id 
                              WHERE ej.event_id = (SELECT event_id FROM rounds WHERE id = $round_id) 
                              AND ej.status = 'Active' 
                              AND ej.is_deleted = 0")->fetch_all(MYSQLI_ASSOC);
        
        $judge_ids = array_column($judges, 'id');
        $judge_count = count($judge_ids);

        // 2. Fetch Contestants
        // We get BOTH IDs: 'user_id' for score lookup, 'detail_id' for saving results
        $c_sql = "SELECT u.id as user_id, cd.id as detail_id, u.name, cd.contestant_number, cd.photo 
                  FROM users u
                  JOIN contestant_details cd ON u.id = cd.user_id
                  JOIN rounds r ON r.event_id = cd.event_id
                  WHERE r.id = $round_id 
                  AND u.status = 'Active' 
                  AND cd.is_deleted = 0
                  ORDER BY cd.contestant_number ASC";
                  
        $contestants = $db->query($c_sql)->fetch_all(MYSQLI_ASSOC);

        // 3. Fetch Weights
        $segments = $db->query("SELECT id, weight_percentage FROM segments WHERE round_id = $round_id ORDER BY ordering")->fetch_all(MYSQLI_ASSOC);

        // 4. Fetch Scores
        $scores_raw = $db->query("SELECT * FROM scores WHERE round_id = $round_id")->fetch_all(MYSQLI_ASSOC);
        
        $score_map = [];
        foreach ($scores_raw as $s) {
            // Scores are stored by contestant_id (which is User ID in your system)
            $score_map[$s['contestant_id']][$s['judge_id']][$s['criteria_id']] = (float)$s['score_value']; 
        }

        // 5. Compute Totals
        $ranking = [];

        foreach ($contestants as $c) {
            $uid = $c['user_id'];   // Used to FIND scores
            $did = $c['detail_id']; // Used to SAVE results later
            
            $grand_total = 0;
            $judge_totals = [];

            foreach ($judge_ids as $jid) {
                $judge_round_total = 0;
                foreach ($segments as $seg) {
                    $sid = $seg['id'];
                    $weight = (float)$seg['weight_percentage'] / 100;
                    $criteria = $db->query("SELECT id FROM criteria WHERE segment_id = $sid")->fetch_all(MYSQLI_ASSOC);
                    
                    $seg_score = 0;
                    foreach ($criteria as $crit) {
                        // [FIXED] Use $uid (User ID) to look up the score
                        $val = $score_map[$uid][$jid][$crit['id']] ?? 0; 
                        $seg_score += $val;
                    }
                    $judge_round_total += ($seg_score * $weight);
                }
                $judge_totals[$jid] = number_format($judge_round_total, 2);
                $grand_total += $judge_round_total;
            }

            $final_score = ($judge_count > 0) ? $grand_total / $judge_count : 0;

            $ranking[] = [
                'contestant' => $c, // Contains both user_id and detail_id
                'judge_scores' => $judge_totals,
                'final_score' => number_format($final_score, 2),
                'rank' => 0
            ];
        }

        // 6. Sort
        usort($ranking, function($a, $b) { return $b['final_score'] <=> $a['final_score']; });

        // 7. Rank
        $rank = 1;
        foreach ($ranking as $key => $item) {
            if ($key > 0 && $item['final_score'] == $ranking[$key-1]['final_score']) {
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
        $criteria = $db->query("SELECT c.id, c.title, c.max_score, c.segment_id FROM criteria c JOIN segments s ON c.segment_id = s.id WHERE s.round_id = $round_id ORDER BY c.id")->fetch_all(MYSQLI_ASSOC);
        $judges = $db->query("SELECT u.id, u.name FROM users u JOIN event_judges ej ON u.id = ej.judge_id WHERE ej.event_id = (SELECT event_id FROM rounds WHERE id=$round_id) AND ej.status='Active' AND ej.is_deleted=0")->fetch_all(MYSQLI_ASSOC);
        $scores = $db->query("SELECT judge_id, contestant_id, criteria_id, score_value FROM scores WHERE round_id = $round_id")->fetch_all(MYSQLI_ASSOC);
        
        $mapped_scores = [];
        foreach($scores as $s) {
            $mapped_scores[$s['contestant_id']][$s['judge_id']][$s['criteria_id']] = $s['score_value'];
        }

        return ['segments' => $segments, 'criteria' => $criteria, 'judges' => $judges, 'scores' => $mapped_scores];
    }
}
?>