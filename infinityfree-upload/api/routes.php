<?php
/**
 * Routes API
 * Handles route search, retrieval, and management
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'auth_middleware.php';
require_once 'route_engine.php';

$request_path = current_request_path();

// Helper function to get route stops
function get_route_stops($conn, $route_id) {
    $query = "
        SELECT rs.id, rs.location_id, rs.stop_order, 
               l.id as loc_id, l.name, l.slug, l.latitude, l.longitude, l.location_type, l.description
        FROM route_stops rs
        JOIN locations l ON rs.location_id = l.id
        WHERE rs.route_id = :route_id
        ORDER BY rs.stop_order ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':route_id' => $route_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stops = [];
    foreach ($rows as $row) {
        $stops[] = [
            'id' => (int)$row['id'],
            'location_id' => (int)$row['location_id'],
            'stop_order' => (int)$row['stop_order'],
            'location' => [
                'id' => (int)$row['loc_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'location_type' => $row['location_type'],
                'description' => $row['description']
            ]
        ];
    }
    
    return $stops;
}

// Helper function to build complete route response
function build_route_response($conn, $route_row) {
    // Get origin location
    $origin_query = "
        SELECT id, name, slug, latitude, longitude, location_type, description 
        FROM locations 
        WHERE id = :id
    ";
    $origin_stmt = $conn->prepare($origin_query);
    $origin_stmt->execute([':id' => $route_row['origin_id']]);
    $origin = $origin_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get destination location
    $dest_stmt = $conn->prepare($origin_query);
    $dest_stmt->execute([':id' => $route_row['destination_id']]);
    $destination = $dest_stmt->fetch(PDO::FETCH_ASSOC);
    
    $stops = get_route_stops($conn, $route_row['id']);
    
    $origin_data = $origin ? [
        'id' => (int)$origin['id'],
        'name' => $origin['name'],
        'slug' => $origin['slug'],
        'latitude' => (float)$origin['latitude'],
        'longitude' => (float)$origin['longitude'],
        'location_type' => $origin['location_type'],
        'description' => $origin['description']
    ] : null;
    
    $dest_data = $destination ? [
        'id' => (int)$destination['id'],
        'name' => $destination['name'],
        'slug' => $destination['slug'],
        'latitude' => (float)$destination['latitude'],
        'longitude' => (float)$destination['longitude'],
        'location_type' => $destination['location_type'],
        'description' => $destination['description']
    ] : null;
    
    return [
        'id' => (int)$route_row['id'],
        'name' => $route_row['name'],
        'origin_id' => (int)$route_row['origin_id'],
        'destination_id' => (int)$route_row['destination_id'],
        'origin' => $origin_data,
        'destination' => $dest_data,
        'estimated_fare' => $route_row['estimated_fare'] ? (int)$route_row['estimated_fare'] : null,
        'via' => $route_row['via'],
        'status' => $route_row['status'],
        'verification_status' => $route_row['verification_status'],
        'source' => $route_row['source'],
        'last_verified_at' => $route_row['last_verified_at'],
        'stops' => $stops,
        'created_at' => $route_row['created_at'],
        'updated_at' => $route_row['updated_at']
    ];
}

// GET /api/v1/routes/search - Search routes from origin to destination
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($request_path, '/api/v1/routes/search') === 0) {
    $from_id = $_GET['from'] ?? null;
    $to_id = $_GET['to'] ?? null;
    
    if (!$from_id || !$to_id) {
        error_response('Missing from or to parameter', 400);
    }
    
    $from_id = (int)$from_id;
    $to_id = (int)$to_id;
    
    if ($from_id === $to_id) {
        error_response('Origin and destination must be different', 422);
    }
    
    try {
        // Check that both locations exist and are active
        $check_query = "SELECT id FROM locations WHERE id IN (:from_id, :to_id) AND is_active = 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':from_id' => $from_id, ':to_id' => $to_id]);
        
        if ($check_stmt->rowCount() < 2) {
            error_response('Origin or destination not found', 404);
        }
        
        // Get all active routes
        $routes_query = "
            SELECT id, name, origin_id, destination_id, estimated_fare, via, 
                   status, verification_status, source, last_verified_at, created_at, updated_at
            FROM routes
            WHERE status = 'ACTIVE'
        ";
        $routes_stmt = $conn->prepare($routes_query);
        $routes_stmt->execute();
        $routes = $routes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use route engine to find options
        $options = find_route_options($conn, $routes, $from_id, $to_id);
        
        // Get origin and destination details
        $location_query = "
            SELECT id, name, slug, latitude, longitude, location_type, description 
            FROM locations 
            WHERE id = :id
        ";
        
        $origin_stmt = $conn->prepare($location_query);
        $origin_stmt->execute([':id' => $from_id]);
        $origin = $origin_stmt->fetch(PDO::FETCH_ASSOC);
        
        $dest_stmt = $conn->prepare($location_query);
        $dest_stmt->execute([':id' => $to_id]);
        $destination = $dest_stmt->fetch(PDO::FETCH_ASSOC);
        
        $response = [
            'origin' => $origin ? [
                'id' => (int)$origin['id'],
                'name' => $origin['name'],
                'slug' => $origin['slug'],
                'latitude' => (float)$origin['latitude'],
                'longitude' => (float)$origin['longitude'],
                'location_type' => $origin['location_type'],
                'description' => $origin['description']
            ] : null,
            'destination' => $destination ? [
                'id' => (int)$destination['id'],
                'name' => $destination['name'],
                'slug' => $destination['slug'],
                'latitude' => (float)$destination['latitude'],
                'longitude' => (float)$destination['longitude'],
                'location_type' => $destination['location_type'],
                'description' => $destination['description']
            ] : null,
            'routes' => $options
        ];
        
        success_response($response);
        
    } catch (PDOException $e) {
        error_log("Route search error: " . $e->getMessage());
        error_response('Failed to search routes', 500);
    }
}

// GET /api/v1/routes/{id} - Get single route
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/v1\/routes\/(\d+)$/', $request_path, $matches)) {
    $route_id = (int)$matches[1];
    
    try {
        $query = "
            SELECT id, name, origin_id, destination_id, estimated_fare, via, 
                   status, verification_status, source, last_verified_at, created_at, updated_at
            FROM routes
            WHERE id = :id
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $route_id]);
        $route_row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$route_row) {
            error_response('Route not found', 404);
        }
        
        $route = build_route_response($conn, $route_row);
        success_response($route);
        
    } catch (PDOException $e) {
        error_log("Get route error: " . $e->getMessage());
        error_response('Failed to get route', 500);
    }
}

// GET /api/v1/routes - List all routes
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/v1\/routes\/?$/', $request_path)) {
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? 'ACTIVE';
    
    try {
        $query = "
            SELECT id, name, origin_id, destination_id, estimated_fare, via, 
                   status, verification_status, source, last_verified_at, created_at, updated_at
            FROM routes
            WHERE status = :status
            ORDER BY name
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $routes_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $routes = [];
        foreach ($routes_data as $route) {
            $routes[] = build_route_response($conn, $route);
        }
        
        success_response($routes);
        
    } catch (PDOException $e) {
        error_log("List routes error: " . $e->getMessage());
        error_response('Failed to list routes', 500);
    }
}

// POST /api/v1/routes - Create new route (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/^\/api\/v1\/routes\/?$/', $request_path)) {
    require_admin();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['name'], $data['origin_id'], $data['destination_id'])) {
        error_response('Missing required fields: name, origin_id, destination_id', 422);
    }
    
    $name = trim($data['name']);
    $origin_id = (int)$data['origin_id'];
    $destination_id = (int)$data['destination_id'];
    $estimated_fare = isset($data['estimated_fare']) ? (int)$data['estimated_fare'] : null;
    $via = isset($data['via']) ? trim($data['via']) : null;
    $status = $data['status'] ?? 'ACTIVE';
    $verification_status = $data['verification_status'] ?? 'UNVERIFIED';
    $source = $data['source'] ?? null;
    
    if (empty($name)) {
        error_response('Route name cannot be empty', 422);
    }
    
    try {
        // Validate both route endpoints are active locations.
        $check_query = "SELECT COUNT(*) FROM locations WHERE (id = :origin_id OR id = :destination_id) AND is_active = 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':origin_id' => $origin_id, ':destination_id' => $destination_id]);
        
        if ((int)$check_stmt->fetchColumn() < 2) {
            error_response('One or more locations not found', 404);
        }
        
        $conn->beginTransaction();
        
        // Create route
        $insert_query = "
            INSERT INTO routes (name, origin_id, destination_id, estimated_fare, via, status, verification_status, source)
            VALUES (:name, :origin_id, :destination_id, :estimated_fare, :via, :status, :verification_status, :source)
        ";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->execute([
            ':name' => $name,
            ':origin_id' => $origin_id,
            ':destination_id' => $destination_id,
            ':estimated_fare' => $estimated_fare,
            ':via' => $via,
            ':status' => $status,
            ':verification_status' => $verification_status,
            ':source' => $source
        ]);
        
        $route_id = $conn->lastInsertId();
        
        // Insert stops if provided
        if (isset($data['stops']) && is_array($data['stops'])) {
            // Validate all stop locations exist
            $stop_ids = array_map(function($stop) { return (int)$stop['location_id']; }, $data['stops']);
            if (!empty($stop_ids)) {
                $placeholders = implode(',', array_fill(0, count($stop_ids), '?'));
                $validate_stops = "SELECT COUNT(*) as count FROM locations WHERE id IN ($placeholders) AND is_active = 1";
                $validate_stmt = $conn->prepare($validate_stops);
                $validate_stmt->execute($stop_ids);
                $count_result = $validate_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($count_result['count'] != count($stop_ids)) {
                    throw new Exception('One or more route locations not found');
                }
            }
            
            // Insert stops
            $stop_insert = "
                INSERT INTO route_stops (route_id, location_id, stop_order)
                VALUES (:route_id, :location_id, :stop_order)
            ";
            $stop_stmt = $conn->prepare($stop_insert);
            
            foreach ($data['stops'] as $order => $stop) {
                $stop_stmt->execute([
                    ':route_id' => $route_id,
                    ':location_id' => (int)$stop['location_id'],
                    ':stop_order' => $order + 1
                ]);
            }
        }
        
        $conn->commit();
        
        // Return created route
        $get_query = "
            SELECT id, name, origin_id, destination_id, estimated_fare, via, 
                   status, verification_status, source, last_verified_at, created_at, updated_at
            FROM routes
            WHERE id = :id
        ";
        $get_stmt = $conn->prepare($get_query);
        $get_stmt->execute([':id' => $route_id]);
        $route_row = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        $route = build_route_response($conn, $route_row);
        success_response($route, 201);
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Create route error: " . $e->getMessage());
        error_response('Failed to create route', 500);
    }
}

// PUT /api/v1/routes/{id} - Update route (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('/^\/api\/v1\/routes\/(\d+)$/', $request_path, $matches)) {
    require_admin();
    
    $route_id = (int)$matches[1];
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        // Check route exists
        $check_query = "SELECT id FROM routes WHERE id = :id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':id' => $route_id]);
        
        if (!$check_stmt->fetch()) {
            error_response('Route not found', 404);
        }
        
        $conn->beginTransaction();
        
        // Update route fields
        $update_query = "UPDATE routes SET ";
        $updates = [];
        $params = [':id' => $route_id];
        
        if (isset($data['name'])) {
            $updates[] = "name = :name";
            $params[':name'] = trim($data['name']);
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (isset($data['verification_status'])) {
            $updates[] = "verification_status = :verification_status";
            $params[':verification_status'] = $data['verification_status'];
        }
        if (isset($data['estimated_fare'])) {
            $updates[] = "estimated_fare = :estimated_fare";
            $params[':estimated_fare'] = (int)$data['estimated_fare'];
        }
        if (isset($data['via'])) {
            $updates[] = "via = :via";
            $params[':via'] = trim($data['via']);
        }
        
        if (!empty($updates)) {
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            $update_query .= implode(', ', $updates) . " WHERE id = :id";
            
            $stmt = $conn->prepare($update_query);
            $stmt->execute($params);
        }
        
        // Update stops if provided
        if (isset($data['stops']) && is_array($data['stops'])) {
            // Delete old stops
            $delete_query = "DELETE FROM route_stops WHERE route_id = :route_id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->execute([':route_id' => $route_id]);
            
            // Insert new stops
            if (!empty($data['stops'])) {
                $stop_insert = "
                    INSERT INTO route_stops (route_id, location_id, stop_order)
                    VALUES (:route_id, :location_id, :stop_order)
                ";
                $stop_stmt = $conn->prepare($stop_insert);
                
                foreach ($data['stops'] as $order => $stop) {
                    $stop_stmt->execute([
                        ':route_id' => $route_id,
                        ':location_id' => (int)$stop['location_id'],
                        ':stop_order' => $order + 1
                    ]);
                }
            }
        }
        
        $conn->commit();
        
        $get_query = "
            SELECT id, name, origin_id, destination_id, estimated_fare, via, 
                   status, verification_status, source, last_verified_at, created_at, updated_at
            FROM routes
            WHERE id = :id
        ";
        $get_stmt = $conn->prepare($get_query);
        $get_stmt->execute([':id' => $route_id]);
        $route_row = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        $route = build_route_response($conn, $route_row);
        success_response($route);
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Update route error: " . $e->getMessage());
        error_response('Failed to update route', 500);
    }
}

// DELETE /api/v1/routes/{id} - Delete route (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('/^\/api\/v1\/routes\/(\d+)$/', $request_path, $matches)) {
    require_admin();
    
    $route_id = (int)$matches[1];
    
    try {
        $query = "DELETE FROM routes WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $route_id]);
        
        if ($stmt->rowCount() === 0) {
            error_response('Route not found', 404);
        }
        
        http_response_code(204);
        exit;
        
    } catch (PDOException $e) {
        error_log("Delete route error: " . $e->getMessage());
        error_response('Failed to delete route', 500);
    }
}
?>
