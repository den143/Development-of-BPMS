<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Tabulator', 'Event Manager']); 
require_once __DIR__ . '/../app/config/database.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// 1. GET ACTIVE EVENT
$evt_sql = "SELECT id, name FROM events WHERE status = 'Active' LIMIT 1";
$event = $conn->query($evt_sql)->fetch_assoc();

if (!$event) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h1>No Active Event</h1><p>The Event Manager must activate an event first.</p></div>");
}

$event_id = $event['id'];

// 2. GET ROUNDS
$rnd_sql = "SELECT id, title, status FROM rounds WHERE event_id = $event_id ORDER BY ordering";
$rounds = $conn->query($rnd_sql)->fetch_all(MYSQLI_ASSOC);

// Default to first round or currently selected
$current_round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : ($rounds[0]['id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabulation - <?= htmlspecialchars($event['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --gold: #F59E0B; --dark: #111827; --success: #059669; }
        
        .tab-nav { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .tab-item { padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; text-decoration: none; color: #374151; font-weight: 600; white-space: nowrap; }
        .tab-item.active { background: var(--dark); color: white; border-color: var(--dark); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-top: 4px solid var(--gold); }
        
        .judge-status-list { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .j-badge { font-size: 11px; padding: 4px 10px; border-radius: 15px; background: #f3f4f6; color: #6b7280; font-weight: bold; border: 1px solid #e5e7eb; }
        .j-badge.submitted { background: #d1fae5; color: #065f46; border-color: #34d399; }

        .tally-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .tally-table { width: 100%; border-collapse: collapse; }
        .tally-table th { background: #f8fafc; padding: 15px; text-align: center; font-size: 12px; color: #64748b; text-transform: uppercase; border-bottom: 2px solid #f1f5f9; }
        .tally-table td { padding: 12px 15px; text-align: center; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        .rank-box { width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin: 0 auto; font-weight: 800; }
        .rank-1 .rank-box { background: var(--gold); color: white; }
        .rank-2 .rank-box { background: #94a3b8; color: white; }
        .rank-3 .rank-box { background: #b45309; color: white; }

        .contestant-cell { display: flex; align-items: center; gap: 12px; text-align: left; }
        .c-img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
        .c-details { display: flex; flex-direction: column; }
        .c-name { font-weight: 700; color: var(--dark); }
        .c-meta { font-size: 11px; color: #64748b; }

        .final-score { font-weight: 900; color: var(--dark); font-size: 16px; }

        .refresh-btn { background: white; border: 1px solid #ddd; padding: 8px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; }
        .refresh-btn:hover { background: #f9fafb; }
        
        .lock-btn { background: var(--dark); color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 10px; }
        .lock-btn:disabled { background: #94a3b8; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="main-wrapper">
    <?php include_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <div class="content-area">
        <div class="navbar" style="background:var(--dark); color:white; padding:15px 25px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight:800; font-size:1.2rem;">Tabulation Panel</div>
            <div id="liveIndicator" style="font-size:12px; color:#34d399; font-weight:bold;">
                <i class="fas fa-circle"></i> LIVE UPDATING
            </div>
        </div>

        <div class="container" style="padding:25px;">
            <div class="tab-nav">
                <?php foreach ($rounds as $r): ?>
                    <a href="?round_id=<?= $r['id'] ?>" class="tab-item <?= $r['id'] == $current_round_id ? 'active' : '' ?>">
                        <?= htmlspecialchars($r['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div style="font-size:11px; color:#64748b; font-weight:bold; text-transform:uppercase;">Judge Progress</div>
                    <div class="judge-status-list" id="judgeStatusList">
                        </div>
                </div>
                <div class="stat-card">
                    <div style="font-size:11px; color:#64748b; font-weight:bold; text-transform:uppercase;">Round Status</div>
                    <div id="roundStatusText" style="font-weight:800; font-size:1.2rem; margin-top:5px;">--</div>
                </div>
                <div class="stat-card" style="display:flex; align-items:center; justify-content:flex-end; gap:10px; border:none; background:transparent; box-shadow:none;">
                    <button class="refresh-btn" onclick="fetchTally()"><i class="fas fa-sync"></i> Refresh</button>
                    <button id="btnLock" class="lock-btn" onclick="lockRound()">
                        <i class="fas fa-lock"></i> LOCK ROUND
                    </button>
                </div>
            </div>

            <div class="tally-card">
                <table class="tally-table">
                    <thead>
                        <tr id="tableHeader">
                            <th>Rank</th>
                            <th style="text-align:left;">Contestant</th>
                            <th>Final Score</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr><td colspan="5" style="padding:50px; color:#64748b;">Initializing data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const ROUND_ID = <?= $current_round_id ?>;
    const API_URL = "../api/tally.php";
    
    async function fetchTally() {
        if (!ROUND_ID) return;
        try {
            const res = await fetch(`${API_URL}?round_id=${ROUND_ID}`);
            const data = await res.json();
            
            if (data.status === 'success') {
                renderJudgeStatus(data.judges, data.submitted_judges || []);
                renderTable(data.judges, data.ranking);
                document.getElementById('roundStatusText').innerText = data.round_status;
                
                const btn = document.getElementById('btnLock');
                if (data.round_status === 'Completed') {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-check-double"></i> ROUND COMPLETED';
                    btn.style.background = '#059669';
                }
            }
        } catch (e) { console.error("Tabulation Error:", e); }
    }

    function renderJudgeStatus(allJudges, submittedIds) {
        const list = document.getElementById('judgeStatusList');
        list.innerHTML = allJudges.map(j => {
            const isDone = submittedIds.includes(parseInt(j.id));
            return `<span class="j-badge ${isDone ? 'submitted' : ''}">
                ${isDone ? '<i class="fas fa-check-circle"></i>' : '<i class="far fa-circle"></i>'} 
                ${j.name}
            </span>`;
        }).join('');
    }

    function renderTable(judges, ranking) {
        const thead = document.getElementById('tableHeader');
        const tbody = document.getElementById('tableBody');

        // Headers
        let head = `<th>Rank</th><th style="text-align:left;">Contestant</th>`;
        judges.forEach(j => head += `<th>${j.name}</th>`);
        head += `<th>Final Score</th>`;
        thead.innerHTML = head;

        // Rows
        if (ranking.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${judges.length + 3}" style="padding:40px;">No scores recorded yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = ranking.map((row, idx) => {
            const c = row.contestant;
            const rank = row.rank;
            const rankClass = rank <= 3 ? `rank-${rank}` : '';
            
            let judgeCols = judges.map(j => {
                const s = row.judge_scores[j.id];
                return `<td>${s !== undefined ? parseFloat(s).toFixed(2) : '<span style="color:#cbd5e1">--</span>'}</td>`;
            }).join('');

            return `<tr class="${rankClass}">
                <td><div class="rank-box">${rank}</div></td>
                <td>
                    <div class="contestant-cell">
                        <img src="assets/uploads/contestants/${c.photo}" class="c-img" onerror="this.src='assets/images/default.png'">
                        <div class="c-details">
                            <span class="c-name">${c.name}</span>
                            <span class="c-meta">#${idx + 1} | ID: ${c.id}</span>
                        </div>
                    </div>
                </td>
                ${judgeCols}
                <td class="final-score">${parseFloat(row.final_score).toFixed(2)}</td>
            </tr>`;
        }).join('');
    }

    async function lockRound() {
        if (!confirm("Confirm Finalization? Judges will no longer be able to submit scores.")) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ round_id: ROUND_ID })
            });
            const result = await res.json();
            if (result.status === 'success') {
                alert("Round Locked Successfully!");
                fetchTally();
            } else { alert("Error: " + result.error); }
        } catch (e) { alert("Network Error"); }
    }

    setInterval(fetchTally, 5000);
    fetchTally();
</script>

</body>
</html>