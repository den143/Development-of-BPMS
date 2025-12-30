<?php
// bpms/public/live_screen.php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();

if (!in_array($_SESSION['role'], ['Event Manager', 'Tabulator', 'Judge Coordinator'])) {
    die("<h1>Access Denied</h1>");
}

require_once __DIR__ . '/../app/config/database.php';

$uid = $_SESSION['user_id'];
$event_name = "BPMS Live";
$event_id = 0;

if ($_SESSION['role'] === 'Event Manager') {
    $evt = $conn->query("SELECT id, name FROM events WHERE user_id=$uid AND status='Active'")->fetch_assoc();
} else {
    $evt = $conn->query("SELECT e.id, e.name FROM events e JOIN event_organizers eo ON e.id = eo.event_id WHERE eo.user_id=$uid AND eo.status='Active' AND e.status='Active'")->fetch_assoc();
}

if ($evt) {
    $event_id = $evt['id'];
    $event_name = $evt['name'];
} else {
    die("<div style='color:white;text-align:center;padding:50px;background:#111;'><h1>No Active Event Found</h1></div>");
}

$rounds = $conn->query("SELECT id, title FROM rounds WHERE event_id=$event_id ORDER BY ordering")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LIVE: <?= htmlspecialchars($event_name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* BASE LAYOUT */
    body { margin: 0; background: #000 radial-gradient(circle at 50% 0%, #222, #000 80%); color: white; font-family: 'Segoe UI', sans-serif; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
    
    #header { height: 15vh; display: flex; flex-direction: column; justify-content: center; align-items: center; background: rgba(0,0,0,0.5); border-bottom: 1px solid #222; transition: margin-top 0.5s; z-index: 10; }
    h1 { font-size: 2.5vh; letter-spacing: 0.2em; color: #aaa; margin:0; }
    h2 { font-size: 5vh; margin: 5px 0 0 0; color: #F59E0B; font-weight: 800; text-transform: uppercase; }
    
    /* STAGE AREA */
    #stage { flex-grow: 1; position: relative; overflow-y: auto; overflow-x: hidden; padding-top: 10px; display: flex; flex-direction: column; align-items: center; }
    
    /* [NEW] TABLE HEADER STYLES */
    .table-header {
        width: 80%;
        display: flex;
        padding: 15px 20px;
        border-bottom: 2px solid #333;
        margin-bottom: 10px;
        color: #666;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 1.2vw;
        letter-spacing: 1px;
    }
    .th-rank { width: 80px; text-align: center; }
    .th-img  { width: 80px; } /* Empty spacer for avatar */
    .th-name { flex-grow: 1; padding-left: 20px; }
    .th-score { width: 150px; text-align: right; }

    /* ANIMATED LIST CONTAINER */
    .ranking-container {
        position: relative;
        width: 80%;
        /* Height set via JS */
    }

    /* ROW CARD STYLES */
    .rank-row {
        position: absolute; /* KEY FOR ANIMATION */
        width: 100%;
        height: 70px; /* Fixed Height per row */
        display: flex;
        align-items: center;
        background: rgba(255, 255, 255, 0.05);
        border-bottom: 1px solid #333;
        border-radius: 8px;
        padding: 0 20px;
        box-sizing: border-box;
        
        /* [UPDATED] MOVEMENT SPEED: 2s */
        transition: top 2s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.3s; 
    }

    .rank-row:hover { background: rgba(255, 255, 255, 0.1); }

    /* INNER COLUMNS */
    .cell-rank { width: 80px; text-align: center; font-weight: 900; font-size: 1.8em; color: #F59E0B; }
    .cell-img  { width: 80px; display: flex; justify-content: center; }
    .t-avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #444; }
    .cell-name { flex-grow: 1; font-weight: 600; font-size: 1.5vw; padding-left: 20px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cell-score { width: 150px; text-align: right; font-weight: 800; font-size: 1.5vw; color: #F59E0B; font-family: 'Courier New', monospace; }

    /* STATES */
    /* Freeze: Blur Score, Hide Rank */
    .mode-freeze .cell-score span { filter: blur(15px); opacity: 0; transition: all 1s; }
    .mode-freeze .cell-rank { opacity: 0; transform: scale(0.5); transition: all 0.5s; }
    
    /* Reveal: Show Score, Show Rank */
    .mode-reveal .cell-rank { opacity: 1; transform: scale(1); transition: all 0.5s 0.5s; } /* Slight delay on rank appear */

    /* Qualify: Green Highlight */
    .row-qualified { background: rgba(34, 197, 94, 0.15) !important; border-left: 5px solid #22c55e; }
    .row-eliminated { opacity: 0.4; }

    /* CONTROL BAR (HOVER) */
    #control-bar { 
        height: 70px; background: #111; border-top: 1px solid #333; 
        display: flex; justify-content: center; align-items: center; gap: 20px; z-index: 1000;
        transition: all 0.3s ease-in-out;
    }

    body.is-fullscreen #control-bar {
        position: fixed; bottom: 0; left: 0; width: 100%;
        background: rgba(0, 0, 0, 0.9); border-top: 1px solid #444;
        opacity: 0; transform: translateY(20px);
    }
    body.is-fullscreen #control-bar:hover, 
    body.is-fullscreen #hover-trigger:hover + #control-bar {
        opacity: 1; transform: translateY(0);
    }

    #hover-trigger { display: none; position: fixed; bottom: 0; left: 0; width: 100%; height: 50px; z-index: 999; }
    body.is-fullscreen #hover-trigger { display: block; }

    .ctrl-group { display: flex; gap: 5px; background: #222; padding: 5px; border-radius: 8px; border: 1px solid #444; }
    .ctrl-btn { background: transparent; color: #aaa; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px; }
    .ctrl-btn:hover { background: #333; color: white; }
    
    .ctrl-btn.active.btn-freeze { background: #0ea5e9; color: white; }
    .ctrl-btn.active.btn-reveal { background: #F59E0B; color: black; }
    .ctrl-btn.active.btn-qualify { background: #22c55e; color: white; }
    
    select { background: #222; color: white; border: 1px solid #444; padding: 10px; border-radius: 6px; outline: none; }
    body.is-fullscreen #header { margin-top: -15vh; }
    #exit-fs-btn { display: none !important; }

</style>
</head>
<body>

<div id="header">
    <h1><?= htmlspecialchars($event_name) ?></h1>
    <h2 id="round-title">SELECT A ROUND</h2>
</div>

<div id="stage">
    <div class="table-header">
        <div class="th-rank">RANK</div>
        <div class="th-img"></div> <div class="th-name">CONTESTANT</div>
        <div class="th-score">TOTAL SCORE</div>
    </div>

    <div class="ranking-container" id="list-container">
        <div style="color:#444; text-align:center; padding-top:50px;">
            <i class="fas fa-desktop"></i> Waiting for Data...
        </div>
    </div>
</div>

<div id="hover-trigger"></div>

<div id="control-bar">
    <select id="round-selector" onchange="changeRound()">
        <option value="">-- Select Round --</option>
        <?php foreach($rounds as $r): ?>
            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></option>
        <?php endforeach; ?>
    </select>

    <div class="ctrl-group">
        <button class="ctrl-btn btn-freeze active" onclick="setMode('freeze')"><i class="fas fa-eye-slash"></i> STANDBY</button>
        <button class="ctrl-btn btn-reveal" onclick="setMode('reveal')"><i class="fas fa-trophy"></i> REVEAL</button>
        <button class="ctrl-btn btn-qualify" onclick="setMode('qualify')"><i class="fas fa-star"></i> VERDICT</button>
    </div>

    <div style="width:1px; height:30px; background:#444;"></div>

    <button class="ctrl-btn" onclick="toggleFullScreen()">
        <i class="fas fa-expand" id="fs-icon"></i> <span id="fs-text">Full Screen</span>
    </button>
</div>

<script>
    let currentRoundId = null;
    let pollInterval = null;
    let currentMode = 'freeze'; 
    let contestantsData = [];
    let cutOffCount = 0;
    
    // Config
    const ROW_HEIGHT = 80; // px (70px height + 10px gap)

    function changeRound() {
        const sel = document.getElementById('round-selector');
        currentRoundId = sel.value;
        if(!currentRoundId) return;
        document.getElementById('round-title').innerText = sel.options[sel.selectedIndex].text;
        setMode('freeze');
        if(pollInterval) clearInterval(pollInterval);
        fetchData(); 
        pollInterval = setInterval(fetchData, 4000); 
    }

    async function fetchData() {
        if(!currentRoundId) return;
        try {
            const res = await fetch(`../api/tally.php?round_id=${currentRoundId}`);
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if(data.status === 'success') {
                    // Update global data but don't re-render entire DOM if not needed
                    // For simplicity, we re-render position logic
                    const isFirstLoad = (contestantsData.length === 0);
                    contestantsData = data.ranking; 
                    cutOffCount = data.qualifiers || 0; 
                    
                    if(isFirstLoad) {
                        buildDOM(); // Create divs first time
                    } else {
                        updatePositions(); // Just animate them
                    }
                }
            } catch(e) { console.error("JSON Error", text); }
        } catch(e) { console.error("Network error", e); }
    }

    // 1. Build the physical DOM Elements (Only once or when list changes size)
    function buildDOM() {
        const container = document.getElementById('list-container');
        container.innerHTML = '';
        container.style.height = (contestantsData.length * ROW_HEIGHT) + 'px';

        contestantsData.forEach(item => {
            const c = item.contestant;
            // Use detail_id as unique key to track elements
            const id = `c-${c.detail_id || c.id}`; 
            
            const div = document.createElement('div');
            div.id = id;
            div.className = 'rank-row mode-freeze'; // Start frozen
            div.innerHTML = `
                <div class="cell-rank"></div>
                <div class="cell-img"><img src="./assets/uploads/contestants/${c.photo}" class="t-avatar" onerror="this.src='./assets/images/default_user.png'"></div>
                <div class="cell-name">${c.name}</div>
                <div class="cell-score"><span>0.00</span></div>
            `;
            container.appendChild(div);
        });
        updatePositions();
    }

    // 2. Calculate Top Position & Update Content
    function updatePositions() {
        // Create a copy to sort without mutating original reference immediately if needed
        let sortedList = [...contestantsData];

        if (currentMode === 'freeze') {
            // Sort by ID/Number (Neutral)
            sortedList.sort((a, b) => a.contestant.contestant_number - b.contestant.contestant_number);
        } else {
            // Sort by Rank
            sortedList.sort((a, b) => parseFloat(a.rank) - parseFloat(b.rank));
        }

        // Apply visual updates
        sortedList.forEach((item, index) => {
            const c = item.contestant;
            const id = `c-${c.detail_id || c.id}`;
            const el = document.getElementById(id);
            
            if (el) {
                // A. ANIMATE POSITION
                el.style.top = (index * ROW_HEIGHT) + 'px';

                // B. UPDATE CONTENT
                const scoreSpan = el.querySelector('.cell-score span');
                const rankDiv = el.querySelector('.cell-rank');
                
                scoreSpan.innerText = parseFloat(item.final_score).toFixed(2);
                
                // Rank Logic
                if(currentMode === 'freeze') {
                    rankDiv.innerText = '';
                } else {
                    rankDiv.innerText = item.rank;
                }

                // C. UPDATE CLASSES (Modes & Colors)
                el.className = `rank-row mode-${currentMode}`;
                
                if (currentMode === 'qualify' && cutOffCount > 0) {
                    if (index < cutOffCount) el.classList.add('row-qualified');
                    else el.classList.add('row-eliminated');
                } else {
                    el.classList.remove('row-qualified', 'row-eliminated');
                }
            }
        });
    }

    function setMode(mode) {
        currentMode = mode;
        document.querySelectorAll('.ctrl-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.btn-${mode}`).classList.add('active');
        updatePositions(); // Trigger animation
    }

    function toggleFullScreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            document.body.classList.add('is-fullscreen');
            document.getElementById('fs-icon').className = 'fas fa-compress';
            document.getElementById('fs-text').innerText = 'Exit';
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
                document.body.classList.remove('is-fullscreen');
                document.getElementById('fs-icon').className = 'fas fa-expand';
                document.getElementById('fs-text').innerText = 'Full Screen';
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        const selector = document.getElementById('round-selector');
        if (selector && selector.options.length > 1) {
            selector.selectedIndex = 1; 
            changeRound();
        }
    });
</script>
</body>
</html>