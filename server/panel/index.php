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
    if (!is_file($LIB)) die('Sunucu dosyası eksik: ortak kütüphane bulunamadı.');
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
        $loginError = 'Kullanıcı adı veya şifre hatalı.';
    }

    if (empty($_SESSION['auth'])) {
        $error = $loginError !== '' ? '<p class="login-error">' . htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        die("<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Invader Control</title>
        <style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#080d13;background-image:linear-gradient(rgba(73,215,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(73,215,255,.035) 1px,transparent 1px);background-size:32px 32px;color:#e6edf3;font:14px system-ui,-apple-system,sans-serif}.login{width:min(430px,calc(100% - 32px));border:1px solid #2a3b49;background:#101820;padding:42px;box-shadow:0 20px 60px rgba(0,0,0,.35)}.login-brand{display:flex;align-items:center;gap:16px;margin-bottom:42px}.login-brand img{display:block;width:58px;height:58px}.login-brand strong{display:block;font-size:17px;letter-spacing:2px}.login-brand span{display:block;margin-top:5px;color:#8fa5b7;font-size:10px;letter-spacing:1.8px}.login h1{font-size:27px;font-weight:600;letter-spacing:-.5px;margin:0 0 9px}.login p{color:#8fa5b7;line-height:1.6;margin:0 0 25px}.login-error{color:#ff8b9a!important}.login label{display:block;color:#9aa7b6;font-size:12px;margin:18px 0 7px}.login input{width:100%;padding:12px;border:1px solid #354b5b;background:#0a1016;color:#e6edf3;border-radius:3px}.login button{width:100%;margin-top:25px;padding:12px;border:0;background:#49d7ff;color:#061117;font-weight:700;border-radius:3px;cursor:pointer}</style></head><body>
        <form class='login' method='post'><div class='login-brand'><img src='/assets/invader-control-mark.svg' alt=''><div><strong>INVADER CONTROL</strong><span>GOOGLEBOT VIEW MONITOR</span></div></div><h1>Kontrol merkezine giriş</h1><p>İzleme paneline devam etmek için kimlik bilgilerinizi girin.</p>$error<label>Kullanıcı adı</label><input name='login_user' autocomplete='username' autofocus><label>Şifre</label><input type='password' name='login_pass' autocomplete='current-password'><button>Panele gir</button></form></body></html>");
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
        return ['OK' => 'OK', 'OBSERVED' => 'GÖZLEMLENDİ', 'DOWN' => 'DÜŞÜK', 'BLOCKED' => 'ENGELLİ', 'ERROR' => 'HATA', 'EMPTY' => 'BOŞ', '-' => 'BEKLİYOR'][$status] ?? $status;
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
            $error = 'Oturum doğrulaması geçersiz. Sayfayı yenileyin.';
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
                if ($host === '' || $expectedHost === '') $error = 'URL ve beklenen alternate alanı zorunlu.';
                elseif ($duplicate) $error = 'Bu URL zaten izleniyor. Aynı URL ikinci kez eklenemez.';
                else {
                    $CFG['sites'] ??= [];
                    $CFG['sites'][] = ['name' => $name ?: $host, 'url' => $url, 'expect' => $expectedHost];
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'Site izleme listesine eklendi.';
                    else $error = 'Yapılandırma dosyası yazılamadı.';
                }
            } elseif ($action === 'del_site') {
                $index = (int) ($_POST['idx'] ?? -1);
                if (isset($CFG['sites'][$index])) {
                    $removed = $CFG['sites'][$index]['name'] ?? $CFG['sites'][$index]['url'];
                    array_splice($CFG['sites'], $index, 1);
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'İzleme kaldırıldı: ' . $removed;
                    else $error = 'Yapılandırma dosyası yazılamadı.';
                }
            } elseif ($action === 'run_now') {
                @set_time_limit(300);
                $CFG = load_cfg($CFG_FILE);
                $results = []; $runResults = [];
                foreach (($CFG['sites'] ?? []) as $site) {
                    try {
                        $result = gb_process($site, $CFG, $DB_FILE);
                        $runResults[] = $result;
                        $results[] = status_label($result['status']) . ' | ' . ($result['name'] ?? $site['url']);
                    } catch (Throwable $e) {
                        $runResults[] = ['status' => 'ERROR', 'ustatus' => 'ERROR', 'http' => 0, 'alt' => [], 'note' => $e->getMessage(), 'name' => $site['name'] ?? $site['url']];
                        $results[] = 'HATA | ' . ($site['name'] ?? $site['url']) . ': ' . $e->getMessage();
                    }
                }
                $sent = $runResults ? gb_notify_run($CFG, $runResults) : false;
                $message = "Kontrol tamamlandı\n" . implode("\n", $results) . "\nTelegram kanıtı: " . ($sent ? 'gönderildi' : 'gönderilemedi');
            }
        } catch (Throwable $e) { $error = 'Islem hatasi: ' . $e->getMessage(); }
    }

    $CFG = load_cfg($CFG_FILE);
    $historyPerPage = 10;
    $historyPage = max(1, (int) ($_GET['page'] ?? 1));
    $states = []; $last = []; $history = []; $historyTotal = 0; $historyPages = 1;
    try {
        gb_init_db($DB_FILE);
        foreach (gb_db($DB_FILE)->query("SELECT site,status,since,user_status FROM state")->fetchAll() as $row) $states[$row['site']] = $row;
        foreach (gb_db($DB_FILE)->query("SELECT c.* FROM checks c JOIN (SELECT site,MAX(id) mid FROM checks GROUP BY site) x ON x.mid=c.id")->fetchAll() as $row) $last[$row['site']] = $row;
        $historyTotal = (int) gb_db($DB_FILE)->query("SELECT COUNT(*) FROM checks")->fetchColumn();
        $historyPages = max(1, (int) ceil($historyTotal / $historyPerPage));
        $historyPage = min($historyPage, $historyPages);
        $historyOffset = ($historyPage - 1) * $historyPerPage;
        $history = gb_db($DB_FILE)->query("SELECT site,ts,status,http,alt,note,ustatus FROM checks ORDER BY id DESC LIMIT $historyPerPage OFFSET $historyOffset")->fetchAll();
    } catch (Throwable $e) { $error .= ($error ? ' | ' : '') . 'Veritabani: ' . $e->getMessage(); }

    $siteCount = count($CFG['sites'] ?? []); $okCount = 0; $attentionCount = 0;
    foreach (($CFG['sites'] ?? []) as $site) {
        if (($states[$site['url']]['status'] ?? '') === 'OK') $okCount++; else $attentionCount++;
    }
    $duplicateUrls = []; $seenUrls = [];
    foreach (($CFG['sites'] ?? []) as $site) {
        $key = gb_url_key($site['url'] ?? '');
        if ($key !== '' && isset($seenUrls[$key])) $duplicateUrls[$key] = true;
        $seenUrls[$key] = true;
    }
    $latestRun = gb_db($DB_FILE)->query("SELECT ts FROM checks ORDER BY id DESC LIMIT 1")->fetchColumn();
    $lastRun = $latestRun ?: null;
}
catch (Throwable $fatal) {
    http_response_code(500);
    die('<meta charset="utf-8"><body style="font-family:monospace;padding:40px"><h2>Sistem hatasi</h2><pre>' . htmlspecialchars($fatal->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></body>');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<title>Invader Control</title>
<style>
:root{--canvas:#eef1f4;--paper:#fff;--ink:#17202b;--muted:#718096;--line:#dce2e8;--navy:#101923;--lime:#b9f227;--blue:#2e6cf6;--red:#d84a5b;--amber:#b77712;--shadow:0 8px 24px rgba(18,31,45,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
button,input{font:inherit}.topbar{height:66px;padding:0 max(24px,calc((100% - 1180px)/2));display:flex;align-items:center;justify-content:space-between;background:var(--navy);color:#fff}.brand{display:flex;align-items:center;gap:11px}.brand-mark{display:grid;place-items:center;width:32px;height:32px;background:var(--lime);color:#11180d;font-size:11px;font-weight:800}.brand-copy strong{display:block;font-size:13px;letter-spacing:1.4px}.brand-copy span{display:block;color:#8e9baa;font-size:9px;letter-spacing:1.7px;margin-top:2px}.top-actions{display:flex;align-items:center;gap:18px}.server-state{display:flex;align-items:center;gap:7px;color:#aeb9c5;font-size:12px}.server-state i{width:7px;height:7px;border-radius:50%;background:#70df8b;box-shadow:0 0 0 3px rgba(112,223,139,.12)}
.top-actions form{margin:0}.btn{border:0;border-radius:3px;padding:9px 13px;cursor:pointer;font-weight:650;white-space:nowrap}.btn-primary{background:var(--blue);color:#fff}.btn-dark{background:#202d3b;border:1px solid #344456;color:#dce6f0}.btn-quiet{background:transparent;border:1px solid #415062;color:#b6c1cd}.btn-danger{background:transparent;border:1px solid #e4abb2;color:#b9384b;padding:6px 9px;font-size:12px}
.wrap{max-width:1180px;margin:0 auto;padding:38px 24px 60px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:28px}.eyebrow,.section-no{color:#66809a;font-size:10px;font-weight:750;letter-spacing:1.5px;text-transform:uppercase}.intro h1{font-size:30px;letter-spacing:-.8px;margin:6px 0 5px;font-weight:680}.intro p{color:var(--muted);margin:0}.intro form{margin:0}.summary{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;background:var(--paper);border:1px solid var(--line);box-shadow:var(--shadow);margin-bottom:30px}.metric{min-height:104px;padding:18px 22px;border-right:1px solid var(--line)}.metric:last-child{border-right:0}.metric-label{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.8px}.metric-value{display:block;margin-top:11px;font-size:27px;font-weight:680;letter-spacing:-.5px}.metric-value.good{color:#238344}.metric-value.bad{color:var(--red)}.metric-note{display:block;color:var(--muted);font-size:11px;margin-top:2px}
.notice{padding:13px 16px;border:1px solid #e6bdc3;background:#fff8f9;color:#9e3445;margin:-12px 0 22px;white-space:pre-wrap}.notice.ok{border-color:#b7dfc2;background:#f5fff7;color:#267743}.section{margin-top:30px}.section-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:13px}.section-title{display:flex;align-items:baseline;gap:11px}.section-title h2{font-size:17px;margin:0;font-weight:680}.section-sub{color:var(--muted);font-size:12px;margin:4px 0 0}.section-head details{position:relative}.section-head summary{list-style:none;cursor:pointer}.section-head summary::-webkit-details-marker{display:none}.add-pop{position:absolute;right:0;top:42px;width:380px;padding:18px;background:var(--paper);border:1px solid var(--line);box-shadow:0 16px 34px rgba(18,31,45,.16);z-index:5}.add-pop h3{font-size:13px;margin:0 0 14px}.field{display:block;margin-bottom:10px}.field span{display:block;color:var(--muted);font-size:11px;margin-bottom:5px}.field input{width:100%;padding:9px 10px;border:1px solid #cbd4dd;border-radius:3px;background:#fbfcfd;color:var(--ink)}.add-actions{display:flex;justify-content:flex-end;margin-top:14px}
.table-shell{background:var(--paper);border:1px solid var(--line);box-shadow:var(--shadow);overflow-x:auto}.site-table{width:100%;border-collapse:collapse;min-width:850px}.site-table th{padding:12px 15px;text-align:left;background:#f8fafb;color:#788b9c;border-bottom:1px solid var(--line);font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:700}.site-table td{padding:16px 15px;border-bottom:1px solid #e8edf1;vertical-align:middle}.site-table tr:last-child td{border-bottom:0}.site-table tbody tr:hover{background:#fbfcfd}.site-name{font-weight:680}.site-url{display:block;color:var(--muted);font-size:11px;margin-top:3px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.expect{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#334e68}.status{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:760;letter-spacing:.45px}.status i{width:8px;height:8px;border-radius:50%;background:#98a5b2}.status.ok{color:#237b40}.status.ok i{background:#35ae5a}.status.info{color:#2f62bc}.status.info i{background:#4d83ec}.status.down,.status.error{color:#bd394b}.status.down i,.status.error i{background:#de5c6b}.status.warn{color:#9d6810}.status.warn i{background:#d49322}.status.idle{color:#768494}.status-note{display:block;margin-top:4px;color:var(--muted);font-size:11px}.alt-cell{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;line-height:1.7}.alt-expected{color:#82909c}.alt-observed{color:#273b4d}.alt-observed.bad{color:#bd394b}.time{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#53677b;font-size:11px;white-space:nowrap}.http{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#53677b}.empty-state{text-align:center;padding:36px;color:var(--muted)}
.history{margin-top:30px}.history summary{cursor:pointer;color:#53677b;font-weight:650;font-size:12px;list-style:none}.history summary::-webkit-details-marker{display:none}.history summary:before{content:'+';display:inline-block;margin-right:8px;color:var(--blue);font-size:16px;vertical-align:-1px}.history[open] summary:before{content:'−'}.history-table{margin-top:13px}.history-table .site-url{max-width:220px}.footer-note{text-align:right;color:#8997a4;font-size:11px;margin-top:22px}
@media(max-width:850px){.summary{grid-template-columns:1fr 1fr}.metric:nth-child(2){border-right:0}.metric:nth-child(-n+2){border-bottom:1px solid var(--line)}.intro{align-items:flex-start;flex-direction:column}.intro form{width:100%}.intro form .btn{width:100%}}
@media(max-width:560px){.topbar{height:60px;padding:0 16px}.server-state{display:none}.wrap{padding:25px 14px 40px}.intro h1{font-size:25px}.summary{grid-template-columns:1fr 1fr}.metric{padding:15px 14px;min-height:91px}.metric-value{font-size:23px}.metric-note{font-size:10px}.add-pop{position:fixed;right:14px;left:14px;top:115px;width:auto}.monitor-shell{overflow:visible}.monitor-table,.monitor-table tbody{display:block;min-width:0}.monitor-table thead{display:none}.monitor-table tr{display:block;position:relative;padding:10px 0;border-bottom:1px solid var(--line)}.monitor-table tr:last-child{border-bottom:0}.monitor-table td{display:flex;justify-content:space-between;gap:16px;padding:8px 15px;border:0;text-align:right}.monitor-table td:first-child{display:block;text-align:left;padding-right:65px;padding-bottom:12px}.monitor-table td:last-child{position:absolute;right:14px;top:16px;display:block;padding:0}.monitor-table td:nth-child(2):before{content:'Beklenen';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(3):before{content:'Googlebot';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(4):before{content:'Kullanıcı';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(5):before{content:'Alternate';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(6):before{content:'Son kontrol';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(7):before{content:'HTTP';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(5) .alt-cell{text-align:right}.history-table{overflow-x:auto}}
</style>
<style>
:root{--canvas:#070b10;--paper:#0d141c;--paper2:#111b25;--ink:#dce8f2;--muted:#7f92a5;--line:#22313e;--navy:#080d13;--lime:#9cff57;--blue:#49d7ff;--red:#ff6c7d;--amber:#f0b74d;--shadow:0 12px 30px rgba(0,0,0,.24)}
body{background-color:var(--canvas);background-image:linear-gradient(rgba(73,215,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(73,215,255,.025) 1px,transparent 1px);background-size:32px 32px;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.topbar{height:70px;background:rgba(8,13,19,.96);border-bottom:1px solid #294252;box-shadow:0 1px 0 rgba(156,255,87,.18)}
 .brand-mark-image{display:block;width:46px;height:46px}.brand-copy strong{display:block;font-size:17px;letter-spacing:2.1px}.brand-copy span{display:block;color:#8296a8;font-size:9px;letter-spacing:1.7px;margin-top:4px}.top-actions form{display:block}.top-actions .btn{color:#a9bdce;border-color:#304453}
.notice{background:#0e1b24;border:1px solid #2b5262;color:#bdeafa;margin:-10px 0 22px;box-shadow:inset 3px 0 0 var(--blue)}.notice.ok{background:#10231b;border-color:#2f6d48;color:#b9f5c7;box-shadow:inset 3px 0 0 var(--lime)}
.wrap{max-width:1240px;padding-top:42px}.intro{margin-bottom:30px}.intro h1{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;text-transform:uppercase;font-size:34px;letter-spacing:-1px;font-weight:600;margin:8px 0 8px}.intro p{font-size:13px;color:#91a6b8}.eyebrow,.section-no{color:var(--blue)}
.btn{border-radius:2px}.btn-primary{background:var(--blue);color:#061117}.btn-dark{background:#182532;border-color:#2d4555;color:#d9e8f1}
.summary{border:1px solid var(--line);border-top:2px solid var(--blue);box-shadow:var(--shadow);background:var(--paper)}.metric{background:var(--paper);padding:20px 22px;border-color:var(--line)}.metric-value{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:28px;font-weight:500}.metric-value.good{color:var(--lime)}.metric-value.bad{color:var(--red)}
.section{margin-top:38px}.section-head{margin-bottom:14px}.section-title h2{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;letter-spacing:-.3px;font-weight:600}.section-sub{font-size:12px;color:#8297aa}.table-shell{box-shadow:var(--shadow);border-color:var(--line)}.site-table{background:var(--paper)}.site-table th{background:#111d27;color:#7891a4;padding:12px 15px;border-color:var(--line)}.site-table td{padding:16px 15px;border-color:var(--line)}.site-table tbody tr:hover{background:#13202b}.site-name{font-size:13px}.expect,.alt-cell,.time,.http{font-size:11px}.status{font-size:11px}.status-note{color:#71889a}.btn-danger{color:var(--red);border-color:#733541}.history{margin-top:40px}.page-label{font-size:11px;color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.pagination{display:flex;align-items:center;justify-content:flex-end;gap:5px;margin-top:14px}.pagination a,.pagination span{min-width:30px;height:30px;padding:6px 9px;text-align:center;border:1px solid var(--line);color:var(--muted);text-decoration:none;font-size:12px;background:var(--paper)}.pagination a:hover{border-color:var(--blue);color:var(--blue)}.pagination .current{background:var(--blue);border-color:var(--blue);color:#061117}.pagination .ellipsis{border-color:transparent;background:transparent;min-width:16px}.footer-note{text-align:left;border-top:1px solid var(--line);padding-top:14px;color:#647b8d}
.add-pop{background:var(--paper2);border-color:#2b4251}.field input{background:#080e14;border-color:#304553;color:var(--ink)}.field span{color:#8398aa}
@media(max-width:850px){.intro h1{font-size:30px}.summary{grid-template-columns:1fr 1fr}.metric:nth-child(2){border-right:0}.metric:nth-child(-n+2){border-bottom:1px solid var(--line)}}
 @media(max-width:560px){.topbar{height:64px}.brand-mark-image{width:36px;height:36px}.brand-copy strong{font-size:13px;letter-spacing:1.6px}.brand-copy span{font-size:8px}.wrap{padding:30px 14px 42px}.intro h1{font-size:26px}.intro p{max-width:330px}.summary{grid-template-columns:1fr 1fr}.metric{padding:15px 14px}.metric-value{font-size:23px}.section-title h2{font-size:16px}.pagination{justify-content:center}.footer-note{font-size:10px}.history-table{overflow-x:auto}}
</style>
</head>
<body>
<header class="topbar">
  <div class="brand"><img class="brand-mark-image" src="/assets/invader-control-mark.svg" alt=""><div class="brand-copy"><strong>INVADER CONTROL</strong><span>GOOGLEBOT VIEW MONITOR</span></div></div>
  <div class="top-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn btn-quiet" name="logout" value="1">Çıkış</button></form></div>
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
    <div class="table-shell monitor-shell"><table class="site-table monitor-table"><thead><tr><th>Site</th><th>Beklenen alternate</th><th>Googlebot</th><th>Kullanıcı</th><th>Görülen alternate</th><th>Son kontrol</th><th>HTTP</th><th></th></tr></thead><tbody>
    <?php if (!$siteCount): ?><tr><td colspan="8" class="empty-state">Henüz izlenen site yok. Sağ üstten ilk hedefi ekleyin.</td></tr><?php endif; ?>
    <?php foreach (($CFG['sites'] ?? []) as $index => $site): $url = $site['url']; $state = $states[$url] ?? null; $check = $last[$url] ?? null; $expected = gb_host_of($site['expect'] ?? ''); $observed = $check['alt'] ?? ''; ?>
      <tr><td><div class="site-name"><?= h($site['name'] ?? gb_host_of($url)) ?></div><span class="site-url"><?= h($url) ?></span></td><td><span class="expect"><?= h($expected) ?></span></td><td><?= status_badge($state['status'] ?? '-') ?><span class="status-note">Googlebot HTML</span></td><td><?= status_badge($check['ustatus'] ?? '-') ?><span class="status-note">normal UA</span></td><td><div class="alt-cell"><span class="alt-expected">beklenen: <?= h($expected) ?></span><br><span class="alt-observed <?= ($observed && strpos(',' . $observed . ',', ',' . $expected . ',') === false) ? 'bad' : '' ?>">görülen: <?= h($observed ?: '-') ?></span></div></td><td class="time"><?= h(local_time($check['ts'] ?? null)) ?></td><td class="http"><?= h($check['http'] ?? '-') ?></td><td><form method="post" onsubmit="return confirm('Bu izlemeyi kaldıralım mı?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="del_site"><input type="hidden" name="idx" value="<?= $index ?>"><button class="btn btn-danger">Sil</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <section class="history" id="history"><div class="section-head"><div><div class="section-title"><span class="section-no">03</span><h2>Kontrol geçmişi</h2></div><p class="section-sub">Her sayfada <?= $historyPerPage ?> kayıt gösteriliyor · toplam <?= $historyTotal ?> kayıt</p></div><span class="page-label">Sayfa <?= $historyPage ?> / <?= $historyPages ?></span></div><div class="table-shell history-table"><table class="site-table"><thead><tr><th>Zaman</th><th>Site</th><th>Googlebot</th><th>Kullanıcı</th><th>HTTP</th><th>Alternate</th><th>Not</th></tr></thead><tbody><?php if (!$history): ?><tr><td colspan="7" class="empty-state">Henüz kontrol kaydı yok.</td></tr><?php endif; ?><?php foreach ($history as $row): ?><tr><td class="time"><?= h(local_time($row['ts'])) ?></td><td><span class="site-url"><?= h($row['site']) ?></span></td><td><?= status_badge($row['status']) ?></td><td><?= status_badge($row['ustatus'] ?? '-') ?></td><td class="http"><?= h($row['http']) ?></td><td class="expect"><?= h($row['alt'] ?: '-') ?></td><td class="status-note"><?= h($row['note'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div><nav class="pagination" aria-label="Kontrol geçmişi sayfaları"><?php if ($historyPage > 1): ?><a href="?page=<?= $historyPage - 1 ?>#history">Önceki</a><?php endif; ?><?php for ($p = 1; $p <= $historyPages; $p++): if ($p === $historyPage): ?><span class="current"><?= $p ?></span><?php elseif ($p <= 3 || $p > $historyPages - 2 || abs($p - $historyPage) <= 1): ?><a href="?page=<?= $p ?>#history"><?= $p ?></a><?php elseif ($p === 4 || $p === $historyPages - 2): ?><span class="ellipsis">…</span><?php endif; endfor; ?><?php if ($historyPage < $historyPages): ?><a href="?page=<?= $historyPage + 1 ?>#history">Sonraki</a><?php endif; ?></nav></section>
  <div class="footer-note">Son ölçüm zamanları Europe/Istanbul · HTTP kontrolü Googlebot User-Agent ile yapılır</div>
</main>
</body></html>
