<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="sidebar">
    <div class="sidebar-header">
        <img src="assets/images/BPMS_logo.png" alt="BPMS Logo" class="sidebar-logo">
        
        <div class="brand-text">
            <div class="brand-name">BPMS</div>
            <div class="brand-subtitle">Beauty Pageant Management System</div>
        </div>
    </div>
    
    <ul class="sidebar-menu">
        <?php
            // Helper function for active state
            function isActive($pageName) {
                $currentScript = basename($_SERVER['PHP_SELF']);
                return ($currentScript === $pageName) ? 'active' : '';
            }
        ?>
        <li><a href="dashboard.php" class="<?= isActive('dashboard.php') ?>"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
        <li><a href="organizers.php" class="<?= isActive('organizers.php') ?>"><i class="fas fa-user-tie"></i> <span>Manage Organizers</span></a></li>
        <li><a href="contestants.php" class="<?= isActive('contestants.php') ?>"><i class="fas fa-female"></i> <span>Register Contestant</span></a></li>
        <li><a href="judges.php" class="<?= isActive('judges.php') ?>"><i class="fas fa-gavel"></i> <span>Register Judge</span></a></li>
        
        <li><a href="rounds.php" class="<?= isActive('rounds.php') ?>"><i class="fas fa-layer-group"></i> <span>Manage Rounds</span></a></li>
        
        <li><a href="criteria.php" class="<?= isActive('criteria.php') ?>"><i class="fas fa-clipboard-list"></i> <span>Segments & Criteria</span></a></li>
        <li><a href="activities.php" class="<?= isActive('activities.php') ?>"><i class="fas fa-calendar-check"></i> <span>Manage Activities</span></a></li>
        <li><a href="awards.php" class="<?= isActive('awards.php') ?>"><i class="fas fa-trophy"></i> <span>Manage Awards</span></a></li>
        
        <li><a href="tabulator.php" class="<?= isActive('tabulator.php') ?>"><i class="fas fa-poll"></i> <span>Result Panel</span></a></li>
    </ul>
    
    <div class="sidebar-footer">
        <a href="settings.php" class="<?= isActive('settings.php') ?>">
            <i class="fas fa-cog"></i> <span>Settings</span>
        </a>
        
        <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>
</div>