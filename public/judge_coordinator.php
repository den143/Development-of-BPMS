<?php
require_once __DIR__ . '/../app/core/guard.php';
// 1. Check Login
requireLogin();
// 2. Check Specific Role (Only Judge Coordinator allowed)
requireRole('Judge Coordinator'); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Judge Coordinator - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <style>
        /* Simple Layout for this Role */
        body { background-color: #f4f6f9; }
        .top-bar {
            background-color: #1f2937;
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .container { padding: 40px; }
        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-top: 5px solid #4338ca; /* Indigo Theme for Judges */
        }
    </style>
</head>
<body>

    <div class="top-bar">
        <h3>BPMS - Judge Coordinator</h3>
        <div>
            <span>Welcome, <?= htmlspecialchars($_SESSION['name']) ?></span>
            <a href="logout.php" style="color: #F59E0B; margin-left: 15px; text-decoration: none;">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <h1>Judge Coordinator Dashboard</h1>
            <p>You have successfully logged in.</p>
            <p>This module will be used to Manage Judges.</p>
        </div>
    </div>

</body>
</html>