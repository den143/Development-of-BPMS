<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Helper class for CSRF protection.
 */
class Csrf {

    /**
     * Generate a CSRF token and store it in the session.
     * @return string The generated token.
     */
    public static function generateToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify the token from the request against the session.
     * @param string $token The token from the form input.
     * @return bool True if valid, false otherwise.
     */
    public static function verifyToken($token) {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Render a hidden input field with the token.
     * @return void
     */
    public static function renderInput() {
        $token = self::generateToken();
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}
