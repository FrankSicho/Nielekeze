<?php
/**
 * Authentication Middleware
 * Handles token verification for protected endpoints
 */

function request_headers() {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !isset($headers['Authorization'])) {
        $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    return $headers;
}

/**
 * Extract and verify Bearer token from Authorization header
 * Returns payload on success, exits with 401 on failure
 */
function require_token() {
    $headers = request_headers();
    
    // Try different header formats
    $auth_header = null;
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $auth_header = $value;
            break;
        }
    }
    
    if (empty($auth_header)) {
        error_response('Missing authorization header', 401);
    }
    
    // Extract Bearer token
    if (!preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
        error_response('Invalid authorization header format. Use: Bearer <token>', 401);
    }
    
    $token = $matches[1];
    $payload = verify_jwt($token);
    
    if (!$payload) {
        error_response('Invalid or expired token', 401);
    }
    
    return $payload;
}

/**
 * Require authentication and admin privileges
 * Returns payload on success, exits with 403 on failure
 */
function require_admin() {
    $identity = require_token();
    
    if (!isset($identity['is_admin']) || !$identity['is_admin']) {
        error_response('Admin privileges required', 403);
    }
    
    return $identity;
}

/**
 * Optional authentication - return payload if token present, null if not
 */
function get_optional_current_user() {
    $headers = request_headers();
    
    $auth_header = null;
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $auth_header = $value;
            break;
        }
    }
    
    if (empty($auth_header)) {
        return null;
    }
    
    if (!preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
        return null;
    }
    
    $token = $matches[1];
    return verify_jwt($token);
}
?>
