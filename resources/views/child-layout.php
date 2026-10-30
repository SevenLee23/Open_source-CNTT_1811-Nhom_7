<?php
$title = $title ?? 'FeatureLab';
$childEnvironmentMap = [];
foreach ($projects as $availableProject) $childEnvironmentMap[(int) $availableProject['id']] = [];
if ($childEnvironmentMap) {
    $childProjectIds = array_keys($childEnvironmentMap);
    $childPlaceholders = implode(',', array_fill(0, count($childProjectIds), '?'));
    $childEnvironmentQuery = \App\Support\Database::connection()->prepare("SELECT id,project_id,name,`key` FROM environments WHERE project_id IN ($childPlaceholders) ORDER BY id");
    $childEnvironmentQuery->execute($childProjectIds);
    foreach ($childEnvironmentQuery->fetchAll() as $childEnvironment) $childEnvironmentMap[(int) $childEnvironment['project_id']][] = $childEnvironment;
}
$isLogsPage = current_route() === 'child/logs';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · FeatureLab</title>
    <link rel="stylesheet" href="<?= e(asset_url('public/assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e(url('child/dashboard')) ?>"><span class="brand-icon">✳</span>FeatureLab</a>
        <div class="workspace"><small>PROJECT WORKSPACE</small><strong><?= e($project['name'] ?? 'Chọn dự án') ?></strong></div>
        <div class="nav-label">Workspace</div>
        <nav class="nav">
            <a class="<?= !$isLogsPage?'active':'' ?>" href="<?= e(url('child/dashboard')) ?>"><span class="ico">▦</span>Tổng quan</a>
            <a href="<?= e(url('child/dashboard')) ?>#features"><span class="ico">◈</span>Features</a>
            <?php if ($canViewAudit): ?><a class="<?= $isLogsPage?'active':'' ?>" href="<?= e(url('child/logs')) ?>"><span class="ico">☷</span>Log</a><?php endif; ?>
        </nav>
        <div class="side-foot">
            <form class="context-form child-context-form" method="post" action="<?= e(url('child/context')) ?>" data-environments="<?= e(json_encode($childEnvironmentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                <?= csrf_field() ?>
                <label for="childProjectSelect">PROJECT</label>
                <select id="childProjectSelect" name="project_id"><?php foreach ($projects as $availableProject): ?><option value="<?= (int) $availableProject['id'] ?>" <?= (int) $availableProject['id']===(int)$projectId?'selected':'' ?>><?= e($availableProject['name']) ?></option><?php endforeach; ?></select>
                <label for="childEnvironmentSelect">ENVIRONMENT</label>
                <select id="childEnvironmentSelect" name="environment_id"><?php foreach ($environments as $availableEnvironment): ?><option value="<?= (int)$availableEnvironment['id'] ?>" <?= (int)$availableEnvironment['id']===(int)$environmentId?'selected':'' ?>><?= e($availableEnvironment['name']) ?></option><?php endforeach; ?></select>
                <button type="submit">Chọn workspace</button>
            </form>
            <div class="profile">
                <span class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U',0,2))) ?></span>
                <span><strong><?= e($user['name'] ?? '') ?></strong><small><?= e($environment['name'] ?? '') ?></small></span>
                <form method="post" action="<?= e(url('child/logout')) ?>"><?= csrf_field() ?><button type="submit">Đăng xuất</button></form>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar"><div class="crumb">Projects / <b><?= e($project['name'] ?? '') ?></b></div><div class="top-user"><?= e($environment['name'] ?? '') ?> · <?= e($user['email'] ?? $user['username'] ?? '') ?></div></header>
        <div class="content">
            <?php if ($message=flash('success')): ?><div class="notice success"><?= e($message) ?></div><?php endif; ?>
            <?php if ($message=flash('error')): ?><div class="notice error"><?= e($message) ?></div><?php endif; ?>
            <?= $content ?>
        </div>
    </main>
</div>
<script>(function(){var form=document.querySelector('.child-context-form'),project=document.getElementById('childProjectSelect'),environment=document.getElementById('childEnvironmentSelect');if(!form||!project||!environment)return;var map=JSON.parse(form.dataset.environments||'{}');project.addEventListener('change',function(){var options=map[project.value]||[];environment.innerHTML='';options.forEach(function(item){var option=document.createElement('option');option.value=item.id;option.textContent=item.name;option.dataset.key=item.key;environment.appendChild(option);});var preferred=options.find(function(item){return item.key==='staging';})||options[0];if(preferred)environment.value=String(preferred.id);});})();</script>
</body>
</html>
