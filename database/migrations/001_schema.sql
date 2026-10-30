CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NULL,
  email VARCHAR(190) NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  account_type VARCHAR(20) NOT NULL DEFAULT 'owner',
  parent_user_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY users_username_unique (username),
  UNIQUE KEY users_email_unique (email),
  KEY users_parent_user_index (parent_user_id),
  CONSTRAINT users_parent_user_fk FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  `key` VARCHAR(80) NOT NULL UNIQUE,
  description TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT projects_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'viewer',
  can_view_audit TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY project_memberships_project_user_unique (project_id, user_id),
  CONSTRAINT memberships_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT memberships_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS environments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  `key` VARCHAR(80) NOT NULL,
  current_version_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY environments_project_key_unique (project_id, `key`),
  CONSTRAINT environments_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(120) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT feature_catalog_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(120) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  catalog_feature_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  emergency_kill_switch TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY flags_project_key_unique (project_id, `key`),
  UNIQUE KEY flags_project_catalog_unique (project_id, catalog_feature_id),
  CONSTRAINT flags_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT flags_catalog_feature_fk FOREIGN KEY (catalog_feature_id) REFERENCES feature_catalog(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(100) NOT NULL,
  name VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY target_groups_project_key_unique (project_id, `key`),
  CONSTRAINT groups_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY group_members_group_user_unique (group_id, user_id),
  KEY group_members_user_index (user_id),
  CONSTRAINT group_members_group_fk FOREIGN KEY (group_id) REFERENCES target_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  environment_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  parent_version_id BIGINT UNSIGNED NULL,
  rollback_of_id BIGINT UNSIGNED NULL,
  change_reason VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  published_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  published_at TIMESTAMP NULL,
  UNIQUE KEY versions_environment_number_unique (environment_id, version_number),
  KEY versions_project_status_index (project_id, status),
  CONSTRAINT versions_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT versions_environment_fk FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
  CONSTRAINT versions_parent_fk FOREIGN KEY (parent_version_id) REFERENCES versions(id) ON DELETE SET NULL,
  CONSTRAINT versions_rollback_fk FOREIGN KEY (rollback_of_id) REFERENCES versions(id) ON DELETE SET NULL,
  CONSTRAINT versions_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT versions_published_by_fk FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE environments ADD CONSTRAINT environments_current_version_fk FOREIGN KEY (current_version_id) REFERENCES versions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS version_flags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  flag_id BIGINT UNSIGNED NOT NULL,
  kill_switch TINYINT(1) NOT NULL DEFAULT 0,
  default_value TINYINT(1) NOT NULL DEFAULT 0,
  rollout_enabled TINYINT(1) NOT NULL DEFAULT 0,
  rollout_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  fallback_value TINYINT(1) NOT NULL DEFAULT 0,
  forced_value TINYINT(1) NULL DEFAULT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY version_flags_version_flag_unique (version_id, flag_id),
  CONSTRAINT version_flags_version_fk FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  CONSTRAINT version_flags_flag_fk FOREIGN KEY (flag_id) REFERENCES flags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_flag_id BIGINT UNSIGNED NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  effect VARCHAR(10) NOT NULL,
  priority INT UNSIGNED NOT NULL DEFAULT 100,
  target_group_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY rules_version_target_priority_index (version_flag_id, target_type, priority),
  CONSTRAINT rules_version_flag_fk FOREIGN KEY (version_flag_id) REFERENCES version_flags(id) ON DELETE CASCADE,
  CONSTRAINT rules_group_fk FOREIGN KEY (target_group_id) REFERENCES target_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rule_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY rule_users_rule_user_unique (rule_id, user_id),
  KEY rule_users_user_index (user_id),
  CONSTRAINT rule_users_rule_fk FOREIGN KEY (rule_id) REFERENCES rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS version_group_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(100) NOT NULL,
  UNIQUE KEY version_group_user_unique (version_id, group_id, user_id),
  KEY version_group_user_lookup_index (version_id, user_id),
  CONSTRAINT version_group_members_version_fk FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  CONSTRAINT version_group_members_group_fk FOREIGN KEY (group_id) REFERENCES target_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_flag_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  flag_id BIGINT UNSIGNED NOT NULL,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY member_flag_unique (membership_id, flag_id),
  CONSTRAINT member_flag_membership_fk FOREIGN KEY (membership_id) REFERENCES project_memberships(id) ON DELETE CASCADE,
  CONSTRAINT member_flag_flag_fk FOREIGN KEY (flag_id) REFERENCES flags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subaccount_flag_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  flag_id BIGINT UNSIGNED NOT NULL,
  can_toggle TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY subaccount_flag_unique (membership_id, flag_id),
  CONSTRAINT subaccount_permission_membership_fk FOREIGN KEY (membership_id) REFERENCES project_memberships(id) ON DELETE CASCADE,
  CONSTRAINT subaccount_permission_flag_fk FOREIGN KEY (flag_id) REFERENCES flags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  environment_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  account_user_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  key_prefix VARCHAR(24) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  last_used_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  KEY api_keys_account_user_index (account_user_id),
  CONSTRAINT api_keys_environment_fk FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
  CONSTRAINT api_keys_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT api_keys_account_user_fk FOREIGN KEY (account_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  environment_id BIGINT UNSIGNED NULL,
  version_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  subject_type VARCHAR(80) NOT NULL,
  subject_key VARCHAR(160) NULL,
  old_values LONGTEXT NULL,
  new_values LONGTEXT NULL,
  reason VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY audit_project_time_index (project_id, created_at),
  KEY audit_subject_index (project_id, subject_key),
  CONSTRAINT audit_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT audit_environment_fk FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE SET NULL,
  CONSTRAINT audit_version_fk FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE SET NULL,
  CONSTRAINT audit_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decision_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  environment_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NULL,
  user_id VARCHAR(100) NOT NULL,
  flag_key VARCHAR(120) NOT NULL,
  result TINYINT(1) NOT NULL,
  reason_code VARCHAR(50) NOT NULL,
  matched_rule_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY decisions_project_time_index (project_id, created_at),
  KEY decisions_user_flag_index (project_id, user_id, flag_key),
  CONSTRAINT decisions_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT decisions_environment_fk FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
  CONSTRAINT decisions_version_fk FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE SET NULL,
  CONSTRAINT decisions_rule_fk FOREIGN KEY (matched_rule_id) REFERENCES rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  environment_id BIGINT UNSIGNED NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  occurred_at DATETIME NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'info',
  source VARCHAR(160) NULL,
  message TEXT NOT NULL,
  context LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY project_logs_project_time_index (project_id, created_at),
  CONSTRAINT project_logs_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT project_logs_environment_fk FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE SET NULL,
  CONSTRAINT project_logs_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
