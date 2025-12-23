<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// --- 1. ADD or RESTORE ORGANIZER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $role     = trim($_POST['role']);
    $pass     = trim($_POST['password']);
    
    $allowed_roles = ['Judge Coordinator', 'Contestant Manager', 'Tabulator'];
    if (!in_array($role, $allowed_roles)) {
        header("Location: ../public/organizers.php?error=Invalid Role selected");
        exit();
    }

    $conn->begin_transaction();
    try {
        // [SECURITY] Check if this email belongs to an Event Manager
        $checkAdmin = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
        $checkAdmin->bind_param("s", $email);
        $checkAdmin->execute();
        $adminRes = $checkAdmin->get_result();

        if ($adminRes->num_rows > 0) {
            $existingUser = $adminRes->fetch_assoc();
            if ($existingUser['role'] === 'Event Manager') {
                throw new Exception("Security Alert: You cannot assign the Event Manager account as an Organizer.");
            }
            // Use existing user
            $user_id = $existingUser['id'];
            
            // Update role if needed (Optional, depends if you want to overwrite roles)
            // $updateRole = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            // $updateRole->bind_param("si", $role, $user_id);
            // $updateRole->execute();
            
        } else {
            // Create New User
            if (empty($pass)) { throw new Exception("Password required for new accounts"); }
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (created_by, name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            $creator = $_SESSION['user_id'];
            $stmt->bind_param("isssss", $creator, $name, $email, $phone, $role, $hashed_pass);
            $stmt->execute();
            $user_id = $conn->insert_id;
        }

        // Check/Create Link
        $linkCheck = $conn->prepare("SELECT id FROM event_organizers WHERE event_id = ? AND user_id = ?");
        $linkCheck->bind_param("ii", $event_id, $user_id);
        $linkCheck->execute();
        $linkRes = $linkCheck->get_result();
        
        if ($linkRes->num_rows > 0) {
            $link_id = $linkRes->fetch_assoc()['id'];
            $restore = $conn->prepare("UPDATE event_organizers SET status='Active' WHERE id=?");
            $restore->bind_param("i", $link_id);
            $restore->execute();
            $msg = "Organizer restored to this event";
        } else {
            $insert = $conn->prepare("INSERT INTO event_organizers (event_id, user_id, status) VALUES (?, ?, 'Active')");
            $insert->bind_param("ii", $event_id, $user_id);
            $insert->execute();
            $msg = "Organizer added successfully";
        }

        $conn->commit();
        header("Location: ../public/organizers.php?success=" . urlencode($msg));

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // Handle Duplicate Entry specifically
        if ($e->getCode() == 1062) {
            header("Location: ../public/organizers.php?error=Email address is already in use by another user.");
        } else {
            header("Location: ../public/organizers.php?error=Database Error: " . urlencode($e->getMessage()));
        }
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/organizers.php?error=" . urlencode($e->getMessage()));
    }
    exit();
}

// --- 2. UPDATE ORGANIZER (Edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $user_id = (int)$_POST['org_id'];
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $role  = trim($_POST['role']);
    $pass  = trim($_POST['password']);

    try {
        // [CHECK 1] Security: Don't edit Event Managers
        $checkRole = $conn->query("SELECT role FROM users WHERE id = $user_id");
        if ($checkRole->fetch_assoc()['role'] === 'Event Manager') {
             throw new Exception("Cannot edit Event Manager accounts here.");
        }

        // [CHECK 2] Duplicate Email Check
        // "Is this email taken by anyone who is NOT me?"
        $dupCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $dupCheck->bind_param("si", $email, $user_id);
        $dupCheck->execute();
        if ($dupCheck->get_result()->num_rows > 0) {
            throw new Exception("This email is already used by another account.");
        }

        // Update User
        if (!empty($pass)) {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, password=? WHERE id=?");
            $stmt->bind_param("sssssi", $name, $email, $phone, $role, $hashed_pass, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $email, $phone, $role, $user_id);
        }

        $stmt->execute();
        header("Location: ../public/organizers.php?success=Organizer updated");

    } catch (mysqli_sql_exception $e) {
        // Catch duplicate entry if the manual check missed race condition
        if ($e->getCode() == 1062) {
             header("Location: ../public/organizers.php?error=Email is already taken.");
        } else {
             header("Location: ../public/organizers.php?error=Database error.");
        }
    } catch (Exception $e) {
        header("Location: ../public/organizers.php?error=" . urlencode($e->getMessage()));
    }
    exit();
}

// --- 3. REMOVE / RESTORE ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $link_id = (int)$_GET['id'];
    $type = $_GET['action'];
    $new_status = ($type === 'restore') ? 'Active' : 'Inactive';

    $stmt = $conn->prepare("UPDATE event_organizers SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $link_id);

    if ($stmt->execute()) {
        $view = ($type === 'remove') ? 'active' : 'archived';
        header("Location: ../public/organizers.php?view=$view&success=Organizer status updated");
    } else {
        header("Location: ../public/organizers.php?error=Action failed");
    }
    exit();
}