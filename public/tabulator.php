<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Tabulator', 'Event Manager']); 
require_once __DIR__ . '/../app/config/database.php';

$user_id = $_SESSION['user_id'];

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
        
        /* --- LAYOUT UTILS --- */
        .tab-nav { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .tab-item { padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; text-decoration: none; color: #374151; font-weight: 600; white-space: nowrap; }
        .tab-item.active { background: var(--dark); color: white; border-color: var(--dark); }

        /* --- STATS GRID --- */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-top: 4px solid var(--gold); }
        
        .judge-status-list { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .j-badge { font-size: 11px; padding: 4px 10px; border-radius: 15px; background: #f3f4f6; color: #6b7280; font-weight: bold; border: 1px solid #e5e7eb; }
        .j-badge.submitted { background: #d1fae5; color: #065f46; border-color: #34d399; }

        /* --- CONTROL TOOLBAR --- */
        .control-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 15px; background: #fff; padding: 10px 15px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; }
        
        .view-switcher { display: flex; background: #f3f4f6; padding: 4px; border-radius: 8px; }
        .view-btn { padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; font-size: 13px; transition: 0.2s; color: #6b7280; }
        .view-btn.active { background: white; color: var(--dark); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        
        .action-group { display: flex; gap: 10px; }
        .action-btn { background: white; border: 1px solid #d1d5db; padding: 8px 16px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; color: #374151; transition: 0.2s; font-size: 13px; }
        .action-btn:hover { background: #f9fafb; border-color: #9ca3af; }
        
        .lock-btn { background: var(--dark); color: white; border: none; padding: 8px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; font-size: 13px; }
        .lock-btn:hover { background: #1f2937; }
        .lock-btn:disabled { background: #059669; cursor: not-allowed; opacity: 1; }

        /* --- TABLES --- */
        .tally-card { background: white; border-radius: 12px; overflow: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .tally-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .tally-table th { background: #f8fafc; padding: 12px; text-align: center; font-size: 11px; color: #64748b; text-transform: uppercase; border: 1px solid #e2e8f0; }
        .tally-table td { padding: 10px; text-align: center; border: 1px solid #e2e8f0; font-size: 13px; }

        /* Audit View Colors */
        .audit-header-main { background: var(--dark) !important; color: white !important; font-size: 13px !important; }
        .audit-header-sub { background: #f1f5f9 !important; font-weight: bold; }
        .audit-na { color: #dc2626; font-weight: bold; font-size: 11px; }
        .audit-weighted { background: #fffbeb; font-weight: bold; color: #b45309; }

        .rank-box { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin: 0 auto; font-weight: 800; }
        .rank-1 .rank-box { background: var(--gold); color: white; }
        .rank-2 .rank-box { background: #94a3b8; color: white; }
        .rank-3 .rank-box { background: #b45309; color: white; }

        .contestant-cell { display: flex; align-items: center; gap: 10px; text-align: left; }
        .c-img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; }
        .final-score { font-weight: 900; color: var(--dark); font-size: 16px; }
        
        .hidden { display: none !important; }
        
        /* Helper for Audit Print Container - ALWAYS Hidden on Screen */
        #auditPrintContainer { display: none; }

        /* --- NEW STYLES FOR AWARDS --- */
        .awards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .award-tile { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; position: relative; }
        .award-tile h4 { margin: 0 0 5px 0; color: var(--dark); display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .award-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: #eee; color: #666; text-transform: uppercase; }
        .award-winner-box { margin-top: 10px; padding: 10px; background: #f9fafb; border-radius: 6px; text-align: center; font-weight: bold; border: 1px solid #f3f4f6; }
        .winner-auto { color: var(--success); display: flex; flex-direction: column; align-items: center; gap: 5px; }
        .winner-pending { color: #d97706; font-style: italic; font-size: 12px; }
        .manual-select { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; margin-top: 5px; font-size: 13px; }

        /* ======================== */
        /* PRINT STYLES (FOOLPROOF) */
        /* ======================== */
        @media print {
            @page { size: landscape; margin: 0.5cm; }
            body { font-family: 'Times New Roman', serif; background: white; -webkit-print-color-adjust: exact; }

            /* 1. HIDE ALL UI CHROME */
            .sidebar, .navbar, .tab-nav, .stats-grid, .control-toolbar, .action-btn { display: none !important; }
            
            /* 2. RESET CONTAINERS */
            .main-wrapper, .content-area, .container { 
                margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; box-shadow: none !important; 
            }
            .tally-card { border: none !important; box-shadow: none !important; overflow: visible !important; }
            
            /* 3. TABLE STYLING FOR PRINT */
            .tally-table { width: 100% !important; font-size: 10pt !important; table-layout: fixed; }
            .tally-table th, .tally-table td { border: 1px solid #000 !important; color: #000 !important; padding: 4px !important; }
            .rank-box { border: 1px solid #000; color: #000 !important; background: none !important; }

            /* 4. VIEW LOGIC - KEY FIX */
            /* If Summary View is ACTIVE on screen, show it in print */
            #summaryView:not(.hidden) { display: block !important; }
            
            /* If Audit View is ACTIVE on screen... */
            #auditView:not(.hidden) { display: block !important; }
            
            /* ...HIDE the Screen Table (Wide) */
            #auditView:not(.hidden) #auditTableScreen { display: none !important; }
            
            /* ...SHOW the Print Container (Segmented) */
            #auditView:not(.hidden) #auditPrintContainer { display: block !important; }

            /* If Awards View is ACTIVE on screen... */
            #awardsView:not(.hidden) { display: block !important; }
            /* Make Awards grid print-friendly */
            #awardsView:not(.hidden) .awards-grid { display: block !important; }
            #awardsView:not(.hidden) .award-tile { page-break-inside: avoid; border: 1px solid #000; margin-bottom: 10px; }
            #awardsView:not(.hidden) .manual-select { display: none !important; } /* Hide dropdown on print */
            
            /* 5. HEADER & FOOTER */
            .tally-card::before, #awardsView::before {
                content: "OFFICIAL RESULT SHEET: <?= strtoupper(htmlspecialchars($event['name'])) ?> - <?= strtoupper(htmlspecialchars($rounds[array_search($current_round_id, array_column($rounds, 'id'))]['title'])) ?>";
                display: block; text-align: center; font-weight: bold; font-size: 14pt; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 2px solid #000;
            }
            .tally-card::after, #awardsView::after {
                content: "\A\A__________________________\A Tabulator Signature \A\A __________________________\A Judge Coordinator Signature";
                white-space: pre; display: block; margin-top: 30px; font-weight: bold; page-break-inside: avoid;
            }

            /* 6. SEGMENT STYLING */
            .print-segment-block { margin-bottom: 25px; page-break-inside: avoid; }
            .print-segment-title { font-size: 12pt; font-weight: bold; margin-bottom: 5px; text-transform: uppercase; border-bottom: 1px solid #000; display: inline-block; }
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <?php include_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <div class="content-area">
        <div class="navbar" style="background:var(--dark); color:white; padding:15px 25px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight:800; font-size:1.2rem;">Tabulation Panel</div>
            <div id="liveIndicator" style="font-size:12px; color:#34d399; font-weight:bold;"><i class="fas fa-circle"></i> LIVE</div>
        </div>

        <div class="container" style="padding:20px;">
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
                    <div class="judge-status-list" id="judgeStatusList"></div>
                </div>
                <div class="stat-card">
                    <div style="font-size:11px; color:#64748b; font-weight:bold; text-transform:uppercase;">Round Status</div>
                    <div id="roundStatusText" style="font-weight:800; font-size:1.2rem; margin-top:5px;">--</div>
                </div>
            </div>

            <div class="control-toolbar">
                <div class="view-switcher">
                    <button class="view-btn active" id="btnSum" onclick="switchView('summary')">Leaderboard</button>
                    <button class="view-btn" id="btnAud" onclick="switchView('audit')">Audit Matrix</button>
                    <button class="view-btn" id="btnAwd" onclick="switchView('awards')">Special Awards</button>
                </div>
                
                <div class="action-group">
                    <button class="action-btn" id="btnPrint" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Leaderboard
                    </button>
                    <button class="action-btn" onclick="fetchTally()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button id="btnLock" class="lock-btn" onclick="lockRound()">
                        <i class="fas fa-lock"></i> LOCK ROUND
                    </button>
                </div>
            </div>

            <div class="tally-card" id="summaryView">
                <table class="tally-table">
                    <thead><tr id="tableHeader"></tr></thead>
                    <tbody id="tableBody"><tr><td colspan="10" style="padding:40px;">Loading data...</td></tr></tbody>
                </table>
            </div>

            <div class="tally-card hidden" id="auditView">
                <table class="tally-table" id="auditTableScreen"></table>
                <div id="auditPrintContainer"></div>
            </div>

            <div class="hidden" id="awardsView">
                <div class="awards-grid" id="awardsGrid">
                    </div>
            </div>

        </div>
    </div>
</div>

<script>
    const ROUND_ID = <?= $current_round_id ?>;
    const EVENT_ID = <?= $event_id ?>;
    const API_URL = "../api/tally.php";
    const AWARDS_API = "../api/awards_tally.php";
    let currentData = null;
    let allContestants = []; // For Manual Dropdowns

    // --- TAB SWITCHING ---
    function switchView(view) {
        document.getElementById('summaryView').classList.toggle('hidden', view !== 'summary');
        document.getElementById('auditView').classList.toggle('hidden', view !== 'audit');
        document.getElementById('awardsView').classList.toggle('hidden', view !== 'awards');
        
        document.getElementById('btnSum').classList.toggle('active', view === 'summary');
        document.getElementById('btnAud').classList.toggle('active', view === 'audit');
        document.getElementById('btnAwd').classList.toggle('active', view === 'awards');

        // DYNAMIC PRINT BUTTON TEXT
        const printBtn = document.getElementById('btnPrint');
        if (view === 'summary') {
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print Leaderboard';
        } else if (view === 'audit') {
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print Audit Matrix';
        } else {
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print Award Results';
        }

        if(view === 'awards') fetchAwards();
    }

    // --- MAIN TALLY FETCH ---
    async function fetchTally() {
        if (!ROUND_ID) return;
        const btnRefreshIcon = document.querySelector('.fa-sync-alt');
        if(btnRefreshIcon) btnRefreshIcon.classList.add('fa-spin');

        try {
            const res = await fetch(`${API_URL}?round_id=${ROUND_ID}`);
            currentData = await res.json();
            if (currentData.status === 'success') {
                renderJudgeStatus(currentData.judges, currentData.submitted_judges || []);
                renderSummaryTable();
                renderAuditTable();
                document.getElementById('roundStatusText').innerText = currentData.round_status;
                const btn = document.getElementById('btnLock');
                if (currentData.round_status === 'Completed') {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-check-double"></i> COMPLETED';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-lock"></i> LOCK ROUND';
                    btn.style.background = ''; 
                }
            }
        } catch (e) { console.error(e); }
        finally { if(btnRefreshIcon) btnRefreshIcon.classList.remove('fa-spin'); }
    }

    // --- NEW: AWARDS FETCH ---
    async function fetchAwards() {
        try {
            const res = await fetch(`${AWARDS_API}?event_id=${EVENT_ID}`);
            const data = await res.json();
            if(data.status === 'success') {
                allContestants = data.contestants;
                renderAwards(data.awards);
            }
        } catch(e) { console.error("Award Error", e); }
    }

    function renderAwards(awards) {
        const grid = document.getElementById('awardsGrid');
        if (awards.length === 0) {
            grid.innerHTML = '<div style="text-align:center; grid-column:1/-1; padding:20px; color:#666;">No awards configured for this event.</div>';
            return;
        }

        grid.innerHTML = awards.map(item => {
            const aw = item.award;
            const win = item.winner;
            let winnerHTML = '';

            // LOGIC FOR DISPLAYING WINNER
            if (aw.source_type === 'Manual') {
                // Dropdown for Tabulator to pick
                let options = `<option value="">-- Select Winner --</option>`;
                allContestants.forEach(c => {
                    const sel = (win && c.name === win.name) ? 'selected' : ''; // Matching by name is safe here
                    options += `<option value="${c.id}" ${sel}>${c.name}</option>`;
                });
                
                // Show dropdown AND current winner text (for print view hiding dropdown)
                winnerHTML = `
                    <select class="manual-select" onchange="saveManualWinner(${aw.id}, this.value)">${options}</select>
                    ${win ? `<div style="margin-top:5px; font-size:12px; color:green;">Selected: <b>${win.name}</b></div>` : ''}
                `;

            } else {
                // Auto-Calculated (Round, Segment, Audience)
                if (win) {
                    winnerHTML = `<div class="winner-auto"><i class="fas fa-trophy" style="font-size:1.2em; color:gold;"></i> <span>${win.name}</span></div>`;
                    if(win.total_score) winnerHTML += `<div style="font-size:11px; margin-top:4px;">Score: ${parseFloat(win.total_score).toFixed(2)}</div>`;
                    if(win.votes) winnerHTML += `<div style="font-size:11px; margin-top:4px;">Votes: ${win.votes}</div>`;
                } else {
                    winnerHTML = `<div class="winner-pending">Waiting for results...</div>`;
                }
            }

            return `
            <div class="award-tile">
                <h4>${aw.title} <span class="award-badge">${aw.source_type}</span></h4>
                <div style="font-size:11px; color:#666; margin-bottom:8px;">${aw.description || ''}</div>
                <div class="award-winner-box">${winnerHTML}</div>
            </div>`;
        }).join('');
    }

    async function saveManualWinner(awardId, contestantId) {
        if(!contestantId) return;
        try {
            const res = await fetch(AWARDS_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    award_id: awardId,
                    contestant_id: contestantId,
                    event_id: EVENT_ID
                })
            });
            const d = await res.json();
            if(d.status === 'success') {
                fetchAwards(); // Refresh to update "Selected: Name" text
            } else {
                alert("Error saving winner");
            }
        } catch(e) { alert("Save failed"); }
    }

    // --- EXISTING TALLY RENDER FUNCTIONS ---
    function renderJudgeStatus(allJudges, submittedIds) {
        const list = document.getElementById('judgeStatusList');
        list.innerHTML = allJudges.map(j => {
            const isDone = submittedIds.includes(parseInt(j.id));
            return `<span class="j-badge ${isDone ? 'submitted' : ''}">${isDone ? '✓' : '○'} ${j.name}</span>`;
        }).join('');
    }

    function renderSummaryTable() {
        const { judges, ranking } = currentData;
        const thead = document.getElementById('tableHeader');
        const tbody = document.getElementById('tableBody');

        thead.innerHTML = `<th>Rank</th><th style="text-align:left;">Contestant</th>` + 
            judges.map(j => `<th>${j.name}</th>`).join('') + `<th>Final Average</th>`;

        if (ranking.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${judges.length + 3}" style="padding:40px;">No scores recorded yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = ranking.map(row => {
            const c = row.contestant;
            const numDisplay = c.contestant_number ? `#${c.contestant_number}` : '';

            return `<tr class="${row.rank <= 3 ? 'rank-'+row.rank : ''}">
                <td><div class="rank-box">${row.rank}</div></td>
                <td class="contestant-cell">
                    <img src="assets/uploads/contestants/${c.photo}" class="c-img" onerror="this.src='assets/images/default.png'">
                    <span>${c.name} <small style="color:#6b7280; font-size:0.85em; margin-left:4px;">${numDisplay}</small></span>
                </td>
                ${judges.map(j => `<td>${row.judge_scores[j.id] !== undefined ? parseFloat(row.judge_scores[j.id]).toFixed(2) : '--'}</td>`).join('')}
                <td class="final-score">${parseFloat(row.final_score).toFixed(2)}</td>
            </tr>`;
        }).join('');
    }

    function renderAuditTable() {
        if(!currentData.audit) return;
        const { audit, judges, ranking } = currentData;
        const tableScreen = document.getElementById('auditTableScreen');
        const containerPrint = document.getElementById('auditPrintContainer');
        
        // --- 1. SCREEN VERSION (Wide) ---
        let htmlScreen = `<thead><tr><th rowspan="2" class="audit-header-main">Contestant Details</th>`;
        audit.segments.forEach(seg => {
            const segCriteria = audit.criteria.filter(cr => cr.segment_id == seg.id);
            const colSpan = judges.length * (segCriteria.length + 1); 
            htmlScreen += `<th colspan="${colSpan}" class="audit-header-main">${seg.title} (${seg.weight_percentage}%)</th>`;
        });
        htmlScreen += `</tr><tr>`;

        audit.segments.forEach(seg => {
            const segCriteria = audit.criteria.filter(cr => cr.segment_id == seg.id);
            judges.forEach(j => {
                htmlScreen += `<th colspan="${segCriteria.length + 1}" class="audit-header-sub" style="border-right:2px solid #ccc">${j.name}</th>`;
            });
        });

        htmlScreen += `</tr><tr><th class="audit-header-sub">Name</th>`;
        audit.segments.forEach(seg => {
            const segCriteria = audit.criteria.filter(cr => cr.segment_id == seg.id);
            judges.forEach(j => {
                 segCriteria.forEach(crit => { htmlScreen += `<th style="font-size:10px; color:#666;">${crit.title.substring(0,8)}..</th>`; });
                 htmlScreen += `<th class="audit-weighted" style="border-right:2px solid #ccc">Total</th>`;
            });
        });
        htmlScreen += `</tr></thead><tbody>`;

        ranking.forEach(row => {
            const c = row.contestant;
            const numDisplay = c.contestant_number ? `<br><small>#${c.contestant_number}</small>` : '';
            htmlScreen += `<tr><td class="contestant-cell"><b>${c.name}</b>${numDisplay}</td>`;
            audit.segments.forEach(seg => {
                const segCriteria = audit.criteria.filter(cr => cr.segment_id == seg.id);
                const weight = parseFloat(seg.weight_percentage) / 100;
                judges.forEach(j => {
                    let segRawSum = 0;
                    let hasAllScores = true;
                    segCriteria.forEach(crit => {
                        const score = audit.scores[c.user_id]?.[j.id]?.[crit.id];
                        if (score !== undefined) {
                            htmlScreen += `<td>${score}</td>`;
                            segRawSum += parseFloat(score);
                        } else {
                            htmlScreen += `<td class="audit-na">N/A</td>`;
                            hasAllScores = false;
                        }
                    });
                    const weighted = (segRawSum * weight).toFixed(2);
                    htmlScreen += `<td class="audit-weighted" style="border-right:2px solid #ccc">${hasAllScores ? weighted : '<span class="audit-na">N/A</span>'}</td>`;
                });
            });
            htmlScreen += `</tr>`;
        });
        htmlScreen += `</tbody>`;
        tableScreen.innerHTML = htmlScreen;


        // --- 2. PRINT VERSION (Segment-by-Segment) ---
        let printHTML = "";
        
        audit.segments.forEach(seg => {
            const segCriteria = audit.criteria.filter(cr => cr.segment_id == seg.id);
            const weight = parseFloat(seg.weight_percentage) / 100;

            printHTML += `<div class="print-segment-block">`;
            printHTML += `<div class="print-segment-title">AUDIT: ${seg.title} (${seg.weight_percentage}%)</div>`;
            printHTML += `<table class="tally-table"><thead><tr><th rowspan="2">Contestant</th>`;
            
            judges.forEach(j => {
                printHTML += `<th colspan="${segCriteria.length + 1}" style="text-align:center;">${j.name}</th>`;
            });
            printHTML += `</tr><tr>`;
            
            judges.forEach(j => {
                 segCriteria.forEach(crit => { printHTML += `<th style="font-size:8pt;">${crit.title}</th>`; });
                 printHTML += `<th>Wtd.</th>`;
            });
            printHTML += `</tr></thead><tbody>`;

            ranking.forEach(row => {
                const c = row.contestant;
                const numDisplay = c.contestant_number ? ` (#${c.contestant_number})` : '';
                printHTML += `<tr><td><b>${c.name}</b>${numDisplay}</td>`;

                judges.forEach(j => {
                    let segRawSum = 0;
                    let hasAllScores = true;
                    segCriteria.forEach(crit => {
                        const score = audit.scores[c.user_id]?.[j.id]?.[crit.id];
                        if (score !== undefined) {
                            printHTML += `<td>${score}</td>`;
                            segRawSum += parseFloat(score);
                        } else {
                            printHTML += `<td>-</td>`;
                            hasAllScores = false;
                        }
                    });
                    const weighted = (segRawSum * weight).toFixed(2);
                    printHTML += `<td><b>${hasAllScores ? weighted : '-'}</b></td>`;
                });
                printHTML += `</tr>`;
            });

            printHTML += `</tbody></table></div>`;
        });
        
        containerPrint.innerHTML = printHTML;
    }

    async function lockRound() {
        if (!confirm("Confirm Finalization? This will store ranks permanently.")) return;
        const btn = document.getElementById('btnLock');
        btn.innerHTML = 'Processing...';
        btn.disabled = true;

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ round_id: ROUND_ID })
            });
            const result = await res.json();
            if (result.status === 'success') { 
                alert("Success! Round Completed."); 
                fetchTally(); 
            } else { 
                alert("Error: " + result.error); 
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-lock"></i> LOCK ROUND';
            }
        } catch (e) { 
            alert("Network Error"); 
            btn.disabled = false;
        }
    }

    // Init
    setInterval(fetchTally, 5000);
    fetchTally();
</script>
</body>
</html>