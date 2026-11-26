<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

cv_session();
$viewer = cv_viewer();
if ($viewer === null) {
    header('Location: ./', true, 303);
    exit;
}
session_write_close();
cv_page_headers();

$id = $_GET['id'] ?? '';
$media = is_string($id) ? Library::get($id) : null;
if ($media === null) {
    http_response_code(404);
}
$title = $media !== null ? (string)($media['title'] ?? $media['id']) : 'Not found';
$video = $media !== null && $media['kind'] === 'video';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Vault</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="bar">
  <a class="back" href="./">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
    Library
  </a>
  <span class="grow"></span>
  <span class="who">Signed in as <b><?= h($viewer) ?></b></span>
</header>
<main class="wrap viewer">
<?php if ($media === null): ?>
  <h1>Not found</h1>
  <p class="muted">This item is no longer available.</p>
<?php else: ?>
  <h1><?= h($title) ?></h1>
  <div class="player" data-player>
    <div class="stage" id="vault" data-media="<?= h($media['id']) ?>" data-viewer="<?= h($viewer) ?>" data-state="opening">
      <canvas aria-label="<?= h($title) ?>"></canvas>
      <p class="status" data-status>Opening…</p>
      <?php if ($video): ?>
      <button class="bigplay" type="button" data-act="play" aria-label="Play" hidden>
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg>
      </button>
      <?php endif; ?>
    </div>
    <?php if ($video): ?>
    <div class="controls" data-controls hidden>
      <button type="button" class="icon" data-act="play" aria-label="Play">
        <svg class="i-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg>
        <svg class="i-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
      </button>
      <input type="range" data-act="seek" min="0" max="0" step="0.01" value="0" aria-label="Position">
      <span class="time" data-time>0:00</span>
      <button type="button" class="icon" data-act="mute" aria-label="Mute">
        <svg class="i-sound" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>
        <svg class="i-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="m16 9 5 6M21 9l-5 6"/></svg>
      </button>
      <button type="button" class="icon" data-act="full" aria-label="Full screen">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
      </button>
    </div>
    <?php endif; ?>
  </div>
  <p class="note">Shown only to you. Your name is marked on this <?= $video ? 'video' : 'photo' ?>.</p>
  <script type="module" src="assets/player.js"></script>
<?php endif; ?>
</main>
</body>
</html>
