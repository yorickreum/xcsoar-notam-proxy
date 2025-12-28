<?php

// This script reads NOTAMs via the FAA machine interface 
// and returns them.
//
// Original author: Markus Sachs, ms@squawk-vfr.de
// Written for Enroute Flight Navigation, Jan 2024.
// Adapted for XCSoar by Yorick Reum, December 2025.
//
// Data source information:
// https://www.faa.gov/
//
// Usage: [server]/notams.php?locationLongitude=a&locationLatitude=b&locationRadius=c
// with
// a = longitude of the search center (decimal point)
// b = latitude of the search center (decimal point)
// c = search radius [units?]
// Optional: append &pageSize=d; default is 1000.

// Function to get database connection
function getDbConnection() {
    $host = getenv('DB_SERVER') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: 'notamcache';
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// Function to clean up old cache entries and aggregate metrics
function performMaintenance($pdo) {
    // Delete expired cache entries
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE expiration < NOW()");
    $stmt->execute();

    // Aggregate metrics older than 30 days
    $stmt = $pdo->prepare("
        INSERT INTO cache_metrics_monthly (year, month, metric, count)
        SELECT YEAR(date), MONTH(date), metric, SUM(count)
        FROM cache_metrics
        WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY YEAR(date), MONTH(date), metric
        ON DUPLICATE KEY UPDATE count = count + VALUES(count)
    ");
    $stmt->execute();

    // Delete aggregated daily metrics
    $stmt = $pdo->prepare("DELETE FROM cache_metrics WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
}

// Log cache metrics
function logCacheMetrics($pdo, $isHit) {
    $metric = $isHit ? 'hit' : 'miss';
    $stmt = $pdo->prepare("INSERT INTO cache_metrics (metric, count, date) 
                           VALUES (?, 1, CURDATE())
                           ON DUPLICATE KEY UPDATE count = count + 1");
    $stmt->execute([$metric]);

    // Perform maintenance operations occasionally (e.g., 1% of the time)
    if (rand(1, 100) == 1) {
        performMaintenance($pdo);
    }
}

// Function to get cached data or fetch from API
function getCachedOrFreshData($pdo, $url, $opts, $pageSize, $cacheTime = 3600) {
    // Generate a unique cache key based on the URL
    $cacheKey = 'notam_' . md5($url);

    // Try to fetch from cache
    $stmt = $pdo->prepare("SELECT cache_value FROM notam_cache WHERE cache_key = ? AND expiration > NOW()");
    $stmt->execute([$cacheKey]);
    $result = $stmt->fetch();

    if ($result) {
        // Data found in cache
        logCacheMetrics($pdo, true); // Cache hit
        return $result['cache_value'];
    }

    logCacheMetrics($pdo, false); // Cache miss

    // If not in cache or expired, fetch from API
    $response = getNotamsFromFaa($url, $opts, $pageSize);
    if ($response === false) {
        $error_message = "Failed to get data from FAA API. Status code: $status_code";
        if ($status_code >= 500) {
            error_log("Server error when accessing FAA API: $status_code");
        } elseif ($status_code == 404) {
            error_log("Resource not found on FAA API: $url");
        }
        throw new Exception($error_message);
    }

    // Store in cache
    $stmt = $pdo->prepare("INSERT INTO notam_cache (cache_key, cache_value, expiration) 
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE 
                           cache_value = VALUES(cache_value), 
                           expiration = VALUES(expiration)");
    $stmt->execute([$cacheKey, $response, $cacheTime]);

    return $response;
}

function getNotamsFromFaa($url, $opts, $pageSize){
    $allItems = [];
    $pageNum = 1;
    $hasMorePages = true;

    while ($hasMorePages) {
        $paginatedUrl = $url . '&pageNum=' . $pageNum;

        // Make the request
        $context = stream_context_create($opts);
        $response = @file_get_contents($paginatedUrl, false, $context);

        // Get the status code
        $status_code = 0;
        if (isset($http_response_header[0])) {
            preg_match('/\d{3}/', $http_response_header[0], $matches);
            $status_code = intval($matches[0]);
        }

        // Handle any error in the response
        if ($response === false || $status_code < 200 || $status_code >= 300) {
            $error_message = "Failed to get data from FAA API. Status code: $status_code";
            if ($status_code >= 500) {
                error_log("Server error when accessing FAA API: $status_code");
            } elseif ($status_code == 404) {
                error_log("Resource not found on FAA API: $paginatedUrl");
            }
            throw new Exception($error_message);
        }

        // Decode the JSON response
        $data = json_decode($response, true);
        if ($data === null) {
            throw new Exception("Failed to decode JSON response from FAA API");
        }

        // Append the items to the allItems array
        if (isset($data['items'])) {
            $allItems = array_merge($allItems, $data['items']);
        }

        // Check if there are more pages
        if (isset($data['pageNum']) && isset($data['totalPages'])) {
            $hasMorePages = $data['pageNum'] < $data['totalPages'];
            $pageNum++; // Move to the next page
        } else {
            // If the pagination info is missing, stop the loop
            $hasMorePages = false;
        }
    }

    // Construct the final response
    $finalResponse = [
        'pageSize' => $pageSize,
        'pageNum' => 1, // Since we're combining all pages, set the current page to 1
        'totalCount' => sizeof($allItems),
        'totalPages' => 1, // Since all data is combined into one response, totalPages is 1
        'items' => $allItems
    ];

    return json_encode($finalResponse);
}


function isValidLatitude($input) {
    // Validate latitude and longitude ranges
    if (isValidDegree($input) && $input >= -90 && $input <= 90) {
        return true;
    } else {
        return false;
    }
}

function isValidLongitude($input) {
    // Validate latitude and longitude ranges
    if (isValidDegree($input) && $input >= -180 && $input <= 180) {
        return true;
    } else {
        return false;
    }
}

function isValidDegree($input) {
    // Define the regular expression pattern for latitude and longitude
    $pattern = '/^(-?\d+(\.\d+)?)$/';

    // Use preg_match to check if the input string matches the pattern
    return preg_match($pattern, $input) === 1;
}

function isValidRadius($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input < 500 && intval($input) == $input;
}

function isValidPageSize($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input <= 1000 && intval($input) == $input;
}


try {
    $pdo = getDbConnection();

    // Input validation and sanitization
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    $latitude = filter_input(INPUT_GET, 'locationLatitude', FILTER_VALIDATE_FLOAT);
    $radius = filter_input(INPUT_GET, 'locationRadius', FILTER_VALIDATE_INT);
    $pageSize = filter_input(INPUT_GET, 'pageSize', FILTER_VALIDATE_INT) ?: 1000;

    if (!isset($latitude) || !isset($longitude) || !$radius || !isValidLongitude($longitude) || !isValidLatitude($latitude) || !isValidRadius($radius) || !isValidPageSize($pageSize)) {
      throw new InvalidArgumentException("Invalid input parameters");
    }


    // Build request
    $url = 'https://external-api.faa.gov/notamapi/v1/notams?'
    . 'locationLongitude=' . $longitude
    . '&locationLatitude=' . $latitude
    . '&locationRadius=' . $radius
    . '&pageSize=' . $pageSize;

    $FAA_KEY = getenv('FAA_KEY');
    $FAA_ID  = getenv('FAA_ID');

    $opts = array(
        'http' => array(
            'header' => "client_id: $FAA_ID\r\n" .
                        "client_secret: $FAA_KEY\r\n"
        )
    );

    // Get data (cached or fresh)
    $response = getCachedOrFreshData($pdo, $url, $opts, $pageSize);
    if ($response === false) {
        throw new Exception("Failed to get NOTAM data from FAA API");
    }

    // Return data
    header('Content-Type: application/json');
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    // Log the error
    error_log($e->getMessage());
}

?>
