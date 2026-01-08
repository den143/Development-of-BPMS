<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/flash.php';
require_once __DIR__ . '/../app/core/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::verifyToken($token)) {
        Flash::set('error', 'Session expired or invalid request. Please reload.');
        header("Location: ../public/register.php");
        exit();
    }

    // 2. Capture Inputs
    $event_id = (int)($_POST['event_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Contestant Details
    $age         = (int)($_POST['age'] ?? 0);
    $height      = trim($_POST['height'] ?? '');
    $vital_stats = trim($_POST['vital_stats'] ?? ''); // e.g., 34-24-36
    $hometown    = trim($_POST['hometown'] ?? '');
    $motto       = trim($_POST['motto'] ?? '');

    // 3. Basic Validation
    if (empty($name) || empty($email) || empty($password) || empty($event_id)) {
        Flash::set('error', 'All required fields must be filled.');
        header("Location: ../public/register.php");
        exit();
    }

    // 4. Check if Email Already Exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        Flash::set('error', 'Email is already registered. Please login.');
        header("Location: ../public/register.php");
        exit();
    }

    // 5. Handle Photo Upload
    $photo_name = "default_contestant.png";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // Validate image type strictly (prevent malicious code in file)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);

            $allowed_mimes = ['image/jpeg', 'image/png'];
            if (!in_array($mime, $allowed_mimes)) {
                Flash::set('error', 'Invalid file content. Not a valid image.');
                header("Location: ../public/register.php");
                exit();
            }

            // New unique name: contestant_TIMESTAMP.jpg
            $new_name = "contestant_" . time() . "." . $ext;
            $upload_dir = __DIR__ . '/../public/assets/uploads/contestants/';
            
            // Ensure directory exists
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name)) {
                $photo_name = $new_name;
            } else {
                Flash::set('error', 'Failed to upload photo.');
                header("Location: ../public/register.php");
                exit();
            }
        } else {
            Flash::set('error', 'Invalid file type. Only JPG/PNG allowed.');
            header("Location: ../public/register.php");
            exit();
        }
    }

    // 6. DATABASE TRANSACTION (Insert User + Contestant Details)
    $conn->begin_transaction();

    try {
        // A. Insert into USERS table (Status = Pending)
        $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
        $role = 'Contestant';
        $status = 'Pending';
        
        $stmt1 = $conn->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt1->bind_param("sssss", $name, $email, $hashed_pass, $role, $status);
        
        if (!$stmt1->execute()) {
            throw new Exception("Error creating user account.");
        }
        $user_id = $conn->insert_id; // Get the new ID

        // B. Insert into CONTESTANT_DETAILS table
        $stmt2 = $conn->prepare("INSERT INTO contestant_details (user_id, event_id, age, height, vital_stats, hometown, motto, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("iiisssss", $user_id, $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name);
        
        if (!$stmt2->execute()) {
            throw new Exception("Error saving contestant details.");
        }

        // C. Commit Everything
        $conn->commit();
        Flash::set('success', 'Registration submitted! Please wait for approval.');
        header("Location: ../public/register.php");

    } catch (Exception $e) {
        $conn->rollback(); // Undo changes if something failed
        error_log($e->getMessage());
        Flash::set('error', 'Registration failed. Please try again.');
        header("Location: ../public/register.php");
    }
    exit();

} else {
    header("Location: ../public/register.php");
    exit();
}
