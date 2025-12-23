<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Contestant');
require_once __DIR__ . '/../app/config/database.php';

$user_id = $_SESSION['user_id'];

// Fetch Contestant Details + Event Name
$sql = "SELECT u.name, u.email, cd.age, cd.height, cd.vital_stats, cd.hometown, cd.motto, cd.photo, e.name as event_name, e.event_date
        FROM users u 
        JOIN contestant_details cd ON u.id = cd.user_id 
        JOIN events e ON cd.event_id = e.id 
        WHERE u.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("Error loading profile. Please contact admin.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; font-family: 'Segoe UI', sans-serif; }
        
        .navbar { background: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .brand { font-size: 20px; font-weight: bold; color: #F59E0B; }
        .user-menu a { color: #6b7280; text-decoration: none; font-weight: 600; }
        .user-menu a:hover { color: #1f2937; }

        .container { max-width: 900px; margin: 40px auto; padding: 20px; }
        
        /* Profile Card */
        .profile-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; flex-direction: column; align-items: center; text-align: center; }
        
        .cover-photo { width: 100%; height: 150px; background: linear-gradient(135deg, #F59E0B 0%, #d97706 100%); }
        
        .profile-img { 
            width: 140px; height: 140px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 5px solid white; 
            margin-top: -70px; 
            background: #fff;
        }

        .profile-body { padding: 30px; width: 100%; }
        
        .name { font-size: 28px; font-weight: bold; color: #1f2937; margin-bottom: 5px; }
        .title { font-size: 16px; color: #F59E0B; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; }
        
        .motto { font-style: italic; color: #6b7280; font-size: 16px; margin-bottom: 30px; max-width: 600px; margin-left: auto; margin-right: auto; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; border-top: 1px solid #f3f4f6; padding-top: 30px; }
        .stat-item h4 { font-size: 14px; color: #9ca3af; margin-bottom: 5px; text-transform: uppercase; }
        .stat-item p { font-size: 18px; font-weight: bold; color: #1f2937; }

        /* Event Badge */
        .event-badge { 
            display: inline-block; 
            background: #ecfdf5; 
            color: #059669; 
            padding: 8px 16px; 
            border-radius: 20px; 
            font-size: 14px; 
            font-weight: 600; 
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 30px; }
        }
    </style>
</head>
<body>

    <div class="navbar">
        <div class="brand">BPMS Candidate Portal</div>
        <div class="user-menu">
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="container">
        
        <div class="profile-card">
            <div class="cover-photo"></div>
            
            <img src="./assets/uploads/contestants/<?= htmlspecialchars($data['photo']) ?>" alt="Profile" class="profile-img">
            
            <div class="profile-body">
                <div class="event-badge">
                    <i class="fas fa-crown"></i> Official Candidate: <?= htmlspecialchars($data['event_name']) ?>
                </div>

                <div class="name"><?= htmlspecialchars($data['name']) ?></div>
                <div class="title">Representing <?= htmlspecialchars($data['hometown']) ?></div>

                <?php if(!empty($data['motto'])): ?>
                    <div class="motto">"<?= htmlspecialchars($data['motto']) ?>"</div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-item">
                        <h4>Age</h4>
                        <p><?= $data['age'] ?></p>
                    </div>
                    <div class="stat-item">
                        <h4>Height</h4>
                        <p><?= htmlspecialchars($data['height']) ?></p>
                    </div>
                    <div class="stat-item">
                        <h4>Vital Stats</h4>
                        <p><?= htmlspecialchars($data['vital_stats'] ?: '-') ?></p>
                    </div>
                    <div class="stat-item">
                        <h4>Email</h4>
                        <p style="font-size:14px;"><?= htmlspecialchars($data['email']) ?></p>
                    </div>
                </div>

            </div>
        </div>

    </div>

</body>
</html>