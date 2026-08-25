<?php
/* GBWatch Panel — tek dosya, şifreli */
$BASE = '/opt/gbwatch';
$CFG_FILE = "$BASE/config.json";
$DB_FILE = "$BASE/data/gbwatch.db";
$MONITOR = "$BASE/bin/monitor.php";
session_start();

$CFG = is_readable($CFG_FILE) ? (json_decode(file_get_contents($CFG_FILE), true) ?: []) : [];
$PASS_FILE = "$BASE/panel_pass.txt";

/* --- ilk kurulum: şifre yoksa kur --- */
if (!is_file($PASS_FILE)) {
    if (isset($_POST['setup_pass'], $_POST['setup_pass2']) && $_POST['setup_pass'] !== '' && $_POST['setup_pass'] === $_POST['setup_pass2']) {
        file_put_contents($PASS_FILE, password_hash($_POST['setup_pass'], PASSWORD_DEFAULT));
        chmod($PASS_FILE, 0600);
    } else {
        die('<form method="post"><h2>Ilk kurulum — panel sifresi belirle</h2>
             Sifre: <input type="password" name="setup_pass"> Tekrar: <input type="password" name="setup_pass2">
             <button>Kaydet</button></form>');
    }
}
$PASS_HASH = file_get_contents($PASS_FILE);

/* --- login --- */
if (isset($_POST['logout'])) { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }
if (isset($_POST['login_pass'])) {
    if (password_verify($_POST['login_pass'], $PASS_HASH)) $_SESSION['auth'] = true;
    else $login_err = 'Hatali sifre';
}
if (empty($_SESSION['auth'])) {
    $e = isset($login_err) ? "<p style='color:red'>$login_err</p>" : '';
    die("<form method='post'><h2>GBWatch Panel</h2>$e Sifre: <input type='password' name='login_pass'> <button>Giris</button></form>");
}

/* --- DB --- */
function db(): PDO {
    global $DB_FILE; static $pdo;
    return $pdo ??= new PDO('sqlite:'.$DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
if (!is_file($DB_FILE)) db()->exec("CREATE TABLE IF NOT EXISTS checks(id INTEGER PRIMARY KEY AUTOINCREMENT, site TEXT, ts TEXT DEFAULT (datetime('now')), status TEXT, http INTEGER, size INTEGER, alt TEXT, note TEXT)");

function save_cfg(array $c, string $f): bool { return file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) !== false; }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function badge(string $st): string {
    $map = ['OK'=>'green','DOWN'=>'red','BLOCKED'=>'orange','ERROR'=>'gray'];
    $c = $map[$st] ?? 'gray';
    return "<span style='background:$c;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px'>$st</span>";
}

/* --- aksiyonlar --- */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'add_site') {
        $url = trim($_POST['s_url'] ?? ''); $name = trim($_POST['s_name'] ?? ''); $expect = strtolower(trim($_POST['s_expect'] ?? ''));
        $expect = preg_replace('~^www\.~', '', $expect);
        if ($url && $expect) {
            $CFG['sites'] ??= [];
            $CFG['sites'][] = ['name'=>$name ?: parse_url($url, PHP_URL_HOST), 'url'=>$url, 'expect'=>$expect];
            $msg = save_cfg($CFG, $CFG_FILE) ? 'Site eklendi.' : 'config.json YAZILAMADI (izin?)';
        } else $msg = 'URL ve beklenen alan adi zorunlu.';
    } elseif ($act === 'del_site') {
        $i = (int)($_POST['idx'] ?? -1);
        if (isset($CFG['sites'][$i])) { array_splice($CFG['sites'], $i, 1); $msg = save_cfg($CFG, $CFG_FILE) ? 'Site silindi.' : 'config.json YAZILAMADI'; }
    } elseif ($act === 'telegram') {
        $CFG['telegram'] = ['token'=>trim($_POST['tg_token'] ?? ''), 'chat_id'=>trim($_POST['tg_chat'] ?? '')];
        $msg = save_cfg($CFG, $CFG_FILE) ? 'Telegram kaydedildi.' : 'config.json YAZILAMADI';
    } elseif ($act === 'tg_test') {
        $tk = $CFG['telegram']['token'] ?? ''; $cid = $CFG['telegram']['chat_id'] ?? '';
        if ($tk && $cid) {
            $ch = curl_init("https://api.telegram.org/bot$tk/sendMessage");
            curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$cid,'text'=>"\u{2705} GBWatch test mesaji"]), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
            $r = json_decode(curl_exec($ch) ?: '', true); curl_close($ch);
            $msg = ($r['ok'] ?? false) ? 'Test mesaji GONDERILDI.' : 'Telegram hatasi: '.substr(json_encode($r), 0, 200);
        } else $msg = 'Once token + chat id kaydet.';
    } elseif ($act === 'run_now') {
        $out = shell_exec("php " . escapeshellarg($MONITOR) . " run 2>&1");
        $msg = "Manuel kontrol calisti:\n" . h($out);
    }
    $CFG = json_decode(file_get_contents($CFG_FILE), true) ?: $CFG;
}

/* --- veri --- */
$states = db()->query("SELECT site, status, since FROM state ORDER BY site")->fetchAll();
$stMap = [];
foreach ($states as $s) $stMap[$s['site']] = $s;
$last = [];
foreach (db()->query("SELECT c.* FROM checks c JOIN (SELECT site, MAX(id) mid FROM checks GROUP BY site) x ON x.mid=c.id") as $r) $last[$r['site']] = $r;
$history = db()->query("SELECT site, ts, status, http, alt, note FROM checks ORDER BY id DESC LIMIT 60")->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>GBWatch Panel</title>
<style>
body{font-family:system-ui,Arial;margin:0;background:#f4f5f7;color:#222}
header{background:#1e293b;color:#fff;padding:12px 24px;display:flex;justify-content:space-between;align-items:center}
header h1{font-size:18px;margin:0}
main{max-width:1100px;margin:24px auto;padding:0 16px}
table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:14px}
th{background:#f8fafc;font-size:12px;text-transform:uppercase;color:#64748b}
.msg{background:#ecfdf5;border:1px solid #10b981;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:16px;white-space:pre-wrap}
.card{background:#fff;border-radius:8px;padding:16px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.card h2{font-size:15px;margin:0 0 12px}
input,button{padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px}
button{background:#2563eb;color:#fff;border:none;cursor:pointer}
button.del{background:#dc2626}
form.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
small{color:#64748b}
</style></head><body>
<header><h1>GBWatch — Googlebot Gorunum Izleyici</h1>
<form method="post" class="inline"><button name="logout" value="1" style="background:#475569">Cikis</button></form></header>
<main>
<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

<form method="post" class="card"><h2>Manuel Kontrol</h2>
<input type="hidden" name="act" value="run_now"><button>Tum siteleri simdi kontrol et</button>
<small>Tum siteleri simdi kontrol et (cron beklemeden)</small></form>

<div class="card"><h2>Izlenen Siteler (<?= count($CFG['sites'] ?? []) ?>)</h2>
<table><tr><th>Site</th><th>Beklenen</th><th>Durum</th><th>Son kontrol</th><th>HTTP</th><th>Alternate</th><th>Not</th><th></th></tr>
<?php foreach (($CFG['sites'] ?? []) as $i => $s):
    $u = $s['url']; $st = $stMap[$u] ?? null; $lc = $last[$u] ?? null; ?>
<tr>
  <td><b><?= h($s['name'] ?? $u) ?></b><br><small><?= h($u) ?></small></td>
  <td><?= h($s['expect']) ?></td>
  <td><?= badge($st['status'] ?? '-') ?><br><small><?= $st ? h($st['since']) : '-' ?></small></td>
  <td><?= $lc ? h($lc['ts']) : '-' ?></td>
  <td><?= $lc ? h($lc['http']) : '-' ?></td>
  <td><?= $lc ? h($lc['alt'] ?: '-') : '-' ?></td>
  <td><?= $lc ? h($lc['note'] ?: '') : '' ?></td>
  <td><form method="post" onsubmit="return confirm('Silinsin mi?')"><input type="hidden" name="act" value="del_site"><input type="hidden" name="idx" value="<?= $i ?>"><button class="del">Sil</button></form></td>
</tr>
<?php endforeach; ?></table>
<br>
<form method="post" class="inline"><h2 style="width:100%">Site Ekle</h2>
<input type="hidden" name="act" value="add_site">
<input name="s_url" placeholder="https://site.com/" size="28" required>
<input name="s_expect" placeholder="beklenen alanadi (ornek: milanbahis.cam)" size="30" required>
<input name="s_name" placeholder="isim (opsiyonel)" size="16">
<button>Ekle</button></form></div>

<div class="card"><h2>Telegram Bildirimi</h2>
<form method="post" class="inline">
<input type="hidden" name="act" value="telegram">
<input name="tg_token" placeholder="bot token" value="<?= h($CFG['telegram']['token'] ?? '') ?>" size="40">
<input name="tg_chat" placeholder="chat id" value="<?= h($CFG['telegram']['chat_id'] ?? '') ?>" size="16">
<button>Kaydet</button>
</form>
<form method="post" style="margin-top:10px"><input type="hidden" name="act" value="tg_test"><button style="background:#059669">Test mesaji gonder</button></form></div>

<div class="card"><h2>Son 60 Kontrol Kaydi</h2>
<table><tr><th>Zaman</th><th>Site</th><th>Durum</th><th>HTTP</th><th>Alternate</th><th>Not</th></tr>
<?php foreach ($history as $r): ?>
<tr><td><?= h($r['ts']) ?></td><td><?= h($r['site']) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['http']) ?></td><td><?= h($r['alt'] ?: '-') ?></td><td><?= h($r['note']) ?></td></tr>
<?php endforeach; ?></table></div>
</main></body></html>
