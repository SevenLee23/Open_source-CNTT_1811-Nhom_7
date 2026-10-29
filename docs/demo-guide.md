# Demo guide

1. Copy `.env.example` to `.env`, configure MySQL, and set a strong `APP_ADMIN_PASSWORD` and API keys.
2. Create the configured database, then run `C:\xampp\php\php.exe scripts\setup.php` from the repository root.
3. Open the project in XAMPP and sign in using `APP_ADMIN_EMAIL` and `APP_ADMIN_PASSWORD`.
   New owners may register with an email at `/index.php?r=%2Fregister`. Registration does not send an email verification link because SMTP is not configured.
4. Select a project and environment in the sidebar. The seed workspace includes Staging and Phát hành; the release environment is read-only.
5. Create a feature, add user/group targeting rules, create a draft and publish it. The published version is immutable.
6. Use Evaluation Playground or call `POST /api/v1/evaluate` from the client application.
7. Owners can create or revoke subaccounts from **Tài khoản con**, assign each child to one or more of their projects, then grant toggle permission for selected features in the current project. Owners and subaccounts sign in at the same `/index.php?r=%2Flogin` page; subaccounts only see assigned projects and granted features.
8. Owners can create projects from **Projects**. Each new project includes the built-in feature catalog, Staging and Phát hành environments, and its own API key. Deleting a project deactivates it and revokes its keys while retaining history.
9. Only the platform admin account configured by `APP_ADMIN_EMAIL` can manage the shared feature catalog. Owners select from this catalog when adding features to their projects.

The setup script prints each environment's API key. Set distinct secrets in `.env` before running setup. If the API is unavailable, the sample client keeps using its configured local fallback.
