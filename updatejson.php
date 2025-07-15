<p id="warning" style="color:red; display:none;">Cannot confirm: the symbol 𒐫 is still present in the JSON to append.</p>
<script>
function checkForSymbol() {
    const toAppendElems = document.querySelectorAll('.to-append');
    let found = false;
    toAppendElems.forEach(el => {
        if (el.textContent.includes('𒐫')) found = true;
    });
    const confirmBtn = document.getElementById('confirm-btn');
    const warning = document.getElementById('warning');
    if (found) {
        confirmBtn.disabled = true;
        warning.style.display = 'block';
    } else {
        confirmBtn.disabled = false;
        warning.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', checkForSymbol);
</script>
<?php
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$session_lifetime = 3 * 60;
$correct_username = "admin";
$correct_password_hash = '$2y$10$S9naYnE6MqP14zVRfIsig.A98jBGzEImaGLgKVuiSGKpI7yr7XeGG'; // password: 4869

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['heartbeat'])) {
    $_SESSION['last_activity'] = time();
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if ($username === $correct_username && password_verify($password, $correct_password_hash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
    ?>
    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
    <form method="post">
        <label>Username: <input name="username" required></label><br>
        <label>Password: <input type="password" name="password" required></label><br>
        <button type="submit">Login</button>
    </form>
    <?php
    exit;
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_lifetime) {
    session_unset();
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$_SESSION['last_activity'] = time();
$remaining_time = ($_SESSION['last_activity'] + $session_lifetime) - time();
?>

Welcome, you are logged in! <span id="countdown"></span><hr>
<a href="?logout=1">Logout</a>
<script>
let remaining = <?php echo $remaining_time; ?>;
function updateCountdown() {
    if (remaining <= 0) {
        clearInterval(timer);
        alert("Session expired due to inactivity.");
        location.reload();
        return;
    }
    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;
    document.getElementById('countdown').textContent =
        minutes + "m " + (seconds < 10 ? "0" : "") + seconds + "s";
    remaining--;
}
updateCountdown();
const timer = setInterval(updateCountdown, 1000);
setInterval(() => {
    fetch("", {
        method: "POST",
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: "heartbeat=1"
    });
}, 30000);
</script>



<?php
$remoteUrl = 'https://alceawis.de/other/extra/scripts/fakesocialmedia/data_part_alcea.json';
$localFile = 'data_alcea.json';

function convertToBlockquoteTags(string $text): string {
    return preg_replace('/𒐫(.*?)𒐫/us', '<blockquote>$1</blockquote>', $text);
}

function convertToUnicodeEnclosure(string $text): string {
    return preg_replace('/<blockquote>(.*?)<\/blockquote>/us', '𒐫$1𒐫', $text);
}

function array_map_recursive(callable $callback, $array) {
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = array_map_recursive($callback, $value);
        } else {
            $array[$key] = $callback($value);
        }
    }
    return $array;
}

function convertDataToCompareFormat(array $data): array {
    return array_map_recursive(function ($v) {
        return is_string($v) ? convertToUnicodeEnclosure($v) : $v;
    }, $data);
}

function removeTimeKeyRecursive($array) {
    foreach ($array as $key => &$value) {
        if (is_array($value)) {
            $value = removeTimeKeyRecursive($value);
        }
    }
    unset($array['time']);
    return $array;
}

function entryExists(array $entry, array $local): bool {
    $needle = removeTimeKeyRecursive($entry);
    $needleJson = json_encode($needle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($local as $item) {
        $haystack = removeTimeKeyRecursive($item);
        $haystackJson = json_encode($haystack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($needleJson === $haystackJson) return true;
    }
    return false;
}

// Load remote
$remoteJson = file_get_contents($remoteUrl);
if (!$remoteJson) die(" Failed to load remote JSON.");
$remoteRaw = json_decode($remoteJson, true);
if (!is_array($remoteRaw)) die(" Failed to decode remote JSON.");

// Load local
if (!file_exists($localFile)) die(" Local JSON file '$localFile' not found.");
$localJson = file_get_contents($localFile);
$localRaw = json_decode($localJson, true);
if (!is_array($localRaw)) die(" Failed to decode local JSON.");

// Normalize local for comparison
$localForCompare = array_map('convertDataToCompareFormat', $localRaw);

// Find new entries
$newRemoteEntries = [];
$current_time = date('YmdHis'); // Current timestamp in the format YmdHis

foreach ($remoteRaw as $entry) {
    foreach ($entry as $payload) {
        if (isset($payload['value']) && strpos($payload['value'], '•acws') !== false) {
            $original = $entry;
            $converted = array_map_recursive(function ($v) {
                return is_string($v) ? convertToBlockquoteTags($v) : $v;
            }, $entry);
            if (!entryExists(convertDataToCompareFormat($converted), $localForCompare)) {
                $newRemoteEntries[] = [
                    'original' => $original,
                    'to_append' => $converted,
                    'time' => $current_time  // Add the 'time' key with the timestamp
                ];
            }
            break;
        }
    }
}

// Confirm save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    if (count($newRemoteEntries) > 0) {
  $newConverted = array_map(function ($e) {
    $converted = $e['to_append'];
    foreach ($converted as $dateKey => &$payload) {
        if (is_array($payload) && isset($payload['value'])) {
            $payload['time'] = $e['time'];
            $hash = substr(md5($payload['value']), 0, 8);
            $payload['status'] = "{$dateKey}-$hash";
        }
    }
    return $converted;
}, $newRemoteEntries);


        $newData = array_merge($newConverted, $localRaw);

        $written = file_put_contents(
            $localFile,
            json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($written === false) {
            echo "<p style='color:red;'>Failed to write to local file.</p>";
        } else {
            echo "<p style='color:green;'>Added " . count($newConverted) . " new entries to <code>$localFile</code>.</p>";
            $newRemoteEntries = [];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review New •acws Entries</title>
    <style>
        body { font-family: sans-serif; max-width: 1000px; margin: 2em auto; }
        pre { background: #f5f5f5; padding: 1em; border-radius: 5px; white-space: pre-wrap; }
        .entry-pair { display: flex; gap: 20px; margin-bottom: 2em; }
        .entry-pair > div { width: 50%; }
        button { padding: 0.6em 1em; font-size: 1em; cursor: pointer; }
        h2 { margin-top: 3em; }
    </style>
</head>
<body>

<h1>New <code>•acws</code> Entries</h1>
<?php if (count($newRemoteEntries) > 0): ?>
    <p><strong><?php echo count($newRemoteEntries); ?></strong> new entries found in remote file not present in local.</p>
    <?php foreach ($newRemoteEntries as $index => $entryPair): ?>
        <div class="entry-pair">
            <div>
                <h2>Original Message #<?php echo $index + 1; ?></h2>
                <pre><?php echo htmlspecialchars(json_encode($entryPair['original'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
            <div>
                <h2>JSON to be Appended</h2>
                <pre>
                    <?php 
                    $to_append_with_time = $entryPair['to_append'];
                    $to_append_with_time['time'] = $entryPair['time'];  // Add the time to the "to_append" JSON
                    echo htmlspecialchars(json_encode($to_append_with_time, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); 
                    ?>
                </pre>
            </div>
        </div>
    <?php endforeach; ?>

    <form method="post">
        <button id="confirm-btn" name="confirm" type="submit">Confirm Append</button>
    </form>
<?php else: ?>
    <p>No new <code>•acws</code> entries found.</p>
<?php endif; ?>

</body>
</html>

