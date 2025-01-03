<?php
// Database configuration
$host = 'localhost'; // or your database host
$db = 'crosscountry';
$user = 'root';
$pass = '';

// Create a connection
$conn = new mysqli($host, $user, $pass, $db);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if 'minor' is set in the POST data
if (isset($_POST['minor'])) {
    $minor = $_POST['minor'];
    
    // First, check for recent entry (within last 10 seconds)
    $check_query = "SELECT id, timestamp FROM beacon 
                   WHERE minor = ? 
                   AND timestamp >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
                   ORDER BY timestamp DESC 
                   LIMIT 1";
    
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $minor);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Found recent entry, just update timestamp
        $row = $result->fetch_assoc();
        $update_query = "UPDATE beacon SET timestamp = NOW() WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
        echo "Timestamp updated for existing entry";
    } else {
        // No recent entry found, insert new record
        $insert_query = "INSERT INTO beacon (minor, timestamp) VALUES (?, NOW())";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("i", $minor);
        $stmt->execute();
        echo "New entry created";
    }
} else {
    echo "No minor value provided.";
}

// Close the connection
$conn->close();
?>
