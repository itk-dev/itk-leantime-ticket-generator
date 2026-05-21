# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.0.0] - 2026-05-21

### Added

- "Across Projects" form: create the same ticket in multiple Leantime projects at once.
- "Across Users" form: create a ticket for multiple users within a single project.
- Configurable priority (Urgent, High, Medium, Low, Lowest) with per-form defaults.
- Due date field used for due date, work start, and work end.
- Planned hours selector (1 hour, 1 day/7.5h, 1 friday/7h, manual input).
- Milestone support with find-or-create logic per project.
- Dynamic milestone loading via API when selecting a project in "Across Users".
- Tags field for ticket tagging.
- Description field for ticket body text.
- User-friendly error messages when Leantime API is unreachable.
- Symfony AssetMapper for external CSS and JS (no build step required).
- ITK Dev Docker template (symfony-7) with nginx + php-fpm.
- LeantimeService: JSON-RPC 2.0 client with full PHPDoc and typed constants.
- TicketHelperService: form data preparation and batch ticket creation logic.
- README with setup, Docker, and production deployment instructions.
- CLAUDE.md with project conventions.
