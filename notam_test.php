<?php

// Simple smoke test for notam.php.
// Usage:
//   php notam_test.php [URL]
// Defaults to NOTAM_URL env var or http://localhost/notam.php (CLI).
// In web mode, defaults to current host's /notam.php.

function httpRequest($url, $method = 'GET', $headers = [], $body = null) {
    $method = strtoupper($method);
    if (!function_exists('curl_init')) {
        return [false, 0, 'cURL extension not available'];
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'notam-test',
    ];
    if ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    if ($body !== null && $body !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error_message = null;
    if ($response === false) {
        $error_message = curl_error($ch);
    }
    curl_close($ch);

    return [$response, $status_code, $error_message];
}

function runRequest($label, $method, $url, $headers = [], $body = null) {
    $startedAt = microtime(true);
    [$response, $status_code, $error_message] = httpRequest($url, $method, $headers, $body);
    $durationMs = (microtime(true) - $startedAt) * 1000;
    $responseBytes = is_string($response) ? strlen($response) : 0;

    return [
        'label' => $label,
        'method' => $method,
        'url' => $url,
        'response' => $response,
        'status_code' => $status_code,
        'error_message' => $error_message,
        'duration_ms' => $durationMs,
        'bytes' => $responseBytes,
    ];
}

function formatResultLine($result, $extra = '') {
    $line = $result['label'] . ': HTTP ' . $result['status_code'];
    $line .= ' | duration_ms=' . round($result['duration_ms']);
    $line .= ' | bytes=' . $result['bytes'];
    if (!empty($result['error_message'])) {
        $line .= ' | error="' . $result['error_message'] . '"';
    }
    if ($extra !== '') {
        $line .= ' | ' . $extra;
    }
    return $line;
}

function getResultStatus($result, $decoded, $expectedMinCount = null) {
    if ($result['response'] === false || $result['status_code'] < 200 || $result['status_code'] >= 300) {
        return ['status' => 'FAIL', 'reason' => 'http'];
    }
    if (!is_array($decoded)) {
        return ['status' => 'FAIL', 'reason' => 'json'];
    }
    if (isset($decoded['error'])) {
        return ['status' => 'FAIL', 'reason' => 'api'];
    }
    if ($expectedMinCount !== null) {
        $count = $decoded['totalCount'] ?? null;
        if (!is_numeric($count) || (int)$count < $expectedMinCount) {
            return ['status' => 'FAIL', 'reason' => 'count'];
        }
    }
    return ['status' => 'PASS', 'reason' => ''];
}

function buildUrl($baseUrl) {
    $parts = parse_url($baseUrl);
    $hasQuery = isset($parts['query']) && $parts['query'] !== '';
    if ($hasQuery) {
        return $baseUrl;
    }

    $query = http_build_query([
        'locationLatitude' => 50.0379,
        'locationLongitude' => 8.5622,
        'locationRadius' => 5,
    ]);

    return $baseUrl . '?' . $query;
}

$baseUrl = null;
if (PHP_SAPI === 'cli') {
    $baseUrl = $argv[1] ?? getenv('NOTAM_URL') ?: 'http://localhost/notam.php';
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    $baseUrl = $scheme . '://' . $host . '/notam.php';
}
$url = buildUrl($baseUrl);

$primary = runRequest('GET primary', 'GET', $url);

if ($primary['response'] === false || $primary['status_code'] < 200 || $primary['status_code'] >= 300) {
    http_response_code(502);
    echo "Request URL: $url\n";
    echo formatResultLine($primary) . "\n";
    echo "RESULT: FAILED\n";
    if (is_string($primary['response']) && $primary['response'] !== '') {
        if (PHP_SAPI === 'cli') {
            echo $primary['response'] . "\n";
        }
    }
    exit(1);
}

$decoded = json_decode($primary['response'], true);
if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo "Request URL: $url\n";
    echo "Invalid JSON response\n";
    echo "RESULT: FAILED\n";
    exit(1);
}

if (isset($decoded['error'])) {
    http_response_code(502);
    echo "Request URL: $url\n";
    echo "API error: {$decoded['error']}\n";
    echo "RESULT: FAILED\n";
    exit(1);
}

$count = $decoded['totalCount'] ?? null;
if ($count === null && isset($decoded['items']) && is_array($decoded['items'])) {
    $count = count($decoded['items']);
}

echo "Request URL: $url\n";
$primaryExtra = $count !== null ? "totalCount=$count" : '';
echo "Tests\n";
$primaryStatus = getResultStatus($primary, $decoded);
$overallPass = $primaryStatus['status'] === 'PASS';
$primaryExtra = $primaryExtra !== '' ? ($primaryExtra . " | result=" . $primaryStatus['status']) : "result=" . $primaryStatus['status'];
echo formatResultLine($primary, $primaryExtra) . "\n";

$cache = runRequest('GET cache', 'GET', $url);
$cacheDecoded = json_decode($cache['response'], true);
$cacheStatus = getResultStatus($cache, is_array($cacheDecoded) ? $cacheDecoded : null);
if ($cacheStatus['status'] !== 'PASS') {
    $overallPass = false;
}
$cacheExtra = "result=" . $cacheStatus['status'];
if ($cacheStatus['reason'] !== '') {
    $cacheExtra .= " ($cacheStatus[reason])";
}
echo formatResultLine($cache, $cacheExtra) . "\n";

$knownNotams = [];
$items = $decoded['items'] ?? [];
if (is_array($items)) {
    foreach ($items as $item) {
        $notam = $item['properties']['coreNOTAMData']['notam'] ?? null;
        $id = is_array($notam) ? ($notam['id'] ?? null) : null;
        $lastUpdated = is_array($notam) ? ($notam['lastUpdated'] ?? null) : null;
        if (is_string($id) && is_string($lastUpdated)) {
            $knownNotams[$id] = $lastUpdated;
        }
    }
}

if (!empty($knownNotams)) {
    $maxKnown = 200;
    if (count($knownNotams) > $maxKnown) {
        $knownNotams = array_slice($knownNotams, 0, $maxKnown, true);
    }

    $deltaHeaders = ['Content-Type: application/json'];
    $deltaPayload = json_encode(['known' => $knownNotams]);
    $delta = runRequest('POST delta', 'POST', $url, $deltaHeaders, $deltaPayload);
    $deltaExtra = '';
    $deltaDecoded = json_decode($delta['response'], true);
    $deltaStatus = getResultStatus($delta, is_array($deltaDecoded) ? $deltaDecoded : null);
    if ($deltaStatus['status'] !== 'PASS') {
        $overallPass = false;
    }
    if (is_array($deltaDecoded)) {
        $deltaCount = $deltaDecoded['totalCount'] ?? null;
        $removedIds = isset($deltaDecoded['removedIds']) && is_array($deltaDecoded['removedIds'])
            ? count($deltaDecoded['removedIds'])
            : null;
        if ($deltaCount !== null) {
            $deltaExtra = "totalCount=$deltaCount";
        }
        if ($removedIds !== null) {
            $deltaExtra = $deltaExtra !== '' ? ($deltaExtra . " | removedIds=$removedIds") : "removedIds=$removedIds";
        }
    }
    $deltaExtra = $deltaExtra !== '' ? ($deltaExtra . " | result=" . $deltaStatus['status']) : "result=" . $deltaStatus['status'];
    if ($deltaStatus['reason'] !== '') {
        $deltaExtra .= " ($deltaStatus[reason])";
    }
    echo formatResultLine($delta, $deltaExtra) . "\n";

    $halfCount = (int)floor(count($knownNotams) / 2);
    if ($halfCount > 0) {
        $missingKnown = array_slice($knownNotams, 0, $halfCount, true);
        $missingPayload = json_encode(['known' => $missingKnown]);
        $missing = runRequest('POST delta missing-ids', 'POST', $url, $deltaHeaders, $missingPayload);
        $missingExtra = '';
        $missingDecoded = json_decode($missing['response'], true);
        $missingStatus = getResultStatus($missing, is_array($missingDecoded) ? $missingDecoded : null, 1);
        if ($missingStatus['status'] !== 'PASS') {
            $overallPass = false;
        }
        if (is_array($missingDecoded)) {
            $missingCount = $missingDecoded['totalCount'] ?? null;
            if ($missingCount !== null) {
                $missingExtra = "totalCount=$missingCount";
            }
        }
        $missingExtra = $missingExtra !== '' ? ($missingExtra . " | result=" . $missingStatus['status']) : "result=" . $missingStatus['status'];
        if ($missingStatus['reason'] !== '') {
            $missingExtra .= " ($missingStatus[reason])";
        }
        echo formatResultLine($missing, $missingExtra) . "\n";
    } else {
        echo "POST delta missing-ids: skipped (not enough NOTAM ids)\n";
    }

    $staleKnown = [];
    foreach ($knownNotams as $id => $lastUpdated) {
        $staleKnown[$id] = '1970-01-01T00:00:00Z';
    }
    $stalePayload = json_encode(['known' => $staleKnown]);
    $stale = runRequest('POST delta stale-updated', 'POST', $url, $deltaHeaders, $stalePayload);
    $staleExtra = '';
    $staleDecoded = json_decode($stale['response'], true);
    $staleStatus = getResultStatus($stale, is_array($staleDecoded) ? $staleDecoded : null, 1);
    if ($staleStatus['status'] !== 'PASS') {
        $overallPass = false;
    }
    if (is_array($staleDecoded)) {
        $staleCount = $staleDecoded['totalCount'] ?? null;
        if ($staleCount !== null) {
            $staleExtra = "totalCount=$staleCount";
        }
    }
    $staleExtra = $staleExtra !== '' ? ($staleExtra . " | result=" . $staleStatus['status']) : "result=" . $staleStatus['status'];
    if ($staleStatus['reason'] !== '') {
        $staleExtra .= " ($staleStatus[reason])";
    }
    echo formatResultLine($stale, $staleExtra) . "\n";
} else {
    echo "POST delta: skipped (no NOTAM ids found in response)\n";
}

echo "RESULT: " . ($overallPass ? 'SUCCEEDED' : 'FAILED') . "\n";
if (!$overallPass) {
    exit(1);
}

?>
