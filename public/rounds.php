<?php
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
$next_order = 1;
$previous_top_n = "N/A";

if ($active_event) {
    $event_id = $active_event['id'];
    $r_query = $conn->query("SELECT * FROM rounds WHERE event_id = $event_id ORDER BY ordering ASC");
    $rounds = $r_query->fetch_all(MYSQLI_ASSOC);

    // 2. Auto-Calculate Next Order
    if (!empty($rounds)) {
        $last_round = end($rounds);
        $next_order = $last_round['ordering'] + 1;
        $previous_top_n = $last_round['contestants_to_advance'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Rounds - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-add { background-color: #F59E0B; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        
        .round-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid transparent; transition: transform 0.2s; }
        .round-card:hover { transform: translateX(5px); }
        
        .round-card.active { border-left-color: #059669; background: #ecfdf5; }
        .round-card.pending { border-left-color: #d1d5db; }
        .round-card.completed { border-left-color: #3b82f6; background: #eff6ff; }

        .round-info h3 { margin: 0 0 5px 0; font-size: 18px; color: #111827; }
        .round-meta { font-size: 13px; color: #6b7280; display: flex; gap: 15px; }
        .round-meta span { display: flex; align-items: center; gap: 5px; }

        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-pending { background: #f3f4f6; color: #6b7280; }
        .status-completed { background: #dbeafe; color: #1e40af; }

        .actions { display: flex; gap: 10px; }
        .btn-icon { background: white; border: 1px solid #d1d5db; width: 32px; height: 32px; border-radius: 4px; cursor: pointer; color: #4b5563; display: flex; justify-content: center; align-items: center; text-decoration: none; }
        .btn-icon:hover { background: #f3f4f6; color: #111827; }
        
        .btn-activate { background: #059669; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold; text-decoration: none; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; width: 400px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        
        .info-alert { background:#eff6ff; padding:10px; border-radius:6px; font-size:12px; color:#1e40af; margin-bottom:15px; border-left: 3px solid #3b82f6; }
    </style>
</head>
<body>

<div class="main-wrapper">
    <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <div class="content-area">
        <div class="navbar"><div class="navbar-title">Manage Rounds</div></div>
        
        <div class="container">
            <div id="toast-container" class="toast-container"></div>

            <?php if (!$active_event): ?>
                <div style="text-align:center; padding: 40px; color: #6b7280;"><h2>No Active Event</h2></div>
            <?php else: ?>

                <div class="page-header">
                    <div>
                        <h2 style="color:#111827;">Pageant Rounds</h2>
                        <p style="color:#6b7280; font-size:14px;">Event: <?= htmlspecialchars($active_event['name']) ?></p>
                    </div>
                    <button class="btn-add" onclick="openAddModal()">+ Add Round</button>
                </div>

                <?php if (empty($rounds)): ?>
                    <div style="text-align:center; padding:30px; color:#9ca3af; background:white; border-radius:8px;">
                        No rounds created yet. Add your first round (e.g. "Preliminaries").
                    </div>
                <?php else: ?>
                    <?php foreach ($rounds as $r): ?>
                        <div class="round-card <?= strtolower($r['status']) ?>">
                            <div class="round-info">
                                <h3>
                                    <?= htmlspecialchars($r['title']) ?> 
                                    <span class="status-badge status-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span>
                                </h3>
                                <div class="round-meta">
                                    <span><i class="fas fa-sort-numeric-down"></i> Order: <?= $r['ordering'] ?></span>
                                    <span><i class="fas fa-trophy"></i> 
                                        <?= ($r['advancement_rule'] === 'winner') ? 'Final Winner' : 'Top ' . $r['contestants_to_advance'] . ' Advance' ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="actions">
                                <?php if ($r['status'] === 'Active'): ?>
                                    <button class="btn-icon" style="background:#fee2e2; color:#dc2626; border-color:#fecaca;" 
                                            onclick="openStopModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['title']) ?>')" 
                                            title="Emergency Stop">
                                        <i class="fas fa-stop-circle"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if ($r['status'] !== 'Active'): ?>
                                    <a href="../api/rounds.php?action=set_active&id=<?= $r['id'] ?>&event_id=<?= $event_id ?>" 
                                       class="btn-activate" onclick="return confirm('Start this round? This will deactivate other rounds.')">
                                       <i class="fas fa-play"></i> Start Round
                                    </a>
                                <?php else: ?>
                                    <span style="color:#059669; font-weight:bold; font-size:12px; display:flex; align-items:center; gap:5px;">
                                        <i class="fas fa-circle fa-beat" style="font-size:10px;"></i> LIVE
                                    </span>
                                <?php endif; ?>

                                <button class="btn-icon" onclick='openEditModal(<?= json_encode($r) ?>)' title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <?php if ($r['status'] !== 'Active'): ?>
                                <a href="../api/rounds.php?action=delete&id=<?= $r['id'] ?>" 
                                   class="btn-icon" style="color:#dc2626; border-color:#fee2e2;" 
                                   onclick="return confirm('Delete this round?')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<div id="addRoundModal" class="modal-overlay">
    <div class="modal-content">
        <h3 id="modalTitle">Add New Round</h3>
        
        <?php if (!empty($rounds)): ?>
        <div id="limitAlert" class="info-alert">
            <i class="fas fa-info-circle"></i> Previous Round Limit: <strong>Top <?= $previous_top_n ?></strong>.
            <br>Your new "Top N" must be lower than this.
        </div>
        <?php endif; ?>

        <form action="../api/rounds.php" method="POST" id="roundForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="event_id" value="<?= $active_event['id'] ?? '' ?>">
            <input type="hidden" name="round_id" id="round_id">
            
            <div class="form-group">
                <label>Round Title</label>
                <input type="text" name="title" id="r_title" class="form-control" placeholder="e.g. Preliminary Round" required>
            </div>
            
            <div class="form-group">
                <label>Order (Sequence)</label>
                <input type="number" name="ordering" id="r_order" class="form-control" value="<?= $next_order ?>" required>
            </div>
            
            <div class="form-group">
                <label>Advancement Rule</label>
                <select name="advancement_rule" id="r_rule" class="form-control" onchange="toggleAdvanceInput()">
                    <option value="top_n">Top N (Elimination)</option>
                    <option value="winner">Final Winner (Champion)</option>
                </select>
            </div>
            
            <div class="form-group" id="advanceGroup">
                <label>How many contestants advance?</label>
                <input type="number" name="contestants_to_advance" id="r_advance" class="form-control" value="5">
            </div>
            
            <div style="text-align:right; margin-top:20px;">
                <button type="button" onclick="closeModal('addRoundModal')" style="padding:8px 15px; border:none; background:#e5e7eb; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 15px; border:none; background:#F59E0B; color:white; border-radius:4px; font-weight:bold; cursor:pointer;">Save</button>
            </div>
        </form>
    </div>
</div>

<div id="stopRoundModal" class="modal-overlay">
    <div class="modal-content" style="border-top: 5px solid #dc2626;">
        <h3 style="color:#dc2626; margin-bottom:10px;"><i class="fas fa-exclamation-triangle"></i> Emergency Stop</h3>
        <p>You are about to stop <strong><span id="stopRoundName"></span></strong>.</p>
        <p style="font-size:13px; color:#6b7280; margin-bottom:15px; background:#fef2f2; padding:10px; border-radius:6px;">
            <i class="fas fa-shield-alt"></i> <strong>Safety Lock:</strong> This action is only allowed if <strong>NO SCORES</strong> have been submitted yet.
        </p>
        
        <form action="../api/rounds.php" method="POST">
            <input type="hidden" name="action" value="stop_round">
            <input type="hidden" name="round_id" id="stopRoundId">
            <div class="form-group">
                <label>Enter Event Manager Password:</label>
                <input type="password" name="password" class="form-control" required placeholder="Required for security">
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="closeModal('stopRoundModal')" style="padding:8px 15px; border:none; background:#e5e7eb; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 15px; border:none; background:#dc2626; color:white; border-radius:4px; font-weight:bold; cursor:pointer;">CONFIRM STOP</button>
            </div>
        </form>
    </div>
</div>

<script>
    // [FIX] Separate Function for ADD to prevent conflict
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerText = 'Add New Round';
        document.getElementById('roundForm').reset();
        document.getElementById('round_id').value = ''; 
        
        // Restore Default Order
        document.getElementById('r_order').value = "<?= $next_order ?>";
        
        // Show Alert
        const alert = document.getElementById('limitAlert');
        if(alert) alert.style.display = 'block';

        toggleAdvanceInput();
        document.getElementById('addRoundModal').style.display = 'flex';
    }

    // [FIX] Clean Edit Function
    function openEditModal(round) {
        document.getElementById('formAction').value = 'update';
        document.getElementById('modalTitle').innerText = 'Edit Round';
        document.getElementById('round_id').value = round.id;
        
        // Populate Data
        document.getElementById('r_title').value = round.title;
        document.getElementById('r_order').value = round.ordering;
        document.getElementById('r_rule').value = round.advancement_rule;
        document.getElementById('r_advance').value = round.contestants_to_advance;
        
        // Hide Alert (Irrelevant during Edit)
        const alert = document.getElementById('limitAlert');
        if(alert) alert.style.display = 'none';
        
        toggleAdvanceInput();
        document.getElementById('addRoundModal').style.display = 'flex';
    }

    function openStopModal(id, title) {
        document.getElementById('stopRoundId').value = id;
        document.getElementById('stopRoundName').innerText = title;
        document.getElementById('stopRoundModal').style.display = 'flex';
    }
    
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function toggleAdvanceInput() {
        const rule = document.getElementById('r_rule').value;
        const group = document.getElementById('advanceGroup');
        if (rule === 'winner') {
            group.style.display = 'none';
        } else {
            group.style.display = 'block';
        }
    }

    // Toast Logic
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => { toast.remove(); }, 3500);
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) showToast(urlParams.get('success'), 'success');
    if (urlParams.has('error')) showToast(urlParams.get('error'), 'error');
    if (urlParams.has('success') || urlParams.has('error')) {
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
</script>

</body>
</html>