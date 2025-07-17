# Docker Base Template Composer Plugin

This project provides a **Composer plugin** that automatically copies a ready-to-use Docker configuration (including Dockerfiles, Compose files, and environment templates) into the root of your PHP project when you require this package. It is designed to help PHP/Drupal projects quickly bootstrap a local Docker-based development environment with best practices.

---

## Supported Variants

This template supports **two main variants** for your Docker base image:

### 1. Ubuntu Base Image (~1.24.0-rc1)

- Uses `ubuntu:24.04` as the base image.
- Suitable for projects that need more control over the OS environment or require additional non-PHP services.
- Installs PHP, Nginx, and other dependencies via `apt`, based on an external `apt-packages.env` file.
- Provides a more flexible and extensible environment for advanced use cases.

### 2. PHP-FPM Base Image (~2.83.0-rc1)

- Uses `php:8.3-fpm` as the base image.
- Suitable for projects that want a direct PHP runtime with FPM, commonly used for web applications.
- Installs PHP extensions and system dependencies required for Drupal and similar PHP projects.
- Composer and other developer tools are included.
- **Recommended for most PHP/Drupal projects.**

---

## Versions

- **Ubuntu Variant:** `~1.24.0-rc1`
- **PHP-FPM Variant:** `~2.83.0-rc1`

You can specify the version you want to use when requiring the package:

```sh
composer require --dev razeem/docker-base-template:~1.24.0-rc1
# or
composer require --dev razeem/docker-base-template:~2.83.0-rc1
```

---

## Features

- **Automatic Docker Setup:**  
  On `composer install` or `composer update`, the plugin copies the `dist/` folder (containing Dockerfiles, Compose files, and config templates) into your project root.

- **Dynamic Project Naming:**  
  If a `project-code.txt` file exists in your project root, its contents are used to replace `project_name` and `project_folder` placeholders in `docker-compose.yml` and `docker-compose-vm.yml`.  
  If not, a random 3-character string is generated and used.

- **Environment Variable Management:**  
  Uses a generic `.env.dist` file for environment variables, which you can copy to `.env` and customize.

- **Extensible Docker Stack:**  
  Includes configuration for PHP, Nginx, SSH, and other common services.  
  Installs system packages based on an external `apt-packages.env` file (especially in the Ubuntu variant).

---

## Usage

### 1. Add the Plugin Repository

In the `repositories` section of your `composer.json`, add:

```json
{
    "type": "vcs",
    "url": "https://github.com/razeem/docker-base-template.git"
}
```

### 2. Require the Plugin

Specify a version:
```sh
composer require --dev razeem/docker-base-template:~1.24.0-rc1
composer require --dev razeem/docker-base-template:~2.83.0-rc1
```

### 3. On Install/Update

- The plugin will copy the contents of its `dist/` directory into your project root.
- Create a file called `project-code.txt` in your project root folder and add your project code (e.g., your JIRA project code); its value will be used for Docker Compose service and folder names.

### 4. Customize

- Edit `docker-compose.yml` and `docker-compose-vm.yml` as needed.
- Copy `.env.dist` to `.env` and set your environment variables.
- Adjust `apt-packages.env` to add/remove system packages for your stack (especially for the Ubuntu variant).

---

## File Structure

- `dist/Dockerfile` – Main Docker build file, uses either Ubuntu or PHP-FPM as the base image depending on the version.
- `dist/docker-compose.yml` – Standard Docker Compose file.
- `dist/docker-compose-vm.yml` – Compose file for running in a VM.
- `dist/Docker/app/apt-packages.env` – List of system packages to install (one per line, mainly for Ubuntu variant).
- `dist/Docker/.env.dist` – Template for environment variables.
- `dist/Docker/ssh/sshd_config` – SSH server configuration.
- `dist/Docker/php/php.ini` – PHP configuration.
- `dist/Docker/nginx/nginx.conf` – Nginx configuration.
- `dist/Docker/scripts/start.sh` – Entrypoint/startup script.

---

## Customization

- **Project Name:**  
  Place a `project-code.txt` file in your project root with your desired project code.  
  The plugin will use this for service and folder names in Compose files.

- **System Packages:**  
  Edit `Docker/app/apt-packages.env` to add/remove Ubuntu packages (for Ubuntu variant).

- **Environment Variables:**  
  Edit `.env` which is copied `Docker/.env.dist` for your local settings.

---

## How It Works

The plugin's main logic is in [`CopyDistPlugin.php`](src/Composer/CopyDistPlugin.php):

- On `composer install` or `composer update`, it copies the `dist/` folder to your project.
- It replaces `project_name` and `project_folder` placeholders in Compose files.
- It ensures your Docker environment is ready to use out of the box.

---

## License

MIT

---

## Maintainer

- [Razeem Ahmad](https://www.drupal.org/u/razeem)

---

## Issues

Report bugs or request features at:  
[https://github.com/razeem/docker-base-template/issues](https://github.com/razeem/docker-base-template/issues)
