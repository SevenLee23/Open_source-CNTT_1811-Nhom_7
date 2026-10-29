# FeatureLab Feature Flag Manager

FeatureLab is a PHP 8.0 and MySQL application for managing feature flags by project and environment. The project uses a small layered MVC structure with PDO so it runs in the existing XAMPP PHP 8.0 environment without Composer packages. It includes project membership authorization, immutable published snapshots, deterministic evaluation, audit and decision logs, JSON configuration import/export, project log upload/download, and a PHP demo client.

## Requirements

- PHP 8.0 or newer with PDO MySQL, JSON, OpenSSL and mbstring enabled
- MySQL 8.0 or MariaDB 10.5+
- Apache with `mod_rewrite` enabled (or use the `index.php?r=...` query routes)

## Setup with XAMPP

1. Create a MySQL database named `feature_flags` (or set `DB_DATABASE`).
2. Copy `.env.example` to `.env` and set the database credentials and admin password.
3. In a terminal at the repository root, run `C:\xampp\php\php.exe scripts\setup.php`.
4. Open `http://localhost/Open_source-CNTT_1811-Nhom_7/`.

The setup command creates the schema and demo records: one project, two environments, ten flags, three groups and 1,000 synthetic application user IDs. It prints the seeded environment API keys once. The seeded admin defaults to `admin@example.test`; set `APP_ADMIN_PASSWORD` before setup and change it before sharing the app. New users register with their email address and receive an account API key. Email confirmation requires an SMTP service and is not configured in this project.

## Main features

- Project dashboard, project and environment switching, member roles and per-feature edit grants
- Per-account API key creation and rotation; the secret is shown only once, plus admin-managed environment keys
- Email registration/login on one shared page; child accounts use a username and may have an optional email. Only owner accounts receive an account-scoped API key, while child accounts can only toggle features
- Owners can assign child accounts toggle permission per feature and project, then revoke child access
- Platform admin manages the shared feature catalog; owners can only add catalog features to a project, not create new flags
- Owners can create or deactivate projects; each project has its own API key and built-in feature catalog
- A published toggle change updates the shared project configuration consumed by the other accounts and clients in that environment
- Feature and target-group configuration with user and group rules
- Draft creation, validation, publish, version comparison and rollback-as-new-version
- `POST /api/v1/evaluate` using account-scoped or environment-scoped bearer keys; account keys can select an environment in their workspace
- Append-only audit/decision records in MySQL; configuration can be downloaded as JSON and uploaded as a Staging draft; project logs can be uploaded as JSON/CSV and downloaded as CSV
- Configuration changes and file imports are restricted to Staging; release environments display the published feature states as read-only
- Demo client at `/demo-app/` with local fallback when the API cannot be reached

See [docs/architecture.md](docs/architecture.md), [docs/database-design.md](docs/database-design.md), [docs/api.md](docs/api.md) and [docs/demo-guide.md](docs/demo-guide.md).

## Security notes

Published versions cannot be edited in place. Viewers cannot mutate project data, and editors need an explicit per-feature grant. API keys are stored as SHA-256 hashes. Do not commit `.env`, database dumps or real user data.
