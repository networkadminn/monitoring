<?php
// =============================================================================
// includes/Validator.php - Centralized input validation and sanitization
// =============================================================================

class Validator {
    
    // Validate and sanitize URLs
    public static function validateUrl(string $url): string {
        $url = trim($url);
        
        // Basic URL format validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL format");
        }
        
        // Ensure scheme is present
        if (!preg_match('#^https?://#', $url)) {
            $url = 'https://' . $url;
        }
        
        // Additional validation
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL structure");
        }
        
        // Prevent localhost and private IPs in production
        if (defined('APP_ENV') && APP_ENV === 'production') {
            $host = $parsed['host'];
            if (in_array($host, ['localhost', '127.0.0.1', '::1']) || 
                filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_PRIVATE | FILTER_FLAG_RESERVED)) {
                throw new InvalidArgumentException("Private/localhost URLs not allowed in production");
            }
        }
        
        return filter_var($url, FILTER_SANITIZE_URL);
    }
    
    // Validate email addresses
    public static function validateEmail(string $email): string {
        $email = trim($email);
        if (empty($email)) {
            return '';
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format");
        }
        
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
    
    // Validate phone numbers for SMS
    public static function validatePhone(string $phone): string {
        $phone = trim($phone);
        if (empty($phone)) {
            return '';
        }
        
        // Remove all non-numeric characters except + for international format
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Basic validation - should start with + and be 10-15 digits
        if (!preg_match('/^\+[0-9]{10,15}$/', $phone)) {
            throw new InvalidArgumentException("Invalid phone number format. Use international format: +1234567890");
        }
        
        return $phone;
    }
    
    // Validate site names
    public static function validateSiteName(string $name): string {
        $name = trim($name);
        if (empty($name)) {
            throw new InvalidArgumentException("Site name cannot be empty");
        }
        
        if (strlen($name) > 255) {
            throw new InvalidArgumentException("Site name too long (max 255 characters)");
        }
        
        // Allow alphanumeric, spaces, hyphens, underscores, and common punctuation
        if (!preg_match('/^[a-zA-Z0-9\s\-_.()]+$/', $name)) {
            throw new InvalidArgumentException("Site name contains invalid characters");
        }
        
        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }
    
    // Validate check types
    public static function validateCheckType(string $type): string {
        $validTypes = ['http', 'ssl', 'port', 'dns', 'keyword', 'ping', 'api'];
        $type = strtolower(trim($type));
        
        if (!in_array($type, $validTypes)) {
            throw new InvalidArgumentException("Invalid check type");
        }
        
        return $type;
    }
    
    // Validate port numbers
    public static function validatePort(int $port): int {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Port must be between 1 and 65535");
        }
        
        return $port;
    }
    
    // Validate timeout values
    public static function validateTimeout(int $timeout, int $min = 1, int $max = 300): int {
        if ($timeout < $min || $timeout > $max) {
            throw new InvalidArgumentException("Timeout must be between {$min} and {$max} seconds");
        }
        
        return $timeout;
    }
    
    // Validate integers
    public static function validateInt(mixed $value, string $fieldName, int $min = null, int $max = null): int {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("{$fieldName} must be a number");
        }
        
        $value = (int) $value;
        
        if ($min !== null && $value < $min) {
            throw new InvalidArgumentException("{$fieldName} must be at least {$min}");
        }
        
        if ($max !== null && $value > $max) {
            throw new InvalidArgumentException("{$fieldName} must be at most {$max}");
        }
        
        return $value;
    }
    
    // Validate boolean values
    public static function validateBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        
        return (bool) $value;
    }
    
    // Sanitize text input
    public static function sanitizeText(string $text, int $maxLength = 1000): string {
        $text = trim($text);
        
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException("Text too long (max {$maxLength} characters)");
        }
        
        // Remove potentially dangerous characters
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        return $text;
    }
    
    // Validate JSON input
    public static function validateJson(string $json): array {
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new InvalidArgumentException("JSON must be an object/array");
        }
        
        return $data;
    }
    
    // Validate date range
    public static function validateDateRange(?string $startDate, ?string $endDate): array {
        $now = new DateTime();
        
        if ($startDate) {
            try {
                $start = new DateTime($startDate);
                if ($start > $now) {
                    throw new InvalidArgumentException("Start date cannot be in the future");
                }
            } catch (Exception $e) {
                throw new InvalidArgumentException("Invalid start date format");
            }
        } else {
            $start = (clone $now)->modify('-30 days');
        }
        
        if ($endDate) {
            try {
                $end = new DateTime($endDate);
                if ($end > $now) {
                    throw new InvalidArgumentException("End date cannot be in the future");
                }
                if ($end < $start) {
                    throw new InvalidArgumentException("End date must be after start date");
                }
            } catch (Exception $e) {
                throw new InvalidArgumentException("Invalid end date format");
            }
        } else {
            $end = $now;
        }
        
        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s')
        ];
    }
}
