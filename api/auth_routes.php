<?php
/**
 * Authentication Routes/Endpoints
 * Handles user registration, login, and profile management
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'auth_middleware.php';

$request_path = current_request_path();

// POST /api/v1/auth/register - Register new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request_path === '/api/v1/auth/register') {
    // Rate limiting: 10 registrations per hour per IP
    if (!check_rate_limit('auth:register', 10, 60)) {
        rate_limit_response(3600); // Retry after 1 hour
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($data['email'], $data['password'])) {
        error_response('Missing email or password', 422);
    }
    
    $email = normalized_email($data['email']);
    $password = $data['password'];
    
    // Validate email format
    if (!is_valid_email($email)) {
        error_response('Invalid email format', 422);
    }
    
    // Validate password strength
    if (!is_valid_password($password)) {
        error_response('Password must be at least 6 characters long', 422);
    }
    
    try {
        // Check if user already exists
        $check_query = "SELECT id FROM users WHERE email = :email";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':email' => $email]);
        
        if ($check_stmt->fetch()) {
            error_response('Email already registered', 409);
        }
        
        // Hash password and create user
        $password_hash = hash_password($password);
        
        $insert_query = "
            INSERT INTO users (email, password_hash, is_admin, is_active)
            VALUES (:email, :password_hash, :is_admin, :is_active)
        ";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $password_hash,
            ':is_admin' => 0,
            ':is_active' => 1
        ]);
        
        $user_id = $conn->lastInsertId();
        
        // Create token
        $token = create_access_token($user_id, false);
        
        $response = [
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => (int)$user_id,
                'email' => $email,
                'is_admin' => false,
                'is_active' => true
            ]
        ];
        
        success_response($response, 201);
        
    } catch (PDOException $e) {
        error_log("Register error: " . $e->getMessage());
        error_response('Failed to register user', 500);
    }
}

// POST /api/v1/auth/login - Login user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request_path === '/api/v1/auth/login') {
    // Rate limiting: 5 login attempts per minute per IP to prevent brute force
    if (!check_rate_limit('auth:login', 5, 1)) {
        rate_limit_response(60); // Retry after 1 minute
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($data['email'], $data['password'])) {
        error_response('Missing email or password', 422);
    }
    
    $email = normalized_email($data['email']);
    $password = $data['password'];
    
    try {
        // Find user
        $query = "
            SELECT id, email, password_hash, is_admin, is_active 
            FROM users 
            WHERE email = :email
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            error_response('Invalid credentials', 401);
        }
        
        // Check if user is active
        if (!$user['is_active']) {
            error_response('User account is inactive', 401);
        }
        
        // Verify password
        if (!verify_password($password, $user['password_hash'])) {
            error_response('Invalid credentials', 401);
        }
        
        // Create token
        $token = create_access_token($user['id'], (bool)$user['is_admin']);
        
        $response = [
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'is_admin' => (bool)$user['is_admin'],
                'is_active' => (bool)$user['is_active']
            ]
        ];
        
        success_response($response);
        
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        error_response('Login failed', 500);
    }
}

// GET /api/v1/auth/me - Get current user info (requires token)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $request_path === '/api/v1/auth/me') {
    $identity = require_token();
    
    try {
        $query = "
            SELECT id, email, is_admin, is_active, created_at, updated_at 
            FROM users 
            WHERE id = :user_id
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':user_id' => $identity['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            error_response('User not found', 404);
        }
        
        $response = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'is_admin' => (bool)$user['is_admin'],
            'is_active' => (bool)$user['is_active'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ];
        
        success_response($response);
        
    } catch (PDOException $e) {
        error_log("Get current user error: " . $e->getMessage());
        error_response('Failed to get user info', 500);
    }
}

// POST /api/v1/auth/logout - Logout user (no-op for JWT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request_path === '/api/v1/auth/logout') {
    require_token();
    
    // JWT tokens are stateless, so logout is just a client-side operation
    // Server responds with success
    success_response(['message' => 'Logged out successfully']);
}

// POST /api/v1/auth/register-admin - Create the first admin with the setup secret;
// subsequent admin accounts require an existing administrator token.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request_path === '/api/v1/auth/register-admin') {
    $data = json_decode(file_get_contents('php://input'), true);

    $admin_count_stmt = $conn->query('SELECT COUNT(*) FROM users WHERE is_admin = 1');
    $admin_exists = (int)$admin_count_stmt->fetchColumn() > 0;

    if ($admin_exists) {
        require_admin();
    } else {
        $setup_secret = getenv('ADMIN_SETUP_SECRET');
        $provided_secret = $data['setup_secret'] ?? '';

        if (empty($setup_secret) || !is_string($provided_secret) || !hash_equals($setup_secret, $provided_secret)) {
            error_response('Invalid administrator setup secret', 403);
        }
    }
    
    // Validate input
    if (!isset($data['email'], $data['password'])) {
        error_response('Missing email or password', 422);
    }
    
    $email = normalized_email($data['email']);
    $password = $data['password'];
    
    // Validate email format
    if (!is_valid_email($email)) {
        error_response('Invalid email format', 422);
    }
    
    // Validate password strength
    if (!is_valid_password($password)) {
        error_response('Password must be at least 12 characters with uppercase, lowercase, and number', 422);
    }
    
    try {
        // Check if user already exists
        $check_query = "SELECT id FROM users WHERE email = :email";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':email' => $email]);
        
        if ($check_stmt->fetch()) {
            error_response('Email already registered', 409);
        }
        
        // Hash password and create admin user
        $password_hash = hash_password($password);
        
        $insert_query = "
            INSERT INTO users (email, password_hash, is_admin, is_active)
            VALUES (:email, :password_hash, :is_admin, :is_active)
        ";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $password_hash,
            ':is_admin' => 1,
            ':is_active' => 1
        ]);
        
        $user_id = $conn->lastInsertId();
        
        // Create token
        $token = create_access_token($user_id, true);
        
        $response = [
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => (int)$user_id,
                'email' => $email,
                'is_admin' => true,
                'is_active' => true
            ]
        ];
        
        success_response($response, 201);
        
    } catch (PDOException $e) {
        error_log("Register admin error: " . $e->getMessage());
        error_response('Failed to register admin user', 500);
    }
}

// PUT /api/v1/auth/me - Update current user (requires token)
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $request_path === '/api/v1/auth/me') {
    $identity = require_token();
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $updates = [];
        $params = [':user_id' => $identity['user_id']];
        
        // Allow password change
        if (isset($data['password'])) {
            $password = $data['password'];
            if (!is_valid_password($password)) {
                error_response('Password must be at least 12 characters with uppercase, lowercase, and number', 422);
            }
            $updates[] = "password_hash = :password_hash";
            $params[':password_hash'] = hash_password($password);
        }
        
        if (!empty($updates)) {
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            $update_query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :user_id";
            
            $stmt = $conn->prepare($update_query);
            $stmt->execute($params);
        }
        
        // Return updated user
        $get_query = "
            SELECT id, email, is_admin, is_active, created_at, updated_at 
            FROM users 
            WHERE id = :user_id
        ";
        $get_stmt = $conn->prepare($get_query);
        $get_stmt->execute([':user_id' => $identity['user_id']]);
        $user = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        $response = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'is_admin' => (bool)$user['is_admin'],
            'is_active' => (bool)$user['is_active'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ];
        
        success_response($response);
        
    } catch (PDOException $e) {
        error_log("Update current user error: " . $e->getMessage());
        error_response('Failed to update user info', 500);
    }
}

// GET /api/v1/auth/users - List all users (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $request_path === '/api/v1/auth/users') {
    require_admin();
    
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $query = "
            SELECT id, email, is_admin, is_active, created_at, updated_at
            FROM users
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $users = [];
        foreach ($users_data as $user) {
            $users[] = [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'is_admin' => (bool)$user['is_admin'],
                'is_active' => (bool)$user['is_active'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at']
            ];
        }
        
        success_response($users);
        
    } catch (PDOException $e) {
        error_log("List users error: " . $e->getMessage());
        error_response('Failed to list users', 500);
    }
}
?>
