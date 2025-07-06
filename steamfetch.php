<?php
header('Content-Type: text/html; charset=utf-8');
function fetchUrl(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'MySteamApp/1.0',
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        error_log("HTTP error $httpCode fetching $url");
        return null;
    }
    return $response;
}
function getSteamAppData(int $appId, string $cc = 'DE', string $lang = 'english'): array {
    $result = [
        'minimum' => null,
        'recommended' => null,
        'deck_compat' => null,
        'deck_detail' => null,
    ];
    $urlSysReq = "https://store.steampowered.com/api/appdetails?appids={$appId}&cc={$cc}&l={$lang}";
    $sysJson = fetchUrl($urlSysReq);
    if ($sysJson) {
        $sysData = json_decode($sysJson, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($sysData[$appId]['success']) && $sysData[$appId]['success']) {
            $requirements = $sysData[$appId]['data']['pc_requirements'] ?? [];
            $result['minimum'] = $requirements['minimum'] ?? null;
            $result['recommended'] = $requirements['recommended'] ?? null;
        }
    }
    $urlDeck = "https://store.steampowered.com/saleaction/ajaxgetdeckappcompatibilityreport?nAppID={$appId}";
    $deckJson = fetchUrl($urlDeck);
    if ($deckJson) {
        $deckData = json_decode($deckJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $result['deck_compat'] = $deckData['rating'] ?? null;
            $result['deck_detail'] = $deckData;
        }
    }
    return $result;
}
function parseSystemRequirements(string $html): array {
    $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    $specs = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $specs[$key] = $value;
        } else {
            if (isset($specs['Additional Notes'])) {
                $specs['Additional Notes'] .= ' ' . $line;
            } else {
                $specs['Additional Notes'] = $line;
            }
        }
    }
    return $specs;
}
function extractKeysForSpecs(array $parsed, array $keys) {
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = $parsed[$key] ?? null;
    }
    return $result;
}
$specKeys = ['OS', 'Processor', 'Memory', 'Graphics', 'DirectX', 'Network', 'Storage', 'Additional Notes'];
$apiKey = isset($_GET['api_key']) ? $_GET['api_key'] : 'apikey';
$steamId = isset($_GET['steam_id']) ? $_GET['steam_id'] : '76561198119673186';
$libraryApiUrl = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key={$apiKey}&steamid={$steamId}&include_appinfo=1&include_played_free_games=1";
$libraryResponse = file_get_contents($libraryApiUrl);
$libraryData = json_decode($libraryResponse, true);
$gamesWithExtra = [];
?>
<!DOCTYPE html>
<html>
<head>
  <title>Steam Library Viewer</title>
  <style>
    table {
      border-collapse: collapse;
    }
    table, th, td {
      border: 1px solid #ccc;
      padding: 8px;
      vertical-align: top;
    }
  </style>
</head>
<body>
  <table id="steamLibrary">
    <thead>
      <tr>
        <th>Game Name</th>
        <th>Playtime (minutes)</th>
        <th>Purchase Date</th>
        <th>Price (Current)</th>
        <th>Last Played</th>
        <th>Steam Deck Compat</th>
        <th>Minimum Specs (Raw HTML)</th>
        <th>Recommended Specs (Raw HTML)</th>
      </tr>
    </thead>
    <tbody>
<?php
if ($libraryData && isset($libraryData['response']['games'])) {
  $games = $libraryData['response']['games'];
  if (!empty($games)) {
    usort($games, function($a, $b) {
      return strcmp($a['name'], $b['name']);
    });
    foreach ($games as $game) {
      $appId = $game['appid'];
      $appName = $game['name'];
      $playtimeMinutes = $game['playtime_forever'];
      $purchaseDate = isset($game['purchase_date']) ? date('Y-m-d', $game['purchase_date']) : 'Unknown';
      $rtimeLastPlayed = isset($game['rtime_last_played']) ? date('Y-m-d', $game['rtime_last_played']) : 'Unknown';
      $purchaseApiUrl = "https://store.steampowered.com/api/appdetails?appids={$appId}";
      $purchaseResponse = file_get_contents($purchaseApiUrl);
      $purchaseData = json_decode($purchaseResponse, true);
      if ($purchaseData && isset($purchaseData[$appId]['data']['price_overview'])) {
        $priceOverview = $purchaseData[$appId]['data']['price_overview'];
        $price = number_format($priceOverview['final'] / 100, 2) . ' €';
      } else {
        $price = 'Unknown';
      }
      $extraData = getSteamAppData($appId);
      $minSpecsRaw = $extraData['minimum'] ?? null;
      $maxSpecsRaw = $extraData['recommended'] ?? null;
      $minParsed = $minSpecsRaw ? parseSystemRequirements($minSpecsRaw) : [];
      $maxParsed = $maxSpecsRaw ? parseSystemRequirements($maxSpecsRaw) : [];
      $minSelected = extractKeysForSpecs($minParsed, $specKeys);
      $maxSelected = extractKeysForSpecs($maxParsed, $specKeys);
      $combinedSpecs = [];
      foreach ($specKeys as $key) {
          $combinedSpecs[$key] = [
              'min' => $minSelected[$key],
              'max' => $maxSelected[$key],
          ];
      }
      $game['system_requirements'] = $combinedSpecs;
      $game['system_requirements_raw'] = [
          'minimum' => $minSpecsRaw,
          'recommended' => $maxSpecsRaw,
      ];
      $game['steam_deck_compatibility'] = $extraData['deck_compat'];
      $game['deck_details'] = $extraData['deck_detail'];
      $gamesWithExtra[] = $game;
      echo '<tr>';
      echo "<td>" . htmlspecialchars($appName) . "</td>";
      echo "<td>{$playtimeMinutes}</td>";
      echo "<td>{$purchaseDate}</td>";
      echo "<td>{$price}</td>";
      echo "<td>{$rtimeLastPlayed}</td>";
      echo "<td>" . htmlspecialchars($extraData['deck_compat'] ?? 'N/A') . "</td>";
      echo "<td>" . ($minSpecsRaw ?? 'N/A') . "</td>";
      echo "<td>" . ($maxSpecsRaw ?? 'N/A') . "</td>";
      echo '</tr>';
    }
  } else {
    echo '<tr><td colspan="8">No games found in the Steam library.</td></tr>';
  }
} else {
  echo '<tr><td colspan="8">Error retrieving Steam library.</td></tr>';
}
?>
    </tbody>
  </table>
  <form action="download.php" method="post">
    <input type="hidden" name="libraryData" value="<?php echo htmlspecialchars(json_encode($gamesWithExtra)); ?>">
    <button type="submit">Download as JSON</button>
  </form>
</body>
</html>

