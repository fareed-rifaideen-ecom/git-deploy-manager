# Git Deploy Manager

> A professional deployment tool enabling automated, zero downtime updates from GitHub repositories directly to WordPress environments.

<p align="center">
  Clean structure. Controlled deployments. GitHub connected workflow.
</p>

***

### Background and Objective

I developed this tool to make life easier for developers who need a reliable way to pull code from GitHub straight to WordPress. 

The project was initiated to address the complexities and risks associated with updating custom WordPress extensions on live environments. Traditional methods, such as manual FTP uploads or direct live file editing, are prone to human error and unexpected downtime. 

Git Deploy Manager bridges the gap between enterprise CI/CD platforms and standard WordPress setups. It provides an automated, secure pipeline that executes version-controlled updates with built-in safety mechanisms, ensuring website stability during the deployment process.

---

## Overview

Git Deploy Manager is a custom WordPress plugin built to support repository-based deployment workflows in a clear and maintainable way.

The project follows a modular architecture so that admin features, settings, deployment logic, GitHub integration, webhook handling, and logging are separated into focused components. This makes the plugin easier to extend, debug, and maintain over time.

## Features

- WordPress admin interface for deployment-related management
- Modular plugin architecture using dedicated class files
- Settings management for plugin configuration
- Deployment service for handling workflow operations
- GitHub integration support
- Webhook controller for external trigger handling
- Logging support for debugging and monitoring
- Organized project structure for long-term development

## Project Structure

```text
git-deploy-manager/
├── admin/
├── assets/
├── includes/
├── .gitattributes
├── .gitignore
├── README.md
└── git-deploy-manager.php
```

## Core Components

| Component | Purpose |
|---|---|
| `git-deploy-manager.php` | Main plugin bootstrap file |
| `admin/` | Admin-side screens, handlers, and plugin management logic |
| `assets/` | Styles and supporting static assets |
| `includes/class-gdm-settings.php` | Plugin settings and configuration handling |
| `includes/class-gdm-package-repository.php` | Repository and package-related operations |
| `includes/class-gdm-deployment-service.php` | Core deployment workflow logic |
| `includes/class-gdm-github-provider.php` | GitHub integration layer |
| `includes/class-gdm-webhook-controller.php` | Webhook endpoint and request handling |
| `includes/class-gdm-logger.php` | Logging and debug support |

## Installation

To install Git Deploy Manager manually via GitHub:

1. Navigate to the main page of this repository.
2. Click the green **Code** button and select **Download ZIP**.
3. Log in to the WordPress Admin dashboard of the target website.
4. Navigate to **Plugins > Add New Plugin**.
5. Click the **Upload Plugin** button at the top of the screen.
6. Select the downloaded `.zip` file and click **Install Now**.
7. Click **Activate Plugin**.
8. Navigate to the new **Git Deploy Manager > Settings** menu to securely store a GitHub Personal Access Token.

## Usage

After activation, the plugin manages deployment related actions through the WordPress admin environment.

A typical workflow includes:

* Configuring plugin settings.
* Connecting deployment related repository information.
* Utilizing the repository autocomplete wizard to select packages.
* Handling webhook driven actions for automatic deployments.
* Reviewing logs and admin notices during testing and operation.

## Disclaimer and Liability

This software is provided "as is", without warranty of any kind. The core functionality involves the deletion and replacement of server directories. Users are strongly advised to test all deployments and updates in a dedicated staging environment prior to executing actions on a production website.

The author and contributors shall not be held liable for any damages, downtime, data loss, or other liabilities arising from the use or inability to use this software. By installing and utilizing this plugin, users accept full responsibility for their server environments and deployment workflows.

## License

`MIT License`

---

<h1 align="center">FMR</h1>

<p align="center">
  Designed and developed with care.<br>
  <strong>Git Deploy Manager</strong> by Fareed M. Rifaideen
</p>
