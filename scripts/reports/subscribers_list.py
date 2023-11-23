import requests
import json
from jinja2 import Environment, FileSystemLoader

# Define the API endpoint URL
api_url = "http://localhost:8081/api/subscription/list.php"

# Make a GET request to the API
response = requests.get(api_url)

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
            # response = requests.get(image_url)

            tex_file_path = f"images/{image_url.split('/')[-1]}"
            local_file_path = f"./output/{tex_file_path}"
            # image_data = response.content

            subscription["qr_filename"] = f"./{tex_file_path}"

            # Save the image to the local file
            # with open(local_file_path, 'wb') as file:
            #     file.write(image_data)


        # Render the LaTeX document with data
        rendered_latex = template.render(subscriptions=subscriptions)

        # Save the rendered LaTeX document to a .tex file
        with open("output/subscription_list.tex", "w") as tex_file:
            tex_file.write(rendered_latex)

        print("LaTeX document created successfully.")
    else:
        print("No subscription data found in the API response.")
        exit(0)
else:
    print("Failed to fetch data from the API. Status code:", response.status_code)
    exit(0)
