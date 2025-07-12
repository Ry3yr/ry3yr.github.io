<?php
// Folder containing your .gif emoji files
$emojiDir = __DIR__ . '/z_files/emojis';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$domain = $_SERVER['HTTP_HOST'];
$emojiUrlBase = $protocol . '://' . $domain . '/z_files/emojis'; // Dynamically generate the base URL

// Set header for JSON response
header('Content-Type: application/json');

// Check if directory exists
if (!is_dir($emojiDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Emoji directory not found.']);
    exit;
}

// Scan for .gif files
$files = glob($emojiDir . '/*.gif');

$result = [];
$counter = 554;
foreach ($files as $filePath) {
    $filename = basename($filePath);
    $shortcode = pathinfo($filename, PATHINFO_FILENAME);

    // Generate the URL directly to the image
    $emoji = [
        "shortcode" => $shortcode,
        "url" => "$emojiUrlBase/$filename",
        "static_url" => "$emojiUrlBase/$filename",
        "visible_in_picker" => true,
        "category" => "emoji"
    ];

    // Add the emoji data to the result array
    $result[] = $emoji;
    $counter++; // Increment for the next emoji
}

// Output the result as JSON
echo json_encode($result, JSON_PRETTY_PRINT);
