#!/usr/bin/env python3

import os
import sys
import time
import json
import queue
import logging
import threading
import requests
from datetime import datetime
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor
import bluetooth._bluetooth as bluez
from requests.adapters import HTTPAdapter, Retry
import ScanUtility

# Configure logging with rotation
from logging.handlers import RotatingFileHandler

# Type definitions for better code organization
@dataclass
class BeaconData:
    """Data structure for beacon information"""
    minor: int
    rssi: int
    timestamp: float
    battery_level: Optional[int] = None
    temperature: Optional[float] = None
    humidity: Optional[float] = None

class HTTPError(Exception):
    """Custom exception for HTTP-related errors"""
    def __init__(self, message: str, status_code: Optional[int] = None, response_text: Optional[str] = None):
        self.status_code = status_code
        self.response_text = response_text
        super().__init__(message)

class SystemConfig:
    """System configuration with environment variable support"""
    def __init__(self):
        self.ENDPOINT = os.getenv('HANDLER_ENDPOINT', 'http://localhost:8000/enhanced_handler.php')
        self.BEACON_UUID = os.getenv('BEACON_UUID', 'b9407f30-f5f8-466e-aff9-25556b57fe6d')
        self.RSSI_THRESHOLD = int(os.getenv('RSSI_THRESHOLD', '-75'))
        self.SCAN_INTERVAL = int(os.getenv('SCAN_INTERVAL', '2'))
        self.CHECKPOINT_ID = int(os.getenv('CHECKPOINT_ID', '1'))
        self.BATCH_SIZE = int(os.getenv('BATCH_SIZE', '10'))
        self.BATCH_TIMEOUT = int(os.getenv('BATCH_TIMEOUT', '5'))
        self.MAX_RETRIES = int(os.getenv('MAX_RETRIES', '3'))
        self.RETRY_DELAY = int(os.getenv('RETRY_DELAY', '1'))
        self.REQUEST_TIMEOUT = int(os.getenv('REQUEST_TIMEOUT', '5'))
        
        # Validate configuration
        self._validate_config()
    
    def _validate_config(self):
        """Validate configuration values"""
        if self.RSSI_THRESHOLD > 0:
            raise ValueError("RSSI_THRESHOLD should be negative")
        if self.SCAN_INTERVAL < 1:
            raise ValueError("SCAN_INTERVAL must be at least 1 second")

class HTTPClient:
    """HTTP client with connection pooling and comprehensive retry logic"""
    def __init__(self, config: SystemConfig):
        self.config = config
        self.session = self._create_session()
        
    def _create_session(self) -> requests.Session:
        """Create and configure requests session with retry logic"""
        session = requests.Session()
        
        # Configure retry strategy
        retry_strategy = Retry(
            total=self.config.MAX_RETRIES,
            backoff_factor=self.config.RETRY_DELAY,
            status_forcelist=[408, 429, 500, 502, 503, 504],
            allowed_methods=["POST"],
            raise_on_status=True
        )
        
        # Configure adapter with retry strategy
        adapter = HTTPAdapter(
            max_retries=retry_strategy,
            pool_connections=10,
            pool_maxsize=10
        )
        
        session.mount("http://", adapter)
        session.mount("https://", adapter)
        
        return session
    
    def send_beacon_data(self, data: BeaconData) -> dict:
        """
        Send beacon data to handler with comprehensive retry logic and validation
        
        Args:
            data: BeaconData object containing beacon information
            
        Returns:
            dict: Processed response from the handler
            
        Raises:
            HTTPError: For any HTTP-related errors
            ValueError: For invalid response data
            Exception: For other unexpected errors
        """
        try:
            # Convert BeaconData to dict and add checkpoint ID
            payload = asdict(data)
            payload['checkpoint_id'] = self.config.CHECKPOINT_ID
            
            # Log request attempt
            logging.debug(f"Sending beacon data: {payload}")
            
            # Send request with timeout
            response = self.session.post(
                self.config.ENDPOINT,
                data=payload,
                timeout=self.config.REQUEST_TIMEOUT
            )
            
            # Check for HTTP errors
            response.raise_for_status()
            
            # Parse and validate response
            try:
                response_data = response.json()
            except json.JSONDecodeError as e:
                raise ValueError(f"Invalid JSON response: {e}")
            
            # Validate response structure
            if not isinstance(response_data, dict):
                raise ValueError("Response must be a JSON object")
            
            if 'status' not in response_data:
                raise ValueError("Response missing 'status' field")
            
            # Log success
            logging.debug(f"Successfully processed beacon {data.minor}: {response_data}")
            
            return response_data
            
        except requests.exceptions.Timeout as e:
            error_msg = f"Timeout sending beacon data: {e}"
            logging.error(error_msg)
            raise HTTPError(error_msg)
            
        except requests.exceptions.ConnectionError as e:
            error_msg = f"Connection error sending beacon data: {e}"
            logging.error(error_msg)
            raise HTTPError(error_msg)
            
        except requests.exceptions.RequestException as e:
            status_code = e.response.status_code if e.response else None
            response_text = e.response.text if e.response else None
            error_msg = f"HTTP error sending beacon data: {e}"
            logging.error(f"{error_msg} (Status: {status_code}, Response: {response_text})")
            raise HTTPError(error_msg, status_code, response_text)
            
        except ValueError as e:
            error_msg = f"Invalid response data: {e}"
            logging.error(error_msg)
            raise
            
        except Exception as e:
            error_msg = f"Unexpected error sending beacon data: {e}"
            logging.error(error_msg)
            raise

    def __del__(self):
        """Cleanup session on deletion"""
        try:
            self.session.close()
        except Exception as e:
            logging.error(f"Error closing HTTP session: {e}")

# Rest of the BeaconScanner class implementation remains the same...