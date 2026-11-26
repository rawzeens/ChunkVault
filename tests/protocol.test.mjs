import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { after, before, test } from 'node:test';
import { openStream, VaultError } from '../public/assets/vault.js';
import { jar, login, makeMp4, makePng, sha256, startServer } from './helpers.mjs';

const PNG_MAGIC = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
let srv;
let png;

before(async () => {
  srv = await startServer();
  png = makePng(220, 160); // ~105 KB of noise -> 7 chunks of 16 KB
  const file = join(srv.tmp, 'photo.png');
  await writeFile(file, png);
  const r = srv.pack(file, '--id', 'photo', '--title', 'Test photo', '--chunk-kb', '16');
  assert.equal(r.status, 0, r.stderr);
});
after(() => srv?.stop());

const viewer = async (name = 'Tester') => {
  const f = jar();
  await login(f, srv.base, name);
  return f;
};
const post = (f, path, body) =>
  f(new URL(path, srv.base), { method: 'POST', headers: { 'content-type': 'application/json' }, body });

test('the photo arrives intact, but only the page can decrypt it', async () => {
  const f = await viewer();
  const s = await openStream({ base: srv.base, media: 'photo', fetch: f });
  assert.equal(s.meta.kind, 'image');
  assert.equal(s.meta.mime, 'image/png');
  assert.ok(s.meta.chunks >= 5, `expected several chunks, got ${s.meta.chunks}`);

  const parts = [];
  for (let i = 0; i < s.meta.chunks; i++) {
    const sealed = Buffer.from(await s.fetchSealed(await s.signed(i)));
    const plain = await s.open(i, new Uint8Array(sealed));
    // This is what DevTools / a sniffer sees: no file signature, none of the real bytes.
    assert.ok(!sealed.includes(PNG_MAGIC));
    assert.ok(!sealed.includes(Buffer.from(plain.subarray(0, 64))));
    parts.push(plain);
  }
  assert.equal(sha256(Buffer.concat(parts)), sha256(png));
});

test('chunks are encrypted on disk as well', async () => {
  const disk = await readFile(join(srv.storage, 'media', 'photo', '000000.bin'));
  assert.ok(!disk.includes(PNG_MAGIC));
  assert.ok(!disk.includes(png.subarray(16, 64)));
});

test('two viewers get different bytes for the same chunk', async () => {
  const a = await openStream({ base: srv.base, media: 'photo', fetch: await viewer('A') });
  const b = await openStream({ base: srv.base, media: 'photo', fetch: await viewer('B') });
  const sa = await a.fetchSealed(await a.signed(0));
  const sb = await b.fetchSealed(await b.signed(0));
  assert.notDeepEqual(sa, sb);
  assert.deepEqual(await a.open(0, sa), await b.open(0, sb));
  await assert.rejects(a.open(0, sb), 'a chunk sealed for B must not open with A’s key');
});

test('a copied request cannot be replayed', async () => {
  const f = await viewer();
  const s = await openStream({ base: srv.base, media: 'photo', fetch: f });
  const body = await s.signed(0);
  assert.equal((await post(f, 'api/chunk.php', body)).status, 200);
  const again = await post(f, 'api/chunk.php', body);
  assert.equal(again.status, 403);
  assert.equal((await again.json()).error, 'replay');
});

test('an edited request fails the signature check', async () => {
  const f = await viewer();
  const s = await openStream({ base: srv.base, media: 'photo', fetch: f });
  const body = JSON.parse(await s.signed(0));
  body.i = 1;
  const res = await post(f, 'api/chunk.php', JSON.stringify(body));
  assert.equal(res.status, 403);
  assert.equal((await res.json()).error, 'signature');
});

test('old requests are refused', async () => {
  const f = await viewer();
  const s = await openStream({ base: srv.base, media: 'photo', fetch: f });
  const res = await post(f, 'api/chunk.php', await s.signed(0, Date.now() - 120_000));
  assert.equal(res.status, 403);
  assert.equal((await res.json()).error, 'clock');
});

test('another browser cannot use someone else’s stream', async () => {
  const s = await openStream({ base: srv.base, media: 'photo', fetch: await viewer('Owner') });
  const res = await post(await viewer('Intruder'), 'api/chunk.php', await s.signed(0));
  assert.equal(res.status, 403);
});

test('no sign-in, no key', async () => {
  await assert.rejects(
    openStream({ base: srv.base, media: 'photo', fetch: jar() }),
    (err) => err instanceof VaultError && err.status === 401,
  );
});

test('chunks cannot be swapped or asked for out of range', async () => {
  const f = await viewer();
  const s = await openStream({ base: srv.base, media: 'photo', fetch: f });
  const first = await s.fetchSealed(await s.signed(0));
  await assert.rejects(s.open(1, first));
  const res = await post(f, 'api/chunk.php', await s.signed(999));
  assert.equal(res.status, 404);
});

test('unknown media and bad keys are rejected', async () => {
  const f = await viewer();
  await assert.rejects(openStream({ base: srv.base, media: 'nope', fetch: f }), (e) => e.status === 404);
  const bad = await post(f, 'api/session.php', JSON.stringify({ media: 'photo', pub: 'BA' + 'A'.repeat(85) }));
  assert.equal(bad.status, 400);
});

test('pages need sign-in and are locked down', async () => {
  const anon = await jar()(new URL('watch.php?id=photo', srv.base));
  assert.equal(anon.status, 303);

  const res = await (await viewer())(new URL('watch.php?id=photo', srv.base));
  assert.equal(res.status, 200);
  const csp = res.headers.get('content-security-policy');
  assert.match(csp, /frame-ancestors 'none'/);
  assert.match(csp, /script-src 'self'/);
  const html = await res.text();
  assert.doesNotMatch(html, /<img|<video|\.bin|storage/);
});

test('pack reads codecs from a streamable MP4 and refuses a plain one', async () => {
  const good = join(srv.tmp, 'good.mp4');
  const plain = join(srv.tmp, 'plain.mp4');
  await writeFile(good, makeMp4());
  await writeFile(plain, makeMp4({ fragmented: false }));

  const ok = srv.pack(good, '--id', 'clip', '--chunk-kb', '16');
  assert.equal(ok.status, 0, ok.stderr);
  const manifest = JSON.parse(await readFile(join(srv.storage, 'media', 'clip', 'manifest.json'), 'utf8'));
  assert.equal(manifest.mime, 'video/mp4; codecs="avc1.64001F,mp4a.40.2"');
  assert.equal(manifest.kind, 'video');

  const no = srv.pack(plain);
  assert.notEqual(no.status, 0);
  assert.match(no.stderr, /ffmpeg .*frag_keyframe/);
});
