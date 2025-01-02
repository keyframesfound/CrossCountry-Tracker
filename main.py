import requests
import time

def detect_message():
    # Simulated message detection
    # In a real scenario, this could be replaced with actual message listening logic
    messages = ["hello", "minor:96", "test", "minor:95"]
    for message in messages:
        print(f"Detected message: {message}")
        if message == "minor:96":
            return True
    return False

def post_to_handler():
    url = "http://localhost/handler/handler.php"  # Replace with your actual URL
    data = {"message": "minor:96"}
    
    try:
        response = requests.post(url, data=data)
        if response.status_code == 200:
            print("Successfully posted to handler.php")
        else:
            print(f"Failed to post: {response.status_code} - {response.text}")
    except requests.exceptions.RequestException as e:
        print(f"An error occurred: {e}")

def main():
    while True:
        if detect_message():
            post_to_handler()
            break  # Exit after posting, remove if you want continuous detection
        time.sleep(1)  # Wait for a while before checking again

if __name__ == "__main__":
    main()
