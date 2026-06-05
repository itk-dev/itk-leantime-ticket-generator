# Leantime Ticket Generator

A Symfony 8 application for batch-creating tickets in [Leantime](https://leantime.io) via its JSON-RPC 2.0 API.

## Features

- **Across Projects**: Create the same ticket in multiple projects at once.
- **Across Users**: Create a ticket for multiple users within a single project.
- **From GitHub Issues**: Import open issues or milestones from a GitHub
  repository as Leantime tickets, with a per-row selectable list and
  per-row planned hours, priority, and due date.
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

To enable the **From GitHub Issues** flow, also set:

```dotenv
GITHUB_ORG=your-github-org
GITHUB_TOKEN=ghp_your_token_here
```

`GITHUB_TOKEN` is optional — leave it empty to read public repositories
anonymously (capped at 60 requests/hour per IP). With a token you get
access to private repositories and the authenticated 5,000 requests/hour
rate limit. See [Create a GitHub token](#create-a-github-token) below.

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

## Create a GitHub token

A token is only needed if you want the **From GitHub Issues** flow to
reach private repositories or to lift the anonymous 60 requests/hour
rate limit. Use a fine-grained personal access token scoped to the
single organization you set in `GITHUB_ORG`.

1. Sign in to GitHub and open
   **Settings → Developer settings → Personal access tokens →
   Fine-grained tokens**
   (<https://github.com/settings/personal-access-tokens>).
2. Click **Generate new token**.
3. Fill in:
   - **Token name**: e.g. `leantime-ticket-generator`.
   - **Expiration**: pick a duration that fits your team's policy.
   - **Resource owner**: select the organization configured in
     `GITHUB_ORG`. If the org requires approval, an admin must
     approve the token before it works.
   - **Repository access**: *All repositories* (simplest), or *Only
     select repositories* and pick the repos you want listed in the
     setup page.
4. Under **Repository permissions**, set:
   - **Metadata**: *Read-only* (required by GitHub).
   - **Issues**: *Read-only* (lets the app list issues and milestones).
5. Leave all other permissions at *No access*. The app only reads;
   it never writes back to GitHub.
6. Click **Generate token** and copy the value (it is shown only once).
7. Paste it into `.env.local` as `GITHUB_TOKEN=` and restart the app
   (`task console -- cache:clear`).

For classic personal access tokens (legacy), the equivalent scopes are
`public_repo` for public-only access or `repo` for private repos —
prefer fine-grained tokens when possible.

## Production deployment

### Compile assets

```bash
php bin/console asset-map:compile
```

This writes optimized CSS/JS to `public/assets/`. In development, AssetMapper
serves assets directly from `assets/` without a build step.

### Clear and warm cache

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## Project structure

```text
src/
  Controller/
    TicketController.php       # Slim controller - routing, forms, templates only
  Form/
    TicketType.php             # "Across Projects" form
    AcrossUsersType.php        # "Across Users" form
    GithubImportType.php       # "From GitHub Issues" collection form
    GithubImportRowType.php    # Single row in the GitHub import table
  Service/
    LeantimeService.php        # JSON-RPC 2.0 API client for Leantime
    TicketHelperService.php    # Form data preparation and ticket creation logic
    GitHubService.php          # REST client for GitHub repos, issues and milestones
    GitHubHelperService.php    # GitHub form data and batch ticket creation
assets/
  app.js                       # AssetMapper entry point
  styles/app.css               # Application styles
  js/manual-hours-toggle.js    # Shared manual hours toggle
  js/across-users.js           # Dynamic milestone loading
  js/from-github.js            # Progressive reveal for the GitHub setup page
templates/
  base.html.twig               # Base layout
  home.html.twig               # Home page with navigation
  from_github.html.twig        # "From GitHub Issues" setup page
  from_github_select.html.twig # GitHub issue/milestone selection table
  from_github_success.html.twig # Results for GitHub-sourced tickets
  ticket/
    across_projects.html.twig  # "Across Projects" form
    across_users.html.twig     # "Across Users" form
    success.html.twig          # Results for across-projects
    success_users.html.twig    # Results for across-users
```
