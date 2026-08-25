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
  try {
    for (const [key, userAgent] of [['googlebot', GOOGLEBOT_UA], ['user', USER_UA]]) {
      const context = await browser.newContext({
        userAgent,
        viewport: {width: 1440, height: 900},
        deviceScaleFactor: 1,
      });
      const page = await context.newPage();
      try {
        const response = await page.goto(url, {waitUntil: 'domcontentloaded', timeout: 30000});
        await page.waitForTimeout(1000);
        const file = path.join(dir, `${key}.png`);
        await page.screenshot({path: file, fullPage: false});
        result[key] = {path: file, http: response?.status() ?? 0, title: await page.title()};
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
  const combined = path.join(dir, 'evidence.jpg');
  await execFileAsync('/usr/bin/montage', [
    '-background', '#111827', '-fill', '#ffffff', '-pointsize', '22',
    '-label', 'GOOGLEBOT', result.googlebot.path,
    '-label', 'NORMAL KULLANICI', result.user.path,
    '-tile', '2x1', '-geometry', '720x450+12+42', '-quality', '82', combined,
  ]);
  result.combined = {path: combined};
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
