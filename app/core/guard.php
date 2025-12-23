<?php
// app/core/guard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
}

/**
 * @param string|array $roles
 */
function requireRole($roles) {
    if (!isset($_SESSION['role'])) {
        header("Location: index.php");
        exit();
    }

    if (is_array($roles)) {
        if (!in_array($_SESSION['role'], $roles, true)) {
            header("Location: index.php");
            exit();
        }
    } else {
        if ($_SESSION['role'] !== $roles) {
            header("Location: index.php");
            exit();
        }
    }
}
