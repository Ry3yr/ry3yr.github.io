<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Function to fetch data from a URL
function fetchDataFromUrl($url) {
    // Validate and sanitize the URL
    $url = filter_var($url, FILTER_SANITIZE_URL);

    // Check if the URL is valid
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL'];
    }

    // Use file_get_contents or cURL to fetch the data
    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return ['error' => 'Unable to fetch data from URL'];
    }

    return ['data' => $response, 'headers' => $http_response_header];
}

// Check if the request is a GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['query'])) {
        $url = htmlspecialchars($_GET['query']);
        $result = fetchDataFromUrl($url);

        if (isset($result['error'])) {
            // Return a JSON response with an error
            header('Content-Type: application/json');
            echo json_encode($result);
        } else {
            // Determine the content type from the headers
            $contentType = 'text/plain'; // Default content type

            foreach ($result['headers'] as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = substr($header, strlen('Content-Type: '));
                    break;
                }
            }

            header('Content-Type: ' . $contentType);
            echo $result['data'];
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No query parameter provided']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request method']);
}
