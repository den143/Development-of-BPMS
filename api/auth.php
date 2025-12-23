<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    $role  = trim($_POST['role'] ?? '');

    if (empty($email) || empty($pass) || empty($role)) {
        header("Location: ../public/index.php?error=All fields are required");
        exit();
    }

    // 1. SECURE QUERY (Prepared Statement)
    // We check email AND role to ensure they are logging in to the correct portal
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ? AND status = 'Active'");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // 2. VERIFY PASSWORD
        if (password_verify($pass, $row['password'])) {
            // Set Session Variables
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];
            $_SESSION['name']    = $row['name'];

            // 3. SPECIAL CHECK FOR EVENT MANAGER (Event Modal Logic)
            if ($role === 'Event Manager') {
                $u_id = $row['id'];
                $check_event = $conn->query("SELECT id FROM events WHERE user_id = '$u_id'");
                $_SESSION['show_modal'] = ($check_event->num_rows == 0);
            }

            // 4. DYNAMIC REDIRECT
            switch ($role) {
                case 'Event Manager':
                    header("Location: ../public/dashboard.php");
                    break;
                case 'Judge Coordinator':
                    header("Location: ../public/judge_coordinator.php");
                    break;
                case 'Contestant Manager':
                    header("Location: ../public/contestant_manager.php"); // Future
                    break;
                case 'Tabulator':
                    header("Location: ../public/tabulator.php"); // Future
                    break;
                default:
                    header("Location: ../public/index.php?error=Role not supported yet");
                    break;
            }
            exit();

        } else {
            header("Location: ../public/index.php?error=Incorrect Password");
            exit();
        }
    } else {
        header("Location: ../public/index.php?error=User not found or Role incorrect");
        exit();
    }
} else {
    header("Location: ../public/index.php");
    exit();
}