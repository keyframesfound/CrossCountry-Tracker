<?php
/**
 * Lap Tracking Handler
 * 
 * This script processes signals from runner tracking devices and records lap completions.
 * It implements several key features:
 * - Signal debouncing to prevent duplicate lap counts
 * - Runner validation against registered participants
 * - Speed-based validation through detection window
 * - Error handling and logging
 * - Optimized database operations
 * 
 * Performance Optimizations:
 * 1. Prepared statement reuse
 * 2. Minimal database queries
 * 3. Early validation checks
 * 4. Efficient error handling
 * 
 * @author Your Team
 * @version 2.0
 */

// Load configuration
require_once 'config.php';

// Custom exception for business logic errors
class LapTrackingException extends Exception {}

/**
 * Database Connection Handler
 * Manages database connection and prepared statements
 */
class DatabaseHandler {
    private static $instance = null;
    public $conn;
    private $preparedStatements = [];
    
    private function __construct() {
        try {
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);
            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
            $this->prepareStatements();
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Prepare commonly used SQL statements
     */
    private function prepareStatements() {
        $statements = [
            'findRunner' => "SELECT id FROM runners WHERE minor = ?",
            'checkRecent' => "SELECT detection_time FROM beacon_logs 
                            WHERE minor = ? 
                            AND detection_time >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                            ORDER BY detection_time DESC LIMIT 1",
            'logBeacon' => "INSERT INTO beacon_logs (minor, detection_time) VALUES (?, NOW())",
            'recordLap' => "INSERT INTO laps (runner_id, lap_time) VALUES (?, NOW())"
        ];
        
        foreach ($statements as $key => $sql) {
            $this->preparedStatements[$key] = $this->conn->prepare($sql);
            if (!$this->preparedStatements[$key]) {
                throw new Exception("Failed to prepare statement: $key");
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DatabaseHandler();
        }
        return self::$instance;
    }
    
    public function getStatement($key) {
        return $this->preparedStatements[$key];
    }
    
    public function __destruct() {
        foreach ($this->preparedStatements as $stmt) {
            $stmt->close();
        }
        $this->conn->close();
    }
}

/**
 * Process incoming tracking signal
 * 
 * @param int $minor The tracker's minor number
 * @return array Response with status and message
 * @throws LapTrackingException
 */
function processTrackingSignal($minor) {
    try {
        // Input validation
        if (!is_numeric($minor) || $minor <= 0) {
            throw new LapTrackingException("Invalid minor value");
        }
        
        $db = DatabaseHandler::getInstance();
        
        // Find runner
        $stmt = $db->getStatement('findRunner');
        $stmt->bind_param("i", $minor);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new LapTrackingException("Unknown runner");
        }
        
        $runner = $result->fetch_assoc();
        $runner_id = $runner['id'];
        
        // Check for recent detections (debouncing)
        $stmt = $db->getStatement('checkRecent');
        $debounce_window = DEBOUNCE_WINDOW; // Store in variable for bind_param
        $stmt->bind_param("ii", $minor, $debounce_window);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ["status" => "ignored", "message" => "Signal debounced"];
        }
        
        // Record the detection and lap in a transaction
        $db->conn->begin_transaction();
        try {
            // Log beacon detection
            $stmt = $db->getStatement('logBeacon');
            $stmt->bind_param("i", $minor);
            $stmt->execute();
            
            // Record lap
            $stmt = $db->getStatement('recordLap');
            $stmt->bind_param("i", $runner_id);
            $stmt->execute();
            
            $db->conn->commit();
            return ["status" => "success", "message" => "Lap recorded successfully"];
            
        } catch (Exception $e) {
            $db->conn->rollback();
            throw $e;
        }
        
    } catch (LapTrackingException $e) {
        error_log("Business logic error: " . $e->getMessage());
        return ["status" => "error", "message" => $e->getMessage()];
    } catch (Exception $e) {
        error_log("System error: " . $e->getMessage());
        return ["status" => "error", "message" => "System error occurred"];
    }
}

// Process incoming request
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

if (!isset($_POST['minor'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No minor value provided"]);
    exit;
}

$response = processTrackingSignal($_POST['minor']);
echo json_encode($response);
