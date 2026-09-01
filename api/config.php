<?php
/**
 * Database Configuration & PDO Connection
 * Using PDO for better database abstraction and security
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't expose errors to clients

// Load local configuration without requiring an external dotenv dependency.
$env_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_readable($env_file)) {
    $env_values = parse_ini_file($env_file, false, INI_SCANNER_RAW);
    foreach ($env_values as $key => $value) {
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

// Database configuration
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_password = getenv('DB_PASSWORD') ?: '';
$db_name = getenv('DB_NAME') ?: 'nielekeze';
$db_port = getenv('DB_PORT') ?: 3306;

// PDO connection string
$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";

try {
    // Create PDO connection with error mode set to exceptions
    $conn = new PDO($dsn, $db_user, $db_password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database connection failed: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed', 'message' => $e->getMessage()]));
}

// Set response headers
header('Content-Type: application/json; charset=utf-8');

// CORS headers for frontend access
// Restrict CORS to allowed origins (configurable via environment)
$allowed_origins = getenv('ALLOWED_ORIGINS') ?: 'http://localhost'; // Default to localhost
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if origin is in allowed list
$allowed_origins_array = array_map('trim', explode(',', $allowed_origins));
if (in_array($origin, $allowed_origins_array, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} elseif (in_array('*', $allowed_origins_array, true)) {
    // Only allow wildcard if explicitly set in environment (not recommended for production)
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Max-Age: 3600');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Rate Limiting - Prevent brute force and abuse
 * Uses file-based tracking stored in temp directory
 */
function check_rate_limit($endpoint, $limit = 100, $window_minutes = 1) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rate_limit_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_rate_limits';
    
    // Create directory if it doesn't exist
    if (!is_dir($rate_limit_dir)) {
        @mkdir($rate_limit_dir, 0777, true);
    }
    
    $rate_key = md5($ip . ':' . $endpoint);
    $rate_file = $rate_limit_dir . DIRECTORY_SEPARATOR . $rate_key . '.json';
    $now = time();
    $window_seconds = $window_minutes * 60;
    
    // Load existing rate limit data
    $data = [];
    if (file_exists($rate_file)) {
        $data = json_decode(file_get_contents($rate_file), true) ?? [];
    }
    
    // Remove requests outside the time window
    $data['requests'] = array_filter($data['requests'] ?? [], function($timestamp) use ($now, $window_seconds) {
        return ($now - $timestamp) < $window_seconds;
    });
    
    // Check if limit exceeded
    if (count($data['requests'] ?? []) >= $limit) {
        return false; // Rate limit exceeded
    }
    
    // Add current request
    $data['requests'][] = $now;
    
    // Save updated data
    file_put_contents($rate_file, json_encode($data));
    
    return true; // Request allowed
}

/**
 * Helper function for rate limit responses
 */
function rate_limit_response($retry_after = 60) {
    http_response_code(429);
    header('Retry-After: ' . $retry_after);
    echo json_encode([
        'error' => 'Too many requests',
        'message' => 'Please try again later',
        'retry_after' => $retry_after
    ]);
    exit;
}

// Helper function for error responses
function error_response($message, $code = 400, $data = null) {
    http_response_code($code);
    $response = ['error' => $message];
    if ($data) {
        $response['details'] = $data;
    }
    die(json_encode($response));
}

// Helper function for success responses
function success_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function current_request_path() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($request_uri, PHP_URL_PATH) ?: '/';
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

    $base_path_length = strlen($base_path);
    if (
        $base_path &&
        $base_path !== '/' &&
        strncasecmp($path, $base_path, $base_path_length) === 0 &&
        (strlen($path) === $base_path_length || $path[$base_path_length] === '/')
    ) {
        $path = substr($path, strlen($base_path));
    }

    return '/' . ltrim($path, '/');
}

function request_path_matches($pattern) {
    $request_path = current_request_path();
    return preg_match($pattern, $request_path) === 1;
}
?>
