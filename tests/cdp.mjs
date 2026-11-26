// Minimal Chrome DevTools Protocol driver (no Puppeteer, no npm install).
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { sleep } from './helpers.mjs';

export const CHROME =
  process.env.CHROME_BIN ||
  [
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].find((p) => existsSync(p));

export async function launch() {
  const dir = await mkdtemp(join(tmpdir(), 'chunkvault-chrome-'));
  const proc = spawn(
    CHROME,
    [
      '--headless=new',
      '--remote-debugging-port=0',
      `--user-data-dir=${dir}`,
      '--no-first-run',
      '--no-default-browser-check',
      '--autoplay-policy=no-user-gesture-required',
      '--mute-audio',
      'about:blank',
    ],
    { stdio: ['ignore', 'ignore', 'pipe'] },
  );
  const wsUrl = await new Promise((resolve, reject) => {
    let log = '';
    const timer = setTimeout(() => reject(new Error(`Chrome did not start:\n${log}`)), 20000);
    proc.stderr.on('data', (d) => {
      if (log.length < 20000) log += d;
      const m = log.match(/DevTools listening on (ws:\/\/\S+)/);
      if (m) {
        clearTimeout(timer);
        resolve(m[1]);
      }
    });
    proc.on('exit', (code) => {
      clearTimeout(timer);
      reject(new Error(`Chrome exited (${code}):\n${log}`));
    });
  });

  const ws = new WebSocket(wsUrl);
  await new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = () => reject(new Error('Could not connect to Chrome'));
  });
  let seq = 0;
  const calls = new Map();
  const listeners = new Set();
  ws.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id) {
      const c = calls.get(msg.id);
      if (!c) return;
      calls.delete(msg.id);
      if (msg.error) c.reject(new Error(`${c.method}: ${msg.error.message}`));
      else c.resolve(msg.result);
    } else {
      for (const l of listeners) l(msg);
    }
  };
  const send = (method, params = {}, sessionId) =>
    new Promise((resolve, reject) => {
      const id = ++seq;
      calls.set(id, { resolve, reject, method });
      ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });

  async function newPage() {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    const call = (method, params) => send(method, params, sessionId);
    const on = (method, fn) => {
      const l = (msg) => msg.sessionId === sessionId && msg.method === method && fn(msg.params);
      listeners.add(l);
      return () => listeners.delete(l);
    };
    const logs = [];
    on('Runtime.consoleAPICalled', (p) => logs.push(p.args.map((a) => a.value ?? a.description).join(' ')));
    on('Runtime.exceptionThrown', (p) => logs.push(p.exceptionDetails.exception?.description ?? p.exceptionDetails.text));
    await call('Page.enable');
    await call('Runtime.enable');
    await call('Network.enable', { maxTotalBufferSize: 200e6, maxResourceBufferSize: 50e6 });

    const page = {
      call,
      on,
      logs,
      async eval(expression) {
        const r = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
        return r.result.value;
      },
      /** Runs `action` (a navigation or an expression that navigates) and waits for the new page. */
      async load(action) {
        const loaded = new Promise((resolve) => {
          const off = on('Page.loadEventFired', () => {
            off();
            resolve();
          });
        });
        if (action.startsWith('http')) await call('Page.navigate', { url: action });
        else await page.eval(action);
        await loaded;
      },
      async waitFor(expression, timeout = 20000) {
        const end = Date.now() + timeout;
        while (Date.now() < end) {
          if (await page.eval(expression)) return;
          await sleep(150);
        }
        throw new Error(`Timed out waiting for: ${expression}\nPage log:\n${logs.join('\n')}`);
      },
      async shot(path) {
        const { data } = await call('Page.captureScreenshot', { format: 'png' });
        await writeFile(path, Buffer.from(data, 'base64'));
      },
      /** Collects the raw response bodies of requests whose URL contains `fragment`. */
      captureBodies(fragment) {
        const wanted = new Set();
        const bodies = [];
        on('Network.responseReceived', (p) => p.response.url.includes(fragment) && wanted.add(p.requestId));
        on('Network.loadingFinished', (p) => {
          if (!wanted.has(p.requestId)) return;
          bodies.push(
            call('Network.getResponseBody', { requestId: p.requestId }).then(
              (r) => Buffer.from(r.body, r.base64Encoded ? 'base64' : 'utf8'),
              () => null,
            ),
          );
        });
        return { collect: async () => (await Promise.all(bodies)).filter(Boolean) };
      },
    };
    return page;
  }

  return {
    newPage,
    async close() {
      try {
        await send('Browser.close');
      } catch {}
      proc.kill();
      await sleep(200);
      await rm(dir, { recursive: true, force: true });
    },
  };
}
