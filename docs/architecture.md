# Architecture

FeatureLab is implemented as a small PHP 8.0 MVC application using PDO and MySQL. This keeps the project compatible with the PHP runtime bundled with the existing XAMPP setup and avoids a Composer dependency.

- `public/index.php` loads the application and dispatches web and API routes.
- `app/Http/Controllers` handles web requests; `app/Services` contains evaluation, versioning, validation, audit, and import/export logic.
- `app/Support/Auth.php` checks project membership and per-feature edit grants.
- `database/migrations/001_schema.sql` creates the project, environment, version, rule, permission, key, and log tables.
- `scripts/setup.php` initializes the schema and sample workspace.

Published configuration is stored as a version snapshot. Editing starts from a draft; publishing switches the environment's current version. Rollback creates and publishes a new version copied from the selected historical snapshot.

## Evaluation precedence

1. Kill switch returns off.
2. A child-account forced toggle applies to the whole feature.
3. A matching explicit user rule wins.
4. A matching group rule wins, ordered by ascending priority and then rule ID.
5. If percentage rollout is enabled, a deterministic SHA-256 bucket based on project, environment, feature key, and application user ID selects the cohort. The version is intentionally excluded so users do not churn on each publication.
6. Otherwise the feature's default value is returned.

The API key is scoped to one environment. The management user account and application user IDs evaluated by the API are separate concepts.

## Access model

Project roles are Admin, Editor, and Viewer. Admin manages project configuration, accounts, and audit visibility. Editors can modify only explicitly granted features and can publish versions. Viewers are read-only. Audit and decision records are exposed through read-only pages; no edit or delete route exists.

Configuration drafts and publishes are restricted to the `staging` environment. Uploading a configuration file writes a validated Staging draft without publishing it. Other environments show their current published snapshot as read-only; the same restriction is enforced by both the web controllers and versioning service.
