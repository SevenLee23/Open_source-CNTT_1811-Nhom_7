<?php $title='Quản lý tính năng'; ?>
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
<main class="child-shell">
    <header class="child-top">
        <a class="brand" href="#"><span class="brand-icon">✳</span>FeatureLab</a>
        <div><span><?= e($user['name']) ?></span><form method="post" action="<?= e(url('child/logout')) ?>"><?= csrf_field() ?><button class="button small" type="submit">Đăng xuất</button></form></div>
    </header>
    <section class="child-content">
        <div class="page-head"><div><span class="eyebrow">SUBACCOUNT</span><h1><?= $isTestEnvironment?'Quản lý bản nháp thử nghiệm':'Theo dõi môi trường phát hành' ?></h1><p><?= $isTestEnvironment?'Thay đổi chỉ lưu vào bản nháp Staging.':'Xem trạng thái đang phát hành; môi trường này không cho phép thay đổi.' ?></p></div></div>
        <?php if(!$isTestEnvironment): ?><div class="notice info">Môi trường phát hành đang ở chế độ chỉ xem. Hãy chọn Staging để thay đổi tính năng.</div><?php endif; ?>
        <?php if($message=flash('success')): ?><div class="notice success"><?= e($message) ?></div><?php endif; ?>
        <?php if($message=flash('error')): ?><div class="notice error"><?= e($message) ?></div><?php endif; ?>

        <section class="panel">
            <div class="panel-head"><div><h2>Dự án</h2><p><?= e($project['name']) ?></p></div></div>
            <?php if(count($projects)>1): ?><form method="post" action="<?= e(url('child/project')) ?>" class="inline-form"><?= csrf_field() ?><select name="project_id"><?php foreach($projects as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)$item['id']===(int)$projectId?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select><button class="button" type="submit">Mở dự án</button></form><?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h2>Môi trường</h2><p>Mỗi môi trường lưu bộ trạng thái riêng.</p></div></div>
            <form method="post" action="<?= e(url('child/environment')) ?>" class="inline-form"><?= csrf_field() ?><select name="environment_id"><?php foreach($environments as $env): ?><option value="<?= (int)$env['id'] ?>" <?= (int)$env['id']===(int)$environmentId?'selected':'' ?>><?= e($env['name']) ?></option><?php endforeach; ?></select><button class="button" type="submit">Chọn môi trường</button></form>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div><h2>Tính năng · <?= e($environment['name']??'') ?></h2><p><?= $current?($current['status']==='draft'?'Bản nháp v':'Phiên bản phát hành v').(int)$current['version_number']:'Chưa phát hành cấu hình' ?> · Các tính năng bị khóa vẫn được liệt kê để dễ kiểm tra quyền.</p></div>
                <?php if($isTestEnvironment && $previous && $hasTogglePermission): ?>
                    <form method="post" action="<?= e(url('child/restore')) ?>" onsubmit="return confirm('Khôi phục trạng thái bật/tắt đã lưu ở phiên bản v<?= (int)$previous['version_number'] ?> cho các tính năng được cấp quyền?')">
                        <?= csrf_field() ?><input type="hidden" name="environment_id" value="<?= (int)$environmentId ?>">
                        <button class="button" type="submit">↶ Quay lại trạng thái v<?= (int)$previous['version_number'] ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if(!$flags): ?>
                <p class="empty">Dự án hiện chưa có tính năng hoạt động trong thư viện Admin.</p>
            <?php else: ?>
                <div class="feature-list">
                    <?php foreach($flags as $flag): $isEnabled=(bool)$flag['kill_switch']?false:($flag['forced_value']!==null?(bool)$flag['forced_value']:((bool)$flag['default_value'] || ((bool)$flag['rollout_enabled'] && (float)$flag['rollout_percentage']>0))); ?>
                    <div class="feature-row">
                        <div class="feature-info"><strong><?= e($flag['name']) ?></strong><code><?= e($flag['key']) ?></code><small><?= e($flag['description']) ?></small></div>
                        <span class="pill <?= $isEnabled?'green':'gray' ?>"><?= $isEnabled?'ĐANG BẬT':'ĐANG TẮT' ?></span>
                        <?php if($isTestEnvironment && (int)$flag['can_toggle']===1): ?>
                            <form method="post" action="<?= e(url('child/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="environment_id" value="<?= (int)$environmentId ?>"><input type="hidden" name="flag_id" value="<?= (int)$flag['id'] ?>"><input type="hidden" name="enabled" value="<?= $isEnabled?'0':'1' ?>"><button class="button <?= $isEnabled?'':'primary' ?>" type="submit"><?= $isEnabled?'Tắt':'Bật' ?></button></form>
                        <?php elseif(!$isTestEnvironment): ?>
                            <span class="pill gray">Chỉ xem · môi trường phát hành</span>
                        <?php else: ?>
                            <span class="pill amber">CHƯA ĐƯỢC CẤP QUYỀN</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
</body>
</html>
