# Project Name

Welcome to the Project Name repository! This document provides guidelines on how
to contribute to this project and set up your local development environment.

## Table of Contents

- [Contributing](#contributing)
  - [Getting Started](#getting-started)
  - [Making Changes](#making-changes)
  - [Creating a Pull Request](#creating-a-pull-request)
- [Setting Up the Development Environment](#setting-up-the-development-environment)
  - [Prerequisites](#prerequisites)
  - [Installing Dependencies](#installing-dependencies)
  - [Enabling PHP Extensions](#enabling-php-extensions)

## Contributing

We welcome contributions from the community! Follow the steps below to
contribute to this project.

### Getting Started

1. Fork the repository by clicking the "Fork" button at the top right of this
   page.

2. Clone your forked repository to your local machine:

   ```bash
   git clone https://github.com/your-username/project-name.git
   ```

3. Change directory to the project:

   ```bash
   cd project-name
   ```

4. Add the original repository as a remote:

   ```bash
   git remote add upstream https://github.com/original-owner/project-name.git
   ```

### Making Changes

1. Create a new branch for your feature or bug fix:

   ```bash
   git checkout -b my-feature-branch
   ```

2. Make your changes and commit them:

   ```bash
   git add .
   git commit -m "Description of changes"
   ```

3. Push your changes to your forked repository:

   ```bash
   git push origin my-feature-branch
   ```

### Creating a Pull Request

1. Visit your forked repository on GitHub.

2. Click on the "New Pull Request" button.

3. Set the base repository to the original repository and the base branch to the
   branch you want to merge your changes into.

4. Review your changes and click "Create Pull Request."

## Setting Up the Development Environment

To work on this project locally, follow these instructions to set up your
development environment.

### Prerequisites

- PHP (version >= 8.2)
- Composer

### Installing Dependencies

1. Install Composer (if not already installed):
   [Composer Installation Guide](https://getcomposer.org/download/)

2. Clone the repository to your local machine (if you haven't already):

   ```bash
   git clone https://github.com/your-username/project-name.git
   ```

3. Change directory to the project:

   ```bash
   cd project-name
   ```

4. Install project dependencies using Composer:

   ```bash
   composer install
   ```

### Enabling PHP Extensions

Ensure that the following PHP extensions are enabled in your php.ini
configuration file:

- `mbstring`
- `dom`

You can enable these extensions by modifying your php.ini file:

- For Ubuntu/Debian:

  ```bash
  sudo nano /etc/php/8.2/cli/php.ini
  ```

- For CentOS/RHEL:

  ```bash
  sudo nano /etc/php.ini
  ```

- For macOS with Homebrew:

  ```bash
  sudo nano /usr/local/etc/php/8.2/php.ini
  ```

Uncomment the lines for `mbstring` and `dom` extensions by removing the
semicolon (`;`) at the beginning of the lines:

```ini
extension=mbstring
extension=dom
```

Save the changes and restart your web server or PHP-FPM service:

- For Apache:

  ```bash
  sudo service apache2 restart
  ```

- For Nginx:

  ```bash
  sudo service nginx restart
  ```

- For PHP-FPM:

  ```bash
  sudo service php8.2-fpm restart
  ```

### Conda Environment

To set up the Conda environment for this project, use the following commands:

```bash
# Create the Conda environment from the provided YAML file
mamba env create -n conda/dev.yaml

# Activate the Conda environment
conda activate rinchen
```

### Makim Automation

We use Makim for automation tasks. Here are some common tasks:

#### Run the Server Application

To run the server application, use the following Makim command:

```bash
makim dev.runserver
```

This command will start the PHP server with the appropriate environment
variables.

#### Run Tests

To run the PHPUnit tests, use the following Makim command:

```bash
makim dev.tests
```

This command will execute PHPUnit tests located in the specified test file.

## Usage

TBD

## License

This project is licensed under the MIT - see the
[LICENSE.md](LICENSE.md) file for details.
