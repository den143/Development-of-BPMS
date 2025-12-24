<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
// Only Tabulator (or Event Manager for oversight) can access
requireRole(['Tabulator', 'Event Manager']); 
require_once __DIR__ . '/../app/config/database.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// 1. GET ACTIVE EVENT
// If Tabulator, find the event they are assigned to. 
// (For now, assuming Tabulator is linked to an event or we just pick the active one created by the manager).
// Since we don't have a direct "Tabulator -> Event" link table yet, we'll fetch the first 'Active' event.
$evt_sql = "SELECT id, name FROM events WHERE status = 'Active' LIMIT 1";
$event = $conn->query($evt_sql)->fetch_assoc();

if (!$event) {
    die("<h1>No Active Event Found</h1><p>Please ask the Event Manager to start an event.</p>");
}

$event_id = $event['id'];

// 2. GET ROUNDS FOR TABS
$rnd_sql = "SELECT id, title, status FROM rounds WHERE event_id = $event_id ORDER BY ordering";
$rounds = $conn->query($rnd_sql)->fetch_all(MYSQLI_ASSOC);

// Default to first round if not specified
$current_round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : ($rounds[0]['id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Panel - <?= htmlspecialchars($event['name']) ?></title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* TABULATOR SPECIFIC STYLES */
        .tally-container { max-width: 100%; overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        
        .tally-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: center; }
        .tally-table th, .tally-table td { padding: 12px 8px; border: 1px solid #e5e7eb; }
        .tally-table th { background: #f9fafb; font-weight: 700; color: #374151; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .tally-table td { color: #1f2937; }

        /* Column Specifics */
        .col-rank { font-weight: 800; font-size: 16px; color: #6b7280; width: 60px; }
        .col-contestant { text-align: left; padding-left: 15px; width: 250px; }
        .col-judge { width: 80px; font-family: 'Courier New', monospace; font-weight: 600; color: #4b5563; }
        .col-total { font-weight: 800; color: #111827; background: #f3f4f6; width: 100px; font-size: 14px; }
        
        /* Rank Highlights */
        .rank-1 { background-color: #fef3c7 !important; color: #92400e; } /* Gold */
        .rank-2 { background-color: #f3f4f6 !important; } /* Silverish */
        .rank-3 { background-color: #fff7ed !important; } /* Bronzeish */

        /* Contestant Cell */
        .c-info { display: flex; align-items: center; gap: 10px; }
        .c-img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }
        .c-num { font-size: 10px; font-weight: bold; background: #1f2937; color: white; padding: 2px 5px; border-radius: 4px; }
        .c-name { font-weight: 600; font-size: 13px; display: block; }
        
        /* Control Bar */
        .control-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .status-Active { background: #d1fae5; color: #065f46; }
        .status-Completed { background: #fee2e2; color: #991b1b; }
        .status-Pending { background: #f3f4f6; color: #6b7280; }

        .btn-refresh { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .btn-refresh:hover { background: #dbeafe; }
        
        .btn-lock { background: #111827; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-lock:hover { background: #374151; }
        .btn-lock:disabled { background: #9ca3af; cursor: not-allowed; }

        .loader { border: 2px solid #f3f3f3; border-top: 2px solid #3498db; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="main-wrapper">
    <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <div class="content-area">
        <div class="navbar">
            <div class="navbar-title">Tabulation Panel</div>
            <div style="font-size:13px; color:#ccc;">
                Event: <strong style="color:white;"><?= htmlspecialchars($event['name']) ?></strong>
            </div>
        </div>

        <div class="container">
            <div style="display:flex; gap:10px; margin-bottom:20px; overflow-x:auto; padding-bottom:5px;">
                <?php foreach ($rounds as $r): ?>
                    <a href="?round_id=<?= $r['id'] ?>" 
                       class="btn-refresh" 
                       style="<?= $r['id'] == $current_round_id ? 'background:#1f2937; color:white; border-color:#1f2937;' : '' ?>">
                       <?= htmlspecialchars($r['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($current_round_id): ?>
                
                <div class="control-bar">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <span id="roundStatusBadge" class="status-badge status-Pending">Loading...</span>
                        <div style="font-size:12px; color:#6b7280;">
                            Last updated: <span id="lastUpdated">--:--:--</span>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:10px;">
                        <button class="btn-refresh" onclick="fetchTally()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        
                        <?php if ($role === 'Tabulator' || $role === 'Event Manager'): ?>
                            <button id="btnLock" class="btn-lock" onclick="lockRound()">
                                <i class="fas fa-lock"></i> Finalize & Lock Round
                            </button>
                         <?php endif; ?>
                    </div>
                </div>

                <div class="tally-container">
                    <table class="tally-table" id="tallyTable">
                        <thead>
                            <tr id="tableHeader">
                                <th>Loading...</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <tr><td colspan="5" style="padding:30px;">Fetching live scores...</td></tr>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#9ca3af;">No rounds found.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const ROUND_ID = <?= $current_round_id ?>;
    const API_URL = "../api/tally.php";
    let isLocked = false;

    // 1. FETCH DATA FUNCTION
    async function fetchTally() {
        if (!ROUND_ID) return;

        try {
            const response = await fetch(`${API_URL}?round_id=${ROUND_ID}`);
            const data = await response.json();

            if (data.error) {
                alert(data.error);
                return;
            }

            renderTable(data);
            updateStatus(data.round_status);
            
            // Timestamp
            const now = new Date();
            document.getElementById('lastUpdated').innerText = now.toLocaleTimeString();

        } catch (error) {
            console.error("Fetch error:", error);
        }
    }

    // 2. RENDER TABLE FUNCTION
    function renderTable(data) {
        const thead = document.getElementById('tableHeader');
        const tbody = document.getElementById('tableBody');
        const judges = data.judges;
        const rows = data.ranking;

        // A. Build Header
        let headerHTML = `<th class="col-rank">Rank</th><th class="col-contestant">Contestant</th>`;
        
        judges.forEach(j => {
            headerHTML += `<th class="col-judge" title="${j.name}">J-${j.id}</th>`;
        });
        
        headerHTML += `<th class="col-total">FINAL</th>`;
        thead.innerHTML = headerHTML;

        // B. Build Rows
        let bodyHTML = "";
        if (rows.length === 0) {
            bodyHTML = `<tr><td colspan="${judges.length + 3}" style="padding:20px;">No scores yet.</td></tr>`;
        } else {
            rows.forEach(row => {
                const c = row.contestant;
                const scores = row.judge_scores; // Array of judge totals
                const final = row.final_score;
                const rank = row.rank;

                // Rank Highlight Class
                let rankClass = "";
                if (rank === 1) rankClass = "rank-1";
                else if (rank === 2) rankClass = "rank-2";
                else if (rank === 3) rankClass = "rank-3";

                bodyHTML += `<tr class="${rankClass}">`;
                
                // Rank
                bodyHTML += `<td class="col-rank">${rank}</td>`;
                
                // Contestant Info
                bodyHTML += `
                    <td class="col-contestant">
                        <div class="c-info">
                            <img src="./assets/uploads/contestants/${c.photo}" class="c-img" onerror="this.src='./assets/images/default.png'">
                            <div>
                                <span class="c-num">#${c.contestant_number}</span>
                                <span class="c-name">${c.name}</span>
                            </div>
                        </div>
                    </td>`;

                // Judge Columns
                judges.forEach(j => {
                    const score = scores[j.id] !== undefined ? scores[j.id] : '<span style="color:#e5e7eb;">--</span>';
                    // Highlight if score is missing (logic can be improved if needed)
                    bodyHTML += `<td>${score}</td>`;
                });

                // Final Score
                bodyHTML += `<td class="col-total">${parseFloat(final).toFixed(2)}</td>`;
                bodyHTML += `</tr>`;
            });
        }
        tbody.innerHTML = bodyHTML;
    }

    // 3. UPDATE STATUS UI
    function updateStatus(status) {
        const badge = document.getElementById('roundStatusBadge');
        const btn = document.getElementById('btnLock');
        
        badge.innerText = status;
        badge.className = `status-badge status-${status}`;

        if (status === 'Completed') {
            isLocked = true;
            if(btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-lock"></i> Round Locked';
                btn.style.background = '#059669'; // Green to show success
            }
        } else {
            isLocked = false;
            if(btn) btn.disabled = false;
        }
    }

    // 4. LOCK ROUND FUNCTION
    async function lockRound() {
        if (!confirm("WARNING: Are you sure you want to FINALIZE this round?\n\n- This will calculate the final rankings.\n- Judges will no longer be able to edit scores.\n- This action cannot be undone.")) {
            return;
        }

        const btn = document.getElementById('btnLock');
        btn.innerHTML = '<div class="loader"></div> Processing...';
        btn.disabled = true;

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ round_id: ROUND_ID })
            });
            
            const result = await response.json();
            
            if (result.status === 'success') {
                alert(result.message);
                fetchTally(); // Refresh to show locked status
            } else {
                alert("Error: " + result.error);
                btn.innerHTML = '<i class="fas fa-lock"></i> Finalize & Lock Round';
                btn.disabled = false;
            }
        } catch (e) {
            console.error(e);
            alert("Network error occurred.");
            btn.disabled = false;
        }
    }

    // INITIAL LOAD & AUTO-REFRESH
    document.addEventListener("DOMContentLoaded", () => {
        fetchTally();
        // Auto-refresh every 5 seconds (5000ms)
        setInterval(fetchTally, 5000);
    });
</script>

</body>
</html>