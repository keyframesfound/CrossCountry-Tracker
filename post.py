import requests

# Define the URL of the PHP handler
url = 'http://localhost/CrossCountryHandler/handler.php'

# Define the data to be sent
data = {'minor': 96}

# Send a POST request
response = requests.post(url, data=data)

# Print the response from the server
print('Response:', response.text)