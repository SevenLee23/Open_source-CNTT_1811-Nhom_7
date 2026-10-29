# FeatureLab demo app

Run the app through Apache/PHP, for example `http://localhost/Open_source-CNTT_1811-Nhom_7/demo-app/`.

## Accounts and feature permissions

On first use, the app creates an Admin account:

- Username: `admin`
- Password: `FeatureLab2026!`

Set `DEMO_APP_USERNAME` and `DEMO_APP_PASSWORD` in the PHP/Apache environment before the first run to seed a different Admin login. The app stores password hashes, accounts and feature state in `data/users.json` and `data/features.json`. The data directory is excluded from Git and blocked from direct Apache requests.

Admins can open **Tài khoản & phân quyền** to create Editor or Viewer accounts. For an Editor, select the exact features the account may toggle. Viewers are read-only. Feature changes are checked by `api.php` on the server and persist across page reloads and logins.

Every feature toggle is recorded in `data/audit.jsonl` with the actor, feature key, old and new state, and timestamp. Admins can view this read-only log and grant or revoke log access for other accounts. The application only appends audit events; there is no user-facing edit or delete operation, and Apache denies direct web access to the data directory.

This is a lightweight PHP demo store; it does not require a database. Back up the `data` directory to preserve the demo accounts and feature state.
