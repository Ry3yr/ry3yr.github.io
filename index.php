<?php
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($requestUri, '/_https://') === 0) {
    $newUri = str_replace('/_https://', '/#https://', $requestUri);
    echo '<script>window.location.href = "' . htmlspecialchars($newUri) . '";</script>';
    exit;
}
?>
<?php
$currentDomain = $_SERVER['HTTP_HOST'];
$requestUri = $_SERVER['REQUEST_URI'];
$rootDomain = 'alceawis.com'; // Replace with your actual root domain
if ($currentDomain == $rootDomain && $requestUri == '/') {
    echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>' . PHP_EOL;
    echo '<script type="text/javascript">$(document).ready(function(){$("#tl").load("timeline.html");});</script>' . PHP_EOL;
    echo '<div class="formClass"><div id="tl"></div></div>' . PHP_EOL;}
?>



<?php
file_put_contents(__DIR__ . '/debug.log', "[" . date('c') . "] REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Error: Autoload file not found at $autoloadPath\n");
}
require $autoloadPath;

use ActivityPhp\Server;

$domain      = 'alceawis.com';
$username    = 'alceawis';
$baseUrl     = "https://$domain";
$server      = new Server();

$data           = json_decode(file_get_contents(__DIR__ . '/data_alcea.json'), true);
$pushedFile     = __DIR__ . '/pushed.json';
$pushed         = file_exists($pushedFile) ? json_decode(file_get_contents($pushedFile), true) : [];
$interactionFile = __DIR__ . '/interaction.json';
$outboxItems    = [];

/* ---------- helpers ---------- */
function formatEmojis($text) {
    return $text;                 // keep shortcode as‑is
}
function formatQuotes($text) {
    return preg_replace_callback('/ð’«(.*?)ð’«/s', function ($m) {
        return "<blockquote>" . trim($m[1]) . "</blockquote>";
    }, $text);
}
function formatDescriptionLinks(string $text): string {
    $escaped = htmlspecialchars($text);
    $html    = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="nofollow noopener noreferrer">$1</a>', $escaped);
    return nl2br($html);
}
function discoverInbox($actorUrl) {
    if (!str_ends_with($actorUrl, '.json')) $actorUrl = rtrim($actorUrl, '/') . '.json';
    $ctx = stream_context_create(['http' => ['method' => 'GET','header' => "Accept: application/activity+json, application/ld+json\r\n"]]);
    $json = @file_get_contents($actorUrl, false, $ctx);
    if (!$json) {
        file_put_contents(__DIR__ . '/inbox.log', "[" . date('c') . "] Failed to fetch actor JSON from $actorUrl\n", FILE_APPEND);
        return null;
    }
    $actor = json_decode($json, true);
    return $actor['inbox'] ?? null;
}
function sendSignedRequest($inboxUrl, $body) {
    $keyId         = "https://alceawis.com/alceawis#main-key";
    $privateKeyPem = file_get_contents(__DIR__ . '/private.pem');
    $date          = gmdate('D, d M Y H:i:s \G\M\T');
    $bodyJson      = json_encode($body, JSON_UNESCAPED_SLASHES);
    $digest        = 'SHA-256=' . base64_encode(hash('sha256', $bodyJson, true));
    $p             = parse_url($inboxUrl);
    $signatureStr  = "(request-target): post {$p['path']}\nhost: {$p['host']}\ndate: $date\ndigest: $digest";
    openssl_sign($signatureStr, $sig, $privateKeyPem, OPENSSL_ALGO_SHA256);
    $sigHeader = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode($sig) . '"';
    $hdrs = ["Host: {$p['host']}","Date: $date","Digest: $digest","Signature: $sigHeader","Content-Type: application/activity+json"];
    $ch = curl_init($inboxUrl);
    curl_setopt_array($ch, [CURLOPT_POST => true,CURLOPT_POSTFIELDS => $bodyJson,CURLOPT_HTTPHEADER => $hdrs,CURLOPT_RETURNTRANSFER => true]);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) file_put_contents(__DIR__ . '/inbox.log', "[" . date('c') . "] cURL error: " . curl_error($ch) . "\n", FILE_APPEND);
    curl_close($ch);
    return $resp;
}
function sendCreateActivity(array $note) {
    global $baseUrl, $username;
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $note['id'] . '/activity',
        'type'     => 'Create',
        'actor'    => "$baseUrl/$username",
        'object'   => $note,
        'to'       => ['https://www.w3.org/ns/activitystreams#Public'],
    ];
    $followers = file_exists(__DIR__ . '/followers.json') ? json_decode(file_get_contents(__DIR__ . '/followers.json'), true) : [];
    foreach ($followers as $follower) {
        if ($inbox = discoverInbox($follower)) sendSignedRequest($inbox, $activity);
    }
}

/* ---------- build outbox ---------- */
foreach ($data as $entry) {
    foreach ($entry as $date => $content) {
        $hash      = substr(md5($content['value']), 0, 8);
        $text      = formatEmojis($content['value']);
        $hashtags  = array_filter(array_map('trim', explode(',', $content['hashtags'] ?? '')));
        $quoted    = formatQuotes($text);
        $htmlText  = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="nofollow noopener noreferrer">$1</a>', $quoted);
        $htmlText  = preg_replace_callback('/#([\w-]+)/', fn($m) => "<a href=\"https://$GLOBALS[domain]/tags/" . urlencode($m[1]) . "\" rel=\"tag nofollow noopener noreferrer\">#" . htmlspecialchars($m[1]) . "</a>", $htmlText);
        $htmlText  = nl2br($htmlText);

        $tags = array_map(fn($tag) => ['type'=>'Hashtag','name'=>"#$tag",'href'=>"https://$GLOBALS[domain]/tags/$tag"], $hashtags);

        preg_match_all('/:([a-zA-Z0-9_]+):/', $content['value'], $emo);
        foreach ($emo[1] as $sc) $tags[] = ['type'=>'Emoji','name'=>":$sc:",'icon'=>['type'=>'Image','mediaType'=>'image/gif','url'=>"https://$GLOBALS[domain]/z_files/emojis/$sc.gif"]];

        $noteId = "$baseUrl/$username/status/{$date}-$hash";
        $note   = [
            'id'           => $noteId,
            'type'         => 'Note',
            'published'    => date(DATE_ATOM, strtotime($date)),
            'attributedTo' => "$baseUrl/$username",
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            'content'      => $htmlText,
            'contentMap'   => ['und' => $text,'html' => $htmlText],
            'tag'          => $tags,
        ];
        $outboxItems[] = $note;

        if (!in_array($noteId, $pushed)) {
            sendCreateActivity($note);
            $pushed[] = $noteId;
        }
    }
}
file_put_contents($pushedFile, json_encode($pushed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

/* ---------- router ---------- */
$uri = explode('?', $_SERVER['REQUEST_URI'] ?? '')[0];

if ($uri === "/$username" || $uri === "/$username/") {
    header('Content-Type: application/activity+json');
    header('Vary: Accept');
    $descHtml = formatDescriptionLinks("This is **Alcea's** semi‑automated profile! It fetches from a local timeline at https://alceawis.com.");
    echo json_encode([
        '@context'          => ['https://www.w3.org/ns/activitystreams',['manuallyApprovesFollowers'=>'as:manuallyApprovesFollowers','toot'=>'http://joinmastodon.org/ns#','featured'=>['@id'=>'toot:featured','@type'=>'@id']]],
        'id'                => "$baseUrl/$username",
        'type'              => 'Person',
        'name'              => 'Alcea Bot',
        'preferredUsername' => $username,
        'summary'           => $descHtml,
        'icon'              => ['type'=>'Image','mediaType'=>'image/gif','url'=>"$baseUrl/z_files/emojis/alceawis.gif"],
        'inbox'             => "$baseUrl/$username/inbox",
        'outbox'            => "$baseUrl/$username/outbox",
        'followers'         => "$baseUrl/$username/followers",
        'publicKey'         => ['id'=>"$baseUrl/$username#main-key",'owner'=>"$baseUrl/$username",'publicKeyPem'=>file_get_contents(__DIR__ . '/public.pem')],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($uri === "/$username/outbox" || $uri === "/$username/outbox/") {
    header('Content-Type: application/activity+json');
    if (($_GET['page'] ?? '') === 'true') {
        echo json_encode([
            '@context'      => ['https://www.w3.org/ns/activitystreams',['manuallyApprovesFollowers'=>'as:manuallyApprovesFollowers','toot'=>'http://joinmastodon.org/ns#','featured'=>['@id'=>'toot:featured','@type'=>'@id']]],
            'id'            => "$baseUrl/$username/outbox?page=true",
            'type'          => 'OrderedCollectionPage',
            'partOf'        => "$baseUrl/$username/outbox",
            'orderedItems'  => $outboxItems,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            '@context'   => ['https://www.w3.org/ns/activitystreams',['manuallyApprovesFollowers'=>'as:manuallyApprovesFollowers','toot'=>'http://joinmastodon.org/ns#','featured'=>['@id'=>'toot:featured','@type'=>'@id']]],
            'id'         => "$baseUrl/$username/outbox",
            'type'       => 'OrderedCollection',
            'totalItems' => count($outboxItems),
            'first'      => "$baseUrl/$username/outbox?page=true"
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    exit;
}

if (preg_match('/^\/' . $username . '\/status\/([a-z0-9\-]+)$/', $uri, $m)) {
    $postId = $m[1];
    $post   = null; $date = ''; $hash = '';
    foreach ($data as $entry)
        foreach ($entry as $d => $c)
            if ("$d-" . substr(md5($c['value']),0,8) === $postId) { $post=$c; $date=$d; $hash=substr(md5($c['value']),0,8); break 2; }
    if (!$post) { http_response_code(404); echo "Not found"; exit; }

    $text  = formatEmojis($post['value']);
    $html  = nl2br(preg_replace('~(https?://[^\s<]+)~i','<a href="$1" target="_blank" rel="nofollow noopener noreferrer">$1</a>',formatQuotes(htmlspecialchars($text))));
    $note  = [
        '@context'      => 'https://www.w3.org/ns/activitystreams',
        'id'            => "$baseUrl/$username/status/{$date}-$hash",
        'type'          => 'Note',
        'published'     => date(DATE_ATOM, strtotime($date)),
        'attributedTo'  => "$baseUrl/$username",
        'to'            => ['https://www.w3.org/ns/activitystreams#Public'],
        'content'       => $html,
        'contentMap'    => ['und'=>$post['value'],'html'=>$html],
        'tag'           => array_map(fn($t)=>['type'=>'Hashtag','name'=>"#$t",'href'=>"https://$domain/tags/$t"], explode(',',$post['hashtags']??'')),
    ];
    header('Content-Type: application/activity+json');
    echo json_encode($note, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($uri === "/$username/followers" || $uri === "/$username/followers/") {
    header('Content-Type: application/activity+json');
    header('Vary: Accept');
    $followers = file_exists(__DIR__ . '/followers.json') ? json_decode(file_get_contents(__DIR__ . '/followers.json'), true) : [];
    echo json_encode(['@context'=>'https://www.w3.org/ns/activitystreams','id'=>"$baseUrl/$username/followers",'type'=>'OrderedCollection','totalItems'=>count($followers),'orderedItems'=>$followers], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ----- inbox (merged) ----- */
if ($uri === "/$username/inbox" || $uri === "/$username/inbox/") {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo "Method Not Allowed";
        exit;
    }

    $payload = file_get_contents('php://input');
    file_put_contents(__DIR__ . '/inbox.log', "[" . date('c') . "] Received payload:\n$payload\n\n", FILE_APPEND);
    $activity = json_decode($payload, true);

    if (!$activity) {
        file_put_contents(__DIR__ . '/inbox.log', "[" . date('c') . "] ERROR: Could not decode JSON payload.\n\n", FILE_APPEND);
        http_response_code(400);
        echo "Bad Request: Invalid JSON";
        exit;
    }

    /* ---- Follow handling ---- */
    if ($activity['type'] === 'Follow') {
        $follower  = $activity['actor'] ?? null;
        if ($follower) {
            file_put_contents(__DIR__ . '/followed.log', $follower . "\n", FILE_APPEND);
            $followersFile = __DIR__ . '/followers.json';
            $followers     = file_exists($followersFile) ? json_decode(file_get_contents($followersFile), true) : [];
            if (!in_array($follower, $followers)) {
                $followers[] = $follower;
                file_put_contents($followersFile, json_encode($followers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            $accept = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id'       => "$baseUrl/$username/accepts/" . uniqid(),
                'type'     => 'Accept',
                'actor'    => "$baseUrl/$username",
                'object'   => $activity,
            ];
            if ($inbox = discoverInbox($follower)) sendSignedRequest($inbox, $accept);
            else file_put_contents(__DIR__ . '/inbox.log', "[" . date('c') . "] Could not discover inbox for follower: $follower\n", FILE_APPEND);
        }
    }

    /* ---- Like / Announce / Create logging ---- */
    if (in_array($activity['type'], ['Like','Announce','Create'])) {
        $interactions = file_exists($interactionFile) ? json_decode(file_get_contents($interactionFile), true) : [];
        $interaction  = [
            'id'          => $activity['id'] ?? uniqid('interaction_'),
            'type'        => $activity['type'],
            'actor'       => $activity['actor'] ?? null,
            'object'      => $activity['object'] ?? null,
            'published'   => $activity['published'] ?? date(DATE_ATOM),
            'received_at' => date(DATE_ATOM),
        ];
        if ($activity['type'] === 'Create') {
            if (!empty($activity['object']['inReplyTo'])) $interactions[] = $interaction;
        } else {
            $interactions[] = $interaction;
        }
        file_put_contents($interactionFile, json_encode($interactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    http_response_code(202);
    echo "Accepted";
    exit;
}

http_response_code(404);
