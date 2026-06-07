<?php
// Increase memory limit to 512MB
ini_set('memory_limit', '512M');

// CORS headers for iframe support
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json');

// Custom error handler
function errorHandler($errno, $errstr, $errfile, $errline) {
    echo json_encode(['error' => "$errstr in $errfile on line $errline"]);
    exit;
}
set_error_handler("errorHandler");

/**
 * Stock Symbol Checker API
 * Usage: ?q=SYMBOL (max 6 chars) or ?q=ISIN (8+ chars)
 */

$input = isset($_GET['q']) ? strtoupper(trim($_GET['q'])) : '';

if (empty($input)) {
    echo json_encode(['error' => 'No input provided. Use ?q=SYMBOL or ?q=ISIN']);
    exit;
}

// Read stocks.json from same directory
$jsonFile = __DIR__ . '/stocks.json';
if (!file_exists($jsonFile)) {
    echo json_encode(['error' => 'stocks.json not found', 'path' => $jsonFile]);
    exit;
}

$stocks = json_decode(file_get_contents($jsonFile), true);
if (!$stocks) {
    echo json_encode(['error' => 'stocks.json is invalid']);
    exit;
}

// Determine input type
$isSymbol = strlen($input) <= 6;
$isIsin = strlen($input) >= 8;

$found = null;
$isinToCheck = null;
$inJson = false;

if ($isSymbol) {
    // Search for symbol in stocks.json
    foreach ($stocks as $item) {
        if (strtoupper($item['stock']) === $input) {
            $found = $item;
            $inJson = true;
            $isinToCheck = $found['isin'] ?? null;
            break;
        }
    }
    
    if (!$found) {
        echo json_encode([
            'status' => 'not_found',
            'symbol' => $input,
            'injson' => false,
            'gettex' => 'no'
        ]);
        exit;
    }
} elseif ($isIsin) {
    // Input is ISIN
    $isinToCheck = $input;
    
    // Check if this ISIN exists in stocks.json
    foreach ($stocks as $item) {
        if (!empty($item['isin']) && strtoupper($item['isin']) === $input) {
            $found = $item;
            $inJson = true;
            break;
        }
    }
    
    // Check Gettex for the ISIN
    $gettexResult = checkIsinInGettex($isinToCheck);
    
    if ($inJson && $found) {
        echo json_encode([
            'status' => 'found_in_json',
            'symbol' => $found['stock'],
            'isin' => $found['isin'],
            'injson' => true,
            'gettex' => $gettexResult['found'] ? 'yes' : 'no',
            'source' => $gettexResult['source'] ?? 'unknown',
            'gettex_message' => $gettexResult['message'] ?? null,
            'price' => $found['price'],
            'currency' => $found['currency'],
            'date' => $found['date'],
            'exchange_market' => $found['exchange_market'],
            'depot' => $found['depot'] ?? null,
            'nrbght' => $found['nrbght'] ?? null
        ]);
    } else {
        echo json_encode([
            'status' => 'isin_checked',
            'submitted_isin' => $input,
            'injson' => false,
            'gettex' => $gettexResult['found'] ? 'yes' : 'no',
            'source' => $gettexResult['source'] ?? 'unknown',
            'gettex_message' => $gettexResult['message'] ?? null,
            'note' => 'ISIN checked against Gettex data'
        ]);
    }
    exit;
} else {
    echo json_encode([
        'error' => 'Ambiguous input length',
        'input' => $input,
        'length' => strlen($input),
        'note' => 'Symbols should be max 6 chars, ISIN should be 8+ chars'
    ]);
    exit;
}

// Symbol found in JSON
$gettexResult = (!empty($isinToCheck)) ? checkIsinInGettex($isinToCheck) : ['found' => false, 'source' => 'none'];

echo json_encode([
    'status' => 'found',
    'symbol' => $found['stock'],
    'injson' => true,
    'isin' => $found['isin'] ?? null,
    'gettex' => $gettexResult['found'] ? 'yes' : 'no',
    'source' => $gettexResult['source'] ?? 'unknown',
    'gettex_message' => $gettexResult['message'] ?? null,
    'price' => $found['price'],
    'currency' => $found['currency'],
    'date' => $found['date'],
    'exchange_market' => $found['exchange_market'],
    'depot' => $found['depot'] ?? null,
    'nrbght' => $found['nrbght'] ?? null
]);

/**
 * Check if ISIN exists in Gettex data
 * Priority: 
 * 1. Primary Gettex URL (live files from gettex.de)
 * 2. Mirror CSV.GZ file (alcea-wisteria.de)
 * 3. JSON cache file (fast lookup, always available)
 */
function checkIsinInGettex($isin) {
    // Priority 1: Primary Gettex URL (live trading data)
    $result = checkIsinInPrimaryGettex($isin);
    if ($result['found']) {
        $result['source'] = 'primary_gettex_live';
        $result['message'] = 'Found in primary Gettex live data';
        return $result;
    }
    
    // Priority 2: Mirror CSV.GZ file
    if (!$result['found'] && !$result['files_available']) {
        $mirrorResult = checkIsinInMirrorCsvGz($isin);
        if ($mirrorResult['found']) {
            $mirrorResult['source'] = 'mirror_csv_gz';
            $mirrorResult['message'] = 'Found in mirror CSV.GZ file';
            return $mirrorResult;
        }
        
        // Priority 3: JSON cache (always available, fast lookup)
        $jsonResult = checkIsinInJsonCache($isin);
        if ($jsonResult['found']) {
            $jsonResult['source'] = 'json_cache';
            $jsonResult['message'] = 'Found in JSON cache (timestamp: ' . $jsonResult['timestamp'] . ')';
            return $jsonResult;
        }
        
        // Not found in any source
        return [
            'found' => false,
            'source' => 'none',
            'message' => 'ISIN not found in any Gettex data source'
        ];
    }
    
    return $result;
}

/**
 * Priority 1: Check primary Gettex live data
 */
function checkIsinInPrimaryGettex($isin) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.gettex.de/en/trading/delayed-data/posttrade-data/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return [
            'found' => false,
            'files_available' => false,
            'message' => 'Could not fetch primary Gettex page'
        ];
    }
    
    // Extract .csv.gz file URL
    preg_match('/href="(https:\/\/erdk\.bayerische-boerse\.de:8000\/delayed-data\/[^"]+\.csv\.gz)"/', $html, $match);
    
    if (empty($match[1])) {
        return [
            'found' => false,
            'files_available' => false,
            'message' => 'No live data files available on Gettex (weekend/holiday)'
        ];
    }
    
    // Search for ISIN in the file
    $found = searchIsinInGzFile($match[1], $isin);
    
    return [
        'found' => $found,
        'files_available' => true,
        'message' => $found ? 'ISIN found in primary Gettex data' : 'ISIN not found in primary Gettex data'
    ];
}

/**
 * Priority 2: Check mirror CSV.GZ file
 */
function checkIsinInMirrorCsvGz($isin) {
    $fileUrl = 'https://alcea-wisteria.de/z_files/trading/exchangeplatform/gettex.csv.gz';
    $found = searchIsinInGzFile($fileUrl, $isin);
    
    return [
        'found' => $found,
        'files_available' => true,
        'message' => $found ? 'ISIN found in mirror CSV.GZ' : 'ISIN not found in mirror CSV.GZ'
    ];
}

/**
 * Helper: Search for ISIN in a gzipped CSV file
 */
function searchIsinInGzFile($url, $isin) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    
    $tempFile = tmpfile();
    curl_setopt($ch, CURLOPT_FILE, $tempFile);
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$success || $httpCode >= 400) {
        if (is_resource($tempFile)) {
            fclose($tempFile);
        }
        return false;
    }
    
    $metaData = stream_get_meta_data($tempFile);
    $gz = gzopen($metaData['uri'], 'rb');
    
    if (!$gz) {
        fclose($tempFile);
        return false;
    }
    
    $found = false;
    while (!gzeof($gz)) {
        $line = gzgets($gz, 4096);
        if (strpos($line, $isin) !== false) {
            $found = true;
            break;
        }
    }
    
    gzclose($gz);
    fclose($tempFile);
    
    return $found;
}

/**
 * Priority 3: Check JSON cache file (fast, always available)
 */
function checkIsinInJsonCache($isin) {
    $jsonCacheUrl = 'https://alcea-wisteria.de/PHP/0demo/2026-05-13-Finance/2026-05-13-Stocks/gettex_isins_cache.json';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $jsonCacheUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $jsonData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$jsonData || $httpCode !== 200) {
        return [
            'found' => false,
            'available' => false,
            'message' => 'Could not fetch JSON cache (HTTP ' . $httpCode . ')'
        ];
    }
    
    $cache = json_decode($jsonData, true);
    if (!$cache || !isset($cache['isins']) || !is_array($cache['isins'])) {
        return [
            'found' => false,
            'available' => false,
            'message' => 'Invalid JSON cache format'
        ];
    }
    
    $found = in_array($isin, $cache['isins']);
    
    return [
        'found' => $found,
        'available' => true,
        'timestamp' => $cache['timestamp'] ?? 'unknown',
        'count' => $cache['count'] ?? count($cache['isins']),
        'message' => $found ? 'ISIN found in JSON cache' : 'ISIN not in JSON cache'
    ];
}
?>