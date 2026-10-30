<?php
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from CLI.' . PHP_EOL);
}

$db = \App\Support\Config::get('database');
if (!preg_match('/^[A-Za-z0-9_]+$/', $db['database'])) {
    throw new RuntimeException('DB_DATABASE contains unsupported characters.');
}
$serverDsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=' . $db['charset'];
$pdo = new PDO($serverDsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . $db['database'] . '`');
$sql = file_get_contents(APP_ROOT . '/database/migrations/001_schema.sql');
foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
    $statement = trim($statement);
    if ($statement !== '' && stripos($statement, 'ALTER TABLE environments ADD CONSTRAINT') !== 0) $pdo->exec($statement);
}
$columnExists = function ($table, $column) use ($pdo) {
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $query->execute([$table, $column]);
    return (bool) $query->fetchColumn();
};
$constraintExists = function ($table, $constraint) use ($pdo) {
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $query->execute([$table, $constraint]);
    return (bool) $query->fetchColumn();
};
$indexExists = function ($table, $index) use ($pdo) {
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $query->execute([$table, $index]);
    return (bool) $query->fetchColumn();
};
// Normalize databases created from the older standalone schema.sql, which used
// password_hash while the application and canonical migration use password.
if (!$columnExists('users', 'password') && $columnExists('users', 'password_hash')) {
    $pdo->exec('ALTER TABLE users CHANGE COLUMN password_hash password VARCHAR(255) NOT NULL');
}
if (!$columnExists('users', 'account_type')) $pdo->exec("ALTER TABLE users ADD account_type VARCHAR(20) NOT NULL DEFAULT 'owner'");
if (!$columnExists('users', 'username')) $pdo->exec('ALTER TABLE users ADD username VARCHAR(80) NULL');
if (!$columnExists('users', 'parent_user_id')) $pdo->exec('ALTER TABLE users ADD parent_user_id BIGINT UNSIGNED NULL');
if (!$columnExists('users', 'is_active')) $pdo->exec('ALTER TABLE users ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
if (!$columnExists('projects', 'is_active')) $pdo->exec('ALTER TABLE projects ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
if (!$columnExists('flags', 'catalog_feature_id')) $pdo->exec('ALTER TABLE flags ADD catalog_feature_id BIGINT UNSIGNED NULL');
if (!$columnExists('flags', 'is_active')) $pdo->exec('ALTER TABLE flags ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
if (!$columnExists('flags', 'emergency_kill_switch')) $pdo->exec('ALTER TABLE flags ADD emergency_kill_switch TINYINT(1) NOT NULL DEFAULT 0');
if (!$indexExists('users', 'users_parent_user_index')) $pdo->exec('ALTER TABLE users ADD KEY users_parent_user_index (parent_user_id)');
if (!$indexExists('users', 'users_username_unique')) $pdo->exec('ALTER TABLE users ADD UNIQUE KEY users_username_unique (username)');
$emailNullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email'")->fetchColumn();
if ($emailNullable === 'NO') $pdo->exec('ALTER TABLE users MODIFY email VARCHAR(190) NULL');
$pdo->exec("UPDATE users SET username=CONCAT('child',id) WHERE account_type='child' AND (username IS NULL OR username='')");
if (!$constraintExists('users', 'users_parent_user_fk')) $pdo->exec('ALTER TABLE users ADD CONSTRAINT users_parent_user_fk FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE');
if (!$columnExists('api_keys', 'project_id')) $pdo->exec('ALTER TABLE api_keys ADD project_id BIGINT UNSIGNED NULL');
if (!$columnExists('api_keys', 'account_user_id')) $pdo->exec('ALTER TABLE api_keys ADD account_user_id BIGINT UNSIGNED NULL');
if (!$indexExists('api_keys', 'api_keys_account_user_index')) $pdo->exec('ALTER TABLE api_keys ADD KEY api_keys_account_user_index (account_user_id)');
if (!$indexExists('flags', 'flags_project_catalog_unique')) $pdo->exec('ALTER TABLE flags ADD UNIQUE KEY flags_project_catalog_unique (project_id,catalog_feature_id)');
$environmentNullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_keys' AND COLUMN_NAME='environment_id'")->fetchColumn();
if ($environmentNullable === 'NO') $pdo->exec('ALTER TABLE api_keys MODIFY environment_id BIGINT UNSIGNED NULL');
if (!$columnExists('version_flags', 'forced_value')) $pdo->exec('ALTER TABLE version_flags ADD forced_value TINYINT(1) NULL DEFAULT NULL');
if (!$constraintExists('api_keys', 'api_keys_project_fk')) $pdo->exec('ALTER TABLE api_keys ADD CONSTRAINT api_keys_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE');
if (!$constraintExists('api_keys', 'api_keys_account_user_fk')) $pdo->exec('ALTER TABLE api_keys ADD CONSTRAINT api_keys_account_user_fk FOREIGN KEY (account_user_id) REFERENCES users(id) ON DELETE CASCADE');
if (!$constraintExists('flags', 'flags_catalog_feature_fk')) $pdo->exec('ALTER TABLE flags ADD CONSTRAINT flags_catalog_feature_fk FOREIGN KEY (catalog_feature_id) REFERENCES feature_catalog(id) ON DELETE SET NULL');
$pdo->exec("UPDATE environments SET name='Phát hành' WHERE `key`='production_sim' AND name='Production Sim'");
$pdo->exec("UPDATE api_keys k JOIN users u ON u.id=k.account_user_id SET k.revoked_at=NOW() WHERE u.account_type='child' AND k.environment_id IS NULL AND k.revoked_at IS NULL");
$fk = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='environments' AND CONSTRAINT_NAME='environments_current_version_fk'")->fetchColumn();
if (!$fk) $pdo->exec('ALTER TABLE environments ADD CONSTRAINT environments_current_version_fk FOREIGN KEY (current_version_id) REFERENCES versions(id) ON DELETE SET NULL');
require APP_ROOT . '/database/seeders/FeatureFlagDemoSeeder.php';
$keys = (new \Database\Seeders\FeatureFlagDemoSeeder())->run($pdo);
(new \App\Services\FeatureCatalogService())->seedDefaults($pdo);
$pdo->exec('UPDATE flags f JOIN feature_catalog c ON c.`key`=f.`key` SET f.catalog_feature_id=c.id,f.is_active=c.is_active WHERE f.catalog_feature_id IS NULL');
$pdo->exec('UPDATE flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id SET f.name=c.name,f.description=c.description,f.is_active=c.is_active');
$pdo->exec('UPDATE flags f LEFT JOIN feature_catalog c ON c.id=f.catalog_feature_id SET f.is_active=0 WHERE c.id IS NULL');
$emptyProjects = $pdo->query('SELECT p.id,p.created_by FROM projects p WHERE p.is_active=1 AND NOT EXISTS (SELECT 1 FROM flags f WHERE f.project_id=p.id)')->fetchAll();
foreach ($emptyProjects as $project) {
    $pdo->beginTransaction();
    try {
        (new \App\Services\ProjectProvisioningService())->installBuiltInFeatures($pdo, (int) $project['id'], (int) $project['created_by']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
echo 'Database schema and demo data are ready.' . PHP_EOL;
echo 'Admin: ' . \App\Support\Config::get('app.admin_email') . PHP_EOL;
if (!empty($keys['project'])) echo 'Project API key (use with an explicit environment field): ' . $keys['project'] . PHP_EOL;
else echo 'Project API key has already been rotated or revoked; generate a new key from the Projects screen if needed.' . PHP_EOL;
