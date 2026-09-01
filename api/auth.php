<?php
/**
 * Authentication Functions
 * Handles password hashing, JWT token creation/verification, and email normalization
 */

// Get secret key from environment or use default
define('SECRET_KEY', getenv('SECRET_KEY') ?: 'your-secret-key-change-in-production');
define('TOKEN_EXPIRE_MINUTES', 60 * 24); // 24 hours
define('TOKEN_EXPIRE_SECONDS', TOKEN_EXPIRE_MINUTES * 60);

/**
 * Slugify text for use in URLs
 * Converts to lowercase and replaces non-alphanumeric characters with hyphens
 */
function slugify($text) {
    // Convert to lowercase
    $text = strtolower(trim($text));
    
    // Replace spaces and underscores with hyphens
    $text = preg_replace('/[\s_]+/', '-', $text);
    
    // Remove any non-alphanumeric characters except hyphens
    $text = preg_replace('/[^a-z0-9\-]/', '', $text);
    
    // Replace multiple consecutive hyphens with single hyphen
    $text = preg_replace('/-+/', '-', $text);
    
    // Remove leading/trailing hyphens
    $text = trim($text, '-');
    
    return $text;
}

/**
 * Hash password using bcrypt
 * More secure than password_hash with PASSWORD_BCRYPT
 */
function hash_password($password) {
    if (empty($password)) {
        throw new Exception('Password cannot be empty');
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against bcrypt hash
 */
function verify_password($password, $hash) {
    if (empty($password) || empty($hash)) {
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * Create JWT access token
 */
function create_access_token($user_id, $is_admin = false) {
    $issued_at = time();
    $expire = $issued_at + TOKEN_EXPIRE_SECONDS;
    
    $payload = [
        'user_id' => (int)$user_id,
        'is_admin' => (bool)$is_admin,
        'iat' => $issued_at,
        'exp' => $expire,
        'type' => 'access'
    ];
    
    return create_jwt($payload);
}

/**
 * Create JWT token from payload
 * Uses HS256 (HMAC with SHA-256)
 */
function create_jwt($payload) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    
    // Encode header and payload to base64url
    $header_encoded = base64url_encode(json_encode($header));
    $payload_encoded = base64url_encode(json_encode($payload));
    
    // Create signature
    $signature_input = "$header_encoded.$payload_encoded";
    $signature = hash_hmac('sha256', $signature_input, SECRET_KEY, true);
    $signature_encoded = base64url_encode($signature);
    
    return "$signature_input.$signature_encoded";
}

/**
 * Verify JWT token and return payload
 */
function verify_jwt($token) {
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
    
    // Verify signature
    $expected_signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", SECRET_KEY, true);
    $expected_signature_encoded = base64url_encode($expected_signature);
    
    if (!hash_equals($signature_encoded, $expected_signature_encoded)) {
        return false;
    }
    
    // Decode payload
    $payload_json = base64url_decode($payload_encoded);
    $payload = json_decode($payload_json, true);
    
    if ($payload === null) {
        return false;
    }
    
    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
}

/**
 * Normalize email (lowercase and trim)
 */
function normalized_email($email) {
    return strtolower(trim($email));
}

/**
 * Base64url encode (for JWT)
 */
function base64url_encode($data) {
    $b64 = base64_encode($data);
    // URL-safe base64
    return rtrim(strtr($b64, '+/', '-_'), '=');
}

/**
 * Base64url decode (for JWT)
 */
function base64url_decode($data) {
    // Add padding if needed
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    // URL-safe base64
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Validate email format
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 * Password must be at least 12 characters and contain:
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one number
 */
function is_valid_password($password) {
    if (strlen($password) < 12) {
        return false;
    }
    
    // Check for uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    
    // Check for lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    
    // Check for number
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    
    return true;
}
?>
