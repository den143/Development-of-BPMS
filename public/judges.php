<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

$manager_id = $_SESSION['user_id'];
$view = $_GET['view'] ?? 'active'; // Default view
$status_filter = ($view === 'archived') ? 'Inactive' : 'Active';

// 1. Get Active Event
$evt_sql = "SELECT id, name FROM events WHERE user_id = ? AND status = 'Active' LIMIT 1";
$evt_stmt = $conn->prepare($evt_sql);
$evt_stmt->bind_param("i", $manager_id);
$evt_stmt->execute();
$active_event = $evt_stmt->get_result()->fetch_assoc();

$judges = [];
if ($active_event) {
    $event_id = $active_event['id'];
    
    // 2. Fetch Judges based on Status
    $sql = "SELECT ej.id as link_id, ej.is_chairman, ej.judge_id, 
                   u.name, u.email 
            FROM event_judges ej 
            JOIN users u ON ej.judge_id = u.id 
            WHERE ej.event_id = ? AND ej.status = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $event_id, $status_filter);
    $stmt->execute();
    $judges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Judges - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }

        .btn-add { background-color: #F59E0B; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-secondary { background-color: white; border: 1px solid #d1d5db; color: #374151; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 5px; }
        .btn-secondary:hover { background-color: #f3f4f6; }

        .table-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f3f4f6; }
        .data-table th { background-color: #f9fafb; font-weight: 600; color: #374151; }
        
        .badge-chairman { background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        
        /* Action Buttons */
        .btn-sm { padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; text-decoration: none; border: none; margin-right: 5px; }
        .btn-edit { background: #e0f2fe; color: #0284c7; }
        .btn-remove { background: #fee2e2; color: #dc2626; }
        .btn-restore { background: #d1fae5; color: #059669; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; width: 400px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; position: relative; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .toggle-password { position: absolute; right: 10px; top: 35px; cursor: pointer; color: #9ca3af; }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

        <div class="content-area">
            <div class="navbar">
                <div class="navbar-title">Manage Judges</div>
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
                            <h2 style="color: #111827;">
                                <?= ($view === 'archived') ? 'Archived Judges' : 'Active Judges' ?>
                            </h2>
                            <p style="color: #6b7280; font-size: 14px;">Event: <?= htmlspecialchars($active_event['name']) ?></p>
                        </div>
                        
                        <div class="header-actions">
                            <?php if ($view === 'active'): ?>
                                <a href="?view=archived" class="btn-secondary"><i class="fas fa-archive"></i> View Archived</a>
                                <button class="btn-add" onclick="openModal('addModal')">+ Add Judge</button>
                            <?php else: ?>
                                <a href="?view=active" class="btn-secondary">← Back to Active List</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-card">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email (Username)</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($judges)): ?>
                                    <tr><td colspan="4" style="text-align:center; padding:30px; color:#9ca3af;">No judges found in this list.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($judges as $j): ?>
                                        <tr>
                                            <td style="font-weight:500;"><?= htmlspecialchars($j['name']) ?></td>
                                            <td><?= htmlspecialchars($j['email']) ?></td>
                                            <td>
                                                <?= $j['is_chairman'] ? '<span class="badge-chairman">Chairman</span>' : '<span style="color:#6b7280; font-size:12px;">Judge</span>' ?>
                                            </td>
                                            <td>
                                                <?php if ($view === 'active'): ?>
                                                    <button class="btn-sm btn-edit" onclick='openEditModal(<?= json_encode($j) ?>)'>Edit</button>
                                                    <a href="../api/judge.php?action=remove&id=<?= $j['link_id'] ?>" class="btn-sm btn-remove" onclick="return confirm('Remove this judge?');">Remove</a>
                                                <?php else: ?>
                                                    <a href="../api/judge.php?action=restore&id=<?= $j['link_id'] ?>" class="btn-sm btn-restore" onclick="return confirm('Restore this judge?');">Restore</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="addModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:15px;">Add New Judge</h3>
            <form action="../api/judge.php" method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="event_id" value="<?= $active_event['id'] ?? '' ?>">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Email (Login)</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="addPass" class="form-control" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('addPass', this)"></i>
                </div>
                <div class="form-group">
                    <input type="checkbox" name="is_chairman" id="chkAdd" value="1">
                    <label for="chkAdd">Set as Chairman?</label>
                </div>

                <div style="text-align:right; margin-top:20px;">
                    <button type="button" onclick="closeModal('addModal')" style="background:#e5e7eb; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#F59E0B; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer;">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:15px;">Edit Judge</h3>
            <form action="../api/judge.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="link_id" id="edit_link_id">
                <input type="hidden" name="judge_id" id="edit_judge_id">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Change Password (Optional)</label>
                    <input type="password" name="password" id="editPass" class="form-control" placeholder="Leave empty to keep current">
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('editPass', this)"></i>
                </div>
                <div class="form-group">
                    <input type="checkbox" name="is_chairman" id="edit_chairman" value="1">
                    <label for="edit_chairman">Set as Chairman?</label>
                </div>

                <div style="text-align:right; margin-top:20px;">
                    <button type="button" onclick="closeModal('editModal')" style="background:#e5e7eb; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#F59E0B; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer;">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(judge) {
            document.getElementById('edit_link_id').value = judge.link_id;
            document.getElementById('edit_judge_id').value = judge.judge_id;
            document.getElementById('edit_name').value = judge.name;
            document.getElementById('edit_email').value = judge.email;
            document.getElementById('edit_chairman').checked = (judge.is_chairman == 1);
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

        // 2. TOAST LOGIC (Matches Organizers/Contestants modules)
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-circle"></i>';
            toast.innerHTML = `${icon} <span>${message}</span>`;
            container.appendChild(toast);
            
            // Remove after 3.5 seconds
            setTimeout(() => { toast.remove(); }, 3500);
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) showToast(urlParams.get('success'), 'success');
        if (urlParams.has('error')) showToast(urlParams.get('error'), 'error');
        
        // Clean URL so the toast doesn't reappear on refresh
        if (urlParams.has('success') || urlParams.has('error')) {
            const newUrl = window.location.pathname + (urlParams.has('view') ? '?view=' + urlParams.get('view') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>
</body>
</html>