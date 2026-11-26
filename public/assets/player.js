// Draws decrypted media onto a <canvas>. There is never an <img>, a <video> in the page,
// or a file URL to copy: photos go Blob -> ImageBitmap -> canvas, video goes
// MediaSource -> detached <video> -> canvas, frame by frame, with the viewer's name burned in.
import { openStream, VaultError } from './vault.js';

const root = document.getElementById('vault');
if (root) boot(root).catch((err) => fail(root, err));

function fail(root, err) {
  console.error(err);
  root.dataset.state = 'error';
  const status = root.querySelector('[data-status]');
  status.hidden = false;
  status.textContent =
    err instanceof VaultError && err.status === 401
      ? 'Please sign in again.'
      : 'This could not be opened. Please reload the page.';
}

const once = (target, type) => new Promise((resolve) => target.addEventListener(type, resolve, { once: true }));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function boot(root) {
  const player = root.closest('[data-player]');
  const canvas = root.querySelector('canvas');
  const ctx = canvas.getContext('2d', { alpha: false });
  const status = root.querySelector('[data-status]');
  const media = root.dataset.media;
  const when = new Date().toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  const mark = watermarker(`${root.dataset.viewer} · ${when}`);

  for (const type of ['contextmenu', 'dragstart', 'selectstart']) player.addEventListener(type, (e) => e.preventDefault());

  const say = (state, text = '') => {
    root.dataset.state = state;
    status.textContent = text;
    status.hidden = !text;
  };

  const start = () => openStream({ base: document.baseURI, media });
  let stream = await start();
  let renewing = null;
  const renew = () =>
    (renewing ??= start()
      .then((s) => {
        stream = s;
      })
      .finally(() => {
        renewing = null;
      }));

  // One retry per chunk: a fresh key if the old one expired, or a short pause after a network blip.
  const chunk = async (i) => {
    try {
      return await stream.chunk(i);
    } catch (err) {
      if (err instanceof VaultError && err.status !== 403 && err.status !== 410) throw err;
      if (err instanceof VaultError) await renew();
      else await sleep(500);
      return stream.chunk(i);
    }
  };

  if (stream.meta.kind === 'image') await showImage();
  else await showVideo();

  async function showImage() {
    const { chunks, mime } = stream.meta;
    const parts = new Array(chunks);
    let next = 0;
    let done = 0;
    say('loading', 'Loading…');
    const worker = async () => {
      while (next < chunks) {
        const i = next++;
        parts[i] = await chunk(i);
        done++;
        say('loading', `Loading ${Math.round((done / chunks) * 100)}%`);
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, chunks) }, worker));

    const bytes = join(parts);
    let bitmap;
    try {
      bitmap = await createImageBitmap(new Blob([bytes], { type: mime }));
    } finally {
      bytes.fill(0);
      for (const p of parts) p.fill(0);
    }
    const scale = Math.min(1, 4096 / Math.max(bitmap.width, bitmap.height));
    const w = Math.round(bitmap.width * scale);
    const h = Math.round(bitmap.height * scale);
    canvas.width = w;
    canvas.height = h;
    root.style.aspectRatio = `${w} / ${h}`;
    ctx.drawImage(bitmap, 0, 0, w, h);
    bitmap.close();
    mark(ctx, w, h, null);
    say('ready');
  }

  async function showVideo() {
    const { chunks, mime } = stream.meta;
    if (!('MediaSource' in window) || !MediaSource.isTypeSupported(mime)) {
      say('error', 'This browser can’t play this video.');
      return;
    }
    const controls = player.querySelector('[data-controls]');
    const seek = controls.querySelector('[data-act=seek]');
    const time = controls.querySelector('[data-time]');
    const bigPlay = root.querySelector('.bigplay');

    // Never attached to the page: nothing to right-click, inspect or save.
    const video = document.createElement('video');
    video.playsInline = true;
    video.preload = 'auto';
    video.disablePictureInPicture = true;
    const source = new MediaSource();
    const url = URL.createObjectURL(source);
    video.src = url;
    await once(source, 'sourceopen');
    URL.revokeObjectURL(url);
    const buffer = source.addSourceBuffer(mime);

    canvas.width = 1280;
    canvas.height = 720;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    say('loading', 'Loading…');

    const idle = async () => {
      while (buffer.updating) await once(buffer, 'updateend');
    };
    const bufferedEnd = () => (video.buffered.length ? video.buffered.end(video.buffered.length - 1) : 0);
    const ahead = () => {
      const b = video.buffered;
      const t = video.currentTime;
      for (let k = 0; k < b.length; k++) if (t >= b.start(k) - 0.5 && t <= b.end(k)) return b.end(k) - t;
      return 0;
    };
    const append = async (data) => {
      for (let attempt = 0; ; attempt++) {
        await idle();
        try {
          buffer.appendBuffer(data);
          await once(buffer, 'updateend');
          return;
        } catch (err) {
          if (err.name !== 'QuotaExceededError' || attempt > 4) throw err;
          const cut = video.currentTime - 15; // free memory behind the playhead
          if (cut > 1) {
            buffer.remove(0, cut);
            await once(buffer, 'updateend');
          } else {
            await once(video, 'timeupdate');
          }
        }
      }
    };

    // Download in order, a few chunks ahead, and stop once ~40 s is buffered.
    const pending = new Map();
    const want = (k) => {
      if (k >= chunks || pending.has(k)) return;
      const p = chunk(k);
      p.catch(() => {});
      pending.set(k, p);
    };
    (async () => {
      for (let i = 0; i < chunks; i++) {
        while (ahead() > 40) await once(video, 'timeupdate');
        for (let k = i; k < i + 3; k++) want(k);
        const data = await pending.get(i);
        pending.delete(i);
        await append(data);
        data.fill(0);
      }
      await idle();
      if (source.readyState === 'open') source.endOfStream();
    })().catch((err) => fail(root, err));

    let raf = 0;
    const paint = () => {
      raf = 0;
      if (video.readyState >= 2 && video.videoWidth) {
        const w = video.videoWidth;
        const h = video.videoHeight;
        if (canvas.width !== w || canvas.height !== h) {
          canvas.width = w;
          canvas.height = h;
          root.style.aspectRatio = `${w} / ${h}`;
        }
        ctx.drawImage(video, 0, 0, w, h);
        mark(ctx, w, h, video.currentTime);
      }
      const end = Number.isFinite(video.duration) ? video.duration : bufferedEnd();
      seek.max = String(end || 0);
      if (document.activeElement !== seek) seek.value = String(video.currentTime);
      time.textContent = `${clock(video.currentTime)} / ${clock(end)}`;
      root.dataset.t = video.currentTime.toFixed(2);
      if (!video.paused) raf = requestAnimationFrame(paint);
    };
    const kick = () => {
      if (!raf) raf = requestAnimationFrame(paint);
    };
    const sync = () => {
      const playing = !video.paused;
      player.toggleAttribute('data-playing', playing);
      for (const b of player.querySelectorAll('[data-act=play]')) b.setAttribute('aria-label', playing ? 'Pause' : 'Play');
      bigPlay.hidden = playing;
      if (root.dataset.state !== 'error') root.dataset.state = playing ? 'playing' : 'paused';
      kick();
    };

    for (const type of ['seeked', 'timeupdate', 'durationchange']) video.addEventListener(type, kick);
    video.addEventListener('play', sync);
    video.addEventListener('pause', sync);
    video.addEventListener('ended', sync);
    video.addEventListener('waiting', () => {
      status.textContent = 'Loading…';
      status.hidden = false;
    });
    video.addEventListener('playing', () => {
      status.hidden = true;
    });
    video.addEventListener('error', () => fail(root, video.error));
    video.addEventListener(
      'loadeddata',
      () => {
        say('paused');
        controls.hidden = false;
        sync();
      },
      { once: true },
    );

    const toggle = () => {
      if (root.dataset.state === 'error' || controls.hidden) return;
      if (video.paused) video.play().catch(() => {});
      else video.pause();
    };
    for (const b of player.querySelectorAll('[data-act=play]')) b.addEventListener('click', toggle);
    canvas.addEventListener('click', toggle);
    seek.addEventListener('input', () => {
      video.currentTime = Number(seek.value);
    });
    controls.querySelector('[data-act=mute]').addEventListener('click', () => {
      video.muted = !video.muted;
      player.toggleAttribute('data-muted', video.muted);
    });
    controls.querySelector('[data-act=full]').addEventListener('click', () => {
      if (document.fullscreenElement) document.exitFullscreen();
      else player.requestFullscreen?.();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) video.pause();
    });
  }
}

function join(parts) {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) {
    out.set(p, at);
    at += p.length;
  }
  return out;
}

function clock(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const s = Math.floor(seconds);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const ss = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

// Faint diagonal tiles across the whole frame, plus (for video) a clearer label that hops
// between corners every few seconds, so cropping or covering one spot is not enough.
function watermarker(text) {
  let layer = null;
  const tiles = (w, h) => {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const g = c.getContext('2d');
    const size = Math.max(14, Math.round(Math.min(w, h) / 22));
    g.font = `600 ${size}px system-ui, -apple-system, "Segoe UI", sans-serif`;
    g.textBaseline = 'middle';
    g.lineWidth = Math.max(1, size / 12);
    g.strokeStyle = 'rgba(0, 0, 0, 0.16)';
    g.fillStyle = 'rgba(255, 255, 255, 0.16)';
    g.translate(w / 2, h / 2);
    g.rotate(-Math.PI / 7);
    const stepX = g.measureText(text).width + size * 4;
    const stepY = size * 5;
    const reach = Math.hypot(w, h) / 2 + stepX;
    let row = 0;
    for (let y = -reach; y < reach; y += stepY, row++) {
      for (let x = -reach + (row % 2) * (stepX / 2); x < reach; x += stepX) {
        g.strokeText(text, x, y);
        g.fillText(text, x, y);
      }
    }
    return c;
  };

  return (ctx, w, h, t) => {
    if (!layer || layer.width !== w || layer.height !== h) layer = tiles(w, h);
    ctx.drawImage(layer, 0, 0);
    if (t == null) return;
    const size = Math.max(12, Math.round(Math.min(w, h) / 30));
    ctx.save();
    ctx.font = `600 ${size}px system-ui, -apple-system, "Segoe UI", sans-serif`;
    ctx.textBaseline = 'middle';
    const pad = Math.round(size * 0.6);
    const bw = ctx.measureText(text).width + pad * 2;
    const bh = size + pad * 1.4;
    const corner = Math.floor(t / 6) % 4;
    const x = corner % 2 ? w - bw - pad : pad;
    const y = corner < 2 ? pad : h - bh - pad;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.38)';
    ctx.fillRect(x, y, bw, bh);
    ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
    ctx.fillText(text, x + pad, y + bh / 2);
    ctx.restore();
  };
}
