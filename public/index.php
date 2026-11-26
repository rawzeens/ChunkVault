<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

cv_session();
cv_page_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf'] ?? '';
    $ok = cv_same_origin() && is_string($token) && hash_equals(cv_csrf(), $token);
    $action = $_POST['action'] ?? '';
    $next = './';
    if ($ok && $action === 'login') {
        $raw = $_POST['name'] ?? '';
        $name = is_string($raw) ? trim(preg_replace('/\s+/u', ' ', $raw) ?? '') : '';
        if ($name !== '' && mb_strlen($name) <= 40) {
            session_regenerate_id(true);
            $_SESSION = ['viewer' => $name, 'csrf' => bin2hex(random_bytes(16))];
        } else {
            $next = './?e=name';
        }
    } elseif ($ok && $action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    header('Location: ' . $next, true, 303);
    exit;
}

$viewer = cv_viewer();
$items = $viewer !== null ? Library::all() : [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vault</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if ($viewer === null): ?>
<main class="center">
  <form class="card login" method="post" action="./">
    <span class="brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
      Vault
    </span>
    <h1>Sign in to view</h1>
    <p class="muted">Your name is marked on everything you open.</p>
    <?php if (($_GET['e'] ?? '') === 'name'): ?><p class="error">Please enter a name (up to 40 letters).</p><?php endif; ?>
    <label class="field">Name
      <input name="name" maxlength="40" autocomplete="name" required autofocus>
    </label>
    <input type="hidden" name="csrf" value="<?= h(cv_csrf()) ?>">
    <input type="hidden" name="action" value="login">
    <button class="btn" type="submit">Continue</button>
  </form>
</main>
<?php else: ?>
<header class="bar">
  <span class="brand">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
    Vault
  </span>
  <span class="grow"></span>
  <span class="who">Signed in as <b><?= h($viewer) ?></b></span>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= h(cv_csrf()) ?>">
    <input type="hidden" name="action" value="logout">
    <button class="pill" type="submit">Sign out</button>
  </form>
</header>
<main class="wrap">
  <h1>Library</h1>
  <?php if (!$items): ?>
    <p class="empty">Nothing here yet.</p>
  <?php else: ?>
  <ul class="grid">
    <?php foreach ($items as $m): $video = ($m['kind'] ?? '') === 'video'; ?>
    <li>
      <a class="tile" href="watch.php?id=<?= h($m['id']) ?>">
        <span class="thumb">
          <?php if ($video): ?>
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg>
          <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></svg>
          <?php endif; ?>
        </span>
        <span class="name"><?= h((string)($m['title'] ?? $m['id'])) ?></span>
        <span class="muted small"><?= $video ? 'Video' : 'Photo' ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
