// Start output buffering
ob_start();

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the message from the POST request
    $message = isset($_POST['message']) ? $_POST['message'] : '';

    // Display the message
    echo "<html>";
    echo "<head><title>Message Received</title></head>";
    echo "<body>";
    
    if ($message === 'minor:96') {
        echo "<h1>Success!</h1>";
        echo "<p>Received message: <strong>" . htmlspecialchars($message) . "</strong></p>";
    } else {
        echo "<h1>Error</h1>";
        echo "<p>Unexpected message received: <strong>" . htmlspecialchars($message) . "</strong></p>";
    }

    echo "</body>";
    echo "</html>";
} else {
    // If the request method is not POST
    echo "<html>";
    echo "<head><title>Invalid Request</title></head>";
    echo "<body>";
    echo "<h1>Error</h1>";
    echo "<p>Invalid request method.</p>";
    echo "</body>";
    echo "</html>";
}

// Flush the output buffer and turn off output buffering
ob_end_flush();
?>