# ChunkVault

Serve photos and videos in small encrypted chunks that **only your own page can turn back into a picture**.
If someone copies a request from DevTools, grabs a chunk URL or sniffs the traffic, they get random bytes
that will not open, play or decrypt anywhere else.

PHP 8.1+ on the server, the browser's built-in WebCrypto on the page. No npm packages, no PHP extensions
beyond OpenSSL.

## What an outsider sees

| They try to… | They get |
|---|---|
| Save a chunk from the Network tab | Encrypted bytes. No file header, will not open as an image or video |
| "Copy as cURL" and replay it | `403 replay`: every request carries a one-time nonce |
| Edit the request (another chunk, another time) | `403 signature`: requests are signed with a key only the page holds |
| Use the request later | `403 clock`: requests older than 30 s are refused |
| Use it from another browser or account | `403`: streams are tied to the signed-in session cookie |
| Swap or reorder chunks | Decryption fails: each chunk is bound to its stream and position |
| Read two viewers' traffic | Different ciphertext. Each viewer gets their own key |
| Download `storage/` from the server | Still encrypted, with a separate master key |
| Right-click → Save image / video | There is no `<img>` or `<video>` in the page, only a `<canvas>` |

## How it works

```
 Page (WebCrypto)                                     Server (PHP + OpenSSL)
 ────────────────                                     ──────────────────────
 make ECDH key pair (private key non-extractable)
 POST api/session.php  { media, publicKey } ───────▶  make its own ECDH key pair
                       ◀───────  { publicKey, salt, sid, chunk count, type }
 ECDH + HKDF → AES key + HMAC key                     ECDH + HKDF → same AES key + HMAC key
              (neither key is ever sent)

 POST api/chunk.php { sid, i, time, nonce, HMAC } ─▶  check signature, time, nonce, cookie
                                                      decrypt chunk i from disk (master key)
                       ◀───────  AES-GCM(chunk i)     re-encrypt for this viewer only
 decrypt → photo: ImageBitmap → canvas
         → video: MediaSource → hidden <video> → canvas, frame by frame
 draw the viewer's name + time over every frame
```

## Quick start

```bash
php bin/pack.php ~/Pictures/beach.jpg --title "Beach"
php bin/pack.php ~/Movies/talk.mp4 --title "Talk"      # must be a streamable MP4, see below
npm start                                              # = php -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080, enter a name, pick an item.

### Adding media

```bash
php bin/pack.php <file> [--title "Name"] [--id slug] [--chunk-kb 512] [--replace]
php bin/pack.php --list
php bin/pack.php --remove <id>
```

- **Photos:** JPEG, PNG, GIF, WebP, AVIF. Default chunk size 64 KB.
- **Videos:** fragmented MP4 (H.264/HEVC/AV1/VP9 + AAC/Opus) or WebM. Default chunk size 512 KB.
  The codec string is read from the file automatically.
- A normal MP4 needs one conversion first. It's fast, with no re-encoding and no quality loss:

  ```bash
  ffmpeg -i input.mp4 -c copy -movflags frag_keyframe+empty_moov+default_base_moof output.mp4
  ```

The first `pack` creates `storage/master.key`. Back it up: without it the stored chunks cannot be read.
On a real server you can set `CHUNKVAULT_MASTER_KEY` (32 random bytes, base64) instead of using the file,
and `CHUNKVAULT_STORAGE` to keep media somewhere else.

## Deploying

1. Point the web server's document root at **`public/`**. `src/`, `bin/` and `storage/` stay unreachable.
   On shared Apache hosting where you can't change that, the root `.htaccess` rewrites into `public/` and
   blocks the other folders. That file is untested on a live Apache server, so check it once.
2. Serve over **HTTPS**. The session cookie becomes `Secure` automatically.
3. Make `storage/` writable by PHP only when you run `pack`. The web side only reads it.

### Use your real login

The sign-in screen is a placeholder that only asks for a name. Replace `cv_viewer()` in
`src/bootstrap.php` with your own session check. Whatever string it returns is drawn on the media as
the watermark, so a user name or email works well.

Tunable limits are at the top of `src/Streams.php`: idle timeout (10 min), max stream age (2 h),
allowed request age (30 s) and streams per browser (6).

## Tests

```bash
npm test                 # protocol tests + real headless Chrome test
npm run test:protocol    # protocol only (no Chrome needed)
```

- **`tests/protocol.test.mjs`** uses the same `vault.js` as the page. It checks byte-exact round
  trips and that replay, tampering, stale requests, wrong browser, swapped chunks, at-rest storage and
  page headers behave as described above.
- **`tests/browser.test.mjs`** drives Chrome. Chrome records a real clip; the test adds it, signs in,
  opens the photo and plays the video, checks that the page has no `<img>`/`<video>`, and checks that
  every chunk body DevTools captured is ciphertext. Screenshots go to `$CV_SHOTS`.

## What this does not stop (be honest with yourself)

- **Screenshots and screen recording.** Canvas pixels are normal pixels. Black screenshots need
  hardware DRM (Widevine L1 / PlayReady / FairPlay via a multi-DRM provider). In a native app you can
  use Android `FLAG_SECURE` or Windows `WDA_EXCLUDEFROMCAPTURE`. The watermark is the answer here:
  a leak shows who leaked it.
- **A determined developer on your own page.** The page has to decrypt to display, so someone who
  hooks `crypto.subtle.decrypt` or `SourceBuffer.appendBuffer` in DevTools can dump the plain bytes
  while signed in. This raises the bar from "right-click save" to "write code against your app".
- **A phone camera pointed at the screen.** Nothing stops that, which is again why the watermark exists.

Video plays in order from the start; seeking works within what has loaded. For long films, split
the video into HLS-style segments and map times to chunks.

## Files

```
bin/pack.php            add / list / remove media (CLI only)
src/bootstrap.php       session, same-site checks, security headers
src/Crypto.php          ECDH (P-256), AES-256-GCM helpers
src/Streams.php         per-viewer keys, signed + one-time chunk requests
src/Library.php         media on disk, master key
src/MediaProbe.php      file type + exact codec string from MP4/WebM headers
public/index.php        sign in + library
public/watch.php        viewer page
public/api/session.php  key exchange
public/api/chunk.php    one encrypted chunk
public/assets/vault.js  WebCrypto client (shared with the tests)
public/assets/player.js canvas renderer, video player, watermark
```
