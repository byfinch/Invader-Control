import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { chromium } from '/opt/gbwatch/render/node_modules/playwright-core/index.mjs';

const PORT = 6077;
const ROOT = '/opt/gbwatch/data/evidence';
const GOOGLEBOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const USER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const execFileAsync = promisify(execFile);

function reply(res, status, body) {
  res.writeHead(status, {'Content-Type': 'application/json'});
  res.end(JSON.stringify(body));
}

async function readBody(req) {
  let body = '';
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 8192) throw new Error('request too large');
  }
  return JSON.parse(body);
}

async function captureView(browser, url, key, userAgent, dir) {
  const context = await browser.newContext({
    userAgent,
    viewport: {width: 1440, height: 900},
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  try {
    let response = null;
    try {
      response = await page.goto(url, {waitUntil: 'domcontentloaded', timeout: 25000});
    } catch (navError) {
      /* bazı siteler domcontentloaded'i hiç bitirmez — commit ile ne yüklendiyse onu yakala */
      response = await page.goto(url, {waitUntil: 'commit', timeout: 15000}).catch(() => null);
    }
    await page.waitForTimeout(1500);
    {
      /* commit bazen hata sayfasını "başarılı" döndürür; kara delikte about:blank'ta kalır */
      const curUrl = page.url();
      if (curUrl.startsWith('chrome-error') || curUrl === 'about:blank') {
        throw new Error('siteye ulaşılamadı (tarayıcı hata sayfası)');
      }
      const bodyText = await page.evaluate(() => (document.body ? document.body.innerText.slice(0, 2000) : '')).catch(() => '');
      if (/\bERR_(CONNECTION|TIMED|NAME|ADDRESS|SSL|PROXY|DNS)[A-Z_]*\b/.test(bodyText)) {
        throw new Error('siteye ulaşılamadı (tarayıcı hata sayfası)');
      }
    }
    await page.evaluate(() => window.stop()).catch(() => {}); /* asılı kalan yüklemeyi kes */
    const file = path.join(dir, `${key}.png`);
    try {
      await page.screenshot({path: file, fullPage: false, timeout: 20000});
    } catch (shotError) {
      /* font/ağ beklemesinde takılırsa Playwright katmanını atla, CDP ile ham görüntü al */
      const session = await context.newCDPSession(page);
      const shot = await session.send('Page.captureScreenshot', {format: 'png'});
      await fs.writeFile(file, Buffer.from(shot.data, 'base64'));
      await session.detach().catch(() => {});
    }
    return {ok: true, data: {path: file, http: response?.status() ?? 0, title: await page.title().catch(() => '')}};
  } catch (viewError) {
    return {ok: false, error: viewError.message};
  } finally {
    await context.close();
  }
}

/* relay üzerinden HTML çek (Google IP'si + seçilen UA) */
async function fetchViaRelay(relay, key, target, uaKind) {
  const sep = relay.includes('?') ? '&' : '?';
  const r = await fetch(relay + sep + 'u=' + encodeURIComponent(target) + '&ua=' + uaKind + '&k=' + encodeURIComponent(key), {redirect: 'follow'});
  const j = await r.json();
  if (!j || !j.ok) throw new Error((j && j.error) || 'relay hatası');
  return j;
}

/* sayfa kaynaklarını Google translate proxy'sine yönlendir — engelli host'a hiç istek gitmesin */
function rewriteAssets(html, host) {
  const thost = host.replace(/\./g, '-');
  const toT = (u) => {
    try {
      const abs = new URL(u, 'https://' + host + '/');
      if (abs.host !== host) return u;   /* dış kaynaklar (CDN vb.) engelli değil, dokunma */
      const qs = abs.search ? abs.search + '&' : '?';
      return 'https://' + thost + '.translate.goog' + abs.pathname + qs + '_x_tr_sl=auto&_x_tr_tl=en&_x_tr_hl=en';
    } catch { return u; }
  };
  return String(html).replace(/\b(src|href|action)\s*=\s*(["'])([^"']+)\2/g, (m, a, q, u) => `${a}=${q}${toT(u)}${q}`);
}

async function captureRelayView(browser, html, key, dir) {
  const context = await browser.newContext({viewport: {width: 1440, height: 900}, deviceScaleFactor: 1});
  const page = await context.newPage();
  try {
    await page.setContent(html, {waitUntil: 'load', timeout: 30000}).catch(() => {});
    await page.waitForTimeout(1200);
    const file = path.join(dir, `${key}.png`);
    await page.screenshot({path: file, fullPage: false, timeout: 20000});
    return {ok: true, data: {path: file, http: 200, title: await page.title().catch(() => '')}};
  } catch (e) {
    return {ok: false, error: e.message};
  } finally {
    await context.close();
  }
}

/* kullanıcı görünümünü Google translate proxy üzerinden çek — engelli host'a istek gitmez,
   translate gerçek (cloak'suz) sayfayı döndürür */
function translateUrl(target) {
  const u = new URL(target);
  const thost = u.host.replace(/\./g, '-');
  return 'https://' + thost + '.translate.goog' + (u.pathname || '/') +
    (u.search ? u.search + '&' : '?') + '_x_tr_sl=auto&_x_tr_tl=tr&_x_tr_hl=tr';
}

async function captureTranslateView(browser, url, dir) {
  const context = await browser.newContext({
    userAgent: USER_UA,
    viewport: {width: 1440, height: 900},
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  try {
    /* HTML'i tarayıcısız çek (hızlı), görselleştirmeyi yerelde yap.
       relative URL'ler translate host'una <base> ile bağlanır; sadece sitenin kendi
       css/görselleri (translate proxy) ve google fontları geçer, geri kalan her şey kesik. */
    const thost = new URL(url).host.replace(/\./g, '-');
    const TH = 'https://' + thost + '.translate.goog';
    const resp = await fetch(TH + (new URL(url).pathname || '/') + '?_x_tr_sl=auto&_x_tr_tl=tr&_x_tr_hl=tr', {redirect: 'follow'});
    if (!resp.ok) throw new Error('translate http ' + resp.status);
    let html = await resp.text();
    html = html.replace(/<head([^>]*)>/i, '<head$1><base href="' + TH + '/">');
    await context.route('**/*', (route) => {
      const req = route.request();
      const type = req.resourceType();
      const u = req.url();
      const okHost = u.startsWith(TH) || u.includes('googleapis.com') || u.includes('gstatic.com');
      if ((type === 'stylesheet' || type === 'image') && okHost) {
        const timer = setTimeout(() => route.abort().catch(() => {}), 6000);
        return route.continue().finally(() => clearTimeout(timer));
      }
      return route.abort().catch(() => {});
    });
    await page.setContent(html, {waitUntil: 'load', timeout: 25000}).catch(() => {});
    await page.waitForTimeout(1500);
    await page.addStyleTag({content: '.goog-te-banner-frame,.goog-te-banner,#goog-gt-tt,.goog-te-balloon-frame,#goog-gt-vc{display:none!important}body{top:0!important;position:static!important}'}).catch(() => {});
    const file = path.join(dir, 'user.png');
    try {
      await page.screenshot({path: file, fullPage: false, timeout: 10000});
    } catch (shotError) {
      const session = await context.newCDPSession(page);
      const shot = await Promise.race([
        session.send('Page.captureScreenshot', {format: 'png'}),
        new Promise((_, rej) => setTimeout(() => rej(new Error('cdp screenshot timeout')), 8000)),
      ]);
      await fs.writeFile(file, Buffer.from(shot.data, 'base64'));
      await session.detach().catch(() => {});
    }
    return {ok: true, data: {path: file, http: 200, title: await page.title().catch(() => '')}};
  } catch (e) {
    return {ok: false, error: e.message};
  } finally {
    await context.close();
  }
}

async function captureWork(browser, url, dir, proxy, relay, relayKey) {
  const result = {};
  const errors = {};
  let g, u;
  if (relay) {
    /* googlebot görünümü relay'den (Google IP'si → cloak'u görür, doğru sinyal).
       normal kullanıcı görünümü translate proxy'den (gerçek site, cloak'suz). */
    const host = new URL(url).host;
    const gHtml = await fetchViaRelay(relay, relayKey, url, 'bot');
    [g, u] = await Promise.all([
      captureRelayView(browser, rewriteAssets(gHtml.body, host), 'googlebot', dir),
      captureTranslateView(browser, url, dir),
    ]);
  } else {
    /* iki görünüm paralel — tek yavaş site toplam süreyi ikiye katlamasın */
    [g, u] = await Promise.all([
      captureView(browser, url, 'googlebot', GOOGLEBOT_UA, dir),
      captureView(browser, url, 'user', USER_UA, dir),
    ]);
  }
  if (g.ok) result.googlebot = g.data; else errors.googlebot = g.error;
  if (u.ok) result.user = u.data; else errors.user = u.error;
  if (!result.googlebot && !result.user) return {ok: false, error: errors.googlebot || errors.user || 'capture failed'};
  const combined = path.join(dir, 'evidence.jpg');
  const args = ['-background', '#111827', '-fill', '#ffffff', '-pointsize', '22'];
  if (result.googlebot) args.push('-label', 'GOOGLEBOT', result.googlebot.path);
  if (result.user) args.push('-label', 'NORMAL KULLANICI', result.user.path);
  const views = (result.googlebot ? 1 : 0) + (result.user ? 1 : 0);
  args.push('-tile', `${views}x1`, '-geometry', '720x450+12+42', '-quality', '82', combined);
  await execFileAsync('/usr/bin/montage', args);
  result.combined = {path: combined};
  if (Object.keys(errors).length) result.partial = errors;
  return result;
}

function parseProxy(p) {
  /* Playwright kullanıcı/şifreyi server URL'sinin içinden almaz — ayrık alanlara çevir */
  try {
    const u = new URL(p);
    const out = {server: `${u.protocol}//${u.hostname}${u.port ? ':' + u.port : ''}`};
    if (u.username) out.username = decodeURIComponent(u.username);
    if (u.password) out.password = decodeURIComponent(u.password);
    return out;
  } catch { return {server: p}; }
}

async function capture(url, id, proxy = '', relay = '', relayKey = '') {
  if (!/^https?:\/\//i.test(url)) throw new Error('invalid URL');
  const safeId = String(id || 'site').replace(/[^a-zA-Z0-9._-]/g, '_').slice(0, 80);
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dir = path.join(ROOT, safeId, stamp);
  await fs.mkdir(dir, {recursive: true});
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/usr/bin/google-chrome-stable',
    args: ['--disable-dev-shm-usage'],
    ...(proxy ? {proxy: parseProxy(proxy)} : {}),   /* coğrafi engelli hedefler için TR çıkışı */
  });
  let watchdog = null;
  try {
    /* sert sınır: ne takılırsa takılsın (CDP çağrısının timeout'u yok) 90 sn'de kes */
    return await Promise.race([
      captureWork(browser, url, dir, proxy, relay, relayKey),
      new Promise((_, reject) => { watchdog = setTimeout(() => reject(new Error('hard capture timeout (90s)')), 90000); }),
    ]);
  } catch (error) {
    try { browser.process()?.kill('SIGKILL'); } catch {}
    return {ok: false, error: error.message};
  } finally {
    if (watchdog) clearTimeout(watchdog);
    await browser.close().catch(() => {});
  }
}

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/capture') {
    reply(res, 404, {ok: false, error: 'not found'});
    return;
  }
  try {
    const input = await readBody(req);
    const result = await capture(
      input.url,
      input.id,
      typeof input.proxy === 'string' ? input.proxy : '',
      typeof input.relay === 'string' ? input.relay : '',
      typeof input.relay_key === 'string' ? input.relay_key : ''
    );
    reply(res, 200, {ok: true, ...result});
  } catch (error) {
    reply(res, 500, {ok: false, error: error.message});
  }
});

await fs.mkdir(ROOT, {recursive: true});
server.listen(PORT, '127.0.0.1');
