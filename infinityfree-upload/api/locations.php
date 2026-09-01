<?php
/**
 * Locations API
 * Handles location search, retrieval, and management
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'auth_middleware.php';

// Helper function to get location with aliases
function get_location_with_aliases($conn, $location_id) {
    $query = "
        SELECT id, name, slug, latitude, longitude, location_type, description, is_active, created_at, updated_at
        FROM locations
        WHERE id = :id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':id' => $location_id]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        return null;
    }
    
    // Get aliases
    $aliases_query = "
        SELECT name FROM location_aliases 
        WHERE location_id = :location_id 
        ORDER BY name
    ";
    $aliases_stmt = $conn->prepare($aliases_query);
    $aliases_stmt->execute([':location_id' => $location_id]);
    $aliases = $aliases_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    return [
        'id' => (int)$location['id'],
        'name' => $location['name'],
        'slug' => $location['slug'],
        'latitude' => $location['latitude'] ? (float)$location['latitude'] : null,
        'longitude' => $location['longitude'] ? (float)$location['longitude'] : null,
        'location_type' => $location['location_type'],
        'description' => $location['description'],
        'aliases' => $aliases,
        'is_active' => (bool)$location['is_active'],
        'created_at' => $location['created_at'],
        'updated_at' => $location['updated_at']
    ];
}

$request_path = current_request_path();

// GET /api/v1/locations/search - Search locations
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($request_path, '/api/v1/locations/search') === 0) {
    $q = $_GET['q'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    if (strlen($q) < 1) {
        error_response('Search query too short', 400);
    }
    
    $search_term = "%$q%";
    
    try {
        $query = "
            SELECT DISTINCT l.id, l.name, l.slug, l.latitude, l.longitude, l.location_type, l.description, l.is_active, l.created_at, l.updated_at
            FROM locations l
            LEFT JOIN location_aliases la ON l.id = la.location_id
            WHERE l.is_active = 1 AND (l.name LIKE :location_search OR la.name LIKE :alias_search)
            ORDER BY l.name
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':location_search', $search_term, PDO::PARAM_STR);
        $stmt->bindValue(':alias_search', $search_term, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $locations_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $locations = [];
        foreach ($locations_data as $location) {
            $locations[] = get_location_with_aliases($conn, $location['id']);
        }
        
        success_response($locations);
        
    } catch (PDOException $e) {
        error_log("Search locations error: " . $e->getMessage());
        error_response('Failed to search locations', 500);
    }
}

// GET /api/v1/locations - List all locations
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/v1\/locations\/?$/', $request_path)) {
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $query = "
            SELECT id, name, slug, latitude, longitude, location_type, description, is_active, created_at, updated_at
            FROM locations
            WHERE is_active = 1
            ORDER BY name
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $locations_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $locations = [];
        foreach ($locations_data as $location) {
            $locations[] = get_location_with_aliases($conn, $location['id']);
        }
        
        success_response($locations);
        
    } catch (PDOException $e) {
        error_log("List locations error: " . $e->getMessage());
        error_response('Failed to list locations', 500);
    }
}

// GET /api/v1/locations/{id} - Get single location
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/v1\/locations\/(\d+)$/', $request_path, $matches)) {
    $location_id = (int)$matches[1];
    
    try {
        $location = get_location_with_aliases($conn, $location_id);
        
        if (!$location) {
            error_response('Location not found', 404);
        }
        
        success_response($location);
        
    } catch (PDOException $e) {
        error_log("Get location error: " . $e->getMessage());
        error_response('Failed to get location', 500);
    }
}

// POST /api/v1/locations - Create new location (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/^\/api\/v1\/locations\/?$/', $request_path)) {
    require_admin();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['name'])) {
        error_response('Missing required field: name', 422);
    }
    
    $name = trim($data['name']);
    $slug = isset($data['slug']) ? trim($data['slug']) : slugify($name);
    $latitude = isset($data['latitude']) ? (float)$data['latitude'] : null;
    $longitude = isset($data['longitude']) ? (float)$data['longitude'] : null;
    $location_type = $data['location_type'] ?? null;
    $description = $data['description'] ?? null;
    $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
    
    if (empty($name)) {
        error_response('Location name cannot be empty', 422);
    }
    
    try {
        $conn->beginTransaction();
        
        $insert_query = "
            INSERT INTO locations (name, slug, latitude, longitude, location_type, description, is_active)
            VALUES (:name, :slug, :latitude, :longitude, :location_type, :description, :is_active)
        ";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':location_type' => $location_type,
            ':description' => $description,
            ':is_active' => $is_active ? 1 : 0
        ]);
        
        $location_id = $conn->lastInsertId();
        
        // Insert aliases if provided
        if (isset($data['aliases']) && is_array($data['aliases'])) {
            $alias_query = "
                INSERT INTO location_aliases (location_id, name)
                VALUES (:location_id, :name)
            ";
            $alias_stmt = $conn->prepare($alias_query);
            
            foreach ($data['aliases'] as $alias) {
                $alias_name = trim($alias);
                if (!empty($alias_name)) {
                    $alias_stmt->execute([
                        ':location_id' => $location_id,
                        ':name' => $alias_name
                    ]);
                }
            }
        }
        
        $conn->commit();
        
        // Return created location
        $location = get_location_with_aliases($conn, $location_id);
        success_response($location, 201);
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Create location error: " . $e->getMessage());
        if ($e->getCode() === '23000') {
            error_response('A location with this slug already exists. Edit the existing location or choose a unique slug.', 409);
        }
        error_response('Failed to create location', 500);
    }
}

// PUT /api/v1/locations/{id} - Update location (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('/^\/api\/v1\/locations\/(\d+)$/', $request_path, $matches)) {
    require_admin();
    
    $location_id = (int)$matches[1];
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        // Check location exists
        $check_query = "SELECT id FROM locations WHERE id = :id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':id' => $location_id]);
        
        if (!$check_stmt->fetch()) {
            error_response('Location not found', 404);
        }
        
        $conn->beginTransaction();
        
        // Update location fields
        $update_query = "UPDATE locations SET ";
        $updates = [];
        $params = [':id' => $location_id];
        
        if (isset($data['name'])) {
            $updates[] = "name = :name";
            $params[':name'] = trim($data['name']);
        }
        if (isset($data['slug'])) {
            $updates[] = "slug = :slug";
            $params[':slug'] = trim($data['slug']);
        }
        if (isset($data['latitude'])) {
            $updates[] = "latitude = :latitude";
            $params[':latitude'] = (float)$data['latitude'];
        }
        if (isset($data['longitude'])) {
            $updates[] = "longitude = :longitude";
            $params[':longitude'] = (float)$data['longitude'];
        }
        if (isset($data['location_type'])) {
            $updates[] = "location_type = :location_type";
            $params[':location_type'] = $data['location_type'];
        }
        if (isset($data['description'])) {
            $updates[] = "description = :description";
            $params[':description'] = $data['description'];
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = :is_active";
            $params[':is_active'] = (bool)$data['is_active'] ? 1 : 0;
        }
        
        if (!empty($updates)) {
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            $update_query .= implode(', ', $updates) . " WHERE id = :id";
            
            $stmt = $conn->prepare($update_query);
            $stmt->execute($params);
        }
        
        // Update aliases if provided
        if (isset($data['aliases']) && is_array($data['aliases'])) {
            // Delete old aliases
            $delete_query = "DELETE FROM location_aliases WHERE location_id = :location_id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->execute([':location_id' => $location_id]);
            
            // Insert new aliases
            if (!empty($data['aliases'])) {
                $alias_query = "
                    INSERT INTO location_aliases (location_id, name)
                    VALUES (:location_id, :name)
                ";
                $alias_stmt = $conn->prepare($alias_query);
                
                foreach ($data['aliases'] as $alias) {
                    $alias_name = trim($alias);
                    if (!empty($alias_name)) {
                        $alias_stmt->execute([
                            ':location_id' => $location_id,
                            ':name' => $alias_name
                        ]);
                    }
                }
            }
        }
        
        $conn->commit();
        
        $location = get_location_with_aliases($conn, $location_id);
        success_response($location);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Update location error: " . $e->getMessage());
        error_response('Failed to update location', 500);
    }
}

// DELETE /api/v1/locations/{id} - Delete location (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('/^\/api\/v1\/locations\/(\d+)$/', $request_path, $matches)) {
    require_admin();
    
    $location_id = (int)$matches[1];
    
    try {
        $query = "DELETE FROM locations WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $location_id]);
        
        if ($stmt->rowCount() === 0) {
            error_response('Location not found', 404);
        }
        
        http_response_code(204);
        exit;
        
    } catch (PDOException $e) {
        error_log("Delete location error: " . $e->getMessage());
        error_response('Failed to delete location', 500);
    }
}
?>
