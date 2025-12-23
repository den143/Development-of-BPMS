<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Capture Inputs
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Contestant Details
    $age         = (int)$_POST['age'];
    $height      = trim($_POST['height']);
    $vital_stats = trim($_POST['vital_stats']); // e.g., 34-24-36
    $hometown    = trim($_POST['hometown']);
    $motto       = trim($_POST['motto']);

    // 2. Basic Validation
    if (empty($name) || empty($email) || empty($password) || empty($event_id)) {
        header("Location: ../public/register.php?error=All required fields must be filled.");
        exit();
    }

    // 3. Check if Email Already Exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        header("Location: ../public/register.php?error=Email is already registered. Please login.");
        exit();
    }

    // 4. Handle Photo Upload
    $photo_name = "default_contestant.png";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // New unique name: contestant_TIMESTAMP.jpg
            $new_name = "contestant_" . time() . "." . $ext;
            $upload_dir = __DIR__ . '/../public/assets/uploads/contestants/';
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name)) {
                $photo_name = $new_name;
            } else {
                header("Location: ../public/register.php?error=Failed to upload photo.");
                exit();
            }
        } else {
            header("Location: ../public/register.php?error=Invalid file type. Only JPG/PNG allowed.");
            exit();
        }
    }

    // 5. DATABASE TRANSACTION (Insert User + Contestant Details)
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
        header("Location: ../public/register.php?success=Registration submitted! Please wait for approval.");

    } catch (Exception $e) {
        $conn->rollback(); // Undo changes if something failed
        header("Location: ../public/register.php?error=" . urlencode($e->getMessage()));
    }
    exit();

} else {
    header("Location: ../public/register.php");
    exit();
}