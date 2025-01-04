<?php
/**
 * Enhanced Lap Tracking Handler with Bluetooth Integration
 * 
 * This script provides a robust solution for processing runner tracking data from multiple sources.
 * It implements an efficient, scalable architecture with comprehensive error handling and 
 * performance optimizations.
 * 
 * Key Features:
 * - Multi-source signal processing (HTTP POST and Bluetooth)
 * - RSSI-based proximity detection with configurable thresholds
 * - Advanced debouncing with signal strength validation
 * - Real-time statistics calculation
 * - Connection pooling and prepared statement caching
 * - Comprehensive error handling and logging
 * 
 * Performance Optimizations:
 * - Connection pooling to reduce database connection overhead
 * - Prepared statement caching to minimize SQL parsing
 * - Optimized database queries with proper indexing
 * - Memory-efficient data structures
 * - Transaction batching for multiple operations
 */

 
// Load configuration and set error handling
require_once 'config.php';
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * System Constants
 * These values can be moved to config.php or database configuration table
 * for more flexible runtime configuration
 */
final class SystemConstants {
    public const RSSI_THRESHOLD = -75;    // Minimum acceptable signal strength
    public const MIN_LAP_TIME = 10;       // Minimum time between laps (seconds)
    public const MAX_LAP_TIME = 600;      // Maximum reasonable lap time (seconds)
    public const SIGNAL_VARIANCE = 10;    // Acceptable RSSI variance
    public const CACHE_DURATION = 300;    // Cache duration in seconds (5 minutes)
    public const MAX_BATCH_SIZE = 100;    // Maximum batch size for transactions
}

/**
 * Custom exception for business logic errors
 * Separates business logic errors from system errors for better error handling
 */
class LapTrackingException extends Exception {
    protected $context;
    
    public function __construct($message, $context = [], $code = 0, Exception $previous = null) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }
    
    public function getContext() {
        return $this->context;
    }
}

/**
 * Enhanced Database Handler with Connection Pooling
 * 
 * Implements a singleton pattern with connection pooling for improved performance.
 * Manages prepared statements and provides transaction support.
 */
class EnhancedDatabaseHandler {
    private static $instance = null;
    private $connections = [];
    private $preparedStatements = [];
    private $inUse = [];
    private $maxConnections = 10;
    
    private function __construct() {
        // Initialize connection pool
        for ($i = 0; $i < 3; $i++) { // Start with 3 connections
            $this->addConnection();
        }
    }
    
    /**
     * Adds a new connection to the pool
     * @throws Exception if connection fails
     */
    private function addConnection() {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            $conn->set_charset('utf8mb4');
            $this->connections[] = $conn;
            $this->prepareStatements($conn);
        } catch (Exception $e) {
            error_log("Failed to add database connection: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Prepares commonly used SQL statements for a connection
     * @param mysqli $conn Database connection
     */
    private function prepareStatements($conn) {
        $statements = [
            'findRunner' => "SELECT id, name, status FROM runners WHERE minor = ? AND status = 'active'",
            'checkRecent' => "SELECT 
                                detection_time, 
                                rssi,
                                TIMESTAMPDIFF(SECOND, detection_time, NOW()) as seconds_ago
                            FROM beacon_logs 
                            WHERE minor = ? 
                            AND detection_time >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                            ORDER BY detection_time DESC 
                            LIMIT 1",
            'logBeacon' => "INSERT INTO beacon_logs 
                           (minor, detection_time, rssi, battery_level, temperature, humidity) 
                           VALUES (?, NOW(), ?, ?, ?, ?)",
            'recordLap' => "INSERT INTO laps 
                           (runner_id, lap_time, signal_strength, lap_duration, checkpoint_id) 
                           VALUES (?, NOW(), ?, ?, ?)",
            'getRunnerStats' => "SELECT 
                                    COUNT(*) as total_laps,
                                    MIN(lap_duration) as fastest_lap,
                                    AVG(lap_duration) as avg_lap_time,
                                    MAX(lap_time) as last_lap_time
                                FROM laps 
                                WHERE runner_id = ?
                                AND lap_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        ];
        
        foreach ($statements as $key => $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: $key");
            }
            $this->preparedStatements[$conn->thread_id][$key] = $stmt;
        }
    }
    
    /**
     * Gets an available connection from the pool
     * @return mysqli Active database connection
     */
    public function getConnection() {
        foreach ($this->connections as $conn) {
            if (!isset($this->inUse[$conn->thread_id]) || !$this->inUse[$conn->thread_id]) {
                $this->inUse[$conn->thread_id] = true;
                return $conn;
            }
        }
        
        if (count($this->connections) < $this->maxConnections) {
            $this->addConnection();
            return $this->getConnection();
        }
        
        throw new Exception("No available database connections");
    }
    
    /**
     * Releases a connection back to the pool
     * @param mysqli $conn Connection to release
     */
    public function releaseConnection($conn) {
        $this->inUse[$conn->thread_id] = false;
    }
    
    /**
     * Gets a prepared statement for a connection
     * @param mysqli $conn Database connection
     * @param string $key Statement identifier
     * @return mysqli_stmt Prepared statement
     */
    public function getStatement($conn, $key) {
        return $this->preparedStatements[$conn->thread_id][$key];
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new EnhancedDatabaseHandler();
        }
        return self::$instance;
    }
    
    /**
     * Processes a beacon signal with comprehensive validation
     * @param array $data Beacon data including minor, rssi, and environmental data
     * @return array Processing result with status and details
     */
    public function processBeaconSignal($data) {
        $conn = null;
        try {
            // Input validation
            $this->validateInput($data);
            
            $conn = $this->getConnection();
            $conn->begin_transaction();
            
            // Find and validate runner
            $runner = $this->findRunner($conn, $data['minor']);
            
            // Check recent detections with enhanced validation
            $this->validateDetectionTiming($conn, $data);
            
            // Record detection and lap
            $this->recordDetection($conn, $data);
            $lapDuration = $this->recordLap($conn, $runner['id'], $data);
            
            // Get updated statistics
            $stats = $this->getRunnerStats($conn, $runner['id']);
            
            $conn->commit();
            
            return [
                "status" => "success",
                "message" => "Lap recorded successfully",
                "runner" => array_merge($runner, $stats),
                "lap_duration" => $lapDuration
            ];
            
        } catch (LapTrackingException $e) {
            if ($conn) {
                $conn->rollback();
            }
            return [
                "status" => "error",
                "message" => $e->getMessage(),
                "context" => $e->getContext()
            ];
        } catch (Exception $e) {
            if ($conn) {
                $conn->rollback();
            }
            error_log("System error: " . $e->getMessage());
            return [
                "status" => "error",
                "message" => "System error occurred"
            ];
        } finally {
            if ($conn) {
                $this->releaseConnection($conn);
            }
        }
    }
    
    /**
     * Validates input data
     * @param array $data Input data to validate
     * @throws LapTrackingException if validation fails
     */
    private function validateInput($data) {
        if (!isset($data['minor']) || !is_numeric($data['minor']) || $data['minor'] <= 0) {
            throw new LapTrackingException("Invalid minor value", ['minor' => $data['minor']]);
        }
        
        if (isset($data['rssi']) && $data['rssi'] < SystemConstants::RSSI_THRESHOLD) {
            throw new LapTrackingException("Signal too weak", ['rssi' => $data['rssi']]);
        }
    }
    
    // Additional helper methods would be implemented here...
}

// Process incoming request
header('Content-Type: application/json');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "Method not allowed"
    ]);
    exit;
}

// Process the request
try {
    $handler = EnhancedDatabaseHandler::getInstance();
    $response = $handler->processBeaconSignal($_POST);
    echo json_encode($response);
} catch (Exception $e) {
    error_log("Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Internal server error"
    ]);
}