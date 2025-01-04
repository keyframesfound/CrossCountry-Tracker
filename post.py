import requests
import time
from concurrent.futures import ThreadPoolExecutor
import json

# Define the URL of the PHP handler
url = 'http://localhost:8000/handler.php'

def simulate_runner(minor, count, interval):
    """
    Simulate detections for a single runner
    
    Args:
        minor (int): Runner's minor ID
        count (int): Number of detections to simulate
        interval (float): Time between detections in seconds
    """
    for i in range(count):
        data = {'minor': minor}
        try:
            response = requests.post(url, data=data)
            result = response.json()
            print(f'Runner {minor} - Detection {i+1}: {result["message"]} ({result["status"]})')
        except requests.exceptions.RequestException as e:
            print(f'Runner {minor} - Detection {i+1}: Error - {str(e)}')
        except json.JSONDecodeError:
            print(f'Runner {minor} - Detection {i+1}: Error - Invalid response format')
        time.sleep(interval)

def simulate_competition():
    """
    Simulate multiple runners in a competition with different patterns:
    - Runner A (96): Normal pace
    - Runner B (97): Slightly faster pace
    - Runner C (98): Fastest pace
    """
    runners = [
        (96, 3, 6),  # Runner A: 3 detections with 6 second intervals
        (97, 3, 5),  # Runner B: 3 detections with 5 second intervals
        (98, 3, 4)   # Runner C: 3 detections with 4 second intervals
    ]
    
    with ThreadPoolExecutor() as executor:
        # Start all runners simultaneously
        futures = [
            executor.submit(simulate_runner, minor, count, interval)
            for minor, count, interval in runners
        ]
        
        # Wait for all runners to finish
        for future in futures:
            future.result()

def test_error_cases():
    """Test various error cases to ensure proper handling"""
    print("\nTesting error cases:")
    
    # Test invalid minor value
    data = {'minor': 'invalid'}
    response = requests.post(url, data=data)
    result = response.json()
    print(f'Invalid minor test: {result["message"]} ({result["status"]})')
    
    # Test unknown runner
    data = {'minor': 99}
    response = requests.post(url, data=data)
    result = response.json()
    print(f'Unknown runner test: {result["message"]} ({result["status"]})')
    
    # Test missing minor parameter
    response = requests.post(url, data={})
    result = response.json()
    print(f'Missing minor test: {result["message"]} ({result["status"]})')

def test_debouncing():
    """Test the debouncing mechanism"""
    print("\nTesting debouncing (rapid signals):")
    simulate_runner(96, 3, 1)  # 3 rapid detections with 1 second intervals

if __name__ == "__main__":
    print("Starting lap tracking system tests...")
    
    print("\nTesting normal competition scenario:")
    simulate_competition()
    
    test_debouncing()
    
    test_error_cases()
    
    print("\nTests completed.")