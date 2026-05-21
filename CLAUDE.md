# Project conventions

## Architecture

- **Symfony 8** application using **AssetMapper** (no Node.js/Webpack).
- **Docker**: ITK Dev Docker images (`itkdev/php8.4-fpm`, `nginxinc/nginx-unprivileged`).
- **Leantime API**: JSON-RPC 2.0 via `x-api-key` header authentication.

## Service classes

### PHPDoc
- Every service class must have a descriptive class-level PHPDoc block.
- Every public method must have a PHPDoc block with `@param`, `@return`, and `@throws` annotations.
- Use one-line `/** ... */` comments for constants.

### Constants
- Extract magic values (strings, numbers, field names) into typed class constants.
- Constants must have a one-line PHPDoc comment above them.
- Use `private const` by default, `public const` only when needed by other classes.
- Examples of what should be constants: API field values, status/priority IDs, date formats, fallback strings, form option values.

### Structure
- Service classes should be `readonly` where possible.
- Constructor promotion for dependencies.
- API client logic (LeantimeService) is separate from form/business logic (TicketHelperService).

## Controllers

- Controllers must be slim - only routing, form creation, and template rendering.
- All data retrieval, transformation, and business logic belongs in service classes.
- Use `TicketHelperService` (not `LeantimeService` directly) in controllers.
- Catch exceptions at the controller level for user-facing error messages via `addFlash()`.

## Forms

- Form types receive dynamic choices via options (e.g., `project_choices`, `priority_choices`).
- Choices are prepared in the helper service or controller, not fetched inside form types.

## Templates and assets

- No inline CSS or JS in templates.
- CSS lives in `assets/styles/`.
- JS lives in `assets/js/`.
- `assets/app.js` is the AssetMapper entry point that imports all CSS and JS.
- When JS needs server-generated values (e.g., URLs), pass them via `data-` attributes on HTML elements.
- Use CSS classes instead of inline `style` attributes.

## Leantime API reference

- Get projects: `leantime.rpc.projects.getAllProjects`
- Get users: `leantime.rpc.users.getAll`
- Get milestones: `leantime.rpc.tickets.getAllMilestones` (with `searchCriteria.currentProject`)
- Create milestone: `leantime.rpc.tickets.quickAddMilestone`
- Create ticket: `leantime.rpc.tickets.addTicket`
- Priority values: 1=Urgent, 2=High, 3=Medium, 4=Low, 5=Lowest
- Status 3 = New (always used)
- "My Project" is excluded from project lists.
