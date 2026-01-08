<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Helper class for managing Session Flash Messages.
 */
class Flash {

    /**
     * Set a flash message.
     * @param string $key The key (e.g., 'error', 'success').
     * @param string $message The message content.
     */
    public static function set($key, $message) {
        $_SESSION['flash'][$key] = $message;
    }

    /**
     * Get a flash message and remove it from the session.
     * @param string $key The key.
     * @return string|null The message or null if not set.
     */
    public static function get($key) {
        if (isset($_SESSION['flash'][$key])) {
            $message = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $message;
        }
        return null;
    }

    /**
     * Check if a flash message exists.
     * @param string $key The key.
     * @return bool
     */
    public static function has($key) {
        return isset($_SESSION['flash'][$key]);
    }
}
