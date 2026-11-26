import { spawn, spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { crc32, deflateSync } from 'node:zlib';

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');

/** Starts `php -S` on a random port with its own empty storage and session folders. */
export async function startServer() {
  const tmp = await mkdtemp(join(tmpdir(), 'chunkvault-'));
  const storage = join(tmp, 'storage');
  const sessions = await mkdtemp(join(tmp, 'sessions-'));
  const env = { ...process.env, CHUNKVAULT_STORAGE: storage };
  delete env.CHUNKVAULT_MASTER_KEY;

  for (let attempt = 0; attempt < 5; attempt++) {
    const port = 20000 + Math.floor(Math.random() * 30000);
    const proc = spawn('php', ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, '-t', join(ROOT, 'public')], {
      env,
      stdio: 'ignore',
    });
    const base = `http://127.0.0.1:${port}/`;
    for (let i = 0; i < 60; i++) {
      if (proc.exitCode !== null) break;
      try {
        await fetch(base);
        return {
          base,
          tmp,
          storage,
          pack: (...args) =>
            spawnSync('php', [join(ROOT, 'bin/pack.php'), ...args], { env, encoding: 'utf8' }),
          stop: async () => {
            proc.kill();
            await rm(tmp, { recursive: true, force: true });
          },
        };
      } catch {
        await sleep(100);
      }
    }
    proc.kill();
  }
  throw new Error('php -S did not start');
}

/** fetch() with a one-cookie jar, like a single browser profile. */
export function jar() {
  let cookie = '';
  return async (url, opts = {}) => {
    const headers = new Headers(opts.headers || {});
    if (cookie) headers.set('cookie', cookie);
    const res = await fetch(url, { ...opts, headers, redirect: 'manual' });
    for (const c of res.headers.getSetCookie()) {
      const kv = c.split(';')[0];
      if (kv.startsWith('cv_sid=')) cookie = kv;
    }
    return res;
  };
}

export async function login(f, base, name = 'Tester') {
  const html = await (await f(base)).text();
  const csrf = html.match(/name="csrf" value="([0-9a-f]+)"/)?.[1];
  if (!csrf) throw new Error('no csrf token on sign-in page');
  const res = await f(base, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'login', name, csrf }),
  });
  if (res.status !== 303) throw new Error(`login failed: ${res.status}`);
}

/** A noisy (incompressible) RGB PNG, so it spans several chunks. */
export function makePng(w, h) {
  const row = w * 3 + 1;
  const raw = randomBytes(row * h);
  for (let y = 0; y < h; y++) raw[y * row] = 0; // filter byte "none" at the start of each row
  const chunk = (type, data) => {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(body));
    return Buffer.concat([len, body, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // RGB
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

const u32 = (n) => {
  const b = Buffer.alloc(4);
  b.writeUInt32BE(n);
  return b;
};
const box = (type, ...parts) => {
  const body = Buffer.concat(parts.map((p) => (Buffer.isBuffer(p) ? p : Buffer.from(p, 'latin1'))));
  return Buffer.concat([u32(8 + body.length), Buffer.from(type, 'latin1'), body]);
};
const fullBox = (type, ...parts) => box(type, Buffer.alloc(4), ...parts);

/** Just enough MP4 structure for the codec reader: H.264 High@3.1 video + AAC-LC audio. */
export function makeMp4({ fragmented = true } = {}) {
  const avc1 = box('avc1', Buffer.alloc(78), box('avcC', Buffer.from([1, 0x64, 0x00, 0x1f, 0xff, 0xe1])));
  const esds = fullBox(
    'esds',
    Buffer.from([
      0x03, 0x16, 0x00, 0x01, 0x00, // ES_Descriptor, ES_ID, flags
      0x04, 0x11, 0x40, 0x15, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // DecoderConfig: AAC (0x40)
      0x05, 0x02, 0x12, 0x10, // AudioSpecificConfig: object type 2 (LC)
    ]),
  );
  const mp4a = box('mp4a', Buffer.alloc(28), esds);
  const trak = (entry) => box('trak', box('mdia', box('minf', box('stbl', fullBox('stsd', u32(1), entry)))));
  const moov = box(
    'moov',
    fullBox('mvhd', Buffer.alloc(96)),
    trak(avc1),
    trak(mp4a),
    ...(fragmented ? [box('mvex', fullBox('trex', Buffer.alloc(20)))] : []),
  );
  const media = fragmented
    ? [box('moof', fullBox('mfhd', u32(1))), box('mdat', randomBytes(40000))]
    : [box('mdat', randomBytes(40000))];
  return Buffer.concat([box('ftyp', 'isom', Buffer.alloc(4), 'isomiso6'), moov, ...media]);
}
