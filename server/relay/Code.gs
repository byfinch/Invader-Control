/**
 * Invader Control — Google Apps Script relay
 *
 * KURULUM (5 dk, ücretsiz):
 *   1. https://script.google.com adresine Google hesabınızla girin → "Yeni proje"
 *   2. Bu dosyanın tamamını Code.gs içine yapıştırın, KAYIT_KEY ile aynı olacak şekilde
 *      RELAY_KEY değerini panel config.json'daki "relay_key" ile eşitleyin
 *   3. Deploy → New deployment → Type: "Web app"
 *      - Execute as: "Me"
 *      - Who has access: "Anyone"  (anahtar olmadan işe yaramaz, güvenli)
 *   4. Çıkan https://script.google.com/macros/s/...../exec URL'sini
 *      panel config.json'a "relay" olarak yazın. Hepsi bu.
 *
 * Monitör şöyle çağırır:  <url>?u=<hedef>&ua=bot|user&k=<anahtar>
 * Google IP'lerinden, seçilen User-Agent ile çeker; sonucu JSON döner.
 */

const RELAY_KEY = 'BURAYA-GIZLI-ANAHTAR-YAZIN';

const UA_MAP = {
  bot:  'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
  user: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
};

function doGet(e) {
  const p = (e && e.parameter) || {};
  const out = (obj) => ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);

  if ((p.k || '') !== RELAY_KEY) return out({ ok: false, code: 403, error: 'bad key' });

  const target = p.u || '';
  if (!/^https?:\/\//i.test(target)) return out({ ok: false, code: 400, error: 'bad url' });

  const ua = UA_MAP[p.ua] || UA_MAP.user;
  try {
    const resp = UrlFetchApp.fetch(target, {
      headers: { 'User-Agent': ua },
      followRedirects: true,
      muteHttpExceptions: true,
      validateHttpsCertificates: true,
    });
    return out({
      ok: true,
      code: resp.getResponseCode(),
      headers: resp.getAllHeaders(),
      body: resp.getContentText(),
    });
  } catch (err) {
    return out({ ok: false, code: 0, error: String(err) });
  }
}
