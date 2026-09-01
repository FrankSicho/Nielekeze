<?php
/**
 * Route Search Engine
 * Core algorithm for finding all possible routes from origin to destination
 * Handles 0, 1, and 2 transfers
 */

/**
 * Find a leg (connection) from start location to end location on a given route
 * Handles both forward and reverse directions on the route
 * 
 * @param PDO $conn Database connection
 * @param array $route Route data from database
 * @param int $start_id Starting location ID
 * @param int $end_id Ending location ID
 * @return array|null Leg data with route, locations, direction, or null if not found
 */
function find_leg($conn, $route, $start_id, $end_id) {
    try {
        // Get all stops on this route in order
        $stops_query = "
            SELECT rs.stop_order, rs.location_id, l.id, l.name, l.slug, l.latitude, l.longitude, l.location_type, l.description
            FROM route_stops rs
            JOIN locations l ON rs.location_id = l.id
            WHERE rs.route_id = :route_id
            ORDER BY rs.stop_order ASC
        ";
        
        $stmt = $conn->prepare($stops_query);
        $stmt->execute([':route_id' => $route['id']]);
        $stops_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($stops_data)) {
            // No stops found, but check if origin and destination are the route endpoints
            $route_query = "SELECT origin_id, destination_id FROM routes WHERE id = :id";
            $route_stmt = $conn->prepare($route_query);
            $route_stmt->execute([':id' => $route['id']]);
            $route_info = $route_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($route_info && $route_info['origin_id'] == $start_id && $route_info['destination_id'] == $end_id) {
                // Direct route without intermediate stops
                $loc_query = "
                    SELECT id, name, slug, latitude, longitude, location_type, description 
                    FROM locations 
                    WHERE id IN (:start_id, :end_id)
                    ORDER BY id
                ";
                $loc_stmt = $conn->prepare($loc_query);
                $loc_stmt->execute([':start_id' => $start_id, ':end_id' => $end_id]);
                $locations = $loc_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($locations) === 2) {
                    $location_data = array_map(function($loc) {
                        return [
                            'id' => (int)$loc['id'],
                            'name' => $loc['name'],
                            'slug' => $loc['slug'],
                            'latitude' => (float)$loc['latitude'],
                            'longitude' => (float)$loc['longitude'],
                            'location_type' => $loc['location_type'],
                            'description' => $loc['description']
                        ];
                    }, $locations);
                    
                    return [
                        'route' => $route,
                        'locations' => $location_data,
                        'direction' => 1,
                        'start_index' => 0,
                        'end_index' => 1
                    ];
                }
            }
            return null;
        }
        
        // Build location arrays for forward and reverse directions
        $location_ids = [];
        $location_data = [];
        
        foreach ($stops_data as $row) {
            $location_ids[] = (int)$row['location_id'];
            $location_data[] = [
                'id' => (int)$row['location_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'location_type' => $row['location_type'],
                'description' => $row['description']
            ];
        }
        
        // Try forward direction
        for ($start = 0; $start < count($location_ids); $start++) {
            if ($location_ids[$start] == $start_id) {
                for ($end = $start + 1; $end < count($location_ids); $end++) {
                    if ($location_ids[$end] == $end_id) {
                        return [
                            'route' => $route,
                            'locations' => array_slice($location_data, $start, $end - $start + 1),
                            'direction' => 1,
                            'start_index' => $start,
                            'end_index' => $end
                        ];
                    }
                }
            }
        }
        
        // Try reverse direction
        $reverse_ids = array_reverse($location_ids);
        $reverse_data = array_reverse($location_data);
        
        for ($start = 0; $start < count($reverse_ids); $start++) {
            if ($reverse_ids[$start] == $start_id) {
                for ($end = $start + 1; $end < count($reverse_ids); $end++) {
                    if ($reverse_ids[$end] == $end_id) {
                        return [
                            'route' => $route,
                            'locations' => array_slice($reverse_data, $start, $end - $start + 1),
                            'direction' => -1,
                            'start_index' => $start,
                            'end_index' => $end
                        ];
                    }
                }
            }
        }
        
        return null;
        
    } catch (PDOException $e) {
        error_log("find_leg error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if a path is simple (no duplicate locations)
 */
function is_simple_path($option) {
    $seen_ids = [];
    foreach ($option['legs'] as $leg) {
        foreach ($leg['locations'] as $location) {
            if (in_array($location['id'], $seen_ids)) {
                return false; // Duplicate location found
            }
            $seen_ids[] = $location['id'];
        }
    }
    return true;
}

/**
 * Check if direction is consistent across all legs
 */
function is_consistent_direction($option) {
    if (empty($option['legs'])) {
        return true;
    }
    
    $first_direction = $option['legs'][0]['direction'];
    foreach ($option['legs'] as $leg) {
        if ($leg['direction'] !== $first_direction) {
            return false;
        }
    }
    return true;
}

/**
 * Main route search algorithm
 * Finds all possible routes from origin to destination with 0, 1, or 2 transfers
 */
function find_route_options($conn, $routes, $origin_id, $destination_id) {
    $all_options = [];
    
    try {
        // 1. Direct routes (0 transfers)
        foreach ($routes as $route) {
            $leg = find_leg($conn, $route, $origin_id, $destination_id);
            if ($leg) {
                $all_options[] = [
                    'legs' => [$leg],
                    'transfers' => 0
                ];
            }
        }
        
        // 2. Single transfers (1 transfer, 2 routes)
        for ($i = 0; $i < count($routes); $i++) {
            for ($j = 0; $j < count($routes); $j++) {
                if ($i === $j) continue;
                
                $first_route = $routes[$i];
                $second_route = $routes[$j];
                
                // Get all stops on first route
                $first_stops_query = "
                    SELECT DISTINCT rs.location_id
                    FROM route_stops rs
                    WHERE rs.route_id = :route_id
                ";
                $stmt = $conn->prepare($first_stops_query);
                $stmt->execute([':route_id' => $first_route['id']]);
                $first_stops_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $first_stops = array_map(function($row) { return (int)$row['location_id']; }, $first_stops_result);
                
                // Also check route endpoints
                $first_stops[] = (int)$first_route['origin_id'];
                $first_stops[] = (int)$first_route['destination_id'];
                $first_stops = array_unique($first_stops);
                
                // Try each transfer point
                foreach ($first_stops as $transfer_id) {
                    if ($transfer_id == $origin_id || $transfer_id == $destination_id) {
                        continue;
                    }
                    
                    $first_leg = find_leg($conn, $first_route, $origin_id, $transfer_id);
                    $second_leg = find_leg($conn, $second_route, $transfer_id, $destination_id);
                    
                    if ($first_leg && $second_leg) {
                        $option = [
                            'legs' => [$first_leg, $second_leg],
                            'transfers' => 1
                        ];
                        
                        if (is_simple_path($option) && is_consistent_direction($option)) {
                            $all_options[] = $option;
                        }
                    }
                }
            }
        }
        
        // 3. Two transfers (2 transfers, 3 routes)
        for ($i = 0; $i < count($routes); $i++) {
            for ($j = 0; $j < count($routes); $j++) {
                if ($i === $j) continue;
                
                for ($k = 0; $k < count($routes); $k++) {
                    if ($k === $i || $k === $j) continue;
                    
                    $first = $routes[$i];
                    $second = $routes[$j];
                    $third = $routes[$k];
                    
                    // Get stops from each route
                    $get_stops = function($route_id) use ($conn) {
                        $query = "
                            SELECT DISTINCT rs.location_id
                            FROM route_stops rs
                            WHERE rs.route_id = :route_id
                        ";
                        $stmt = $conn->prepare($query);
                        $stmt->execute([':route_id' => $route_id]);
                        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $stops = array_map(function($row) { return (int)$row['location_id']; }, $result);
                        
                        // Get route data
                        $route_query = "SELECT origin_id, destination_id FROM routes WHERE id = :id";
                        $route_stmt = $conn->prepare($route_query);
                        $route_stmt->execute([':id' => $route_id]);
                        $route_info = $route_stmt->fetch(PDO::FETCH_ASSOC);
                        if ($route_info) {
                            $stops[] = (int)$route_info['origin_id'];
                            $stops[] = (int)$route_info['destination_id'];
                        }
                        
                        return array_unique($stops);
                    };
                    
                    $first_stops = $get_stops($first['id']);
                    $second_stops = $get_stops($second['id']);
                    
                    // Try combinations of transfer points
                    foreach ($first_stops as $transfer_one) {
                        if ($transfer_one == $origin_id || $transfer_one == $destination_id) {
                            continue;
                        }
                        
                        $first_leg = find_leg($conn, $first, $origin_id, $transfer_one);
                        if (!$first_leg) continue;
                        
                        foreach ($second_stops as $transfer_two) {
                            if ($transfer_two == $origin_id || $transfer_two == $destination_id || $transfer_two == $transfer_one) {
                                continue;
                            }
                            
                            $second_leg = find_leg($conn, $second, $transfer_one, $transfer_two);
                            $third_leg = find_leg($conn, $third, $transfer_two, $destination_id);
                            
                            if ($second_leg && $third_leg) {
                                $option = [
                                    'legs' => [$first_leg, $second_leg, $third_leg],
                                    'transfers' => 2
                                ];
                                
                                if (is_simple_path($option) && is_consistent_direction($option)) {
                                    $all_options[] = $option;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Remove duplicate route combinations
        $seen_routes = [];
        $unique_options = [];
        
        foreach ($all_options as $option) {
            $route_ids = array_map(function($leg) { return $leg['route']['id']; }, $option['legs']);
            sort($route_ids);
            $route_combo_key = implode(',', $route_ids);
            
            if (!in_array($route_combo_key, $seen_routes)) {
                $seen_routes[] = $route_combo_key;
                $unique_options[] = $option;
            }
        }
        
        // Format response
        $response_options = [];
        foreach ($unique_options as $option) {
            $all_locations = [];
            $total_fare = 0;
            $all_route_ids = [];
            
            // Combine locations and calculate total fare
            foreach ($option['legs'] as $leg) {
                $all_locations = array_merge($all_locations, $leg['locations']);
                $fare = isset($leg['route']['estimated_fare']) ? (int)$leg['route']['estimated_fare'] : 0;
                $total_fare += $fare;
                $all_route_ids[] = (int)$leg['route']['id'];
            }
            
            // Remove duplicate locations while preserving order
            $seen_ids = [];
            $unique_locations = [];
            foreach ($all_locations as $loc) {
                if (!in_array($loc['id'], $seen_ids)) {
                    $seen_ids[] = $loc['id'];
                    $unique_locations[] = $loc;
                }
            }
            
            // Build segments
            $segments = [];
            foreach ($option['legs'] as $leg) {
                $segments[] = [
                    'route_id' => (int)$leg['route']['id'],
                    'route_name' => $leg['route']['name'],
                    'via' => $leg['route']['via'] ?? null,
                    'stops' => $leg['locations'],
                    'estimated_fare' => $leg['route']['estimated_fare'] ? (int)$leg['route']['estimated_fare'] : null
                ];
            }
            
            // Get verification statuses
            $statuses = array_map(function($leg) {
                return $leg['route']['verification_status'] ?? 'UNVERIFIED';
            }, $option['legs']);
            $verification_status = count(array_unique($statuses)) === 1 ? $statuses[0] : 'MIXED';
            
            $response_options[] = [
                'route_ids' => $all_route_ids,
                'via' => $option['legs'][0]['route']['via'] ?? null,
                'stops' => $unique_locations,
                'segments' => $segments,
                'estimated_fare' => $total_fare > 0 ? $total_fare : null,
                'currency' => 'TZS',
                'transfers' => $option['transfers'],
                'legs' => count($option['legs']),
                'verification_status' => $verification_status
            ];
        }
        
        return $response_options;
        
    } catch (PDOException $e) {
        error_log("find_route_options error: " . $e->getMessage());
        return [];
    }
}
?>
