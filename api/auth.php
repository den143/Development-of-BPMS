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

    // 1. Fetch user
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // 2. CHECK PASSWORD
        if (password_verify($pass, $row['password'])) {
            
            // 3. CHECK ACCOUNT STATUS
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

            // ============================================================
            // 4. [NEW] CHECK IF LINKED EVENT IS ACTIVE
            // ============================================================
            if ($role !== 'Event Manager') {
                $u_id = $row['id'];
                $has_active_event = false;

                // A. Check for CONTESTANTS
                if ($role === 'Contestant') {
                    $c_check = $conn->prepare("
                        SELECT e.status 
                        FROM contestant_details cd 
                        JOIN events e ON cd.event_id = e.id 
                        WHERE cd.user_id = ? LIMIT 1
                    ");
                    $c_check->bind_param("i", $u_id);
                    $c_check->execute();
                    $res = $c_check->get_result();
                    
                    if ($res->num_rows > 0) {
                        $evt = $res->fetch_assoc();
                        if ($evt['status'] === 'Active') $has_active_event = true;
                    }
                } 
                // B. Check for JUDGES
                elseif ($role === 'Judge') {
                    // Must be linked to an Active Event AND have Active status in that event
                    $j_check = $conn->prepare("
                        SELECT ej.id FROM event_judges ej 
                        JOIN events e ON ej.event_id = e.id 
                        WHERE ej.judge_id = ? AND e.status = 'Active' AND ej.status = 'Active' LIMIT 1
                    ");
                    $j_check->bind_param("i", $u_id);
                    $j_check->execute();
                    if ($j_check->get_result()->num_rows > 0) $has_active_event = true;
                }
                // C. Check for ORGANIZERS (Coordinators, Tabulators, etc.)
                elseif (in_array($role, ['Judge Coordinator', 'Contestant Manager', 'Tabulator'])) {
                    $o_check = $conn->prepare("
                        SELECT eo.id FROM event_organizers eo 
                        JOIN events e ON eo.event_id = e.id 
                        WHERE eo.user_id = ? AND e.status = 'Active' AND eo.status = 'Active' LIMIT 1
                    ");
                    $o_check->bind_param("i", $u_id);
                    $o_check->execute();
                    if ($o_check->get_result()->num_rows > 0) $has_active_event = true;
                }

                // IF NO ACTIVE EVENT FOUND -> BLOCK LOGIN
                if (!$has_active_event) {
                    header("Location: ../public/index.php?error=Access Denied: The event you are assigned to is not currently active.");
                    exit();
                }
            }
            // ============================================================


            // 5. PROCEED TO LOGIN
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];
            $_SESSION['name']    = $row['name'];

            // Special Logic for Event Manager Modal
            if ($role === 'Event Manager') {
                $u_id = $row['id'];
                $check_event = $conn->query("SELECT id FROM events WHERE user_id = '$u_id'");
                $_SESSION['show_modal'] = ($check_event->num_rows == 0);
            }

            // 6. REDIRECT BASED ON ROLE
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
                    header("Location: ../public/contestant_dashboard.php");
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