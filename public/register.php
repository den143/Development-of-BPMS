<?php
session_start();
require_once __DIR__ . '/../app/config/database.php';

// Fetch Active Events
$events = [];
$result = $conn->query("SELECT id, name FROM events WHERE status = 'Active'");
if ($result) {
    $events = $result->fetch_all(MYSQLI_ASSOC);
}

$error = $_GET['error'] ?? null;
$success = $_GET['success'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Candidate Registration - BPMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reuse core styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; height: 100vh; display: flex; overflow: hidden; }

        .brand-section {
            width: 40%;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            text-align: center;
        }
        .brand-logo { width: 120px; margin-bottom: 20px; }
        .brand-title { font-size: 32px; font-weight: bold; color: #F59E0B; }
        .brand-desc { font-size: 14px; color: #9ca3af; margin-top: 10px; max-width: 300px; }

        .form-section {
            width: 60%;
            background-color: #f9fafb;
            overflow-y: auto;
            padding: 40px;
            display: flex;
            justify-content: center;
        }

        .register-card {
            width: 100%;
            max-width: 600px;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-top: 20px;
            margin-bottom: 40px;
        }

        .form-header { margin-bottom: 30px; text-align: center; }
        .form-header h2 { color: #1f2937; }
        .form-header p { color: #6b7280; font-size: 14px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full-width { grid-column: span 2; }

        .form-group { position: relative; } /* Needed for toggle icon */
        .form-group label { display: block; margin-bottom: 5px; color: #374151; font-weight: 600; font-size: 13px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; outline: none; }
        .form-control:focus { border-color: #F59E0B; }
        
        .file-input-wrapper { border: 2px dashed #d1d5db; padding: 20px; text-align: center; border-radius: 6px; cursor: pointer; color: #6b7280; }
        .file-input-wrapper:hover { border-color: #F59E0B; color: #F59E0B; }

        .btn-submit { width: 100%; padding: 12px; background-color: #F59E0B; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 20px; font-size: 16px; }
        .btn-submit:hover { background-color: #d97706; }

        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: center; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #d1fae5; color: #059669; border: 1px solid #a7f3d0; }

        .back-link { display: block; text-align: center; margin-top: 20px; color: #6b7280; text-decoration: none; font-size: 14px; }
        .back-link:hover { color: #1f2937; }

        /* PASSWORD TOGGLE ICON */
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 32px; /* Adjust based on label height */
            cursor: pointer;
            color: #9ca3af;
        }
        .toggle-password:hover { color: #374151; }

        @media (max-width: 900px) {
            body { flex-direction: column; overflow-y: auto; }
            .brand-section { width: 100%; padding: 20px; min-height: 150px; flex-direction: row; justify-content: space-between; text-align: left;}
            .brand-logo { width: 50px; margin: 0; }
            .brand-title { font-size: 24px; }
            .brand-desc { display: none; }
            .form-section { width: 100%; padding: 20px; }
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>

    <div class="brand-section">
        <div>
            <img src="./assets/images/BPMS_logo.png" alt="Logo" class="brand-logo">
            <div class="brand-title">BPMS</div>
            <p class="brand-desc">Join the most prestigious pageant. Register your application today.</p>
        </div>
    </div>

    <div class="form-section">
        <div class="register-card">
            
            <div class="form-header">
                <h2>Candidate Registration</h2>
                <p>Please fill in your details correctly.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                    <br><a href="index.php" style="font-weight:bold; color:inherit; text-decoration:underline;">Return to Login</a>
                </div>
            <?php endif; ?>

            <form action="../api/register_contestant.php" method="POST" enctype="multipart/form-data">
                
                <div class="form-grid">
                    
                    <div class="form-group full-width">
                        <label>Select Pageant Event</label>
                        <select name="event_id" class="form-control" required>
                            <option value="" disabled selected>-- Choose an Open Event --</option>
                            <?php foreach ($events as $evt): ?>
                                <option value="<?= $evt['id'] ?>"><?= htmlspecialchars($evt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Maria Clara" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Create Password</label>
                        <input type="password" name="password" id="regPass" class="form-control" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('regPass', this)"></i>
                    </div>

                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" class="form-control" min="16" max="30" required>
                    </div>

                    <div class="form-group">
                        <label>Height (cm or ft)</label>
                        <input type="text" name="height" class="form-control" placeholder="e.g. 170cm" required>
                    </div>

                    <div class="form-group">
                        <label>Vital Statistics</label>
                        <input type="text" name="vital_stats" class="form-control" placeholder="e.g. 34-24-36">
                    </div>

                    <div class="form-group">
                        <label>Hometown / Representing</label>
                        <input type="text" name="hometown" class="form-control" placeholder="e.g. Catarman" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Advocacy / Motto</label>
                        <input type="text" name="motto" class="form-control" placeholder="Short phrase describing you...">
                    </div>

                    <div class="form-group full-width">
                        <label>Upload Photo (Headshot/Half Body)</label>
                        <div class="file-input-wrapper" onclick="document.getElementById('photoInput').click()">
                            <i class="fas fa-cloud-upload-alt" style="font-size:24px; margin-bottom:5px;"></i><br>
                            <span>Click to Upload Image (JPG/PNG)</span>
                            <input type="file" name="photo" id="photoInput" style="display:none;" accept="image/*" onchange="document.querySelector('.file-input-wrapper span').innerText = this.files[0].name" required>
                        </div>
                    </div>

                </div>

                <button type="submit" class="btn-submit">Submit Application</button>

                <a href="index.php" class="back-link">← Back to Login</a>

            </form>
        </div>
    </div>

    <script>
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
    </script>

</body>
</html>