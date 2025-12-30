<?php

// This script reads NOTAMs via the FAA machine interface 
// and returns them.
//
// Original author: Markus Sachs, ms@squawk-vfr.de
// Written for Enroute Flight Navigation, Jan 2024.
// From: https://github.com/Akaflieg-Freiburg/enrouteProxy/
// Adapted for XCSoar by Yorick Reum, December 2025.
//
// Data source information:
// https://www.faa.gov/
//
// Might be possible to also add
// https://applications.icao.int/dataservices/default.aspx
// as a data source in the future.
//
// Usage (GET):
//   /notam.php?locationLongitude=a&locationLatitude=b&locationRadius=c
//   Aliases: lon/lat/radius
// Required:
//   a = longitude of the search center (decimal point)
//   b = latitude of the search center (decimal point)
//   c = search radius in nautical miles (0-100)
// Delta mode (POST JSON):
//   {"known": {"NOTAM_ID": "lastUpdated", "NOTAM_ID_2": "lastUpdated"}}
// Response includes only new/changed items plus "removedIds".

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

function purgeExpiredCache($pdo) {
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE expiration < NOW()");
    $stmt->execute();
}

function httpRequest($url, $opts) {
    $httpOpts = isset($opts['http']) && is_array($opts['http']) ? $opts['http'] : [];
    $method = strtoupper($httpOpts['method'] ?? 'GET');
    $headerStr = $httpOpts['header'] ?? '';
    $content = $httpOpts['content'] ?? null;
    $timeout = $httpOpts['timeout'] ?? 30;

    if (!function_exists('curl_init')) {
        return [false, 0, 'cURL extension not available'];
    }

    $headers = [];
    if (is_string($headerStr) && $headerStr !== '') {
        foreach (preg_split("/\r\n|\n|\r/", $headerStr) as $line) {
            if ($line !== '') {
                $headers[] = $line;
            }
        }
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($content !== null && $content !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    }

    $response = curl_exec($ch);
    $status_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error_message = null;
    if ($response === false) {
        $error_message = curl_error($ch);
    }
    curl_close($ch);

    return [$response, $status_code, $error_message];
}

function getAccessToken($pdo, $authUrl, $clientId, $clientSecret) {
    if (!$clientId || !$clientSecret) {
        throw new InvalidArgumentException("Missing FAA credentials");
    }

    $cacheKey = 'faa_token_' . md5($authUrl . '|' . $clientId);
    $stmt = $pdo->prepare("SELECT cache_value FROM notam_cache WHERE cache_key = ? AND expiration > NOW()");
    $stmt->execute([$cacheKey]);
    $result = $stmt->fetch();

    if ($result) {
        return $result['cache_value'];
    }

    $basicAuth = base64_encode($clientId . ':' . $clientSecret);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                        "Authorization: Basic $basicAuth\r\n",
            'content' => http_build_query(['grant_type' => 'client_credentials']),
            'timeout' => 30,
        ],
    ];

    [$response, $status_code, $request_error] = httpRequest($authUrl, $opts);
    if ($response === false || $status_code < 200 || $status_code >= 300) {
        $error_message = "Failed to get FAA bearer token. Status code: $status_code";
        if ($request_error !== null && $request_error !== '') {
            $error_message .= " Error: $request_error";
        }
        if ($status_code >= 500) {
            error_log("Server error when accessing FAA auth: $status_code");
        } elseif ($status_code == 401) {
            error_log("Unauthorized when accessing FAA auth");
        }
        throw new Exception($error_message);
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        throw new Exception("Failed to decode FAA auth response");
    }

    $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 1800;
    $ttl = max(60, $expiresIn - 30);

    $stmt = $pdo->prepare("INSERT INTO notam_cache (cache_key, cache_value, expiration) 
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE 
                           cache_value = VALUES(cache_value), 
                           expiration = VALUES(expiration)");
    $stmt->execute([$cacheKey, $data['access_token'], $ttl]);

    return $data['access_token'];
}

function normalizeNmsNotamResponse($response, $responseFormat) {
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        throw new Exception("Failed to decode JSON response from FAA NMS API");
    }

    if (($decoded['status'] ?? null) !== 'Success') {
        $errorDetails = '';
        if (isset($decoded['errors']) && is_array($decoded['errors']) && !empty($decoded['errors'])) {
            $errorDetails = ': ' . json_encode($decoded['errors']);
        }
        throw new Exception("FAA NMS API returned failure{$errorDetails}");
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        throw new Exception("Missing data in FAA NMS response");
    }

    $formatKey = strtolower($responseFormat);
    $items = [];
    if ($formatKey === 'geojson' && isset($data['geojson']) && is_array($data['geojson'])) {
        $items = $data['geojson'];
    } elseif ($formatKey === 'aixm' && isset($data['aixm']) && is_array($data['aixm'])) {
        $items = $data['aixm'];
    } elseif (isset($data['geojson']) && is_array($data['geojson'])) {
        $items = $data['geojson'];
    } elseif (isset($data['aixm']) && is_array($data['aixm'])) {
        $items = $data['aixm'];
    } else {
        throw new Exception("FAA NMS response missing NOTAM data");
    }

    $normalized = [
        'pageNum' => 1,
        'totalCount' => count($items),
        'totalPages' => 1,
        'items' => $items,
    ];

    $normalizedJson = json_encode($normalized);
    if ($normalizedJson === false) {
        throw new Exception("Failed to encode normalized NOTAM response");
    }

    return $normalizedJson;
}

// Function to get cached data or fetch from API
function getCachedOrFreshData($pdo, $url, $headers, $responseFormat, $cacheTime = 3600) {
    // Generate a unique cache key based on the URL and response format
    $cacheKey = 'notam_' . md5($url . '|format=' . $responseFormat);

    // Try to fetch from cache
    $stmt = $pdo->prepare("SELECT cache_value FROM notam_cache WHERE cache_key = ? AND expiration > NOW()");
    $stmt->execute([$cacheKey]);
    $result = $stmt->fetch();

    if ($result) {
        return $result['cache_value'];
    }

    if (rand(1, 100) == 1) {
        purgeExpiredCache($pdo);
    }

    // If not in cache or expired, fetch from API
    $response = getNotamsFromFaa($url, $headers, $responseFormat);

    // Store in cache
    $stmt = $pdo->prepare("INSERT INTO notam_cache (cache_key, cache_value, expiration) 
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE 
                           cache_value = VALUES(cache_value), 
                           expiration = VALUES(expiration)");
    $stmt->execute([$cacheKey, $response, $cacheTime]);

    return $response;
}

function getNotamsFromFaa($url, $headers, $responseFormat) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => $headers,
            'timeout' => 30,
        ],
    ];

    [$response, $status_code, $request_error] = httpRequest($url, $opts);
    if ($response === false || $status_code < 200 || $status_code >= 300) {
        $error_message = "Failed to get data from FAA NMS API. Status code: $status_code";
        if ($request_error !== null && $request_error !== '') {
            $error_message .= " Error: $request_error";
        }
        if ($status_code >= 500) {
            error_log("Server error when accessing FAA NMS API: $status_code");
        } elseif ($status_code == 404) {
            error_log("Resource not found on FAA NMS API: $url");
        }
        throw new Exception($error_message);
    }

    return normalizeNmsNotamResponse($response, $responseFormat);
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
    return is_numeric($input) && $input >= 0 && $input <= 100;
}

function sendErrorResponse($statusCode, $exception) {
    http_response_code($statusCode);
    $appEnv = getenv('APP_ENV') ?: 'production';
    $isProduction = $appEnv === 'production';
    if ($isProduction) {
        $errorMessage = $statusCode === 400 ? "Invalid request" : "Internal server error";
    } else {
        $errorMessage = $exception->getMessage();
    }
    echo json_encode(["error" => $errorMessage]);
    error_log($exception->getMessage());
    exit();
}

try {
    $pdo = getDbConnection();

    // Input validation and sanitization
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    if ($longitude === null || $longitude === false) {
        $longitude = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
    }
    $latitude = filter_input(INPUT_GET, 'locationLatitude', FILTER_VALIDATE_FLOAT);
    if ($latitude === null || $latitude === false) {
        $latitude = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
    }
    $radius = filter_input(INPUT_GET, 'locationRadius', FILTER_VALIDATE_FLOAT);
    if ($radius === null || $radius === false) {
        $radius = filter_input(INPUT_GET, 'radius', FILTER_VALIDATE_FLOAT);
    }
    if ($latitude === null || $latitude === false || $longitude === null || $longitude === false ||
        $radius === null || $radius === false || !isValidLongitude($longitude) || !isValidLatitude($latitude) ||
        !isValidRadius($radius)) {
      throw new InvalidArgumentException("Invalid input parameters");
    }

    $deltaRequest = false;
    $knownNotams = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $maxBodySize = 1048576; // 1MB limit
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $maxBodySize) {
            throw new InvalidArgumentException("Request body too large");
        }
        $inputStream = fopen('php://input', 'r');
        if ($inputStream === false) {
            throw new Exception("Failed to read request body");
        }
        $rawBody = stream_get_contents($inputStream, $maxBodySize);
        fclose($inputStream);
        if ($rawBody !== false && trim($rawBody) !== '') {
            $payload = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("Invalid JSON in POST body: " . json_last_error_msg());
            }
            if (!is_array($payload) || !isset($payload['known']) || !is_array($payload['known'])) {
                throw new InvalidArgumentException("POST body must contain a 'known' array for delta mode");
            }
            $known = $payload['known'];
            $isList = array_keys($known) === range(0, count($known) - 1);
            if ($isList) {
                foreach ($known as $entry) {
                    if (is_array($entry) && isset($entry['id'], $entry['lastUpdated']) && is_string($entry['id']) && is_string($entry['lastUpdated'])) {
                        $knownNotams[$entry['id']] = $entry['lastUpdated'];
                    }
                }
            } else {
                foreach ($known as $id => $lastUpdated) {
                    if (is_string($id) && is_string($lastUpdated)) {
                        $knownNotams[$id] = $lastUpdated;
                    }
                }
            }
            if (!empty($knownNotams)) {
                $deltaRequest = true;
            } else {
                throw new InvalidArgumentException("Delta payload contains no valid entries");
            }
        }
    }


    $responseFormat = getenv('NMS_RESPONSE_FORMAT') ?: 'GEOJSON';
    $responseFormat = strtoupper(trim($responseFormat));
    if ($responseFormat !== 'GEOJSON' && $responseFormat !== 'AIXM') {
        throw new InvalidArgumentException("Invalid NMS response format");
    }

    // Build request
    $baseUrl = getenv('FAA_API_BASE') ?: 'https://api-nms.aim.faa.gov/nmsapi';
    $authUrl = getenv('FAA_AUTH_URL') ?: 'https://api-nms.aim.faa.gov/v1/auth/token';
    $query = http_build_query([
        'longitude' => $longitude,
        'latitude' => $latitude,
        'radius' => $radius,
    ]);
    $url = rtrim($baseUrl, '/') . '/v1/notams?' . $query;

    $FAA_ID = getenv('FAA_ID');
    $FAA_SECRET = getenv('FAA_SECRET');
    $token = getAccessToken($pdo, $authUrl, $FAA_ID, $FAA_SECRET);

    $headers = "Authorization: Bearer $token\r\n" .
               "nmsResponseFormat: $responseFormat\r\n" .
               "Accept: application/json\r\n";

    // Get data (cached or fresh)
    $response = getCachedOrFreshData($pdo, $url, $headers, $responseFormat);
    if ($response === false) {
        throw new Exception("Failed to get NOTAM data from FAA API");
    }

    if ($deltaRequest) {
        if ($responseFormat !== 'GEOJSON') {
            throw new InvalidArgumentException("Delta mode requires GEOJSON response format");
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
            throw new Exception("Failed to decode FAA response for delta mode");
        }

        $filteredItems = [];
        $serverIds = [];
        foreach ($decoded['items'] as $item) {
            $notam = $item['properties']['coreNOTAMData']['notam'] ?? null;
            $id = is_array($notam) ? ($notam['id'] ?? null) : null;
            $lastUpdated = is_array($notam) ? ($notam['lastUpdated'] ?? null) : null;

            if (is_string($id)) {
                $serverIds[$id] = is_string($lastUpdated) ? $lastUpdated : null;
            }

            $knownLastUpdated = is_string($id) ? ($knownNotams[$id] ?? null) : null;
            if (!is_string($id) || !is_string($lastUpdated) || $knownLastUpdated === null || $knownLastUpdated !== $lastUpdated) {
                $filteredItems[] = $item;
            }
        }

        $removedIds = [];
        foreach ($knownNotams as $id => $lastUpdated) {
            if (!array_key_exists($id, $serverIds)) {
                $removedIds[] = $id;
            }
        }

        $decoded['items'] = $filteredItems;
        $decoded['pageNum'] = 1;
        $decoded['totalPages'] = 1;
        $decoded['totalCount'] = count($filteredItems);
        $decoded['delta'] = true;
        $decoded['removedIds'] = $removedIds;

        $response = json_encode($decoded);
        if ($response === false) {
            throw new Exception("Failed to encode delta response");
        }
    }

    // Return data
    header('Content-Type: application/json');
    echo $response;

} catch (InvalidArgumentException $e) {
    sendErrorResponse(400, $e);
} catch (Exception $e) {
    sendErrorResponse(500, $e);
}

?>
