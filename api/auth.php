<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';

if (isset($_POST['email']) && isset($_POST['password']) && isset($_POST['role'])) {

    function validate($data){
       $data = trim($data);
       $data = stripslashes($data);
       $data = htmlspecialchars($data);
       return $data;
    }

    $email = validate($_POST['email']);
    $pass = validate($_POST['password']);
    $role = validate($_POST['role']);

    if (empty($email) || empty($pass) || empty($role)) {
        header("Location: ../public/index.php?error=All fields are required");
        exit();
    } else {
        // SQL to check email and role
        $sql = "SELECT * FROM users WHERE email='$email' AND role='$role'";
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);
            
            if (password_verify($pass, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['email'] = $row['email'];
                $_SESSION['role'] = $row['role'];
                
                $u_id = $row['id'];
                $check_event = mysqli_query($conn, "SELECT id FROM events WHERE user_id = '$u_id'");

                $_SESSION['show_modal'] = (mysqli_num_rows($check_event) == 0);

                // Redirect to Event Manager dashboard
                header("Location: ../public/dashboard.php");
                exit();
            } else {
                header("Location: ../public/index.php?error=Incorrect Password");
                exit();
            }
        } else {
            header("Location: ../public/index.php?error=User not found or Role incorrect");
            exit();
        }
    }
} else {
    header("Location: ../public/index.php");
    exit();
}
