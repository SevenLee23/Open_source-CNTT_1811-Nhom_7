# Database design

The MySQL schema separates platform accounts (`users`) from application user IDs (`group_members.user_id`, `rule_users.user_id`, and `decision_logs.user_id`). Applications pass their own stable user identifiers to the evaluation API; the platform does not require those users to sign into FeatureLab.

- `projects`, `project_memberships`, `environments`: workspace and access boundaries.
- `flags`, `target_groups`: project-level feature and audience definitions.
- `versions`, `version_flags`, `rules`, `rule_users`, `version_group_members`: immutable configuration snapshots by environment.
- `membership_flag_permissions`: grants an editor write access to selected features.
- `api_keys`: account-scoped or environment-scoped read credentials stored as SHA-256 hashes; rotation revokes prior active keys.
- `users.account_type` and `users.parent_user_id`: owner/member/child identity and the parent-child account relationship.
- `users.username`: optional unique login identifier (required by the application for child accounts); `email` is nullable so a child may add it later.
- `users.is_active`: disables deleted subaccounts while retaining version and audit attribution; deleting also revokes their API keys.
- `projects.is_active`: deactivates deleted projects and blocks their API keys while retaining configuration history.
- `subaccount_flag_permissions`: stores each child's toggle grants for individual features in each project.
- `feature_catalog`: platform-admin-owned feature definitions; project flags link back to catalog entries and are disabled when the admin stops providing one.
- `audit_logs`, `decision_logs`: change history and evaluation outcomes.
- `project_logs`: append-only log entries uploaded to a project, associated with the selected environment and uploader when available.

Foreign keys cascade snapshots and child rules with their owning records. Audit rows use no user edit/delete route and preserve actor, reason, version, and timestamp for review.
