<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');

require_once __DIR__ . '/../app/config/database.php';

// --- HANDLE POST REQUESTS (Create & Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check if this is an UPDATE or CREATE
    $action = $_POST['action'] ?? 'create';
    $id     = isset($_POST['org_id']) ? (int)$_POST['org_id'] : 0;

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role  = trim($_POST['role'] ?? '');
    $pass  = trim($_POST['password'] ?? '');

    // 1. Basic Validation
    if (empty($name) || empty($email) || empty($role)) {
        header("Location: ../public/organizers.php?error=Name, Email, and Role are required");
        exit();
    }

    $allowed_roles = ['Judge Coordinator', 'Contestant Manager', 'Tabulator'];
    if (!in_array($role, $allowed_roles)) {
        header("Location: ../public/organizers.php?error=Invalid Role");
        exit();
    }

    // 2. Check Email Uniqueness (Exclude current ID if updating)
    $sql = "SELECT id FROM users WHERE email = ? AND id != ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $email, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: ../public/organizers.php?error=Email already exists");
        exit();
    }

    // --- CREATE NEW ORGANIZER ---
    if ($action === 'create') {
        if (empty($pass)) {
            header("Location: ../public/organizers.php?error=Password is required");
            exit();
        }
        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
        $creator_id = $_SESSION['user_id'];

        $stmt = $conn->prepare("INSERT INTO users (created_by, name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param("isssss", $creator_id, $name, $email, $phone, $role, $hashed_pass);

        if ($stmt->execute()) {
            header("Location: ../public/organizers.php?success=Organizer added successfully");
        } else {
            header("Location: ../public/organizers.php?error=Database error");
        }
    }

    // --- UPDATE EXISTING ORGANIZER ---
    elseif ($action === 'update') {
        // If password is provided, update it. If empty, keep the old one.
        if (!empty($pass)) {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, password=? WHERE id=? AND created_by=?");
            $stmt->bind_param("sssssii", $name, $email, $phone, $role, $hashed_pass, $id, $_SESSION['user_id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=? AND created_by=?");
            $stmt->bind_param("ssssii", $name, $email, $phone, $role, $id, $_SESSION['user_id']);
        }

        if ($stmt->execute()) {
            header("Location: ../public/organizers.php?success=Organizer updated successfully");
        } else {
            header("Location: ../public/organizers.php?error=Update failed");
        }
    }
    exit();
}

// --- HANDLE GET ACTIONS (Remove & Restore) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $type = $_GET['action'];
    $my_id = $_SESSION['user_id'];
    $msg = "";

    if ($type === 'remove') {
        // Soft Delete
        $stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ? AND created_by = ?");
        $stmt->bind_param("ii", $id, $my_id);
        $msg = "Organizer deactivated";
    } elseif ($type === 'restore') {
        // Restore
        $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE id = ? AND created_by = ?");
        $stmt->bind_param("ii", $id, $my_id);
        $msg = "Organizer restored successfully";
    }

    if (isset($stmt) && $stmt->execute()) {
        // FIXED REDIRECT LOGIC
        if ($type === 'remove') {
            // Standard view
            header("Location: ../public/organizers.php?success=" . urlencode($msg));
        } else {
            // Archived view
            header("Location: ../public/organizers.php?view=archived&success=" . urlencode($msg));
        }
    } else {
        header("Location: ../public/organizers.php?error=Action failed");
    }
    exit();
}