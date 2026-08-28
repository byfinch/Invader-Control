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

async function capture(url, id) {
  if (!/^https?:\/\//i.test(url)) throw new Error('invalid URL');
  const safeId = String(id || 'site').replace(/[^a-zA-Z0-9._-]/g, '_').slice(0, 80);
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dir = path.join(ROOT, safeId, stamp);
  await fs.mkdir(dir, {recursive: true});
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/usr/bin/google-chrome-stable',
    args: ['--disable-dev-shm-usage'],
  });
  const result = {};
  const errors = {};
  try {
    /* iki görünüm paralel — tek yavaş site toplam süreyi ikiye katlamasın */
    const [g, u] = await Promise.all([
      captureView(browser, url, 'googlebot', GOOGLEBOT_UA, dir),
      captureView(browser, url, 'user', USER_UA, dir),
    ]);
    if (g.ok) result.googlebot = g.data; else errors.googlebot = g.error;
    if (u.ok) result.user = u.data; else errors.user = u.error;
  } finally {
    await browser.close();
  }
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

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/capture') {
    reply(res, 404, {ok: false, error: 'not found'});
    return;
  }
  try {
    const input = await readBody(req);
    const result = await capture(input.url, input.id);
    reply(res, 200, {ok: true, ...result});
  } catch (error) {
    reply(res, 500, {ok: false, error: error.message});
  }
});

await fs.mkdir(ROOT, {recursive: true});
server.listen(PORT, '127.0.0.1');
