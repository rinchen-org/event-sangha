<?php
require_once dirname(dirname(__DIR__)) . '/lib/subscription.php'; // Adjust the path as needed

// Set the response content type to JSON
header('Content-Type: application/json');

// Fetch subscription data using Subscription::list()
try {
    $subscriptions = Subscription::list(["active" => 1]);

    if ($subscriptions === null) {
        $response = [
            'success' => true,
            'message' => 'No subscriptions found',
            'data' => []
        ];
    } else {
        $response = [
            'success' => true,
            'message' => 'Subscriptions retrieved successfully',
            'data' => $subscriptions
        ];
    }
} catch (Exception $e) {
    // Handle any exceptions or errors
    $response = [
        'success' => false,
        'message' => 'Error retrieving subscriptions: ' . $e->getMessage()
    ];
}

// Encode the response array as JSON and print it
echo json_encode($response);
