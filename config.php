<?php
/**
 * Enhanced Lap Tracking System Configuration
 * 
 * This file contains all configuration parameters for the lap tracking system.
 * It maintains backward compatibility while supporting enhanced features.
 * 
 * Configuration Categories:
 * - Database Connection
 * - System Timing
 * - Signal Processing
 * - Environmental Monitoring
 * - Performance Tuning
 */

// Database credentials (Original configuration maintained)
define('DB_HOST', 'localhost');
define('DB_NAME', 'crosscountry');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_SOCKET', '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock');

// System timing (Original configuration maintained)
define('DEBOUNCE_WINDOW', 5);    // Debouncing window in seconds
define('MAX_LAP_TIME', 600);     // Maximum reasonable lap time in seconds (10 minutes)
define('MIN_LAP_TIME', 30);      // Minimum reasonable lap time in seconds

// Signal processing (New configurations)
define('RSSI_THRESHOLD', -75);   // Minimum acceptable signal strength (dBm)
define('SIGNAL_VARIANCE', 10);   // Acceptable RSSI variance
define('BEACON_UUID', 'b9407f30-f5f8-466e-aff9-25556b57fe6d');  // Default beacon UUID

// Database optimization (New configurations)
define('DB_MAX_CONNECTIONS', 10);     // Maximum database connections in pool
define('DB_INIT_CONNECTIONS', 3);     // Initial number of database connections
define('STATEMENT_CACHE_SIZE', 100);  // Maximum prepared statements to cache

// Performance tuning (New configurations)
define('BATCH_SIZE', 10);            // Number of records to process in batch
define('BATCH_TIMEOUT', 5);          // Maximum seconds to wait for batch completion
define('QUEUE_WARNING_THRESHOLD', 1000);  // Queue size warning threshold

// Health monitoring (New configurations)
define('HEALTH_CHECK_INTERVAL', 60);  // Seconds between health checks
define('CLEANUP_INTERVAL', 3600);     // Seconds between cleanup operations
define('MAX_RETRY_ATTEMPTS', 3);      // Maximum retry attempts for operations

// Checkpoint configuration (New configurations)
define('CHECKPOINT_ID', 1);           // Default checkpoint ID
define('CHECKPOINT_NAME', 'Main');    // Default checkpoint name

/**
 * Dynamic Configuration Loader
 * 
 * Loads additional configuration from database if available,
 * falls back to defaults if database is not accessible
 */
function loadDynamicConfig() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);
        if ($conn->connect_error) {
            error_log("Warning: Could not load dynamic configuration: " . $conn->connect_error);
            return false;
        }

        $sql = "SELECT config_key, config_value FROM system_config";
        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!defined($row['config_key'])) {
                    define($row['config_key'], $row['config_value']);
                }
            }
        }

        $conn->close();
        return true;
    } catch (Exception $e) {
        error_log("Warning: Error loading dynamic configuration: " . $e->getMessage());
        return false;
    }
}

// Load dynamic configuration if not in CLI mode
if (php_sapi_name() !== 'cli') {
    loadDynamicConfig();
}

/**
 * Configuration Validation
 * 
 * Validates critical configuration parameters
 */
function validateConfig() {
    $errors = [];

    // Validate timing parameters
    if (MIN_LAP_TIME >= MAX_LAP_TIME) {
        $errors[] = "MIN_LAP_TIME must be less than MAX_LAP_TIME";
    }

    if (DEBOUNCE_WINDOW <= 0) {
        $errors[] = "DEBOUNCE_WINDOW must be greater than 0";
    }

    // Validate RSSI threshold
    if (RSSI_THRESHOLD > 0) {
        $errors[] = "RSSI_THRESHOLD should be negative";
    }

    // Validate batch processing parameters
    if (BATCH_SIZE <= 0) {
        $errors[] = "BATCH_SIZE must be greater than 0";
    }

    if (!empty($errors)) {
        throw new Exception("Configuration validation failed:\n" . implode("\n", $errors));
    }

    return true;
}

// Validate configuration if not in CLI mode
if (php_sapi_name() !== 'cli') {
    validateConfig();
}