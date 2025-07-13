<?php
// ActivityPub main script for alceawis.com

file_put_contents(__DIR__ . '/debug.log', "[" . date('c') . "] --- New Request: {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']} ---\n", FILE_APPEND);

require_once __DIR__ . '/vendor/autoload.php';

use ActivityPhp\Server;

$domain = 'alceawis.com';
$username = 'alceawis';
$baseUrl = "https://$domain";
$server = new Server();

$dataFile = __DIR__ . '/data_alcea.json';
$profileInfoFile = __DIR__ . '/profileinfo.json';
$followersFile = __DIR__ . '/followers.json';
$privateKeyFile = __DIR__ . '/private.pem';
$publicKeyFile = __DIR__ . '/public.pem';

$logFile = __DIR__ . '/debug.log';
$inboxLogFile = __DIR__ . '/inbox.log';

// ------------------- Helper Functions --------------------

function logDebug($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('c') . "] $msg\n", FILE_APPEND);
}

function logInbox($msg) {
    global $inboxLogFile;
    file_put_contents($inboxLogFile, "[" . date('c') . "] $msg\n", FILE_APPEND);
}

function formatDescriptionLinks(string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return nl2br(preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="nofollow noopener noreferrer">$1</a>', $escaped));
}

function discoverInbox(string $actorUrl): ?string {
    $actorUrl = rtrim($actorUrl, '/') . '.json';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/activity+json, application/ld+json\r\n",
            'timeout' => 5,
        ]
    ]);
    $json = @file_get_contents($actorUrl, false, $ctx);
    if (!$json) {
        logInbox("Failed to fetch actor JSON from $actorUrl");
        return null;
    }
    $actor = json_decode($json, true);
    if (!$actor) {
        logInbox("Invalid JSON from actor URL $actorUrl");
        return null;
    }
    return $actor['inbox'] ?? null;
}

function sendSignedRequest(string $inboxUrl, array $body): bool {
    global $privateKeyFile, $username, $domain, $baseUrl;

    $privateKeyPem = @file_get_contents($privateKeyFile);
    if (!$privateKeyPem) {
        logInbox("Private key file not found or unreadable.");
        return false;
    }

    $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($bodyJson === false) {
        logInbox("Failed to encode JSON body for sending.");
        return false;
    }

    $digest = 'SHA-256=' . base64_encode(hash('sha256', $bodyJson, true));
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $urlParts = parse_url($inboxUrl);
    $host = $urlParts['host'] ?? '';
    $path = $urlParts['path'] ?? '/';

    $signatureString = "(request-target): post $path\nhost: $host\ndate: $date\ndigest: $digest";
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if (!$privateKey) {
        logInbox("Failed to load private key for signing.");
        return false;
    }
    $signatureOk = openssl_sign($signatureString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    openssl_free_key($privateKey);

    if (!$signatureOk) {
        logInbox("Failed to create signature.");
        return false;
    }
    $signatureB64 = base64_encode($signature);
    $keyId = "$baseUrl/$username#main-key";

    $signatureHeader = sprintf(
        'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
        $keyId,
        $signatureB64
    );

    $headers = [
        "Host: $host",
        "Date: $date",
        "Digest: $digest",
        "Signature: $signatureHeader",
        "Content-Type: application/activity+json"
    ];

    $ch = curl_init($inboxUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyJson,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        logInbox("cURL error sending to $inboxUrl: $curlError");
        return false;
    }

    logInbox("Sent activity to $inboxUrl, HTTP $httpCode, response: " . substr($response, 0, 300));
    return $httpCode >= 200 && $httpCode < 300;
}

function sendCreateActivity(array $object) {
    global $baseUrl, $username, $followersFile;

    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $object['id'] . '/activity',
        'type' => 'Create',
        'actor' => "$baseUrl/$username",
        'object' => $object,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    ];

    $followers = [];
    if (file_exists($followersFile)) {
        $followers = json_decode(file_get_contents($followersFile), true);
        if (!is_array($followers)) {
            logDebug("Invalid JSON in followers.json");
            $followers = [];
        }
    }

    foreach ($followers as $followerUrl) {
        $inbox = discoverInbox($followerUrl);
        if ($inbox) {
            sendSignedRequest($inbox, $activity);
        } else {
            logInbox("No inbox found for follower $followerUrl");
        }
    }
}

// ---------------- Update Profile on Fediverse (No checks, every run) -----------------

function updateProfile() {
    global $profileInfoFile, $baseUrl, $username;

    if (!file_exists($profileInfoFile)) {
        logDebug("profileinfo.json file not found");
        return false;
    }

    $profileData = json_decode(file_get_contents($profileInfoFile), true);
    if (!$profileData) {
        logDebug("Invalid JSON in profileinfo.json");
        return false;
    }

    $description = $profileData['description'] ?? '';
    $imageUrl = $profileData['image_url'] ?? '';
    $links = $profileData['links'] ?? [];

    // Build Mastodon profile fields from links, skipping empty ones
    $fields = [];
    foreach ($links as $link) {
        $name = trim($link['name'] ?? '');
        $url = trim($link['url'] ?? '');
        if ($name !== '' && $url !== '') {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fields[] = [
                'name' => $name,
                'value' => "<a href=\"$safeUrl\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">$safeUrl</a>",
            ];
        }
    }

    $profileObject = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => "$baseUrl/$username",
        'type' => 'Person',
        'name' => 'Alcea Bot',
        'preferredUsername' => $username,
        'summary' => formatDescriptionLinks($description),
        'icon' => [
            'type' => 'Image',
            'mediaType' => 'image/gif',
            'url' => $imageUrl,
        ],
        'inbox' => "$baseUrl/$username/inbox",
        'outbox' => "$baseUrl/$username/outbox",
        'followers' => "$baseUrl/$username/followers",
        'publicKey' => [
            'id' => "$baseUrl/$username#main-key",
            'owner' => "$baseUrl/$username",
            'publicKeyPem' => @file_get_contents(__DIR__ . '/public.pem'),
        ],
        // Mastodon-style profile fields here:
        'fields' => $fields,
    ];

    $followers = [];
    $followersFile = __DIR__ . '/followers.json';
    if (file_exists($followersFile)) {
        $followers = json_decode(file_get_contents($followersFile), true);
        if (!is_array($followers)) {
            logDebug("Invalid JSON in followers.json");
            $followers = [];
        }
    }

    $updateActivity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => "$baseUrl/$username#update-" . time(),
        'type' => 'Update',
        'actor' => "$baseUrl/$username",
        'object' => $profileObject,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    ];

    foreach ($followers as $followerUrl) {
        $inbox = discoverInbox($followerUrl);
        if ($inbox) {
            logDebug("Pushing profile Update activity to $inbox");
            sendSignedRequest($inbox, $updateActivity);
        } else {
            logDebug("No inbox discovered for follower $followerUrl");
        }
    }

    return true;
}

// ------------- Federate Outbox items -------------------

function federateOutbox() {
    global $dataFile;

    if (!file_exists($dataFile)) {
        logDebug("Outbox data file not found");
        return false;
    }

    $data = json_decode(file_get_contents($dataFile), true);
    if (!$data || !is_array($data)) {
        logDebug("Invalid JSON in outbox data file");
        return false;
    }

    foreach ($data as $note) {
        if (!isset($note['id'])) {
            logDebug("Skipping note without id");
            continue;
        }
        sendCreateActivity($note);
    }
    return true;
}

// ---------------------- Main Execution --------------------

updateProfile();   // Push profile update every run
federateOutbox();  // Push all outbox activities every run

// -------------------- Serve ActivityPub requests --------------------

try {
    $response = $server->run();
    header('Content-Type: application/activity+json; charset=utf-8');
    http_response_code($response->getStatusCode());
    echo $response->getBody();
} catch (Throwable $e) {
    // Ignore errors from run(), just log and continue
    logDebug("Ignored error in server run(): " . $e->getMessage());
    // No output here, continue silently
}
