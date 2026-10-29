<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';

if (!empty($_SESSION['demo_user'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!demo_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Phiên đăng nhập đã hết hạn. Vui lòng thử lại.';
    } elseif (($account = demo_authenticate_user($username, $password)) !== null) {
        session_regenerate_id(true);
        $_SESSION['demo_user'] = $account['username'];
        unset($_SESSION['demo_csrf_token']);
        header('Location: index.php');
        exit;
    } else {
        $error = 'Tên đăng nhập hoặc mật khẩu chưa chính xác.';
    }
}
?><!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#f6f8fb"><title>Đăng nhập · FeatureLab</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fb;color:#182230;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.login-shell{width:min(410px,calc(100% - 32px));}.brand{display:flex;align-items:center;justify-content:center;gap:10px;font-size:17px;font-weight:750;letter-spacing:-.4px;margin-bottom:24px}.brand-mark{width:36px;height:36px;border-radius:11px;background:#6d88ff;color:white;display:grid;place-items:center;font-size:20px;box-shadow:0 5px 13px #6d88ff44}.card{background:white;border:1px solid #e6ebf2;border-radius:16px;padding:30px;box-shadow:0 18px 50px #263a570d}.eyebrow{text-align:center;color:#738198;text-transform:uppercase;letter-spacing:.12em;font-weight:700;font-size:10px;margin-bottom:9px}h1{text-align:center;margin:0;font-size:23px;letter-spacing:-.7px}p.intro{text-align:center;color:#7b8798;font-size:12px;line-height:1.6;margin:9px 0 24px}.field{margin-bottom:16px}.field label{display:block;font-size:11px;font-weight:700;color:#465368;margin-bottom:7px}.field input{width:100%;border:1px solid #dfe5ed;border-radius:9px;padding:11px 12px;font-size:12px;color:#283548;outline:none}.field input:focus{border-color:#91a4f0;box-shadow:0 0 0 3px #4669e814}.error{background:#fff0f0;border:1px solid #f5d5d5;color:#b34848;border-radius:8px;padding:10px 11px;font-size:11px;margin-bottom:15px}.submit{width:100%;border:0;border-radius:9px;background:#4669e8;color:white;padding:11px;font-weight:700;font-size:12px;cursor:pointer;box-shadow:0 4px 10px #4669e833}.submit:hover{background:#385ad4}.hint{border-top:1px solid #edf0f4;margin-top:20px;padding-top:15px;color:#8793a2;font-size:10px;line-height:1.7}.hint b{color:#58667a;font-weight:650}.footer{text-align:center;color:#9aa4b2;font-size:10px;margin-top:18px}@media(max-width:460px){.card{padding:24px}}
  </style>
</head>
<body>
  <main class="login-shell">
    <div class="brand"><span class="brand-mark">✳</span>FeatureLab</div>
    <section class="card">
      <div class="eyebrow">FEATURE FLAG MANAGEMENT</div>
      <h1>Chào mừng trở lại</h1>
      <p class="intro">Đăng nhập để tiếp tục đến workspace FeatureLab.</p>
      <?php if ($error !== ''): ?><div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(demo_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="field"><label for="username">Tên đăng nhập</label><input id="username" name="username" type="text" autocomplete="username" required autofocus value="<?= htmlspecialchars(is_string($_POST['username'] ?? null) ? $_POST['username'] : '', ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="field"><label for="password">Mật khẩu</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="submit" type="submit">Đăng nhập &nbsp; →</button>
      </form>
      <div class="hint">Tài khoản demo: <b>admin</b> / <b>FeatureLab2026!</b><br>Có thể đổi bằng biến môi trường <b>DEMO_APP_USERNAME</b> và <b>DEMO_APP_PASSWORD</b>.</div>
    </section>
    <div class="footer">FeatureLab · Feature management workspace</div>
  </main>
</body>
</html>
