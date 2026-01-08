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
        header("Location: ../public/index.php");
        exit();
    }

    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    $role  = trim($_POST['role'] ?? '');

    if (empty($email) || empty($pass) || empty($role)) {
        Flash::set('error', 'All fields are required');
        header("Location: ../public/index.php");
        exit();
    }

    // 2. Fetch user
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // 3. CHECK PASSWORD
        if (password_verify($pass, $row['password'])) {
            
            // 4. CHECK ACCOUNT STATUS
            if ($row['status'] === 'Pending') {
                Flash::set('error', 'Your application is still pending approval.');
                header("Location: ../public/index.php");
                exit();
            } elseif ($row['status'] === 'Rejected') {
                Flash::set('error', 'Your application was rejected.');
                header("Location: ../public/index.php");
                exit();
            } elseif ($row['status'] === 'Inactive') {
                Flash::set('error', 'Your account is deactivated.');
                header("Location: ../public/index.php");
                exit();
            }

            // ============================================================
            // 5. CHECK IF LINKED EVENT IS ACTIVE
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
                    $j_check = $conn->prepare("
                        SELECT ej.id FROM event_judges ej 
                        JOIN events e ON ej.event_id = e.id 
                        WHERE ej.judge_id = ? AND e.status = 'Active' AND ej.status = 'Active' LIMIT 1
                    ");
                    $j_check->bind_param("i", $u_id);
                    $j_check->execute();
                    if ($j_check->get_result()->num_rows > 0) $has_active_event = true;
                }
                // C. Check for ORGANIZERS
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

                if (!$has_active_event) {
                    Flash::set('error', 'Access Denied: The event you are assigned to is not currently active.');
                    header("Location: ../public/index.php");
                    exit();
                }
            }

            // 6. PROCEED TO LOGIN
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];
            $_SESSION['name']    = $row['name'];

            if ($role === 'Event Manager') {
                $u_id = $row['id'];
                // Use prepared statement here too for consistency, though user_id is integer from DB
                $check_event = $conn->prepare("SELECT id FROM events WHERE user_id = ?");
                $check_event->bind_param("i", $u_id);
                $check_event->execute();
                $_SESSION['show_modal'] = ($check_event->get_result()->num_rows == 0);
            }

            // 7. REDIRECT BASED ON ROLE
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
                    header("Location: ../public/judge_dashboard.php");
                    break;
                default:
                    Flash::set('error', 'Role configuration error');
                    header("Location: ../public/index.php");
                    break;
            }
            exit();

        } else {
            Flash::set('error', 'Incorrect Password');
            header("Location: ../public/index.php");
            exit();
        }
    } else {
        Flash::set('error', 'User not found or Role incorrect');
        header("Location: ../public/index.php");
        exit();
    }
} else {
    header("Location: ../public/index.php");
    exit();
}
