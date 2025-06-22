<?php
// config/sanitizer.php - Input Sanitization
class InputSanitizer {
    
    public static function sanitizeString($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function sanitizeEmail($email) {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
    
    public static function sanitizeUrl($url) {
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }
    
    public static function sanitizeInt($input) {
        return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }
    
    public static function sanitizeFloat($input) {
        return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    public static function validatePassword($password) {
        // Minimum 8 characters, at least one letter and one number
        return strlen($password) >= 8 && preg_match('/[A-Za-z]/', $password) && preg_match('/[0-9]/', $password);
    }
    
    public static function sanitizeFilename($filename) {
        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        return substr($filename, 0, 255);
    }
}
?>
