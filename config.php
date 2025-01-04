<?php
/**
 * Database Configuration
 * 
 * This file contains all database connection parameters and constants
 * used throughout the lap tracking system.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'crosscountry');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_SOCKET', '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock');

// System configuration
define('DEBOUNCE_WINDOW', 5); // Debouncing window in seconds
define('MAX_LAP_TIME', 600);  // Maximum reasonable lap time in seconds (10 minutes)
define('MIN_LAP_TIME', 30);   // Minimum reasonable lap time in seconds