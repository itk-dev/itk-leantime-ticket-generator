# Leantime Ticket Generator

A Symfony 8 application for batch-creating tickets in [Leantime](https://leantime.io) via its JSON-RPC 2.0 API.

## Features

- **Across Projects**: Create the same ticket in multiple projects at once.
- **Across Users**: Create a ticket for multiple users within a single project.
- Configurable priority, due date, planned hours, tags, and milestones.
- Dynamic milestone loading per project (with create-if-missing support).

## Requirements

- Docker (uses ITK Dev Docker images)
- A Leantime instance with API access

## Setup

### 1. Clone and install dependencies

```bash
docker run --rm -v $(pwd):/app -w /app itkdev/php8.4-fpm:latest composer install
```

### 2. Configure environment

Copy `.env` to `.env.local` and fill in your Leantime credentials:

```dotenv
LEANTIME_API_URL=https://your-leantime-instance.com
LEANTIME_API_KEY=your-api-key
```

### 3. Configure Docker

Set required environment variables for Docker:

```bash
export COMPOSE_PROJECT_NAME=leantime-ticket-generator
export COMPOSE_DOMAIN=leantime-ticket-generator.local.itkdev.dk
```

### 4. Start the application

```bash
docker compose up -d
```

Access the application via Traefik at `https://${COMPOSE_DOMAIN}` or directly on the nginx container's port 8080.

## Production deployment

### Compile assets

```bash
php bin/console asset-map:compile
```

This writes optimized CSS/JS to `public/assets/`. In development, AssetMapper serves assets directly from `assets/` without a build step.

### Clear and warm cache

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## Project structure

```
src/
  Controller/
    TicketController.php      # Slim controller - routing, forms, templates only
  Form/
    TicketType.php             # "Across Projects" form
    AcrossUsersType.php        # "Across Users" form
  Service/
    LeantimeService.php        # JSON-RPC 2.0 API client for Leantime
    TicketHelperService.php    # Form data preparation and ticket creation logic
assets/
  app.js                       # AssetMapper entry point
  styles/app.css               # Application styles
  js/manual-hours-toggle.js    # Shared manual hours toggle
  js/across-users.js           # Dynamic milestone loading
templates/
  base.html.twig               # Base layout
  home.html.twig               # Home page with navigation
  ticket/
    across_projects.html.twig  # "Across Projects" form
    across_users.html.twig     # "Across Users" form
    success.html.twig          # Results for across-projects
    success_users.html.twig    # Results for across-users
```
