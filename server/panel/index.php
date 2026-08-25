<?php
/* GBWatch Panel */
$BASE = '/opt/gbwatch';
$CFG_FILE = "$BASE/config.json";
$DB_FILE = "$BASE/data/gbwatch.db";
$LIB = "$BASE/repo/server/lib/common.php";
$AUTH_FILE = "$BASE/panel_auth.json";

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    if (!is_file($LIB)) die('Sunucu dosyasi eksik: repo/server/lib/common.php bulunamadi. Git pull yapildi mi?');
    require $LIB;

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];

    /* kimlik dosyasi yoksa varsayilan olustur */
    if (!is_file($AUTH_FILE)) {
        @file_put_contents($AUTH_FILE, json_encode(['user' => 'admin', 'hash' => password_hash('invader25', PASSWORD_DEFAULT)]));
        @chmod($AUTH_FILE, 0600);
    }
    $AUTH = is_file($AUTH_FILE) ? (json_decode(file_get_contents($AUTH_FILE), true) ?: []) : [];

    if (isset($_POST['logout'])) { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }

    $login_err = '';
    if (isset($_POST['login_user'], $_POST['login_pass'])) {
        if (($AUTH['user'] ?? '') !== '' && hash_equals($AUTH['user'], $_POST['login_user'])
            && ($AUTH['hash'] ?? '') !== '' && password_verify($_POST['login_pass'], $AUTH['hash'])) {
            $_SESSION['auth'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
        $login_err = 'Hatali kullanici adi veya sifre';
    }

    if (empty($_SESSION['auth'])) {
        $e = $login_err !== '' ? "<p class='err'>$login_err</p>" : '';
        die("<!doctype html><meta charset='utf-8'><style>body{font-family:system-ui;background:#111827;display:grid;place-items:center;height:100vh;margin:0}form{background:#1f2937;padding:32px;border-radius:12px;color:#e5e7eb;display:flex;flex-direction:column;gap:12px}input{padding:9px;border-radius:6px;border:1px solid #374151;background:#111827;color:#e5e7eb}button{padding:9px;border:0;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer}.err{color:#f87171;margin:0;font-size:14px}</style>
        <form method='post'><h2>Invader Control</h2>$e
        <input name='login_user' placeholder='Kullanici' autocomplete='username' autofocus>
        <input type='password' name='login_pass' placeholder='Sifre' autocomplete='current-password'>
        <button>Giris</button></form>");
    }

    function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function local_time(?string $value): string {
        if (!$value) return '-';
        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
            return $dt->format('d.m.Y H:i:s');
        } catch (Throwable $e) { return (string) $value; }
    }
    function load_cfg(string $f): array { return is_readable($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
    function save_cfg(array $c, string $f): bool {
        $j = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $j !== false && @file_put_contents($f, $j) !== false;
    }
    function badge(string $st): string {
        $c = ['OK' => '#16a34a', 'OBSERVED' => '#2563eb', 'DOWN' => '#dc2626', 'BLOCKED' => '#d97706', 'ERROR' => '#6b7280', 'EMPTY' => '#a16207'][$st] ?? '#6b7280';
        return "<span style='background:$c;color:#fff;padding:2px 9px;border-radius:4px;font-size:12px;font-weight:600'>$st</span>";
    }

    $CFG = load_cfg($CFG_FILE);
    $msg = ''; $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = $_POST['act'] ?? '';
        if ($act !== '' && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            $err = 'Oturum dogrulamasi gecersiz. Sayfayi yenileyip tekrar deneyin.';
            $act = '';
        }
        try {
            if ($act === 'add_site') {
                $url = strtolower(trim($_POST['s_url'] ?? ''));
                $expect = strtolower(trim($_POST['s_expect'] ?? ''));
                $name = trim($_POST['s_name'] ?? '');
                if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
                $expect = preg_replace('~^www\.~', '', preg_replace('~^https?://~', '', $expect));
                $host = gb_host_of($url); $ehost = gb_host_of('https://' . $expect);
                if ($host === '' || $ehost === '') { $err = 'URL ve beklenen alan adi zorunlu.'; }
                elseif (count(array_filter($CFG['sites'] ?? [], fn($old) => gb_url_key($old['url'] ?? '') === gb_url_key($url))) > 0) {
                    $err = 'Bu URL zaten izleniyor. Ayni URL iki kez eklenemez; mevcut kaydi duzenleyin veya silin.';
                }
                else {
                    $CFG['sites'] ??= [];
                    $CFG['sites'][] = ['name' => $name !== '' ? $name : $host, 'url' => $url, 'expect' => $ehost];
                    $err = save_cfg($CFG, $CFG_FILE) ? '' : 'config.json yazilamadi (dosya izinleri).';
                    $msg = $err === '' ? 'Site eklendi: ' . $host : '';
                }
            } elseif ($act === 'del_site') {
                $i = (int) ($_POST['idx'] ?? -1);
                if (isset($CFG['sites'][$i])) {
                    $nm = $CFG['sites'][$i]['name'] ?? '';
                    array_splice($CFG['sites'], $i, 1);
                    $err = save_cfg($CFG, $CFG_FILE) ? '' : 'config.json yazilamadi.';
                    $msg = $err === '' ? 'Site silindi: ' . $nm : '';
                }
            } elseif ($act === 'telegram') {
                $CFG['telegram'] = ['token' => trim($_POST['tg_token'] ?? ''), 'chat_id' => trim($_POST['tg_chat'] ?? '')];
                $err = save_cfg($CFG, $CFG_FILE) ? '' : 'config.json yazilamadi.';
                $msg = $err === '' ? 'Telegram ayarlari kaydedildi.' : '';
            } elseif ($act === 'tg_test') {
                $msg = gb_tg_send($CFG['telegram'] ?? [], "\u{2705} Invader Control test mesaji") ? 'Test mesaji gonderildi.' : 'Gonderilemedi (token/chat id?)';
            } elseif ($act === 'run_now') {
                @set_time_limit(300);
                $CFG = load_cfg($CFG_FILE);
                $out = [];
                foreach (($CFG['sites'] ?? []) as $s) {
                    try { $r = gb_process($s, $CFG, $DB_FILE); $out[] = $r['status'] . ' — ' . $r['name']; }
                    catch (Throwable $e) { $out[] = 'HATA — ' . ($s['name'] ?? $s['url']) . ': ' . $e->getMessage(); }
                }
                $msg = "Kontrol bitti:\n" . implode("\n", $out);
            } elseif ($act === 'change_pass') {
                $np = $_POST['new_pass'] ?? '';
                if (strlen($np) >= 6) {
                    $AUTH['hash'] = password_hash($np, PASSWORD_DEFAULT);
                    @file_put_contents($AUTH_FILE, json_encode($AUTH));
                    $msg = 'Sifre degistirildi.';
                } else $err = 'Sifre en az 6 karakter olmali.';
            }
        } catch (Throwable $e) {
            $err = 'Hata: ' . $e->getMessage();
        }
    }

    /* --- veri oku --- */
    $stMap = []; $last = []; $history = [];
    try {
        gb_init_db($DB_FILE);
        foreach (gb_db($DB_FILE)->query("SELECT site,status,since FROM state")->fetchAll() as $r) $stMap[$r['site']] = $r;
        foreach (gb_db($DB_FILE)->query("SELECT c.* FROM checks c JOIN (SELECT site,MAX(id) mid FROM checks GROUP BY site) x ON x.mid=c.id")->fetchAll() as $r) $last[$r['site']] = $r;
        $history = gb_db($DB_FILE)->query("SELECT site,ts,status,http,alt,note,ustatus FROM checks ORDER BY id DESC LIMIT 60")->fetchAll();
    } catch (Throwable $e) {
        $err .= ($err ? ' | ' : '') . 'Veritabani: ' . $e->getMessage();
    }

    } catch (Throwable $fatal) {
    http_response_code(500);
    die('<meta charset="utf-8"><body style="font-family:monospace;padding:40px"><h2>Sistem hatasi</h2><pre>' . htmlspecialchars($fatal->getMessage()) . '</pre></body>');
}

$siteCount = count($CFG['sites'] ?? []);
$okCount = 0;
$attentionCount = 0;
foreach (($CFG['sites'] ?? []) as $s) {
    $state = $stMap[$s['url']] ?? null;
    if (($state['status'] ?? '') === 'OK') $okCount++;
    else $attentionCount++;
}
$duplicateUrls = [];
$seenUrls = [];
foreach (($CFG['sites'] ?? []) as $s) {
    $key = gb_url_key($s['url'] ?? '');
    if ($key !== '' && isset($seenUrls[$key])) $duplicateUrls[$key] = true;
    $seenUrls[$key] = true;
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invader Control</title>
<style>
:root{--bg:#0a0f1a;--card:#111827;--card2:#0e1625;--line:#243044;--txt:#e5edf7;--mut:#8190a7;--acc:#74a7ff;--green:#4ade80;--red:#fb7185}
*{box-sizing:border-box}
body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif;margin:0;background:var(--bg);color:var(--txt);font-size:14px}
header{display:flex;justify-content:space-between;align-items:center;padding:18px max(24px,calc((100% - 1160px)/2));border-bottom:1px solid var(--line);background:#0d1422}
header h1{font-size:15px;margin:0;font-weight:650;letter-spacing:.2px}
header h1:before{content:'●';color:var(--green);font-size:11px;margin-right:9px;vertical-align:1px}
main{max-width:1160px;margin:30px auto;padding:0 24px}
.card{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:20px;margin-bottom:18px}
.card h2{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--mut);margin:0 0 14px}
.overview{display:grid;grid-template-columns:minmax(220px,1.8fr) repeat(3,minmax(100px,.7fr)) auto;gap:1px;align-items:stretch;background:var(--line);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:18px}
.overview>div,.overview>form{background:var(--card);padding:20px}
.overview-title h2{font-size:18px;letter-spacing:0;text-transform:none;color:var(--txt);margin:5px 0 4px}
.overview-title p{margin:0;font-size:13px}
.eyebrow{font-size:10px;letter-spacing:1.5px;color:var(--acc);font-weight:700}
.metric{display:flex;flex-direction:column;justify-content:center;border-left:1px solid var(--line)}
.metric strong{font-size:25px;line-height:1;font-weight:650}
.metric span{font-size:11px;color:var(--mut);margin-top:8px}
.metric.good strong{color:var(--green)}
.metric.bad strong{color:var(--red)}
.overview form{display:flex;align-items:center;border-left:1px solid var(--line)}
table{border-collapse:collapse;width:100%}
th,td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.5px}
tr:last-child td{border-bottom:0}
small{color:var(--mut)}
input,button{padding:8px 10px;border-radius:5px;border:1px solid #334155;background:#0b1220;color:var(--txt);font-size:14px}
button{background:var(--acc);border:0;color:#fff;cursor:pointer;font-weight:500}
button:hover{filter:brightness(1.1)}
button.ghost{background:transparent;border:1px solid #334155;color:var(--mut)}
button.danger{background:transparent;border:1px solid #7f1d1d;color:#f87171}
form.inline{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.msg{background:#0d2b1a;border:1px solid #1f6b3a;color:#9af3b2;padding:12px 16px;border-radius:5px;margin-bottom:18px;white-space:pre-wrap}
.errbox{background:#35131c;border:1px solid #7f293d;color:#fda4af;padding:12px 16px;border-radius:5px;margin-bottom:18px;white-space:pre-wrap}
.muted{color:var(--mut)}
@media(max-width:900px){.overview{grid-template-columns:1fr 1fr}.overview-title{grid-column:1/-1}.overview form{grid-column:1/-1;border-left:0}.metric:nth-of-type(2){border-left:0}}
@media(max-width:700px){main{padding:0 12px;margin-top:18px}.card{padding:14px;overflow-x:auto}.overview{grid-template-columns:1fr 1fr}.overview-title h2{font-size:16px}table{min-width:850px}header{padding:15px 16px}.inline input{width:100%;flex:1}}
</style></head><body>
<header><h1>Invader Control</h1>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button name="logout" value="1" class="ghost">Cikis</button></form></header>
<main>

<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="errbox"><?= h($err) ?></div><?php endif; ?>
<?php if ($duplicateUrls): ?><div class="errbox">Ayni URL birden fazla kez kayitli. Son kontrolde kayitlar birbirinin sonucunu eziyor. Tekrarlanan satiri silip yeniden kontrol et.</div><?php endif; ?>

<div class="overview">
  <div class="overview-title"><span class="eyebrow">OPERASYON PANELI</span><h2>Kontrol durumu</h2><p class="muted">Son ölçümler ve beklenen alternate eşleşmeleri</p></div>
  <div class="metric"><strong><?= $siteCount ?></strong><span>İzlenen site</span></div>
  <div class="metric good"><strong><?= $okCount ?></strong><span>Googlebot OK</span></div>
  <div class="metric <?= $attentionCount ? 'bad' : '' ?>"><strong><?= $attentionCount ?></strong><span>Dikkat gerekli</span></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="run_now"><button>Tumunu kontrol et</button></form>
</div>

<div class="card"><h2>Siteler</h2>
<?php if (!($CFG['sites'] ?? [])): ?><p class="muted">Henuz site eklenmedi.</p><?php else: ?>
<table><tr><th>Site</th><th>Beklenen</th><th>Googlebot</th><th>Kullanici</th><th>Son kontrol</th><th>HTTP</th><th>Alternate</th><th>Not</th><th></th></tr>
<?php foreach ($CFG['sites'] as $i => $s):
  $u = $s['url']; $st = $stMap[$u] ?? null; $lc = $last[$u] ?? null; ?>
<tr>
  <td><b><?= h($s['name'] ?? $u) ?></b><br><small><?= h($u) ?></small></td>
  <td><?= h($s['expect']) ?></td>
  <td><?= badge($st['status'] ?? '-') ?></td>
  <td><?= $lc ? badge($lc['ustatus'] ?? '-') : '-' ?></td>
  <td><?= $lc ? h(local_time($lc['ts'])) : '-' ?></td>
  <td><?= $lc ? h($lc['http']) : '-' ?></td>
  <td><?= $lc ? h($lc['alt'] ?: '-') : '-' ?></td>
  <td><?= $lc ? h($lc['note'] ?: '') : '' ?></td>
  <td><form method="post" onsubmit="return confirm('Silinsin mi?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="del_site"><input type="hidden" name="idx" value="<?= $i ?>"><button class="danger">Sil</button></form></td>
</tr>
<?php endforeach; ?></table>
<?php endif; ?>
<br>
<form method="post" class="inline">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="add_site">
  <input name="s_url" placeholder="https://site.com/" size="26" required>
  <input name="s_expect" placeholder="beklenen alanadi (milanbahis.cam)" size="30" required>
  <input name="s_name" placeholder="isim (opsiyonel)" size="16">
  <button>Ekle</button>
</form></div>

<div class="card"><h2>Telegram</h2>
<form method="post" class="inline">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="telegram">
  <input name="tg_token" placeholder="bot token" value="<?= h($CFG['telegram']['token'] ?? '') ?>" size="38">
  <input name="tg_chat" placeholder="chat id" value="<?= h($CFG['telegram']['chat_id'] ?? '') ?>" size="14">
  <button>Kaydet</button>
</form>
<form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="tg_test"><button class="ghost">Test mesaji gonder</button></form></div>

<div class="card"><h2>Panel Sifresi</h2>
<form method="post" class="inline">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="act" value="change_pass">
  <input type="password" name="new_pass" placeholder="yeni sifre (min 6)" required>
  <button>Kaydet</button></form></div>

<div class="card"><h2>Gecmis (son 60)</h2>
<?php if (!$history): ?><p class="muted">Kayit yok.</p><?php else: ?>
<table><tr><th>Zaman</th><th>Site</th><th>Googlebot</th><th>Kullanici</th><th>HTTP</th><th>Alternate</th><th>Not</th></tr>
<?php foreach ($history as $r): ?>
<tr><td><?= h(local_time($r['ts'])) ?></td><td><?= h($r['site']) ?></td><td><?= badge($r['status']) ?></td><td><?= badge($r['ustatus'] ?? '-') ?></td><td><?= h($r['http']) ?></td><td><?= h($r['alt'] ?: '-') ?></td><td><?= h($r['note']) ?></td></tr>
<?php endforeach; ?></table>
<?php endif; ?></div>

</main></body></html>
