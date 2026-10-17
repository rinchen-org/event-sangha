import requests
import json
import os
from getpass import getpass
from html.parser import HTMLParser
from urllib.parse import urlparse
from jinja2 import Environment, FileSystemLoader


class LoginForm(HTMLParser):
    def __init__(self):
        super().__init__()
        self.csrf = None

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        if tag == 'input' and fields.get('name') == 'csrf_token':
            self.csrf = fields.get('value')


base_url = os.environ.get('RINCHEN_REPORT_BASE_URL', 'http://localhost:8081').rstrip('/')
origin = urlparse(base_url)
if origin.scheme != 'https' and not (origin.scheme == 'http' and origin.hostname in ('localhost', '127.0.0.1', '::1')):
    raise SystemExit('Use HTTPS for remote report authentication.')
client = requests.Session()
login_url = base_url + '/templates/login.php'
login = client.get(login_url, timeout=30)
login.raise_for_status()
form = LoginForm()
form.feed(login.text)
if not form.csrf:
    raise SystemExit('Login form unavailable; no report was generated.')
login = client.post(login_url, data={
    'username': os.environ.get('RINCHEN_REPORT_USER', 'admin'),
    'password': os.environ.get('RINCHEN_REPORT_PASSWORD') or getpass('Contraseña de Rinchen: '),
    'csrf_token': form.csrf,
}, timeout=30, allow_redirects=False)
if login.status_code != 303:
    raise SystemExit(f'Login failed (HTTP {login.status_code}); no report was generated.')

# Define the API endpoint URL
api_url = base_url + "/api/subscription/list.php"

# Make a GET request to the API
response = client.get(api_url, timeout=30)

# Check if the request was successful (status code 200)
if response.status_code == 200:
    # Parse the JSON response
    data = json.loads(response.text)

    # Check if the API response contains data
    if 'data' in data:
        subscriptions = data['data']

        # Create a Jinja2 environment
        env = Environment(loader=FileSystemLoader('.'))

        # Load the LaTeX template
        template = env.get_template('subscription_template.tex')

        for subscription in subscriptions:
            image_url = subscription["qr"]
            if urlparse(image_url)[:2] != origin[:2]:
                raise SystemExit('QR URL must belong to the authenticated report origin.')
            response = client.get(image_url, timeout=30, allow_redirects=False)
            response.raise_for_status()
            if response.status_code != 200:
                raise SystemExit('QR image unavailable; refresh the report session.')

            tex_file_path = f"images/{image_url.split('/')[-1]}"
            local_file_path = f"./output/{tex_file_path}"
            image_data = response.content

            subscription["qr_filename"] = f"./{tex_file_path}"

            # Save the image to the local file
            with open(local_file_path, 'wb') as file:
                file.write(image_data)


        # Render the LaTeX document with data
        rendered_latex = template.render(subscriptions=subscriptions)

        # Save the rendered LaTeX document to a .tex file
        with open("output/subscription_list.tex", "w") as tex_file:
            tex_file.write(rendered_latex)

        print("LaTeX document created successfully.")
    else:
        print("No subscription data found in the API response.")
        exit(1)
else:
    print("Failed to fetch data from the API. Status code:", response.status_code)
    exit(1)
