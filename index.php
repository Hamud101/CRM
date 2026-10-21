<?php
require_once __DIR__ . '/lib/auth.php';

if (($_GET['logout'] ?? '') === '1') { auth_logout(); header('Location: ' . crm_base()); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (auth_login((string)$_POST['password'])) { header('Location: ' . crm_base()); exit; }
    $err = 'Incorrect password.';
}

$authed = auth_user();
$base = crm_base();
$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$logo = (string)biz('logo', '');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= $e(biz('app_name')) ?></title>
<link rel="stylesheet" href="<?= $e($base) ?>assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body class="<?= $authed ? 'app' : 'gate' ?>">

<?php if (!$authed): ?>
<form class="login" method="post" autocomplete="off">
  <?php if ($logo !== ''): ?>
  <img class="mark" src="<?= $e($base . $logo) ?>" alt="<?= $e(biz('name')) ?>">
  <?php else: ?>
  <div class="mark wordmark"><?= $e(biz('name')) ?></div>
  <?php endif; ?>
  <p class="sub">Sales CRM</p>
  <input type="password" name="password" placeholder="Password" autofocus required>
  <button type="submit">Sign in</button>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
</form>

<?php else: ?>
<div id="app" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
  <div class="boot">Loading…</div>
</div>
<script>window.CRM = <?= json_encode(['base' => $base, 'business' => biz_public(), 'local' => crm_local_mode()],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= $e($base) ?>assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
<?php endif; ?>

</body>
</html>
