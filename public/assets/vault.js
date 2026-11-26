// Client half of the protocol. Runs in browsers and in Node 20+ (the tests use it as-is).
//
//   1. Make a throwaway P-256 key pair. The private key is non-extractable: page scripts can use it,
//      but cannot read its bytes.
//   2. Send only the public key. The server answers with its public key and a salt.
//   3. Both sides run ECDH + HKDF and end up with the same AES-GCM key (to decrypt) and HMAC key (to sign).
//      Neither key ever crosses the network, so a copied response is just noise.

const subtle = globalThis.crypto.subtle;
const utf8 = new TextEncoder();

export class VaultError extends Error {
  constructor(status, code) {
    super(`Vault request failed (${status}${code ? ` ${code}` : ''})`);
    this.status = status;
    this.code = code;
  }
}

export function toB64u(bytes) {
  const u8 = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  let s = '';
  for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode(...u8.subarray(i, i + 0x8000));
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function fromB64u(text) {
  const s = atob(text.replace(/-/g, '+').replace(/_/g, '/'));
  const out = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
  return out;
}

async function errorCode(res) {
  try {
    return (await res.json()).error ?? '';
  } catch {
    return '';
  }
}

/** Starts a viewing of `media`. `base` is the page URL (document.baseURI) or the site root. */
export async function openStream({ base, media, fetch: send = globalThis.fetch.bind(globalThis) }) {
  const pair = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
  const pub = await subtle.exportKey('raw', pair.publicKey);
  const res = await send(new URL('api/session.php', base), {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ media, pub: toB64u(pub) }),
    credentials: 'same-origin',
  });
  if (!res.ok) throw new VaultError(res.status, await errorCode(res));
  const info = await res.json();

  const peer = await subtle.importKey('raw', fromB64u(info.pub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
  const shared = new Uint8Array(await subtle.deriveBits({ name: 'ECDH', public: peer }, pair.privateKey, 256));
  const ikm = await subtle.importKey('raw', shared, 'HKDF', false, ['deriveKey']);
  shared.fill(0);
  const salt = fromB64u(info.salt);
  const hkdf = (label) => ({ name: 'HKDF', hash: 'SHA-256', salt, info: utf8.encode(`chunkvault ${label} ${info.sid}`) });
  const aes = await subtle.deriveKey(hkdf('enc'), ikm, { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
  const mac = await subtle.deriveKey(hkdf('mac'), ikm, { name: 'HMAC', hash: 'SHA-256', length: 256 }, false, ['sign']);

  return new Stream({
    base,
    send,
    sid: info.sid,
    aes,
    mac,
    skew: Number(info.now) - Date.now(),
    meta: { kind: info.kind, mime: info.mime, chunks: info.chunks },
  });
}

class Stream {
  #aes;
  #mac;
  #send;
  #base;
  #skew;

  constructor({ base, send, sid, aes, mac, skew, meta }) {
    this.sid = sid;
    this.meta = Object.freeze(meta);
    this.#aes = aes;
    this.#mac = mac;
    this.#send = send;
    this.#base = base;
    this.#skew = Number.isFinite(skew) ? Math.round(skew) : 0;
  }

  /** Fetches and decrypts chunk `i`. */
  async chunk(i) {
    return this.open(i, await this.fetchSealed(await this.signed(i)));
  }

  /** The request body for chunk `i`: signed, timestamped, with a one-time nonce. */
  async signed(i, t = Date.now() + this.#skew) {
    const n = toB64u(globalThis.crypto.getRandomValues(new Uint8Array(12)));
    const sig = await subtle.sign('HMAC', this.#mac, utf8.encode(`${this.sid}.${i}.${t}.${n}`));
    return JSON.stringify({ s: this.sid, i, t, n, g: toB64u(sig) });
  }

  /** Sends a signed body; returns the still-encrypted bytes. */
  async fetchSealed(body) {
    const res = await this.#send(new URL('api/chunk.php', this.#base), {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body,
      credentials: 'same-origin',
    });
    if (!res.ok) throw new VaultError(res.status, await errorCode(res));
    return new Uint8Array(await res.arrayBuffer());
  }

  /** Decrypts bytes from fetchSealed. Fails if they belong to another chunk or another viewer. */
  async open(i, sealed) {
    const plain = await subtle.decrypt(
      { name: 'AES-GCM', iv: sealed.subarray(0, 12), additionalData: utf8.encode(`${this.sid}|${i}`) },
      this.#aes,
      sealed.subarray(12),
    );
    return new Uint8Array(plain);
  }
}
