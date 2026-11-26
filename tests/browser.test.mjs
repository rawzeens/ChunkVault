// End to end in real (headless) Chrome: record a clip, add it, then watch it the way a viewer would.
// Screenshots go to $CV_SHOTS (default: <tmp>/chunkvault-shots).
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { CHROME, launch } from './cdp.mjs';
import { sleep, startServer } from './helpers.mjs';

const SHOTS = process.env.CV_SHOTS || join(tmpdir(), 'chunkvault-shots');

// 4 s of animated canvas, recorded by Chrome itself (MP4 if it can, else WebM). Both come out fragmented.
const RECORD = `(async () => {
  const c = document.createElement('canvas'); c.width = 640; c.height = 360;
  const g = c.getContext('2d');
  const type = ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm']
    .find((t) => MediaRecorder.isTypeSupported(t));
  const rec = new MediaRecorder(c.captureStream(30), { mimeType: type, videoBitsPerSecond: 2500000 });
  const parts = [];
  rec.ondataavailable = (e) => e.data.size && parts.push(e.data);
  let f = 0;
  const draw = () => {
    g.fillStyle = 'hsl(' + ((f * 3) % 360) + ' 65% 42%)'; g.fillRect(0, 0, 640, 360);
    g.fillStyle = 'rgba(255,255,255,.9)'; g.beginPath(); g.arc(80 + (f * 6) % 480, 180, 48, 0, 7); g.fill();
    g.fillStyle = '#fff'; g.font = 'bold 64px sans-serif'; g.fillText('Frame ' + f, 40, 320); f++;
  };
  draw(); const timer = setInterval(draw, 33);
  rec.start(500);
  await new Promise((r) => setTimeout(r, 4000));
  const stopped = new Promise((r) => (rec.onstop = r)); rec.stop(); await stopped; clearInterval(timer);
  const buf = new Uint8Array(await new Blob(parts).arrayBuffer());
  let s = ''; for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
  return { type, b64: btoa(s) };
})()`;

const PHOTO = `(() => {
  const c = document.createElement('canvas'); c.width = 1200; c.height = 800;
  const g = c.getContext('2d');
  const sky = g.createLinearGradient(0, 0, 0, 800); sky.addColorStop(0, '#1f4e8c'); sky.addColorStop(1, '#f2a65a');
  g.fillStyle = sky; g.fillRect(0, 0, 1200, 800);
  g.fillStyle = '#ffe9a8'; g.beginPath(); g.arc(860, 300, 110, 0, 7); g.fill();
  g.fillStyle = '#1b2a3a'; g.beginPath(); g.moveTo(0, 800); g.lineTo(0, 560); g.lineTo(260, 380); g.lineTo(520, 600);
  g.lineTo(760, 430); g.lineTo(1200, 640); g.lineTo(1200, 800); g.fill();
  g.fillStyle = '#fff'; g.font = 'bold 72px sans-serif'; g.fillText('Private photo', 60, 140);
  return c.toDataURL('image/png').split(',')[1];
})()`;

const SPREAD = `(() => {
  const c = document.querySelector('#vault canvas');
  const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
  let lo = 255, hi = 0;
  for (let i = 0; i < d.length; i += 4 * 101) { lo = Math.min(lo, d[i]); hi = Math.max(hi, d[i]); }
  return hi - lo;
})()`;

const state = `document.getElementById('vault').dataset.state`;

test('real browser: photo and video render on canvas; DevTools only sees ciphertext', {
  skip: CHROME ? false : 'Chrome not found (set CHROME_BIN)',
  timeout: 180000,
}, async (t) => {
  const srv = await startServer();
  const browser = await launch();
  try {
    await mkdir(SHOTS, { recursive: true });
    const page = await browser.newPage();
    await page.call('Emulation.setDeviceMetricsOverride', { width: 1200, height: 860, deviceScaleFactor: 1, mobile: false });
    await page.load(srv.base);

    const rec = await page.eval(RECORD);
    const clip = join(srv.tmp, rec.type.startsWith('video/mp4') ? 'clip.mp4' : 'clip.webm');
    await writeFile(clip, Buffer.from(rec.b64, 'base64'));
    let r = srv.pack(clip, '--id', 'clip', '--title', 'Sample clip', '--chunk-kb', '64');
    assert.equal(r.status, 0, r.stderr);
    t.diagnostic(`Chrome recorded ${rec.type}; ${r.stdout.trim()}`);

    const photo = join(srv.tmp, 'photo.png');
    await writeFile(photo, Buffer.from(await page.eval(PHOTO), 'base64'));
    r = srv.pack(photo, '--id', 'photo', '--title', 'Sample photo', '--chunk-kb', '32');
    assert.equal(r.status, 0, r.stderr);

    await page.load(`document.querySelector('input[name=name]').value = 'Ada Lim'; document.querySelector('form').submit()`);
    assert.equal(
      await page.eval(`document.querySelectorAll('.tile').length`),
      2,
      `library should list both items. Page: ${await page.eval(`location.href + ' :: ' + document.body.innerText`)}`,
    );
    await page.shot(join(SHOTS, 'library.png'));

    const traffic = page.captureBodies('/api/chunk.php');

    // Photo
    await page.load(new URL('watch.php?id=photo', srv.base).href);
    await page.waitFor(`${state} === 'ready'`);
    assert.equal(await page.eval(`document.querySelectorAll('img, video, picture, object, embed').length`), 0);
    assert.ok((await page.eval(SPREAD)) > 40, 'photo canvas should not be blank');
    await page.shot(join(SHOTS, 'photo.png'));

    // Video
    await page.load(new URL('watch.php?id=clip', srv.base).href);
    await page.waitFor(`${state} === 'paused'`);
    await page.eval(`document.querySelector('.bigplay').click()`);
    await page.waitFor(`Number(document.getElementById('vault').dataset.t) > 1.5`);
    assert.equal(await page.eval(state), 'playing');
    assert.equal(await page.eval(`document.querySelectorAll('img, video').length`), 0, 'no <video> in the page');
    assert.ok((await page.eval(SPREAD)) > 40, 'video canvas should show frames');
    await page.shot(join(SHOTS, 'video.png'));

    await page.call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await sleep(400);
    await page.shot(join(SHOTS, 'video-phone.png'));
    assert.equal(await page.eval(`document.documentElement.scrollWidth <= innerWidth`), true, 'no sideways scroll on phones');

    // What DevTools captured for every chunk request: none of it is a playable or viewable file.
    const bodies = await traffic.collect();
    assert.ok(bodies.length >= 4, `expected chunk traffic, got ${bodies.length}`);
    const magic = [Buffer.from('ftyp'), Buffer.from([0x1a, 0x45, 0xdf, 0xa3]), Buffer.from([0x89, 0x50, 0x4e, 0x47])];
    for (const body of bodies) for (const m of magic) assert.ok(!body.includes(m), 'chunk looked like a real file');
    t.diagnostic(`checked ${bodies.length} chunk responses; screenshots in ${SHOTS}`);
    assert.deepEqual(page.logs.filter((l) => /error/i.test(l)), []);
  } finally {
    await browser.close();
    await srv.stop();
  }
});
