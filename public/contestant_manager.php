<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Contestant Manager');
require_once __DIR__ . '/../app/config/database.php';

$manager_id = $_SESSION['user_id'];
$message = "";
$error = "";

// --- 1. FETCH ASSIGNED EVENT ---
$event_stmt = $conn->prepare("
    SELECT e.id, e.name, e.venue, e.event_date 
    FROM events e 
    JOIN event_organizers eo ON e.id = eo.event_id 
    WHERE eo.user_id = ? AND eo.status = 'Active' AND e.status = 'Active' 
    LIMIT 1
");
$event_stmt->bind_param("i", $manager_id);
$event_stmt->execute();
$event_result = $event_stmt->get_result();
$active_event = $event_result->fetch_assoc();

$event_id = $active_event['id'] ?? null;
$event_name = $active_event['name'] ?? "No Active Event Assigned";

// --- 2. HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event_id) {
    
    // A. APPROVE / REJECT
    if (isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
        $target_id = intval($_POST['user_id']);
        $new_status = ($_POST['action'] === 'approve') ? 'Active' : 'Rejected';
        
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $target_id);
        
        if ($stmt->execute()) {
            if ($new_status === 'Active') {
                // Ensure detail row exists
                $check = $conn->prepare("SELECT id FROM contestant_details WHERE user_id = ? AND event_id = ?");
                $check->bind_param("ii", $target_id, $event_id);
                $check->execute();
                
                // Get next available number
                $num_q = $conn->prepare("SELECT MAX(contestant_number) as max_num FROM contestant_details WHERE event_id = ?");
                $num_q->bind_param("i", $event_id);
                $num_q->execute();
                $next_num = ($num_q->get_result()->fetch_assoc()['max_num'] ?? 0) + 1;

                if ($check->get_result()->num_rows == 0) {
                    $ins = $conn->prepare("INSERT INTO contestant_details (user_id, event_id, contestant_number) VALUES (?, ?, ?)");
                    $ins->bind_param("iii", $target_id, $event_id, $next_num);
                    $ins->execute();
                } else {
                    $upd = $conn->prepare("UPDATE contestant_details SET contestant_number = ? WHERE user_id = ? AND event_id = ? AND contestant_number IS NULL");
                    $upd->bind_param("iii", $next_num, $target_id, $event_id);
                    $upd->execute();
                }
            }
            $message = "Contestant status updated.";
        } else {
            $error = "Failed to update status.";
        }
    }

    // B. AUTO-SEQUENCE
    if (isset($_POST['action']) && $_POST['action'] === 'resequence_all') {
        $q = $conn->prepare("
            SELECT cd.id 
            FROM contestant_details cd 
            JOIN users u ON cd.user_id = u.id 
            WHERE cd.event_id = ? AND u.status = 'Active' 
            ORDER BY u.name ASC
        ");
        $q->bind_param("i", $event_id);
        $q->execute();
        $res = $q->get_result();
        
        $counter = 1;
        while($row = $res->fetch_assoc()) {
            $upd = $conn->prepare("UPDATE contestant_details SET contestant_number = ? WHERE id = ?");
            $upd->bind_param("ii", $counter, $row['id']);
            $upd->execute();
            $counter++;
        }
        $message = "Roster re-sorted alphabetically (1 to " . ($counter-1) . ")";
    }

    // C. UPDATE ORDER (MANUAL)
    if (isset($_POST['action']) && $_POST['action'] === 'update_order') {
        $detail_id = intval($_POST['detail_id']);
        $new_number = intval($_POST['contestant_number']);
        
        $stmt = $conn->prepare("UPDATE contestant_details SET contestant_number = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_number, $detail_id);
        if ($stmt->execute()) {
            $message = "Candidate number updated.";
        }
    }

    // D. EDIT PROFILE
    if (isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
        $detail_id = intval($_POST['detail_id']);
        $age = intval($_POST['age']);
        $height = floatval($_POST['height']);
        $vital = trim($_POST['vital_stats']);
        $hometown = trim($_POST['hometown']);
        $motto = trim($_POST['motto']);
        
        $photo_sql_part = "";
        $types = "idsssi";
        $params = [$age, $height, $vital, $hometown, $motto, $detail_id];

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/assets/uploads/contestants/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'contestant_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                $photo_sql_part = ", photo = ?";
                array_splice($params, 5, 0, $filename); 
                $types = "idssssi";
            }
        }

        $sql = "UPDATE contestant_details SET age=?, height=?, vital_stats=?, hometown=?, motto=? $photo_sql_part WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $message = "Profile updated.";
        }
    }
}

// --- 3. FETCH DATA ---
$pending_contestants = [];
$active_contestants = [];
$stats = [ 'total' => 0, 'pending' => 0, 'incomplete' => 0 ];

if ($event_id) {
    // Pending
    $p_stmt = $conn->prepare("
        SELECT u.id, u.name, u.email 
        FROM users u 
        JOIN contestant_details cd ON u.id = cd.user_id 
        WHERE cd.event_id = ? AND u.status = 'Pending'
    ");
    $p_stmt->bind_param("i", $event_id);
    $p_stmt->execute();
    $res = $p_stmt->get_result();
    while($row = $res->fetch_assoc()) $pending_contestants[] = $row;
    $stats['pending'] = count($pending_contestants);

    // Active
    $a_stmt = $conn->prepare("
        SELECT u.id as user_id, u.name, cd.id as detail_id, cd.contestant_number, 
               cd.hometown, cd.age, cd.height, cd.vital_stats, cd.motto, cd.photo
        FROM users u 
        JOIN contestant_details cd ON u.id = cd.user_id 
        WHERE cd.event_id = ? AND u.status = 'Active'
        ORDER BY cd.contestant_number ASC, u.name ASC
    ");
    $a_stmt->bind_param("i", $event_id);
    $a_stmt->execute();
    $res = $a_stmt->get_result();
    while($row = $res->fetch_assoc()) {
        $active_contestants[] = $row;
        if (empty($row['photo']) || $row['photo'] === 'default_contestant.png' || 
            empty($row['motto']) || empty($row['vital_stats'])) {
            $stats['incomplete']++;
        }
    }
    $stats['total'] = count($active_contestants);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contestant Manager - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .sidebar { background-color: #831843; }
        .sidebar-header { background-color: #500724; border-bottom-color: #9d174d; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background-color: rgba(255, 255, 255, 0.1); color: #FBCFE8; border-left-color: #FBCFE8; }
        .navbar-title { color: #831843; }
        .stat-icon.pink { background-color: rgba(219, 39, 119, 0.1); color: #DB2777; }
        .stat-card:hover { border-bottom-color: #DB2777; }
        
        .contestant-thumb { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }
        .order-input { width: 50px; padding: 5px; border: 1px solid #d1d5db; border-radius: 4px; text-align: center; font-weight: bold; }
        .btn-mini { padding: 4px 8px; background: #e5e7eb; border-radius: 4px; color: #374151; cursor: pointer; border: none; }
        .btn-mini:hover { background: #d1d5db; }

        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; width: 500px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .full-width { grid-column: span 2; }
    </style>
</head>
<body>

    <div class="main-wrapper">
        
        <div class="sidebar">
            <div class="sidebar-header">
                <img src="assets/images/BPMS_logo.png" alt="BPMS Logo" class="sidebar-logo">
                <div class="brand-text">
                    <div class="brand-name">BPMS</div>
                    <div class="brand-subtitle">Contestant Manager</div>
                </div>
            </div>
            
            <ul class="sidebar-menu">
                <li><a href="contestant_manager.php" class="active"><i class="fas fa-users-cog"></i> <span>Manage Roster</span></a></li>
            </ul>
            
            <div class="sidebar-footer">
                <a href="logout.php" onclick="return confirm('Logout?');">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </div>
        </div>

        <div class="content-area">
            
            <div class="navbar">
                <div class="navbar-title">Roster Management</div>
                <div style="font-size: 14px; color: #6b7280;">
                    Event: <strong><?= htmlspecialchars($event_name) ?></strong>
                </div>
            </div>

            <div class="container">
                
                <?php if ($message): ?>
                    <div class="toast success" style="margin-bottom: 20px; background: #10B981; color: white; padding: 10px; border-radius: 6px;">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="toast error" style="margin-bottom: 20px; background: #EF4444; color: white; padding: 10px; border-radius: 6px;">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon pink"><i class="fas fa-female"></i></div>
                        <div class="stat-info">
                            <h3><?= $stats['total'] ?></h3>
                            <p>Official Candidates</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: rgba(245, 158, 11, 0.1); color: #F59E0B;">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?= $stats['pending'] ?></h3>
                            <p>Pending Approval</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: rgba(239, 68, 68, 0.1); color: #EF4444;">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?= $stats['incomplete'] ?></h3>
                            <p>Incomplete Profiles</p>
                        </div>
                    </div>
                </div>

                <?php if ($stats['pending'] > 0): ?>
                <div class="card-section" style="margin-bottom: 30px; border: 1px solid #FCD34D;">
                    <div class="card-title" style="color: #B45309;">
                        <i class="fas fa-exclamation-circle"></i> Pending Applications
                    </div>
                    <table style="width:100%; border-collapse: collapse;">
                        <tbody>
                            <?php foreach ($pending_contestants as $p): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6;">
                                <td style="padding: 15px;">
                                    <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                                    <span style="font-size:12px; color:#6b7280;"><?= htmlspecialchars($p['email']) ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn-mini" style="background:#10B981; color:white; margin-right:5px;">Approve & #</button>
                                        <button type="submit" name="action" value="reject" class="btn-mini" style="background:#EF4444; color:white;">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="card-section">
                    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Official Contestant Roster</span>
                        <form method="POST" onsubmit="return confirm('This will reset all numbers to 1, 2, 3... Alphabetically. Continue?');">
                            <input type="hidden" name="action" value="resequence_all">
                            <button type="submit" style="background:#374151; color:white; padding:6px 12px; border:none; border-radius:4px; font-size:12px; cursor:pointer;">
                                <i class="fas fa-sort-numeric-down"></i> Auto-Number All
                            </button>
                        </form>
                    </div>
                    
                    <?php if (empty($active_contestants)): ?>
                        <p style="color:#6b7280; text-align:center; padding:20px;">No active contestants found.</p>
                    <?php else: ?>
                    
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background:#f9fafb; text-align:left; color:#374151; font-size:13px; text-transform:uppercase;">
                                <th style="padding:15px;">#</th>
                                <th style="padding:15px;">Profile</th>
                                <th style="padding:15px;">Details</th>
                                <th style="padding:15px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_contestants as $c): 
                                $imgSrc = !empty($c['photo']) ? "./assets/uploads/contestants/" . $c['photo'] : "./assets/images/default_user.png";
                            ?>
                            <tr style="border-bottom: 1px solid #f3f4f6;">
                                <td style="padding:15px; width: 80px;">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="detail_id" value="<?= $c['detail_id'] ?>">
                                        <input type="number" name="contestant_number" class="order-input" value="<?= $c['contestant_number'] ?>" placeholder="#">
                                        <button type="submit" style="display:none;"></button>
                                    </form>
                                </td>
                                
                                <td style="padding:15px;">
                                    <div style="display:flex; align-items:center; gap:15px;">
                                        <img src="<?= htmlspecialchars($imgSrc) ?>" class="contestant-thumb" onerror="this.src='./assets/images/default_user.png'">
                                        <div>
                                            <div style="font-weight:bold; color:#111827;"><?= htmlspecialchars($c['name']) ?></div>
                                            <div style="font-size:12px; color:#6b7280;"><?= htmlspecialchars($c['hometown'] ?? 'No Hometown') ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td style="padding:15px; font-size:13px; color:#4b5563;">
                                    <div><strong>Age:</strong> <?= $c['age'] ?? '-' ?> | <strong>Height:</strong> <?= $c['height'] ?? '-' ?> cm</div>
                                    <div><strong>Vitals:</strong> <?= htmlspecialchars($c['vital_stats'] ?? '-') ?></div>
                                </td>

                                <td style="padding:15px;">
                                    <button class="btn-mini" onclick="openEditModal(<?= $c['detail_id'] ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
                <h3 style="margin:0;">Edit Profile</h3>
                <span onclick="document.getElementById('editModal').style.display='none'" style="cursor:pointer; font-size:20px;">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_profile">
                <input type="hidden" name="detail_id" id="edit_detail_id">
                <div class="form-grid">
                    <div class="full-width">
                        <label>Full Name</label>
                        <input type="text" id="edit_name" disabled style="width:100%; padding:8px; margin-top:5px; background:#eee; border:1px solid #ccc;">
                    </div>
                    <div>
                        <label>Hometown</label>
                        <input type="text" name="hometown" id="edit_hometown" style="width:100%; padding:8px; margin-top:5px; border:1px solid #ccc;">
                    </div>
                    <div>
                        <label>Vital Stats</label>
                        <input type="text" name="vital_stats" id="edit_vitals" style="width:100%; padding:8px; margin-top:5px; border:1px solid #ccc;">
                    </div>
                    <div>
                        <label>Age</label>
                        <input type="number" name="age" id="edit_age" style="width:100%; padding:8px; margin-top:5px; border:1px solid #ccc;">
                    </div>
                    <div>
                        <label>Height (cm)</label>
                        <input type="number" step="0.01" name="height" id="edit_height" style="width:100%; padding:8px; margin-top:5px; border:1px solid #ccc;">
                    </div>
                    <div class="full-width">
                        <label>Motto</label>
                        <textarea name="motto" id="edit_motto" rows="3" style="width:100%; padding:8px; margin-top:5px; border:1px solid #ccc;"></textarea>
                    </div>
                    <div class="full-width">
                        <label>Photo</label>
                        <input type="file" name="photo" style="width:100%; margin-top:5px;">
                    </div>
                </div>
                <button type="submit" style="width:100%; margin-top:15px; background:#DB2777; color:white; padding:10px; border:none; cursor:pointer;">Save</button>
            </form>
        </div>
    </div>

    <script>
        // PASS DATA SAFELY TO JS
        const contestantsData = <?= json_encode($active_contestants) ?>;

        function openEditModal(id) {
            // Find data by ID safely
            const data = contestantsData.find(c => c.detail_id == id);
            
            if (data) {
                document.getElementById('edit_detail_id').value = data.detail_id;
                document.getElementById('edit_name').value = data.name;
                document.getElementById('edit_hometown').value = data.hometown || '';
                document.getElementById('edit_vitals').value = data.vital_stats || '';
                document.getElementById('edit_age').value = data.age || '';
                document.getElementById('edit_height').value = data.height || '';
                document.getElementById('edit_motto').value = data.motto || '';
                document.getElementById('editModal').style.display = 'flex';
            }
        }

        window.onclick = function(e) { if(e.target == document.getElementById('editModal')) document.getElementById('editModal').style.display = 'none'; }
    </script>
</body>
</html>