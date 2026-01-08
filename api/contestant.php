<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']);
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/flash.php';
require_once __DIR__ . '/../app/core/csrf.php';

// --- HANDLE POST REQUESTS (Create OR Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::verifyToken($token)) {
        Flash::set('error', 'Security token mismatch. Please try again.');
        header("Location: ../public/contestants.php");
        exit();
    }

    $action = $_POST['action'] ?? 'create';
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    
    // Details
    $age         = (int)$_POST['age'];
    $height      = trim($_POST['height']);
    $vital_stats = trim($_POST['vital_stats']);
    $hometown    = trim($_POST['hometown']);
    $motto       = trim($_POST['motto']);

    // --- CREATE NEW (Manual Add) ---
    if ($action === 'create') {
        $pass = trim($_POST['password']);
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
            Flash::set('error', 'Photo is required');
            header("Location: ../public/contestants.php");
            exit();
        }

        $photo_name = uploadPhoto($_FILES['photo']);
        if (!$photo_name) {
            Flash::set('error', 'Photo upload failed or invalid file type');
            header("Location: ../public/contestants.php");
            exit();
        }

        $conn->begin_transaction();
        try {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            
            // 1. Insert into Users
            $stmt1 = $conn->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'Contestant', 'Active')");
            $stmt1->bind_param("sss", $name, $email, $hashed_pass);
            $stmt1->execute();
            $user_id = $conn->insert_id;

            // 2. Insert into Details (Explicitly is_deleted = 0)
            $stmt2 = $conn->prepare("INSERT INTO contestant_details (user_id, event_id, age, height, vital_stats, hometown, motto, photo, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt2->bind_param("iiisssss", $user_id, $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name);
            $stmt2->execute();
            
            // Send Email
            require_once __DIR__ . '/../app/core/CustomMailer.php';
            // Note: Ideally this URL should be in a config
            $site_link = "http://" . $_SERVER['HTTP_HOST'] . "/bpms/public/index.php";

            // Fetch Event Name safely
            $evt_name = "the Pageant";
            $stmt_e = $conn->prepare("SELECT name FROM events WHERE id = ?");
            $stmt_e->bind_param("i", $event_id);
            $stmt_e->execute();
            $res_e = $stmt_e->get_result();
            if ($row = $res_e->fetch_assoc()) $evt_name = $row['name'];

            $subject = "Official Contestant Registration";
            $body = "<h2>Welcome, " . htmlspecialchars($name) . "!</h2>
                     <p>You have been registered for <b>" . htmlspecialchars($evt_name) . "</b>.</p>
                     <div style='background:#f3f4f6; padding:15px; border-radius:8px; margin:20px 0;'>
                        <strong>Credentials:</strong><br>
                        Email: <b>" . htmlspecialchars($email) . "</b><br>
                        Password: <b>" . htmlspecialchars($pass) . "</b>
                     </div>
                     <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login Now</a></p>";
            
            sendCustomEmail($email, $subject, $body);

            $conn->commit();
            Flash::set('success', 'Contestant added successfully');
            header("Location: ../public/contestants.php");

        } catch (Exception $e) {
            $conn->rollback();
            Flash::set('error', 'Database Error: ' . $e->getMessage());
            header("Location: ../public/contestants.php");
        }
    } 

    // --- UPDATE EXISTING ---
    elseif ($action === 'update') {
        $id = (int)$_POST['contestant_id']; // This is user_id
        $pass = trim($_POST['password']);

        $conn->begin_transaction();
        try {
            if (!empty($pass)) {
                $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                $stmt1 = $conn->prepare("UPDATE users SET name=?, email=?, password=? WHERE id=?");
                $stmt1->bind_param("sssi", $name, $email, $hashed_pass, $id);
            } else {
                $stmt1 = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
                $stmt1->bind_param("ssi", $name, $email, $id);
            }
            $stmt1->execute();

            $photo_name = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                $photo_name = uploadPhoto($_FILES['photo']);
            }
            
            if ($photo_name) {
                $stmt2 = $conn->prepare("UPDATE contestant_details SET event_id=?, age=?, height=?, vital_stats=?, hometown=?, motto=?, photo=? WHERE user_id=?");
                $stmt2->bind_param("iisssssi", $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name, $id);
            } else {
                $stmt2 = $conn->prepare("UPDATE contestant_details SET event_id=?, age=?, height=?, vital_stats=?, hometown=?, motto=? WHERE user_id=?");
                $stmt2->bind_param("iissssi", $event_id, $age, $height, $vital_stats, $hometown, $motto, $id);
            }
            
            $stmt2->execute();
            $conn->commit();
            Flash::set('success', 'Contestant updated');
            header("Location: ../public/contestants.php");

        } catch (Exception $e) {
            $conn->rollback();
            Flash::set('error', 'Update Failed');
            header("Location: ../public/contestants.php");
        }
    }
    exit();
}

// Helper Function
function uploadPhoto($file) {
    // Validate mime type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/png'];
    if (!in_array($mime, $allowed_mimes)) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];
    if (in_array($ext, $allowed)) {
        $new_name = "contestant_" . time() . "." . $ext;
        $target_dir = __DIR__ . '/../public/assets/uploads/contestants/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        $target = $target_dir . $new_name;
        if (move_uploaded_file($file['tmp_name'], $target)) {
            return $new_name;
        }
    }
    return false;
}

// --- HANDLE GET ACTIONS (Approve / Reject / Remove / Restore / Delete) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    
    // CSRF for GET? Usually we prefer POST forms for actions.
    // Given the constraints and existing code, we will enforce minimal check if possible,
    // but typically links in tables are GET. We will trust the auth guard + ID check for now,
    // as converting all table actions to Forms is a big UI change.
    // Ideally, these should be POST forms with CSRF.

    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $my_id = $_SESSION['user_id'];

    // Security Check: Ensure I have rights to this specific contestant's event
    $check_auth = $conn->prepare("
        SELECT u.id FROM users u
        JOIN contestant_details cd ON u.id = cd.user_id
        JOIN events e ON cd.event_id = e.id
        LEFT JOIN event_organizers eo ON (e.id = eo.event_id AND eo.user_id = ? AND eo.status = 'Active')
        WHERE u.id = ? AND (e.user_id = ? OR eo.id IS NOT NULL)
    ");
    $check_auth->bind_param("iii", $my_id, $id, $my_id);
    $check_auth->execute();
    
    if ($check_auth->get_result()->num_rows === 0) {
        Flash::set('error', 'Unauthorized Action');
        header("Location: ../public/contestants.php");
        exit();
    }

    // --- ACTION LOGIC ---
    if ($action === 'delete') {
        // SOFT DELETE
        $stmt = $conn->prepare("UPDATE contestant_details SET is_deleted = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $id);
        $msg = "Contestant permanently removed from list.";
        $tab = 'archived';

    } elseif ($action === 'restore') {
        // RESTORE
        $conn->begin_transaction();
        $conn->query("UPDATE users SET status = 'Active' WHERE id = $id"); // Safe int cast via $id
        $conn->query("UPDATE contestant_details SET is_deleted = 0 WHERE user_id = $id");
        $conn->commit();
        $msg = "Contestant restored successfully.";
        $tab = 'archived';

    } elseif ($action === 'remove') {
        // ARCHIVE
        $stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $msg = "Contestant moved to archive.";
        $tab = 'active';

    } elseif ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $msg = "Application Approved";
        $tab = 'pending';
        // Send Email
        require_once __DIR__ . '/../app/core/CustomMailer.php';
        $site_link = "http://" . $_SERVER['HTTP_HOST'] . "/bpms/public/index.php";

        $stmt_u = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $id);
        $stmt_u->execute();
        $u_data = $stmt_u->get_result()->fetch_assoc();

        if ($u_data) sendCustomEmail($u_data['email'], "Application ACCEPTED", "<h2>Congrats " . htmlspecialchars($u_data['name']) . "!</h2><p>Your application is accepted.</p><p><a href='$site_link'>Login</a></p>");

    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET status = 'Rejected' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $msg = "Application Rejected";
        $tab = 'pending';
        // Send Email
        require_once __DIR__ . '/../app/core/CustomMailer.php';
        $stmt_u = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $id);
        $stmt_u->execute();
        $u_data = $stmt_u->get_result()->fetch_assoc();

        if ($u_data) sendCustomEmail($u_data['email'], "Application Update", "<p>Sorry, your application was not accepted.</p>");
    }

    if (isset($stmt)) $stmt->execute();
    
    Flash::set('success', $msg);
    header("Location: ../public/contestants.php?view=$tab");
    exit();
}
?>