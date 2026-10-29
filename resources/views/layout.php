<?php
$layoutUser = \App\Support\Auth::user();
$layoutProjectId = \App\Support\Auth::projectId();
$layoutProjects = $layoutUser ? \App\Models\Project::forUser((int) $layoutUser['id']) : [];
$layoutProject = $layoutProjectId ? \App\Models\Project::find($layoutProjectId) : null;
$layoutMembership = $layoutProjectId ? \App\Support\Auth::membership($layoutProjectId) : null;
$layoutEnvironments = $layoutProjectId ? \App\Models\Environment::forProject($layoutProjectId) : [];
$layoutEnvironmentId = $layoutProjectId ? \App\Support\Auth::environmentId($layoutProjectId) : null;
$layoutIsAdmin = $layoutProjectId ? \App\Support\Auth::isAdmin($layoutProjectId) : false;
$layoutCanAudit = $layoutProjectId ? \App\Support\Auth::canViewAudit($layoutProjectId) : false;
$title = $title ?? 'FeatureLab';
?><!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><title><?= e($title) ?> · FeatureLab</title><link rel="stylesheet" href="<?= e(asset_url('public/assets/app.css')) ?>"></head><body>
<div class="shell"><aside class="sidebar"><a class="brand" href="<?= e(url('dashboard')) ?>"><span class="brand-icon">✳</span>FeatureLab</a><div class="workspace"><small>PROJECT WORKSPACE</small><strong><?= e($layoutProject['name'] ?? 'Chọn dự án') ?></strong></div><div class="nav-label">Workspace</div><nav class="nav">
<a class="<?= current_route()==='dashboard'||current_route()===''?'active':'' ?>" href="<?= e(url('dashboard')) ?>"><span class="ico">▦</span>Dashboard</a>
<a class="<?= strpos(current_route(),'flags')===0?'active':'' ?>" href="<?= e(url('flags')) ?>"><span class="ico">◈</span>Features</a>
<a class="<?= strpos(current_route(),'rules')===0?'active':'' ?>" href="<?= e(url('rules')) ?>"><span class="ico">⌘</span>Rules &amp; Groups</a>
<a class="<?= strpos(current_route(),'versions')===0?'active':'' ?>" href="<?= e(url('versions')) ?>"><span class="ico">◷</span>Version History</a>
<a class="<?= strpos(current_route(),'evaluate')===0?'active':'' ?>" href="<?= e(url('evaluate')) ?>"><span class="ico">⚡</span>Evaluation Playground</a>
<?php if ($layoutIsAdmin): ?><a class="<?= strpos(current_route(),'members')===0?'active':'' ?>" href="<?= e(url('members')) ?>"><span class="ico">♧</span>Members &amp; Permissions</a><?php endif; ?>
<?php if ($layoutIsAdmin && ($layoutUser['account_type'] ?? '')==='owner' && (int)($layoutProject['created_by'] ?? 0)===(int)($layoutUser['id'] ?? 0)): ?><a class="<?= strpos(current_route(),'subaccounts')===0?'active':'' ?>" href="<?= e(url('subaccounts')) ?>"><span class="ico">♙</span>Tài khoản con</a><?php endif; ?>
<?php if (\App\Support\Auth::isPlatformAdmin($layoutUser)): ?><a class="<?= strpos(current_route(),'admin/features')===0?'active':'' ?>" href="<?= e(url('admin/features')) ?>"><span class="ico">◆</span>Thư viện tính năng</a><?php endif; ?>
<a class="<?= strpos(current_route(),'projects')===0?'active':'' ?>" href="<?= e(url('projects')) ?>"><span class="ico">▣</span>Projects</a>
<?php if ($layoutCanAudit): ?><a class="<?= strpos(current_route(),'audit')===0?'active':'' ?>" href="<?= e(url('audit')) ?>"><span class="ico">☷</span>Audit &amp; Import/Export</a><?php endif; ?>
</nav><div class="side-foot">
<?php if ($layoutProjectId): ?><form class="context-form" method="post" action="<?= e(url('projects/switch')) ?>"><?= csrf_field() ?><label for="projectSelect">PROJECT</label><select id="projectSelect" name="project_id" onchange="this.form.elements.environment_id.value='0'"><?php foreach ($layoutProjects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $project['id']===$layoutProjectId?'selected':'' ?>><?= e($project['name']) ?></option><?php endforeach; ?></select><label for="environmentSelect">ENVIRONMENT</label><select id="environmentSelect" name="environment_id"><?php foreach ($layoutEnvironments as $environment): ?><option value="<?= (int) $environment['id'] ?>" <?= (int) $environment['id']===(int) $layoutEnvironmentId?'selected':'' ?>><?= e($environment['name']) ?></option><?php endforeach; ?></select><input type="hidden" name="return_to" value="<?= e(current_route() ?: 'dashboard') ?>"><button type="submit">Chọn workspace</button></form><?php endif; ?>
<div class="profile"><span class="avatar"><?= e(strtoupper(substr($layoutUser['name'] ?? 'U',0,2))) ?></span><span><strong><?= e($layoutUser['name'] ?? '') ?></strong><small><?= e(ucfirst($layoutMembership['role'] ?? 'member')) ?></small></span><form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button type="submit">Đăng xuất</button></form></div></div></aside>
<main class="main"><header class="topbar"><div class="crumb">Projects / <b><?= e($layoutProject['name'] ?? 'FeatureLab') ?></b></div><div class="top-user"><?= e($layoutEnvironments ? ($layoutEnvironments[0]['name'] ?? '') : '') ?> · <?= e($layoutUser['email'] ?? '') ?></div></header><div class="content">
<?php if ($message=flash('success')): ?><div class="notice success"><?= e($message) ?></div><?php endif; ?><?php if ($message=flash('error')): ?><div class="notice error"><?= e($message) ?></div><?php endif; ?>
<?= $content ?>
</div></main></div></body></html>
