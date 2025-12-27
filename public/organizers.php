<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

$manager_id = $_SESSION['user_id'];
$view = $_GET['view'] ?? 'active';
$status_filter = ($view === 'archived') ? 'Inactive' : 'Active';

$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? '';

// 1. Get Active Event
$evt_sql = "SELECT id, name FROM events WHERE user_id = ? AND status = 'Active' LIMIT 1";
$evt_stmt = $conn->prepare($evt_sql);
$evt_stmt->bind_param("i", $manager_id);
$evt_stmt->execute();
$active_event = $evt_stmt->get_result()->fetch_assoc();

$organizers = [];

if ($active_event) {
    $event_id = $active_event['id'];

    // 2. Build Query
    $sql = "SELECT eo.id as link_id, u.id as user_id, u.name, u.email, u.phone, u.role 
            FROM event_organizers eo 
            JOIN users u ON eo.user_id = u.id 
            WHERE eo.event_id = ? 
              AND eo.status = ?
              AND u.role != 'Event Manager'";

    $types = "is";
    $params = [$event_id, $status_filter];

    // Add Search
    if (!empty($search)) {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $types .= "ss";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Add Role Filter
    if (!empty($role_filter)) {
        $sql .= " AND u.role = ?";
        $types .= "s";
        $params[] = $role_filter;
    }

    $sql .= " ORDER BY u.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $organizers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Organizers - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }
        .btn-add { background-color: #F59E0B; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-secondary { background-color: white; border: 1px solid #d1d5db; color: #374151; padding: 10px 20px; border-radius: 6px; text-decoration: none; display: inline-block; }
        .btn-secondary:hover { background-color: #f3f4f6; }

        .search-container { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .search-input { padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; flex-grow: 1; }
        .filter-select { padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; width: 200px; }
        .btn-search { background-color: #1f2937; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }

        .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table th { background-color: #f9fafb; font-weight: 600; color: #374151; }
        
        .role-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .role-judge { background-color: #e0e7ff; color: #4338ca; } 
        .role-cm { background-color: #fce7f3; color: #db2777; }
        .role-tab { background-color: #d1fae5; color: #059669; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; width: 450px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; position: relative; } 
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .toggle-password { position: absolute; right: 10px; top: 35px; cursor: pointer; color: #9ca3af; }

        /* Action Buttons */
        .action-group { display: flex; gap: 5px; align-items: center; }
        
        .btn-sm { 
            padding: 6px 12px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: bold; 
            text-decoration: none; 
            border: none; 
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-sm:hover { opacity: 0.8; }
        
        .btn-edit { background: #e0f2fe; color: #0284c7; }
        .btn-remove { background: #fee2e2; color: #dc2626; }
        .btn-restore { background: #d1fae5; color: #059669; }
        
        /* New Resend Button Style (Matches Judges) */
        .btn-resend { background: #e0e7ff; color: #4f46e5; }
        .btn-resend:hover { background: #c7d2fe; }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

        <div class="content-area">
            <div class="navbar">
                <div class="navbar-title">Manage Organizers</div>
            </div>

            <div class="container">
                <div id="toast-container" class="toast-container"></div>

                <?php if (!$active_event): ?>
                    <div style="text-align:center; padding: 40px; color: #6b7280;">
                        <h2>No Active Event</h2>
                        <p>Please go to Settings to create an event.</p>
                    </div>
                <?php else: ?>

                    <div class="page-header">
                        <div>
                            <h2 style="color: #111827;"><?= ($view === 'archived') ? 'Archived Organizers' : 'Active Organizers' ?></h2>
                            <p style="color: #6b7280; font-size: 14px;">Event: <?= htmlspecialchars($active_event['name']) ?></p>
                        </div>
                        <div class="header-actions">
                            <?php if ($view === 'active'): ?>
                                <a href="?view=archived" class="btn-secondary"><i class="fas fa-archive"></i> View Archived</a>
                                <button class="btn-add" onclick="openModal('addModal')">+ Add Organizer</button>
                            <?php else: ?>
                                <a href="?view=active" class="btn-secondary">← Back to Active List</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="GET" action="organizers.php" class="search-container">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <input type="text" name="search" class="search-input" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                        <select name="role" class="filter-select">
                            <option value="">All Roles</option>
                            <option value="Judge Coordinator" <?= $role_filter === 'Judge Coordinator' ? 'selected' : '' ?>>Judge Coordinator</option>
                            <option value="Contestant Manager" <?= $role_filter === 'Contestant Manager' ? 'selected' : '' ?>>Contestant Manager</option>
                            <option value="Tabulator" <?= $role_filter === 'Tabulator' ? 'selected' : '' ?>>Tabulator</option>
                        </select>
                        <button type="submit" class="btn-search">Search</button>
                    </form>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($organizers)): ?>
                                <tr><td colspan="5" style="text-align:center; padding: 30px; color:#9ca3af;">No organizers found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($organizers as $org): ?>
                                    <tr>
                                        <td style="font-weight: 500;"><?= htmlspecialchars($org['name']) ?></td>
                                        <td>
                                            <?php 
                                                $cls = 'role-tab';
                                                if ($org['role'] === 'Judge Coordinator') $cls = 'role-judge';
                                                if ($org['role'] === 'Contestant Manager') $cls = 'role-cm';
                                            ?>
                                            <span class="role-badge <?= $cls ?>"><?= htmlspecialchars($org['role']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($org['email']) ?></td>
                                        <td><?= htmlspecialchars($org['phone'] ?? '-') ?></td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($view === 'active'): ?>
                                                    
                                                    <button class="btn-sm btn-edit" onclick='openEditModal(<?= json_encode($org) ?>)'>Edit</button>
                                                    
                                                    <a href="../api/organizer.php?action=remove&id=<?= $org['link_id'] ?>" class="btn-sm btn-remove" onclick="return confirm('Remove from this event?');">Remove</a>
                                                    
                                                    <form action="../api/resend_email.php" method="POST" style="margin:0;" onsubmit="return confirm('Resend invite and reset password?');">
                                                        <input type="hidden" name="user_id" value="<?= $org['user_id'] ?>">
                                                        <input type="hidden" name="role_type" value="Organizer"> <button type="submit" class="btn-sm btn-resend" title="Resend Invite">
                                                            <i class="fas fa-paper-plane"></i> Resend
                                                        </button>
                                                    </form>

                                                <?php else: ?>
                                                    <a href="../api/organizer.php?action=restore&id=<?= $org['link_id'] ?>" class="btn-sm btn-restore" onclick="return confirm('Restore?');">Restore</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="addModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:20px;">Add New Organizer</h3>
            <form action="../api/organizer.php" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="event_id" value="<?= $active_event['id'] ?? '' ?>">
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control" required>
                        <option value="Judge Coordinator">Judge Coordinator</option>
                        <option value="Contestant Manager">Contestant Manager</option>
                        <option value="Tabulator">Tabulator</option>
                    </select>
                </div>
                <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="addPass" class="form-control" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('addPass', this)"></i>
                </div>
                <div style="text-align:right;">
                    <button type="button" onclick="document.getElementById('addModal').style.display='none'" style="background:#e5e7eb; padding:8px 15px; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#F59E0B; color:white; padding:8px 15px; border:none; border-radius:4px; cursor:pointer;">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:20px;">Edit Organizer</h3>
            <form action="../api/organizer.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="org_id" id="edit_id"> 
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit_role" class="form-control" required>
                        <option value="Judge Coordinator">Judge Coordinator</option>
                        <option value="Contestant Manager">Contestant Manager</option>
                        <option value="Tabulator">Tabulator</option>
                    </select>
                </div>
                <div class="form-group"><label>Name</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                <div class="form-group">
                    <label>New Password (Optional)</label>
                    <input type="password" name="password" id="editPass" class="form-control">
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('editPass', this)"></i>
                </div>
                <div style="text-align:right;">
                    <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="background:#e5e7eb; padding:8px 15px; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#F59E0B; color:white; padding:8px 15px; border:none; border-radius:4px; cursor:pointer;">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function openEditModal(user) {
            document.getElementById('edit_id').value = user.user_id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_phone').value = user.phone;
            document.getElementById('edit_role').value = user.role;
            openModal('editModal');
        }
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
        
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-circle"></i>';
            toast.innerHTML = `${icon} <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3500);
        }
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) showToast(urlParams.get('success'), 'success');
        if (urlParams.has('error')) showToast(urlParams.get('error'), 'error');
        if (urlParams.has('success') || urlParams.has('error')) {
            const newUrl = window.location.pathname + (urlParams.has('view') ? '?view=' + urlParams.get('view') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>
</body>
</html>