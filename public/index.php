<?php
session_start();

// Redirect logged-in users
if (isset($_SESSION['user_id'])) {
    header("Location: ./dashboard.php");
    exit();
}

// Capture error message
$error = $_GET['error'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPMS - Login</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 350px;
        }
        .login-header h2 { text-align: center; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #e91e63;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover { background-color: #c2185b; }
        .error { color: red; font-size: 14px; text-align: center; margin-bottom: 10px;}
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-header">
            <h2>BPMS Login</h2>
        </div>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form action="../api/auth.php" method="POST">
            <div class="form-group">
                <label>Select Role</label>
                <select name="role" required>
                    <option value="" disabled selected>Select your role</option>
                    <option value="Event Manager">Event Manager</option>
                    <option value="Contestant">Contestant</option>
                    <option value="Judge">Judge</option>
                </select>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter email" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>

            <button type="submit">Sign In</button>
        </form>
    </div>

</body>
</html>
