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

    // 1. Fetch user (Check email & role)
    // We do NOT check status='Active' here immediately, because we want to give specific error messages
    // (e.g. "Your account is pending approval")
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // 2. CHECK PASSWORD
        if (password_verify($pass, $row['password'])) {
            
            // 3. CHECK STATUS
            if ($row['status'] === 'Pending') {
                header("Location: ../public/index.php?error=Your application is still pending approval.");
                exit();
            } elseif ($row['status'] === 'Rejected') {
                header("Location: ../public/index.php?error=Your application was rejected.");
                exit();
            } elseif ($row['status'] === 'Inactive') {
                header("Location: ../public/index.php?error=Your account is deactivated.");
                exit();
            }

            // Status is Active -> Proceed
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];
            $_SESSION['name']    = $row['name'];

            // Special Logic for Event Manager
            if ($role === 'Event Manager') {
                $u_id = $row['id'];
                $check_event = $conn->query("SELECT id FROM events WHERE user_id = '$u_id'");
                $_SESSION['show_modal'] = ($check_event->num_rows == 0);
            }

            // 4. REDIRECT BASED ON ROLE
            switch ($role) {
                case 'Event Manager':
                    header("Location: ../public/dashboard.php");
                    break;
                case 'Judge Coordinator':
                    header("Location: ../public/judge_coordinator.php");
                    break;
                case 'Contestant Manager':
                    header("Location: ../public/contestant_manager.php"); 
                    break;
                case 'Tabulator':
                    header("Location: ../public/tabulator.php"); 
                    break;
                case 'Contestant':
                    header("Location: ../public/contestant_dashboard.php"); // NEW FILE
                    break;
                case 'Judge':
                    header("Location: ../public/judge_dashboard.php"); // Future
                    break;
                default:
                    header("Location: ../public/index.php?error=Role configuration error");
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