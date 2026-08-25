<?php
/* Invader Control panel */
$BASE = '/opt/gbwatch';
$CFG_FILE = "$BASE/config.json";
$DB_FILE = "$BASE/data/gbwatch.db";
$LIB = "$BASE/repo/server/lib/common.php";
$AUTH_FILE = "$BASE/panel_auth.json";

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    if (!is_file($LIB)) die('Sunucu dosyasi eksik: ortak kutuphane bulunamadi.');
    require $LIB;

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];

    if (!is_file($AUTH_FILE)) {
        @file_put_contents($AUTH_FILE, json_encode([
            'user' => 'admin',
            'hash' => password_hash('invader25', PASSWORD_DEFAULT),
        ]));
        @chmod($AUTH_FILE, 0600);
    }
    $AUTH = json_decode((string) @file_get_contents($AUTH_FILE), true) ?: [];

    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $loginError = '';
    if (isset($_POST['login_user'], $_POST['login_pass'])) {
        if (($AUTH['user'] ?? '') !== ''
            && hash_equals((string) $AUTH['user'], (string) $_POST['login_user'])
            && ($AUTH['hash'] ?? '') !== ''
            && password_verify((string) $_POST['login_pass'], (string) $AUTH['hash'])) {
            $_SESSION['auth'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $loginError = 'Kullanici adi veya sifre hatali.';
    }

    if (empty($_SESSION['auth'])) {
        $error = $loginError !== '' ? '<p class="login-error">' . htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        die("<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Invader Control</title>
        <style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d1117;color:#e6edf3;font:14px system-ui,-apple-system,sans-serif}.login{width:min(390px,calc(100% - 32px));border:1px solid #263241;background:#151b23;padding:30px}.mark{display:flex;align-items:center;gap:12px;margin-bottom:34px}.mark-icon{display:grid;place-items:center;width:34px;height:34px;background:#b9f227;color:#10150b;font-weight:800;font-size:12px}.mark strong{display:block;letter-spacing:1.2px;font-size:14px}.mark span{display:block;margin-top:4px;color:#7d8a99;font-size:10px;letter-spacing:1.5px}.login h1{font-size:23px;font-weight:600;margin:0 0 8px}.login p{color:#7d8a99}.login-error{color:#ff8b9a!important}.login label{display:block;color:#9aa7b6;font-size:12px;margin:18px 0 7px}.login input{width:100%;padding:11px;border:1px solid #354253;background:#0d1117;color:#e6edf3;border-radius:3px}.login button{width:100%;margin-top:22px;padding:11px;border:0;background:#b9f227;color:#10150b;font-weight:700;border-radius:3px;cursor:pointer}</style></head><body>
        <form class='login' method='post'><div class='mark'><div class='mark-icon'>IC</div><div><strong>INVADER CONTROL</strong><span>GOOGLEBOT VIEW MONITOR</span></div></div><h1>Kontrol merkezine giris</h1><p>Izleme paneline devam etmek icin kimlik bilgilerinizi girin.</p>$error<label>Kullanici adi</label><input name='login_user' autocomplete='username' autofocus><label>Sifre</label><input type='password' name='login_pass' autocomplete='current-password'><button>Panele gir</button></form></body></html>");
    }

    function h(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function local_time(?string $value): string {
        if (!$value) return '-';
        try {
            $date = new DateTime($value, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone('Europe/Istanbul'));
            return $date->format('d.m.Y H:i:s');
        } catch (Throwable $e) { return (string) $value; }
    }
    function load_cfg(string $file): array {
        return is_readable($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }
    function save_cfg(array $cfg, string $file): bool {
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json !== false && @file_put_contents($file, $json) !== false;
    }
    function status_label(string $status): string {
        return ['OK' => 'OK', 'OBSERVED' => 'GOZLEMLENDI', 'DOWN' => 'DUSUK', 'BLOCKED' => 'ENGELLI', 'ERROR' => 'HATA', 'EMPTY' => 'BOS', '-' => 'BEKLIYOR'][$status] ?? $status;
    }
    function status_class(string $status): string {
        return ['OK' => 'ok', 'OBSERVED' => 'info', 'DOWN' => 'down', 'BLOCKED' => 'warn', 'ERROR' => 'error', 'EMPTY' => 'warn', '-' => 'idle'][$status] ?? 'idle';
    }
    function status_badge(?string $status): string {
        $status = $status ?: '-';
        return '<span class="status ' . status_class($status) . '"><i></i>' . h(status_label($status)) . '</span>';
    }

    $CFG = load_cfg($CFG_FILE);
    $message = ''; $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['act'] ?? '';
        if ($action !== '' && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            $error = 'Oturum dogrulamasi gecersiz. Sayfayi yenileyin.';
            $action = '';
        }
        try {
            if ($action === 'add_site') {
                $url = trim((string) ($_POST['s_url'] ?? ''));
                $expect = trim((string) ($_POST['s_expect'] ?? ''));
                $name = trim((string) ($_POST['s_name'] ?? ''));
                if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
                $expect = preg_replace('~^https?://~i', '', $expect);
                $host = gb_host_of($url); $expectedHost = gb_host_of($expect);
                $duplicate = false;
                foreach (($CFG['sites'] ?? []) as $old) {
                    if (gb_url_key($old['url'] ?? '') === gb_url_key($url)) { $duplicate = true; break; }
                }
                if ($host === '' || $expectedHost === '') $error = 'URL ve beklenen alternate alani zorunlu.';
                elseif ($duplicate) $error = 'Bu URL zaten izleniyor. Ayni URL ikinci kez eklenemez.';
                else {
                    $CFG['sites'] ??= [];
                    $CFG['sites'][] = ['name' => $name ?: $host, 'url' => $url, 'expect' => $expectedHost];
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'Site izleme listesine eklendi.';
                    else $error = 'Config dosyasi yazilamadi.';
                }
            } elseif ($action === 'del_site') {
                $index = (int) ($_POST['idx'] ?? -1);
                if (isset($CFG['sites'][$index])) {
                    $removed = $CFG['sites'][$index]['name'] ?? $CFG['sites'][$index]['url'];
                    array_splice($CFG['sites'], $index, 1);
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'Izleme kaldirildi: ' . $removed;
                    else $error = 'Config dosyasi yazilamadi.';
                }
            } elseif ($action === 'run_now') {
                @set_time_limit(300);
                $CFG = load_cfg($CFG_FILE);
                $results = [];
                foreach (($CFG['sites'] ?? []) as $site) {
                    try {
                        $result = gb_process($site, $CFG, $DB_FILE);
                        $results[] = status_label($result['status']) . ' | ' . ($result['name'] ?? $site['url']);
                    } catch (Throwable $e) {
                        $results[] = 'HATA | ' . ($site['name'] ?? $site['url']) . ': ' . $e->getMessage();
                    }
                }
                $message = "Kontrol tamamlandi\n" . implode("\n", $results);
            } elseif ($action === 'tg_test') {
                $message = gb_tg_send($CFG['telegram'] ?? [], 'Invader Control test mesaji')
                    ? 'Telegram test mesaji gonderildi.'
                    : 'Telegram mesaji gonderilemedi. Token veya chat ID ayarini kontrol edin.';
            } elseif ($action === 'change_pass') {
                $newPassword = (string) ($_POST['new_pass'] ?? '');
                if (strlen($newPassword) < 6) $error = 'Sifre en az 6 karakter olmali.';
                else {
                    $AUTH['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    if (@file_put_contents($AUTH_FILE, json_encode($AUTH)) !== false) $message = 'Panel sifresi degistirildi.';
                    else $error = 'Sifre dosyasi yazilamadi.';
                }
            }
        } catch (Throwable $e) { $error = 'Islem hatasi: ' . $e->getMessage(); }
    }

    $CFG = load_cfg($CFG_FILE);
    $states = []; $last = []; $history = [];
    try {
        gb_init_db($DB_FILE);
        foreach (gb_db($DB_FILE)->query("SELECT site,status,since,user_status FROM state")->fetchAll() as $row) $states[$row['site']] = $row;
        foreach (gb_db($DB_FILE)->query("SELECT c.* FROM checks c JOIN (SELECT site,MAX(id) mid FROM checks GROUP BY site) x ON x.mid=c.id")->fetchAll() as $row) $last[$row['site']] = $row;
        $history = gb_db($DB_FILE)->query("SELECT site,ts,status,http,alt,note,ustatus FROM checks ORDER BY id DESC LIMIT 20")->fetchAll();
    } catch (Throwable $e) { $error .= ($error ? ' | ' : '') . 'Veritabani: ' . $e->getMessage(); }

    $siteCount = count($CFG['sites'] ?? []); $okCount = 0; $attentionCount = 0;
    foreach (($CFG['sites'] ?? []) as $site) {
        if (($states[$site['url']]['status'] ?? '') === 'OK') $okCount++; else $attentionCount++;
    }
    $telegramReady = !empty($CFG['telegram']['token']) && !empty($CFG['telegram']['chat_id']);
    $duplicateUrls = []; $seenUrls = [];
    foreach (($CFG['sites'] ?? []) as $site) {
        $key = gb_url_key($site['url'] ?? '');
        if ($key !== '' && isset($seenUrls[$key])) $duplicateUrls[$key] = true;
        $seenUrls[$key] = true;
    }
    $lastRun = $history[0]['ts'] ?? null;
}
catch (Throwable $fatal) {
    http_response_code(500);
    die('<meta charset="utf-8"><body style="font-family:monospace;padding:40px"><h2>Sistem hatasi</h2><pre>' . htmlspecialchars($fatal->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></body>');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invader Control</title>
<style>
:root{--canvas:#eef1f4;--paper:#fff;--ink:#17202b;--muted:#718096;--line:#dce2e8;--navy:#101923;--lime:#b9f227;--blue:#2e6cf6;--red:#d84a5b;--amber:#b77712;--shadow:0 8px 24px rgba(18,31,45,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
button,input{font:inherit}.topbar{height:66px;padding:0 max(24px,calc((100% - 1180px)/2));display:flex;align-items:center;justify-content:space-between;background:var(--navy);color:#fff}.brand{display:flex;align-items:center;gap:11px}.brand-mark{display:grid;place-items:center;width:32px;height:32px;background:var(--lime);color:#11180d;font-size:11px;font-weight:800}.brand-copy strong{display:block;font-size:13px;letter-spacing:1.4px}.brand-copy span{display:block;color:#8e9baa;font-size:9px;letter-spacing:1.7px;margin-top:2px}.top-actions{display:flex;align-items:center;gap:18px}.server-state{display:flex;align-items:center;gap:7px;color:#aeb9c5;font-size:12px}.server-state i{width:7px;height:7px;border-radius:50%;background:#70df8b;box-shadow:0 0 0 3px rgba(112,223,139,.12)}
.top-actions form{margin:0}.btn{border:0;border-radius:3px;padding:9px 13px;cursor:pointer;font-weight:650;white-space:nowrap}.btn-primary{background:var(--blue);color:#fff}.btn-dark{background:#202d3b;border:1px solid #344456;color:#dce6f0}.btn-quiet{background:transparent;border:1px solid #415062;color:#b6c1cd}.btn-danger{background:transparent;border:1px solid #e4abb2;color:#b9384b;padding:6px 9px;font-size:12px}
.wrap{max-width:1180px;margin:0 auto;padding:38px 24px 60px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:28px}.eyebrow,.section-no{color:#66809a;font-size:10px;font-weight:750;letter-spacing:1.5px;text-transform:uppercase}.intro h1{font-size:30px;letter-spacing:-.8px;margin:6px 0 5px;font-weight:680}.intro p{color:var(--muted);margin:0}.intro form{margin:0}.summary{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;background:var(--paper);border:1px solid var(--line);box-shadow:var(--shadow);margin-bottom:30px}.metric{min-height:104px;padding:18px 22px;border-right:1px solid var(--line)}.metric:last-child{border-right:0}.metric-label{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.8px}.metric-value{display:block;margin-top:11px;font-size:27px;font-weight:680;letter-spacing:-.5px}.metric-value.good{color:#238344}.metric-value.bad{color:var(--red)}.metric-note{display:block;color:var(--muted);font-size:11px;margin-top:2px}
.notice{padding:13px 16px;border:1px solid #e6bdc3;background:#fff8f9;color:#9e3445;margin:-12px 0 22px;white-space:pre-wrap}.notice.ok{border-color:#b7dfc2;background:#f5fff7;color:#267743}.section{margin-top:30px}.section-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:13px}.section-title{display:flex;align-items:baseline;gap:11px}.section-title h2{font-size:17px;margin:0;font-weight:680}.section-sub{color:var(--muted);font-size:12px;margin:4px 0 0}.section-head details{position:relative}.section-head summary{list-style:none;cursor:pointer}.section-head summary::-webkit-details-marker{display:none}.add-pop{position:absolute;right:0;top:42px;width:380px;padding:18px;background:var(--paper);border:1px solid var(--line);box-shadow:0 16px 34px rgba(18,31,45,.16);z-index:5}.add-pop h3{font-size:13px;margin:0 0 14px}.field{display:block;margin-bottom:10px}.field span{display:block;color:var(--muted);font-size:11px;margin-bottom:5px}.field input{width:100%;padding:9px 10px;border:1px solid #cbd4dd;border-radius:3px;background:#fbfcfd;color:var(--ink)}.add-actions{display:flex;justify-content:flex-end;margin-top:14px}
.table-shell{background:var(--paper);border:1px solid var(--line);box-shadow:var(--shadow);overflow-x:auto}.site-table{width:100%;border-collapse:collapse;min-width:850px}.site-table th{padding:12px 15px;text-align:left;background:#f8fafb;color:#788b9c;border-bottom:1px solid var(--line);font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:700}.site-table td{padding:16px 15px;border-bottom:1px solid #e8edf1;vertical-align:middle}.site-table tr:last-child td{border-bottom:0}.site-table tbody tr:hover{background:#fbfcfd}.site-name{font-weight:680}.site-url{display:block;color:var(--muted);font-size:11px;margin-top:3px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.expect{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#334e68}.status{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:760;letter-spacing:.45px}.status i{width:8px;height:8px;border-radius:50%;background:#98a5b2}.status.ok{color:#237b40}.status.ok i{background:#35ae5a}.status.info{color:#2f62bc}.status.info i{background:#4d83ec}.status.down,.status.error{color:#bd394b}.status.down i,.status.error i{background:#de5c6b}.status.warn{color:#9d6810}.status.warn i{background:#d49322}.status.idle{color:#768494}.status-note{display:block;margin-top:4px;color:var(--muted);font-size:11px}.alt-cell{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;line-height:1.7}.alt-expected{color:#82909c}.alt-observed{color:#273b4d}.alt-observed.bad{color:#bd394b}.time{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#53677b;font-size:11px;white-space:nowrap}.http{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#53677b}.empty-state{text-align:center;padding:36px;color:var(--muted)}
.utility-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:30px}.utility{background:var(--paper);border:1px solid var(--line);padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px}.utility h3{margin:0;font-size:13px}.utility p{color:var(--muted);font-size:12px;margin:5px 0 0}.telegram-state{display:flex;align-items:center;gap:7px;color:#237b40;font-size:11px;font-weight:700;margin-top:8px}.telegram-state i{width:7px;height:7px;border-radius:50%;background:#35ae5a}.security summary{cursor:pointer;color:#53677b;font-size:12px}.security form{display:flex;gap:9px;margin-top:14px}.security input{flex:1;padding:9px;border:1px solid #cbd4dd;border-radius:3px}.history{margin-top:30px}.history summary{cursor:pointer;color:#53677b;font-weight:650;font-size:12px;list-style:none}.history summary::-webkit-details-marker{display:none}.history summary:before{content:'+';display:inline-block;margin-right:8px;color:var(--blue);font-size:16px;vertical-align:-1px}.history[open] summary:before{content:'−'}.history-table{margin-top:13px}.history-table .site-url{max-width:220px}.footer-note{text-align:right;color:#8997a4;font-size:11px;margin-top:22px}
@media(max-width:850px){.summary{grid-template-columns:1fr 1fr}.metric:nth-child(2){border-right:0}.metric:nth-child(-n+2){border-bottom:1px solid var(--line)}.utility-grid{grid-template-columns:1fr}.intro{align-items:flex-start;flex-direction:column}.intro form{width:100%}.intro form .btn{width:100%}}
@media(max-width:560px){.topbar{height:60px;padding:0 16px}.server-state{display:none}.wrap{padding:25px 14px 40px}.intro h1{font-size:25px}.summary{grid-template-columns:1fr 1fr}.metric{padding:15px 14px;min-height:91px}.metric-value{font-size:23px}.metric-note{font-size:10px}.add-pop{position:fixed;right:14px;left:14px;top:115px;width:auto}.utility{align-items:flex-start;flex-direction:column}.security form{flex-direction:column}}
</style>
</head>
<body>
<header class="topbar">
  <div class="brand"><div class="brand-mark">IC</div><div class="brand-copy"><strong>INVADER CONTROL</strong><span>GOOGLEBOT VIEW MONITOR</span></div></div>
  <div class="top-actions"><span class="server-state"><i></i>Monitor aktif</span><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn btn-quiet" name="logout" value="1">Çıkış</button></form></div>
</header>
<main class="wrap">
  <section class="intro"><div><span class="eyebrow">01 / Genel bakış</span><h1>Kontrol merkezi</h1><p>Googlebot görünümü ile normal kullanıcı görünümünü aynı ölçümde izleyin.</p></div><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="run_now"><button class="btn btn-primary">Tüm siteleri kontrol et</button></form></section>

  <?php if ($message): ?><div class="notice ok"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
  <?php if ($duplicateUrls): ?><div class="notice">Aynı URL birden fazla kez kayıtlı. Tekrarlanan kaydı silmeden sonuçlar birbirini ezebilir.</div><?php endif; ?>

  <section class="summary">
    <div class="metric"><span class="metric-label">İzlenen site</span><strong class="metric-value"><?= $siteCount ?></strong><span class="metric-note">aktif hedef</span></div>
    <div class="metric"><span class="metric-label">Googlebot OK</span><strong class="metric-value good"><?= $okCount ?></strong><span class="metric-note">beklenen alternate bulundu</span></div>
    <div class="metric"><span class="metric-label">Dikkat gerekli</span><strong class="metric-value <?= $attentionCount ? 'bad' : '' ?>"><?= $attentionCount ?></strong><span class="metric-note">son ölçüme göre</span></div>
    <div class="metric"><span class="metric-label">Son ölçüm</span><strong class="metric-value" style="font-size:16px;margin-top:16px"><?= h(local_time($lastRun)) ?></strong><span class="metric-note">Türkiye saati</span></div>
  </section>

  <section class="section">
    <div class="section-head"><div><div class="section-title"><span class="section-no">02</span><h2>İzlenen siteler</h2></div><p class="section-sub">Googlebot kodundaki alternate domain ile beklenen hedefi karşılaştırır.</p></div><details><summary class="btn btn-dark">+ Site ekle</summary><div class="add-pop"><h3>Yeni izleme hedefi</h3><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="add_site"><label class="field"><span>İzlenecek URL</span><input name="s_url" placeholder="https://site.com/" required></label><label class="field"><span>Beklenen alternate domain</span><input name="s_expect" placeholder="milanbahis.cam" required></label><label class="field"><span>Panel adı <small>(opsiyonel)</small></span><input name="s_name" placeholder="site adı"></label><div class="add-actions"><button class="btn btn-primary">İzlemeye al</button></div></form></div></details></div>
    <div class="table-shell"><table class="site-table"><thead><tr><th>Site</th><th>Beklenen alternate</th><th>Googlebot</th><th>Kullanıcı</th><th>Görülen alternate</th><th>Son kontrol</th><th>HTTP</th><th></th></tr></thead><tbody>
    <?php if (!$siteCount): ?><tr><td colspan="8" class="empty-state">Henüz izlenen site yok. Sağ üstten ilk hedefi ekleyin.</td></tr><?php endif; ?>
    <?php foreach (($CFG['sites'] ?? []) as $index => $site): $url = $site['url']; $state = $states[$url] ?? null; $check = $last[$url] ?? null; $expected = gb_host_of($site['expect'] ?? ''); $observed = $check['alt'] ?? ''; ?>
      <tr><td><div class="site-name"><?= h($site['name'] ?? gb_host_of($url)) ?></div><span class="site-url"><?= h($url) ?></span></td><td><span class="expect"><?= h($expected) ?></span></td><td><?= status_badge($state['status'] ?? '-') ?><span class="status-note">Googlebot HTML</span></td><td><?= status_badge($check['ustatus'] ?? '-') ?><span class="status-note">normal UA</span></td><td><div class="alt-cell"><span class="alt-expected">beklenen: <?= h($expected) ?></span><br><span class="alt-observed <?= ($observed && strpos(',' . $observed . ',', ',' . $expected . ',') === false) ? 'bad' : '' ?>">görülen: <?= h($observed ?: '-') ?></span></div></td><td class="time"><?= h(local_time($check['ts'] ?? null)) ?></td><td class="http"><?= h($check['http'] ?? '-') ?></td><td><form method="post" onsubmit="return confirm('Bu izlemeyi kaldıralım mı?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="del_site"><input type="hidden" name="idx" value="<?= $index ?>"><button class="btn btn-danger">Sil</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <section class="utility-grid">
    <div class="utility"><div><h3>Telegram bildirimleri</h3><p>Durum değiştiğinde gruba bildirim gönderilir.</p><div class="telegram-state"><i></i><?= $telegramReady ? 'Bildirim kanalı bağlı' : 'Bildirim kanalı ayarlı değil' ?></div></div><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="tg_test"><button class="btn btn-dark">Test gönder</button></form></div>
    <details class="utility security"><summary>Panel güvenliği</summary><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="change_pass"><input type="password" name="new_pass" placeholder="yeni şifre (en az 6 karakter)" required><button class="btn btn-dark">Şifreyi değiştir</button></form></details>
  </section>

  <details class="history"><summary>Son 20 kontrol kaydını göster</summary><div class="table-shell history-table"><table class="site-table"><thead><tr><th>Zaman</th><th>Site</th><th>Googlebot</th><th>Kullanıcı</th><th>HTTP</th><th>Alternate</th><th>Not</th></tr></thead><tbody><?php if (!$history): ?><tr><td colspan="7" class="empty-state">Henüz kontrol kaydı yok.</td></tr><?php endif; ?><?php foreach ($history as $row): ?><tr><td class="time"><?= h(local_time($row['ts'])) ?></td><td><span class="site-url"><?= h($row['site']) ?></span></td><td><?= status_badge($row['status']) ?></td><td><?= status_badge($row['ustatus'] ?? '-') ?></td><td class="http"><?= h($row['http']) ?></td><td class="expect"><?= h($row['alt'] ?: '-') ?></td><td class="status-note"><?= h($row['note'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div></details>
  <div class="footer-note">Son ölçüm zamanları Europe/Istanbul · HTTP kontrolü Googlebot User-Agent ile yapılır</div>
</main>
</body></html>
