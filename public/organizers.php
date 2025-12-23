<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// --- LOGIC: Handle "View Archived" Toggle ---
$view = $_GET['view'] ?? 'active'; // Default to active
$status_filter = ($view === 'archived') ? 'Inactive' : 'Active';

$my_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE created_by = ? AND status = ? ORDER BY created_at DESC");
$stmt->bind_param("is", $my_id, $status_filter);
$stmt->execute();
$organizers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Organizers - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <style>
        /* Header Layout */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }
        
        /* Buttons */
        .btn-add { background-color: #F59E0B; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .btn-add:hover { background-color: #d97706; }

        .btn-secondary { background-color: white; border: 1px solid #d1d5db; color: #374151; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; display: inline-block; }
        .btn-secondary:hover { background-color: #f3f4f6; }
        
        .btn-edit { color: #2563eb; text-decoration: none; font-weight: 500; font-size: 14px; margin-right: 10px; cursor: pointer; border: none; background: none;}
        .btn-edit:hover { text-decoration: underline; }

        .btn-delete { color: #ef4444; text-decoration: none; font-weight: 500; font-size: 14px; }
        .btn-delete:hover { text-decoration: underline; }

        .btn-restore { color: #059669; text-decoration: none; font-weight: 500; font-size: 14px; }
        .btn-restore:hover { text-decoration: underline; }

        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f3f4f6; }
        .data-table th { background-color: #f9fafb; font-weight: 600; color: #374151; }
        .data-table tr:hover { background-color: #ffffeb; }

        /* Badges */
        .role-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .role-judge { background-color: #e0e7ff; color: #4338ca; } 
        .role-contestant { background-color: #fce7f3; color: #db2777; }
        .role-tabulator { background-color: #d1fae5; color: #059669; }

        /* Modal & Form */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; width: 450px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; position: relative; } /* Relative for eye icon */
        .form-group label { display: block; margin-bottom: 5px; color: #374151; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; outline: none; }
        
        .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .btn-cancel { background: #e5e7eb; color: #374151; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }
        .btn-submit { background: #F59E0B; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }

        /* Password Eye Icon */
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 38px; /* Adjust based on label height */
            cursor: pointer;
            color: #6b7280;
        }
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

                <div class="page-header">
                    <h2 style="color: #111827;"><?= ($view === 'archived') ? 'Archived Organizers' : 'Active Organizers' ?></h2>
                    <div class="header-actions">
                        <?php if ($view === 'active'): ?>
                            <a href="?view=archived" class="btn-secondary"><i class="fas fa-archive"></i> View Archived</a>
                            <button class="btn-add" onclick="openModal('addModal')">+ Add New Organizer</button>
                        <?php else: ?>
                            <a href="?view=active" class="btn-secondary">← Back to Active List</a>
                        <?php endif; ?>
                    </div>
                </div>

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
                            <tr>
                                <td colspan="5" style="text-align:center; color:#9ca3af; padding: 30px;">
                                    No <?= $status_filter ?> organizers found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($organizers as $org): ?>
                                <tr>
                                    <td style="font-weight: 500; color: #111827;">
                                        <?= htmlspecialchars($org['name']) ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $badgeClass = '';
                                            if ($org['role'] === 'Judge Coordinator') $badgeClass = 'role-judge';
                                            elseif ($org['role'] === 'Contestant Manager') $badgeClass = 'role-contestant';
                                            elseif ($org['role'] === 'Tabulator') $badgeClass = 'role-tabulator';
                                        ?>
                                        <span class="role-badge <?= $badgeClass ?>"><?= htmlspecialchars($org['role']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($org['email']) ?></td>
                                    <td><?= htmlspecialchars($org['phone'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($view === 'active'): ?>
                                            <button class="btn-edit" 
                                                onclick='openEditModal(<?= json_encode($org) ?>)'>
                                                Edit
                                            </button>
                                            
                                            <a href="../api/organizer.php?action=remove&id=<?= $org['id'] ?>" class="btn-delete" onclick="return confirm('Deactivate this organizer?');">Remove</a>
                                        <?php else: ?>
                                            <a href="../api/organizer.php?action=restore&id=<?= $org['id'] ?>" class="btn-restore" onclick="return confirm('Restore this organizer?');">Restore</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <div id="addModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:20px; color: #111827;">Add New Organizer</h3>
            <form action="../api/organizer.php" method="POST">
                <input type="hidden" name="action" value="create">
                
                <div class="form-group">
                    <label>Assign Role</label>
                    <select name="role" required>
                        <option value="" disabled selected>Select Role</option>
                        <option value="Judge Coordinator">Judge Coordinator</option>
                        <option value="Contestant Manager">Contestant Manager</option>
                        <option value="Tabulator">Tabulator</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="addPass" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('addPass', this)"></i>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Add Organizer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:20px; color: #111827;">Edit Organizer</h3>
            <form action="../api/organizer.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="org_id" id="edit_id">

                <div class="form-group">
                    <label>Assign Role</label>
                    <select name="role" id="edit_role" required>
                        <option value="Judge Coordinator">Judge Coordinator</option>
                        <option value="Contestant Manager">Contestant Manager</option>
                        <option value="Tabulator">Tabulator</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="edit_phone">
                </div>
                <div class="form-group">
                    <label>Change Password (Optional)</label>
                    <input type="password" name="password" id="editPass" placeholder="Leave blank to keep current">
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('editPass', this)"></i>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        // Logic to populate Edit Modal
        function openEditModal(user) {
            document.getElementById('edit_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_phone').value = user.phone;
            document.getElementById('edit_role').value = user.role;
            openModal('editModal');
        }

        // Toggle Password Visibility
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

        // Toast Logic
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
        
        // Clean URL but keep view param if exists
        if (urlParams.has('success') || urlParams.has('error')) {
            const newUrl = window.location.pathname + (urlParams.has('view') ? '?view=' + urlParams.get('view') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>

</body>
</html>