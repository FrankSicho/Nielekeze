<?php
/**
 * Main Router & Entry Point
 * Handles API routing and serves frontend files
 */

// Include all necessary files
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/auth_middleware.php';
require_once __DIR__ . '/api/route_engine.php';

// Get the request path
$request_uri = $_SERVER['REQUEST_URI'];

// Remove query string if present
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove leading slash if present
$path = ltrim($path, '/');

// Health check endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($path === 'api/v1/health' || $path === 'health')) {
    success_response(['status' => 'ok', 'timestamp' => date('c')]);
}

// API Routes - Auth
if (strpos($path, 'api/v1/auth') === 0) {
    require_once __DIR__ . '/api/auth_routes.php';
}

// API Routes - Locations
if (strpos($path, 'api/v1/locations') === 0) {
    require_once __DIR__ . '/api/locations.php';
}

// API Routes - Routes
if (strpos($path, 'api/v1/routes') === 0) {
    require_once __DIR__ . '/api/routes.php';
}

// Serve frontend files
$frontend_dir = __DIR__ . '/frontend';

// Map routes to HTML files
$route_map = [
    '' => 'index.html',
    'index' => 'index.html',
    'index.html' => 'index.html',
    'route' => 'route.html',
    'route.html' => 'route.html',
    'admin' => 'admin.html',
    'admin.html' => 'admin.html',
    'setup-admin' => 'setup-admin.html',
    'setup-admin.html' => 'setup-admin.html',
];

// Check if it's a static file request
if (preg_match('/\.(js|css|json|ico|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/', $path)) {
    $file = $frontend_dir . '/' . $path;
    if (file_exists($file)) {
        // Set appropriate headers based on file type
        $mime_types = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (isset($mime_types[$ext])) {
            header('Content-Type: ' . $mime_types[$ext]);
        }
        
        // Add caching headers for static files
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }
}

// Serve HTML files
if (isset($route_map[$path])) {
    $file = $frontend_dir . '/' . $route_map[$path];
    if (file_exists($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

// Check if file exists in frontend directory as-is
if (file_exists($frontend_dir . '/' . $path)) {
    $file = $frontend_dir . '/' . $path;
    
    // Determine MIME type using finfo (mime_content_type is deprecated in PHP 8.1+)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file);
    finfo_close($finfo);
    
    if ($mime_type) {
        header('Content-Type: ' . $mime_type);
    }
    
    readfile($file);
    exit;
}

// Default to index.html for SPA routing
if (!preg_match('/^api/', $path)) {
    $index_file = $frontend_dir . '/index.html';
    if (file_exists($index_file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index_file);
        exit;
    }
}

// 404 Not Found
http_response_code(404);
echo json_encode(['error' => 'Not found']);
exit;
?>
