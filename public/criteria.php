<?php
// DEBUGGING ON
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

$manager_id = $_SESSION['user_id'];

// 1. Get Active Event
$evt_sql = "SELECT id, name FROM events WHERE user_id = ? AND status = 'Active' LIMIT 1";
$evt_stmt = $conn->prepare($evt_sql);
$evt_stmt->bind_param("i", $manager_id);
$evt_stmt->execute();
$active_event = $evt_stmt->get_result()->fetch_assoc();

$rounds = [];
$active_round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
$active_segments = [];
$total_round_percentage = 0;

if ($active_event) {
    $event_id = $active_event['id'];
    
    // Fetch Rounds
    $r_query = $conn->query("SELECT * FROM rounds WHERE event_id = $event_id ORDER BY ordering ASC");
    if ($r_query) {
        $rounds = $r_query->fetch_all(MYSQLI_ASSOC);
    }

    // Default to first round
    if ($active_round_id === 0 && !empty($rounds)) {
        $active_round_id = $rounds[0]['id'];
    }

    // Fetch Segments & Criteria
    if ($active_round_id > 0) {
        $s_query = $conn->query("SELECT * FROM segments WHERE round_id = $active_round_id ORDER BY ordering ASC");
        
        if ($s_query) {
            $active_segments = $s_query->fetch_all(MYSQLI_ASSOC);

            foreach ($active_segments as &$seg) {
                $sid = $seg['id'];
                
                // Fetch Criteria
                $c_query = $conn->query("SELECT * FROM criteria WHERE segment_id = $sid ORDER BY ordering ASC");
                if ($c_query) {
                    $seg['criteria'] = $c_query->fetch_all(MYSQLI_ASSOC);
                } else {
                    $seg['criteria'] = [];
                }
                
                $total_round_percentage += $seg['weight_percentage'];
                
                $seg['total_points'] = 0;
                foreach ($seg['criteria'] as $c) { 
                    $seg['total_points'] += $c['max_score']; 
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Segments & Criteria - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* TABS */
        .tabs-wrapper { display: flex; gap: 5px; border-bottom: 2px solid #e5e7eb; margin-bottom: 25px; overflow-x: auto; }
        .tab-item { padding: 12px 25px; background: #f9fafb; border: 1px solid #e5e7eb; border-bottom: none; border-radius: 8px 8px 0 0; cursor: pointer; font-weight: 600; color: #6b7280; text-decoration: none; white-space: nowrap; }
        .tab-item:hover { background: #f3f4f6; color: #374151; }
        .tab-item.active { background: white; color: #F59E0B; border-color: #e5e7eb; border-bottom: 2px solid white; margin-bottom: -2px; }

        /* STATUS BAR */
        .status-bar { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #d1d5db; }
        .status-bar.valid { border-left-color: #059669; background: #ecfdf5; }
        .status-bar.invalid { border-left-color: #dc2626; background: #fef2f2; }

        /* CARD & TABLE */
        .segment-card { background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; overflow: hidden; border: 1px solid #e5e7eb; }
        .segment-header { background: #fff; padding: 20px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: flex-start; }
        .segment-title h3 { margin: 0; font-size: 18px; color: #111827; }
        .segment-desc { color: #6b7280; font-size: 14px; margin-top: 5px; font-style: italic; display: block; }
        .weight-badge { background: #1f2937; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-left: 10px; }

        .criteria-table { width: 100%; border-collapse: collapse; }
        .criteria-table th { background: #f9fafb; padding: 12px 20px; text-align: left; font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .criteria-table td { padding: 15px 20px; border-bottom: 1px solid #f3f4f6; color: #374151; font-size: 14px; }
        .crit-desc { display: block; font-size: 13px; color: #9ca3af; margin-top: 4px; font-style: italic; }

        /* BUTTONS */
        .btn-add-segment { background: #F59E0B; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn-sm { padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-block; }
        .btn-outline { border-color: #d1d5db; background: white; color: #374151; }
        .btn-delete { color: #dc2626; background: #fee2e2; }

        /* MODAL */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; width: 450px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
    </style>
</head>
<body>

<div class="main-wrapper">
    <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <div class="content-area">
        <div class="navbar"><div class="navbar-title">Segments & Criteria</div></div>
        
        <div class="container">
            <div id="toast-container" class="toast-container"></div>

            <?php if (!$active_event): ?>
                <div style="text-align:center; padding: 40px; color: #6b7280;">
                    <h2>No Active Event</h2>
                </div>
            <?php else: ?>

                <?php if (empty($rounds)): ?>
                    <div style="text-align:center; padding:30px; background:white; border-radius:8px;">
                        <p>No rounds found. Please <a href="rounds.php" style="color:#2563eb; font-weight:bold;">Create a Round</a> first.</p>
                    </div>
                <?php else: ?>
                    <div class="tabs-wrapper">
                        <?php foreach ($rounds as $r): ?>
                            <a href="?round_id=<?= $r['id'] ?>" class="tab-item <?= ($r['id'] == $active_round_id) ? 'active' : '' ?>">
                                <?= htmlspecialchars($r['title']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php 
                        $is_valid = ($total_round_percentage == 100); 
                    ?>
                    <div class="status-bar <?= $is_valid ? 'valid' : 'invalid' ?>">
                        <div>
                            <strong style="display:block; font-size:16px;">Round Composition</strong>
                            <span style="font-size:13px; color:#6b7280;">Total Weight must be exactly 100%.</span>
                        </div>
                        <div style="text-align:right;">
                            <span style="font-size:24px; font-weight:bold; color: <?= $is_valid ? '#059669' : '#dc2626' ?>">
                                <?= $total_round_percentage ?>%
                            </span>
                        </div>
                    </div>

                    <div style="text-align:right; margin-bottom:20px;">
                        <button class="btn-add-segment" onclick="openModal('addSegmentModal')">+ Add New Segment</button>
                    </div>

                    <?php if (empty($active_segments)): ?>
                        <div style="text-align:center; padding:40px; color:#9ca3af; border:2px dashed #e5e7eb; border-radius:8px;">
                            <p>No segments in this round yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($active_segments as $seg): ?>
                            <div class="segment-card">
                                <div class="segment-header">
                                    <div class="segment-title">
                                        <h3>
                                            <?= htmlspecialchars($seg['title']) ?>
                                            <span class="weight-badge"><?= $seg['weight_percentage'] ?>%</span>
                                        </h3>
                                        <?php if (!empty($seg['description'])): ?>
                                            <span class="segment-desc"><?= htmlspecialchars($seg['description']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex; gap:5px;">
                                        <button class="btn-sm btn-outline" onclick="openCriteriaModal(<?= $seg['id'] ?>)">+ Add Criteria</button>
                                        <form action="../api/criteria.php" method="POST" onsubmit="return confirm('Delete this segment?');">
                                            <input type="hidden" name="action" value="delete_segment">
                                            <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                                            <input type="hidden" name="round_id" value="<?= $active_round_id ?>">
                                            <button type="submit" class="btn-sm btn-delete">Del</button>
                                        </form>
                                    </div>
                                </div>

                                <table class="criteria-table">
                                    <thead><tr><th>Criteria Name</th><th>Max</th><th>Order</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php if (empty($seg['criteria'])): ?>
                                            <tr><td colspan="4" style="text-align:center; padding:15px; color:#9ca3af;">No criteria.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($seg['criteria'] as $crit): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($crit['title']) ?></strong>
                                                        <?php if (!empty($crit['description'])): ?>
                                                            <span class="crit-desc"><?= htmlspecialchars($crit['description']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $crit['max_score'] ?></td>
                                                    <td><?= $crit['ordering'] ?></td>
                                                    <td>
                                                        <form action="../api/criteria.php" method="POST" onsubmit="return confirm('Remove criteria?');">
                                                            <input type="hidden" name="action" value="delete_criteria">
                                                            <input type="hidden" name="criteria_id" value="<?= $crit['id'] ?>">
                                                            <input type="hidden" name="round_id" value="<?= $active_round_id ?>">
                                                            <button type="submit" class="btn-sm btn-delete">x</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr style="background:#f9fafb; font-weight:bold; font-size:12px;">
                                                <td colspan="4" style="text-align:right;">Total: <?= $seg['total_points'] ?> / 100</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="addSegmentModal" class="modal-overlay">
    <div class="modal-content">
        <h3>Add Segment</h3>
        <form action="../api/criteria.php" method="POST">
            <input type="hidden" name="action" value="add_segment">
            <input type="hidden" name="round_id" value="<?= $active_round_id ?>">
            <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required></div>
            <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control"></div>
            <div class="form-group"><label>Weight (%)</label><input type="number" step="0.01" name="weight_percentage" class="form-control" required></div>
            <div class="form-group"><label>Order</label><input type="number" name="ordering" class="form-control" value="1"></div>
            <div style="text-align:right;">
                <button type="button" onclick="closeModal('addSegmentModal')" class="btn-sm btn-outline">Cancel</button>
                <button type="submit" class="btn-add-segment">Save</button>
            </div>
        </form>
    </div>
</div>

<div id="addCriteriaModal" class="modal-overlay">
    <div class="modal-content">
        <h3>Add Criteria</h3>
        <form action="../api/criteria.php" method="POST">
            <input type="hidden" name="action" value="add_criteria">
            <input type="hidden" name="segment_id" id="crit_seg_id">
            <input type="hidden" name="round_id" value="<?= $active_round_id ?>">
            <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required></div>
            <div class="form-group"><label>Description</label><input type="text" name="description" class="form-control"></div>
            <div class="form-group"><label>Max Score</label><input type="number" step="0.01" name="max_score" class="form-control" value="50"></div>
            <div class="form-group"><label>Order</label><input type="number" name="ordering" class="form-control" value="1"></div>
            <div style="text-align:right;">
                <button type="button" onclick="closeModal('addCriteriaModal')" class="btn-sm btn-outline">Cancel</button>
                <button type="submit" class="btn-add-segment">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function openCriteriaModal(segId) {
        document.getElementById('crit_seg_id').value = segId;
        openModal('addCriteriaModal');
    }
    // Simple Toast Display
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success') || urlParams.has('error')) {
        const msg = urlParams.get('success') || urlParams.get('error');
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast success';
        toast.innerText = msg;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
        window.history.replaceState({}, document.title, window.location.pathname + "?round_id=<?= $active_round_id ?>");
    }
</script>

</body>
</html>