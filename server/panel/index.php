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
        die("<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><link rel='icon' href='/assets/favicon.svg?v=2' type='image/svg+xml'><link rel='shortcut icon' href='/assets/favicon.svg?v=2' type='image/svg+xml'><title>Invader Control</title>
        <style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px 16px;background:#020402;background-image:linear-gradient(rgba(57,255,106,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(57,255,106,.05) 1px,transparent 1px);background-size:28px 28px;color:#c9ffd6;font:14px system-ui,-apple-system,sans-serif}.login{position:relative;width:min(470px,100%);padding:48px;border:1px solid transparent;background:linear-gradient(#050805,#050805) padding-box,linear-gradient(160deg,rgba(57,255,106,.6),#12301c 55%,rgba(57,255,106,.25)) border-box;clip-path:polygon(22px 0,100% 0,100% calc(100% - 22px),calc(100% - 22px) 100%,0 100%,0 22px);box-shadow:0 0 60px rgba(57,255,106,.1)}.login:before{content:'// AUTH MODULE v2.1 — IDENTIFY YOURSELF';display:block;color:#39ff6a;font:10px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:1.4px;margin-bottom:30px}.login-brand{display:flex;align-items:center;gap:17px;margin-bottom:38px}.login-brand img{display:block;width:64px;height:64px;filter:drop-shadow(0 0 12px rgba(57,255,106,.45))}.login-brand strong{display:block;color:#e9fff0;font-size:19px;letter-spacing:2.2px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.login-brand span{display:block;margin-top:6px;color:#5f8a6a;font-size:10px;letter-spacing:1.9px}.login h1{color:#e9fff0;font-size:29px;font-weight:650;letter-spacing:-.7px;margin:0 0 10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;text-transform:uppercase}.login p{color:#5f8a6a;line-height:1.65;margin:0 0 27px}.login-error{color:#ff4d5e!important}.login label{display:block;color:#8fc39c;font-size:12px;font-weight:600;letter-spacing:.2px;margin:19px 0 7px}.login input{width:100%;padding:13px;border:1px solid transparent;background:linear-gradient(#020602,#020602) padding-box,linear-gradient(135deg,#1c4226,#0e2415) border-box;clip-path:polygon(8px 0,100% 0,100% calc(100% - 8px),calc(100% - 8px) 100%,0 100%,0 8px);color:#c9ffd6;outline:none;transition:box-shadow .18s}.login input:focus{box-shadow:0 0 18px rgba(57,255,106,.18)}.login button{width:100%;margin-top:26px;padding:14px;border:0;background:#39ff6a;color:#021108;font-weight:700;cursor:pointer;letter-spacing:2px;text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;clip-path:polygon(12px 0,100% 0,100% calc(100% - 12px),calc(100% - 12px) 100%,0 100%,0 12px);transition:background .15s}.login button:hover{background:#6dff92}@media(max-width:560px){.login{padding:32px 22px}}</style></head><body>
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
    /* İzlenen siteler tablo gövdesi — ilk yükleme ve AJAX güncellemesi aynı kaynaktan üretilir */
    function render_site_rows(array $cfg, array $states, array $last, string $csrf): string {
        ob_start();
        $sites = $cfg['sites'] ?? [];
        if (!$sites): ?>
            <tr><td colspan="8" class="empty-state">Henüz izlenen site yok. Sağ üstten ilk hedefi ekleyin.</td></tr>
        <?php endif;
        foreach ($sites as $index => $site):
            $url = $site['url']; $state = $states[$url] ?? null; $check = $last[$url] ?? null; $expected = gb_host_of($site['expect'] ?? ''); $observed = $check['alt'] ?? ''; ?>
            <tr><td><div class="site-name"><?= h($site['name'] ?? gb_host_of($url)) ?></div><span class="site-url"><?= h($url) ?></span></td><td><span class="expect"><?= h($expected) ?></span></td><td><?= status_badge($state['status'] ?? '-') ?><span class="status-note">Googlebot HTML</span></td><td><?= status_badge($check['ustatus'] ?? '-') ?><span class="status-note">normal UA</span></td><td><div class="alt-cell"><span class="alt-expected">beklenen: <?= h($expected) ?></span><br><span class="alt-observed <?= ($observed && strpos(',' . $observed . ',', ',' . $expected . ',') === false) ? 'bad' : '' ?>">görülen: <?= h($observed ?: '-') ?></span></div></td><td class="time"><?= h(local_time($check['ts'] ?? null)) ?></td><td class="http"><?= h($check['http'] ?? '-') ?></td><td><div class="row-actions"><button type="button" class="btn btn-dark btn-edit" data-idx="<?= $index ?>" data-name="<?= h($site['name'] ?? '') ?>" data-url="<?= h($url) ?>" data-expect="<?= h($site['expect'] ?? '') ?>">Düzenle</button><form method="post" class="del-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="del_site"><input type="hidden" name="idx" value="<?= $index ?>"><button class="btn btn-danger">Sil</button></form></div></td></tr>
        <?php endforeach;
        return (string) ob_get_clean();
    }
    function job_file(string $id): string { return '/opt/gbwatch/data/jobs/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json'; }
    function save_job(array $job): void {
        $dir = '/opt/gbwatch/data/jobs';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $file = job_file($job['id']); $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $file);
    }
    $CFG = load_cfg($CFG_FILE);
    $message = (string) ($_SESSION['flash_m'] ?? ''); $error = (string) ($_SESSION['flash_e'] ?? '');
    unset($_SESSION['flash_m'], $_SESSION['flash_e']);

    if (isset($_GET['job'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($_GET['job'] === 'active') {
            $active = @json_decode((string) @file_get_contents('/opt/gbwatch/data/jobs/active.json'), true);
            echo json_encode(is_array($active) ? $active : ['status' => 'idle']);
        } else {
            $job = @json_decode((string) @file_get_contents(job_file((string) $_GET['job'])), true);
            echo json_encode(is_array($job) ? $job : ['status' => 'missing']);
        }
        exit;
    }

    if (isset($_GET['hist'])) {
        header('Content-Type: application/json; charset=utf-8');
        $histPer = 10;
        $histPageReq = max(1, (int) $_GET['hist']);
        try {
            gb_init_db($DB_FILE);
            $histTotalReq = (int) gb_db($DB_FILE)->query("SELECT COUNT(*) FROM checks")->fetchColumn();
            $histPagesReq = max(1, (int) ceil($histTotalReq / $histPer));
            $histPageReq = min($histPageReq, $histPagesReq);
            $histRows = gb_db($DB_FILE)->query("SELECT site,ts,status,http,alt,note,ustatus FROM checks ORDER BY id DESC LIMIT $histPer OFFSET " . (($histPageReq - 1) * $histPer))->fetchAll();
            $out = [];
            foreach ($histRows as $r) {
                $out[] = [
                    'ts' => local_time($r['ts']),
                    'site' => (string) $r['site'],
                    'status' => status_badge($r['status']),
                    'ustatus' => status_badge($r['ustatus'] ?? '-'),
                    'http' => (string) $r['http'],
                    'alt' => $r['alt'] !== '' ? (string) $r['alt'] : '-',
                    'note' => $r['note'] !== '' ? (string) $r['note'] : '-',
                ];
            }
            echo json_encode(['ok' => true, 'page' => $histPageReq, 'pages' => $histPagesReq, 'total' => $histTotalReq, 'per' => $histPer, 'rows' => $out]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'start_run') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Oturum doğrulaması geçersiz.']); exit;
        }
        $active = @json_decode((string) @file_get_contents('/opt/gbwatch/data/jobs/active.json'), true);
        if (is_array($active) && in_array($active['status'] ?? '', ['queued', 'running'], true)) {
            echo json_encode(['ok' => true, 'job_id' => $active['id'], 'existing' => true]); exit;
        }
        $id = bin2hex(random_bytes(12));
        save_job(['id' => $id, 'status' => 'queued', 'total' => count($CFG['sites'] ?? []), 'completed' => 0, 'current' => '', 'results' => [], 'started_at' => date('c'), 'updated_at' => date('c')]);
        @file_put_contents('/opt/gbwatch/data/jobs/active.json', json_encode(['id' => $id, 'status' => 'queued']), LOCK_EX);
        echo json_encode(['ok' => true, 'job_id' => $id]);
        exit;
    }

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
                /* her türlü girdiyi kabul et: şemalı/şemasız, //, www., boşluk, büyük harf, yol */
                $url = preg_replace('~\s+~', '', $url);
                $url = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', (string) $url);
                $url = ltrim((string) $url, '/');
                if ($url !== '') $url = 'https://' . $url;
                $expect = preg_replace('~\s+~', '', $expect);
                $expect = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', (string) $expect);
                $expect = trim((string) $expect, '/');
                $host = gb_host_of($url); $expectedHost = gb_host_of($expect);
                $duplicate = false;
                foreach (($CFG['sites'] ?? []) as $old) {
                    if (gb_url_key($old['url'] ?? '') === gb_url_key($url)) { $duplicate = true; break; }
                }
                if ($host === '') $error = 'İzlenecek URL anlaşılamadı. Örnek: site.com veya https://site.com/';
                elseif ($expectedHost === '') $error = 'Beklenen alternate domain anlaşılamadı. Örnek: milanbahis.cam';
                elseif ($duplicate) $error = 'Bu URL zaten izleniyor. Aynı URL ikinci kez eklenemez.';
                else {
                    $CFG['sites'] ??= [];
                    $CFG['sites'][] = ['name' => $name ?: $host, 'url' => $url, 'expect' => $expectedHost];
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'Site izleme listesine eklendi.';
                    else $error = 'Yapılandırma dosyası yazılamadı.';
                }
            } elseif ($action === 'edit_site') {
                $index = (int) ($_POST['idx'] ?? -1);
                if (!isset($CFG['sites'][$index])) {
                    $error = 'Düzenlenecek kayıt bulunamadı.';
                } else {
                    $url = trim((string) ($_POST['s_url'] ?? ''));
                    $expect = trim((string) ($_POST['s_expect'] ?? ''));
                    $name = trim((string) ($_POST['s_name'] ?? ''));
                    /* add_site ile aynı esnek normalizasyon */
                    $url = preg_replace('~\s+~', '', $url);
                    $url = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', (string) $url);
                    $url = ltrim((string) $url, '/');
                    if ($url !== '') $url = 'https://' . $url;
                    $expect = preg_replace('~\s+~', '', $expect);
                    $expect = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', (string) $expect);
                    $expect = trim((string) $expect, '/');
                    $host = gb_host_of($url); $expectedHost = gb_host_of($expect);
                    $duplicate = false;
                    foreach (($CFG['sites'] ?? []) as $i => $old) {
                        if ($i !== $index && gb_url_key($old['url'] ?? '') === gb_url_key($url)) { $duplicate = true; break; }
                    }
                    if ($host === '') $error = 'İzlenecek URL anlaşılamadı. Örnek: site.com veya https://site.com/';
                    elseif ($expectedHost === '') $error = 'Beklenen alternate domain anlaşılamadı. Örnek: milanbahis.cam';
                    elseif ($duplicate) $error = 'Bu URL başka bir kayıtta zaten izleniyor.';
                    else {
                        $CFG['sites'][$index] = ['name' => $name ?: $host, 'url' => $url, 'expect' => $expectedHost];
                        if (save_cfg($CFG, $CFG_FILE)) $message = 'İzleme güncellendi: ' . ($name ?: $host);
                        else $error = 'Yapılandırma dosyası yazılamadı.';
                    }
                }
            } elseif ($action === 'del_site') {
                $index = (int) ($_POST['idx'] ?? -1);
                if (isset($CFG['sites'][$index])) {
                    $removed = $CFG['sites'][$index]['name'] ?? $CFG['sites'][$index]['url'];
                    array_splice($CFG['sites'], $index, 1);
                    if (save_cfg($CFG, $CFG_FILE)) $message = 'İzleme kaldırıldı: ' . $removed;
                    else $error = 'Yapılandırma dosyası yazılamadı.';
                }
            }
        } catch (Throwable $e) { $error = 'Islem hatasi: ' . $e->getMessage(); }
    }

    /* JS'siz istem: post → yönlendir (PRG) ki yenileme formu tekrar göndermesin */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['ajax'])) {
        $_SESSION['flash_m'] = $message; $_SESSION['flash_e'] = $error;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
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

    /* AJAX form gönderimi: sayfa yenilemeden tablo + metrik güncellemesi */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $error === '',
            'message' => $message,
            'error' => $error,
            'tbody' => render_site_rows($CFG, $states, $last, $csrf),
            'siteCount' => $siteCount,
            'okCount' => $okCount,
            'attentionCount' => $attentionCount,
            'lastRun' => local_time($lastRun),
        ]);
        exit;
    }
}
catch (Throwable $fatal) {
    http_response_code(500);
    die('<meta charset="utf-8"><body style="font-family:monospace;padding:40px"><h2>Sistem hatasi</h2><pre>' . htmlspecialchars($fatal->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></body>');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="/assets/favicon.svg?v=2" type="image/svg+xml"><link rel="shortcut icon" href="/assets/favicon.svg?v=2" type="image/svg+xml">
<title>Invader Control</title>
<style>
:root{color-scheme:dark;--canvas:#020402;--paper:#050805;--paper2:#071007;--ink:#c9ffd6;--muted:#5f8a6a;--line:#12301c;--navy:#020402;--lime:#39ff6a;--red:#ff4d5e;--amber:#ffd166;--head-font:ui-monospace,SFMono-Regular,Menlo,monospace;--chamfer-lg:polygon(16px 0,100% 0,100% calc(100% - 16px),calc(100% - 16px) 100%,0 100%,0 16px);--chamfer-sm:polygon(8px 0,100% 0,100% calc(100% - 8px),calc(100% - 8px) 100%,0 100%,0 8px)}
*{box-sizing:border-box}body{margin:0;background:var(--canvas);background-image:linear-gradient(rgba(57,255,106,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(57,255,106,.05) 1px,transparent 1px);background-size:28px 28px;color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
button,input{font:inherit}.topbar{height:70px;padding:0 max(24px,calc((100% - 1180px)/2));display:flex;align-items:center;justify-content:space-between;background:rgba(2,4,2,.96);border-bottom:1px solid var(--line)}.brand{display:flex;align-items:center;gap:11px}.brand-mark-image{display:block;width:46px;height:46px;filter:drop-shadow(0 0 10px rgba(57,255,106,.4))}.brand-copy strong{display:block;font-size:17px;letter-spacing:2.1px;font-family:var(--head-font)}.brand-copy span{display:block;color:var(--muted);font-size:9px;letter-spacing:1.7px;margin-top:4px}.top-actions{display:flex;align-items:center;gap:18px}.top-actions form{margin:0}
.btn{border:0;padding:9px 13px;cursor:pointer;font-weight:650;white-space:nowrap;font-family:var(--head-font);font-size:12px;letter-spacing:.6px;clip-path:var(--chamfer-sm)}.btn-primary{background:var(--lime);color:#021108;text-transform:uppercase}.btn-primary:hover{background:#6dff92}.btn-primary:disabled{opacity:.55;cursor:default}.btn-dark{border:1px solid transparent;background:linear-gradient(#071007,#071007) padding-box,linear-gradient(135deg,rgba(57,255,106,.5),#12301c) border-box;color:#c9ffd6}.btn-quiet{border:1px solid transparent;background:linear-gradient(#071007,#071007) padding-box,linear-gradient(135deg,rgba(57,255,106,.5),#12301c) border-box;color:#8fc39c}.btn-danger{border:1px solid transparent;background:linear-gradient(#140505,#140505) padding-box,linear-gradient(135deg,rgba(255,77,94,.5),#5c1f27) border-box;color:var(--red);padding:6px 9px}
.wrap{max-width:1180px;margin:0 auto;padding:38px 24px 60px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:28px}.eyebrow,.section-no{color:var(--lime);font-size:10px;font-weight:750;letter-spacing:1.5px;text-transform:uppercase}.intro h1{font-size:30px;letter-spacing:-.8px;margin:6px 0 5px;font-weight:600;font-family:var(--head-font);text-transform:uppercase;text-shadow:0 0 18px rgba(57,255,106,.35)}.intro p{color:var(--muted);margin:0}.intro form{margin:0}
.summary{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;margin-bottom:30px;border:1px solid transparent;background:linear-gradient(var(--paper),var(--paper)) padding-box,linear-gradient(135deg,rgba(57,255,106,.45),#12301c 60%) border-box;clip-path:var(--chamfer-lg)}.metric{min-height:104px;padding:18px 22px;border-right:1px solid var(--line);display:flex;align-items:center;gap:16px}.metric:last-child{border-right:0}.metric-label{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.8px}.metric-value{display:block;margin-top:0;font-size:28px;font-weight:500;font-family:var(--head-font)}.metric-value.good{color:var(--lime);text-shadow:0 0 12px rgba(57,255,106,.4)}.metric-value.bad{color:var(--red);text-shadow:0 0 12px rgba(255,77,94,.4)}.metric-note{display:block;color:var(--muted);font-size:11px;margin-top:2px}.metric-side{display:flex;flex-direction:column;gap:3px;min-width:0}
.notice{padding:13px 16px;margin:-10px 0 22px;white-space:pre-wrap;border:1px solid transparent;background:linear-gradient(#160707,#160707) padding-box,linear-gradient(135deg,rgba(255,77,94,.45),#5c1f27) border-box;color:#ffb9c1;clip-path:polygon(10px 0,100% 0,100% calc(100% - 10px),calc(100% - 10px) 100%,0 100%,0 10px)}.notice.ok{background:linear-gradient(#071608,#071608) padding-box,linear-gradient(135deg,rgba(57,255,106,.4),#1d4d28) border-box;color:#a9f5c0}
.run-progress{margin:-10px 0 22px;padding:18px 20px;border:1px solid transparent;background:linear-gradient(var(--paper),var(--paper)) padding-box,linear-gradient(135deg,rgba(57,255,106,.45),#12301c 60%) border-box;clip-path:polygon(14px 0,100% 0,100% calc(100% - 14px),calc(100% - 14px) 100%,0 100%,0 14px)}.run-progress.error{background:linear-gradient(#140505,#140505) padding-box,linear-gradient(135deg,rgba(255,77,94,.5),#5c1f27) border-box}.rp-head{display:flex;justify-content:space-between;gap:16px;align-items:baseline}.rp-head strong{font:600 13px var(--head-font);letter-spacing:1.6px;text-transform:uppercase;color:var(--ink)}.rp-count{color:var(--lime);font:12px var(--head-font)}.rp-counters{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.rp-counter{background:#030a05;border:1px solid var(--line);padding:12px 14px;clip-path:var(--chamfer-sm)}.rp-counter small{display:block;color:var(--muted);font:9px var(--head-font);letter-spacing:1.2px;text-transform:uppercase}.rp-counter b{display:block;margin-top:6px;font:500 24px var(--head-font);color:var(--ink)}.rp-counter.ok b{color:var(--lime);text-shadow:0 0 12px rgba(57,255,106,.5)}.rp-counter.down b{color:var(--red);text-shadow:0 0 12px rgba(255,77,94,.5)}.rp-counter.warn b{color:var(--amber)}.rp-seg{display:flex;height:8px;margin-top:14px;gap:3px}.rp-seg i{display:block;height:100%;clip-path:polygon(3px 0,100% 0,100% calc(100% - 3px),calc(100% - 3px) 100%,0 100%,0 3px);transition:width .3s}.seg-ok{background:var(--lime)}.seg-down{background:var(--red)}.seg-warn{background:var(--amber)}.seg-left{background:#0a1a0e;border:1px solid var(--line)}.rp-foot{display:flex;justify-content:space-between;gap:16px;margin-top:12px;color:var(--ink);font:12px var(--head-font)}.rp-eta{color:var(--muted);font-size:11px}@keyframes rp-blink{0%,100%{opacity:1}50%{opacity:.25}}.pulse{animation:rp-blink 1.1s infinite}
.section{margin-top:30px}.section-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:13px}.section-title{display:flex;align-items:baseline;gap:11px}.section-title h2{font-size:17px;margin:0;font-weight:600;font-family:var(--head-font);letter-spacing:-.3px}.section-sub{color:var(--muted);font-size:12px;margin:4px 0 0}.section-head details{position:relative}.section-head summary{list-style:none;cursor:pointer}.section-head summary::-webkit-details-marker{display:none}.add-pop{position:absolute;right:0;top:42px;width:380px;padding:18px;z-index:5;border:1px solid transparent;background:linear-gradient(var(--paper2),var(--paper2)) padding-box,linear-gradient(135deg,rgba(57,255,106,.45),#12301c 60%) border-box;clip-path:var(--chamfer-lg)}.add-pop h3{font-size:13px;margin:0 0 14px}.field{display:block;margin-bottom:10px}.field span{display:block;color:var(--muted);font-size:11px;margin-bottom:5px}.field input{width:100%;padding:9px 10px;border:1px solid transparent;background:linear-gradient(#020602,#020602) padding-box,linear-gradient(135deg,#1c4226,#0e2415) border-box;clip-path:var(--chamfer-sm);color:var(--ink);outline:none}.field input:focus{box-shadow:0 0 14px rgba(57,255,106,.15)}.field .hint{display:block;color:var(--muted);font-size:10px;margin-top:4px;opacity:.85}.add-actions{display:flex;justify-content:flex-end;margin-top:14px;gap:8px}.row-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center}.row-actions form{margin:0}.edit-dialog{border:1px solid transparent;background:linear-gradient(var(--paper2),var(--paper2)) padding-box,linear-gradient(135deg,rgba(57,255,106,.45),#12301c 60%) border-box;clip-path:var(--chamfer-lg);padding:20px;width:min(420px,calc(100vw - 32px));color:var(--ink)}.edit-dialog::backdrop{background:rgba(0,0,0,.72)}.edit-dialog h3{font-size:13px;margin:0 0 14px;font-family:var(--head-font);text-transform:uppercase;letter-spacing:1px}.form-error{color:var(--red);font-size:12px;margin:0 0 10px;font-family:var(--head-font)}
.table-shell{border:1px solid transparent;background:linear-gradient(var(--paper),var(--paper)) padding-box,linear-gradient(135deg,rgba(57,255,106,.45),#12301c 60%) border-box;clip-path:var(--chamfer-lg);overflow-x:auto}.site-table{width:100%;border-collapse:collapse;min-width:850px}.site-table th{padding:12px 15px;text-align:left;background:var(--paper2);color:#4f7a5c;border-bottom:1px solid var(--line);font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:700}.site-table td{padding:16px 15px;border-bottom:1px solid #0e2415;vertical-align:middle}.site-table tr:last-child td{border-bottom:0}.site-table tbody tr:hover{background:#081408}.site-name{font-weight:680}.site-url{display:block;color:var(--muted);font-size:11px;margin-top:3px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.expect{font-family:var(--head-font);font-size:12px;color:#7fae8d}.status{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:760;letter-spacing:.45px;border:1px solid currentColor;padding:2px 7px;clip-path:polygon(5px 0,100% 0,100% calc(100% - 5px),calc(100% - 5px) 100%,0 100%,0 5px)}.status i{width:8px;height:8px;border-radius:50%;background:#98a5b2;box-shadow:0 0 8px currentColor}.status.ok{color:var(--lime)}.status.ok i{background:var(--lime)}.status.info{color:#7ab8ff}.status.info i{background:#7ab8ff}.status.down,.status.error{color:var(--red)}.status.down i,.status.error i{background:var(--red)}.status.warn{color:var(--amber)}.status.warn i{background:var(--amber)}.status.idle{color:var(--muted)}.status-note{display:block;margin-top:4px;color:var(--muted);font-size:11px}.alt-cell{font-family:var(--head-font);font-size:11px;line-height:1.7}.alt-expected{color:var(--muted)}.alt-observed{color:#a9d8b6}.alt-observed.bad{color:var(--red)}.time{font-family:var(--head-font);color:var(--muted);font-size:11px;white-space:nowrap}.http{font-family:var(--head-font);font-size:12px;color:var(--muted)}.empty-state{text-align:center;padding:36px;color:var(--muted)}
.history{margin-top:30px}.history summary{cursor:pointer;color:var(--muted);font-weight:650;font-size:12px;list-style:none}.history summary::-webkit-details-marker{display:none}.history summary:before{content:'+';display:inline-block;margin-right:8px;color:var(--lime);font-size:16px;vertical-align:-1px}.history[open] summary:before{content:'−'}.history-table{margin-top:13px}.history-table .site-url{max-width:220px}.history-table .note{color:var(--muted);font-size:11px;max-width:280px}.page-label{font-size:11px;color:var(--muted);font-family:var(--head-font)}.pagination{display:flex;align-items:center;justify-content:flex-end;gap:5px;margin-top:14px}.pagination a,.pagination span{min-width:30px;height:30px;padding:6px 9px;text-align:center;border:1px solid var(--line);color:var(--muted);text-decoration:none;font-size:12px;background:var(--paper);clip-path:polygon(5px 0,100% 0,100% calc(100% - 5px),calc(100% - 5px) 100%,0 100%,0 5px)}.pagination a:hover{border-color:var(--lime);color:var(--lime)}.pagination .current{background:var(--lime);border-color:var(--lime);color:#021108;font-weight:700}.pagination .ellipsis{border-color:transparent;background:transparent;min-width:16px;clip-path:none}.footer-note{text-align:left;border-top:1px solid var(--line);padding-top:14px;color:var(--muted);font-size:11px;margin-top:22px}
@media(max-width:850px){.summary{grid-template-columns:1fr 1fr}.metric:nth-child(2){border-right:0}.metric:nth-child(-n+2){border-bottom:1px solid var(--line)}.intro{align-items:flex-start;flex-direction:column}.intro form{width:100%}.intro form .btn{width:100%}.rp-counters{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.topbar{height:64px;padding:0 16px}.brand-mark-image{width:36px;height:36px}.brand-copy strong{font-size:13px;letter-spacing:1.6px}.brand-copy span{font-size:8px}.wrap{padding:30px 14px 42px}.intro h1{font-size:26px}.intro p{max-width:330px}.summary{grid-template-columns:1fr 1fr}.metric{padding:15px 14px;min-height:91px}.metric-value{font-size:23px}.metric-note{font-size:10px}.section-title h2{font-size:16px}.add-pop{position:fixed;right:14px;left:14px;top:115px;width:auto}.monitor-shell{overflow:visible}.monitor-table,.monitor-table tbody{display:block;min-width:0}.monitor-table thead{display:none}.monitor-table tr{display:block;position:relative;padding:10px 0;border-bottom:1px solid var(--line)}.monitor-table tr:last-child{border-bottom:0}.monitor-table td{display:flex;justify-content:space-between;gap:16px;padding:8px 15px;border:0;text-align:right}.monitor-table td:first-child{display:block;text-align:left;padding-right:65px;padding-bottom:12px}.monitor-table td:last-child{position:absolute;right:14px;top:16px;display:block;padding:0}.monitor-table td:nth-child(2):before{content:'Beklenen';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(3):before{content:'Googlebot';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(4):before{content:'Kullanıcı';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(5):before{content:'Alternate';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(6):before{content:'Son kontrol';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(7):before{content:'HTTP';color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px}.monitor-table td:nth-child(5) .alt-cell{text-align:right}.pagination{justify-content:center}.history-table{overflow-x:auto}.footer-note{font-size:10px}}
</style>
</head>
<body>
<header class="topbar">
  <div class="brand"><img class="brand-mark-image" src="/assets/invader-control-mark.svg" alt=""><div class="brand-copy"><strong>INVADER CONTROL</strong><span>GOOGLEBOT VIEW MONITOR</span></div></div>
  <div class="top-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn btn-quiet" name="logout" value="1">Çıkış</button></form></div>
</header>
<main class="wrap">
  <section class="intro"><div><span class="eyebrow">01 / Genel bakış</span><h1>Kontrol merkezi</h1><p>Googlebot görünümü ile normal kullanıcı görünümünü aynı ölçümde izleyin.</p></div><form method="post" id="run-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="start_run"><button class="btn btn-primary" id="run-button" type="submit">Tüm siteleri kontrol et</button></form></section>
  <div class="run-progress" id="run-progress" hidden><div class="rp-head"><strong><span class="pulse">●</span> <span id="run-progress-title">Kontrol hazırlanıyor</span></strong><span class="rp-count" id="run-progress-count">0 / 0</span></div><div class="rp-counters"><div class="rp-counter"><small>Tamamlanan</small><b id="run-count-done">0</b></div><div class="rp-counter ok"><small>OK</small><b id="run-count-ok">0</b></div><div class="rp-counter down"><small>Düşük</small><b id="run-count-down">0</b></div><div class="rp-counter warn"><small>Engelli</small><b id="run-count-warn">0</b></div></div><div class="rp-seg"><i class="seg-ok" id="run-seg-ok" style="width:0%"></i><i class="seg-down" id="run-seg-down" style="width:0%"></i><i class="seg-warn" id="run-seg-warn" style="width:0%"></i><i class="seg-left" id="run-seg-left" style="width:100%"></i></div><div class="rp-foot"><span id="run-progress-current">Sunucu işi başlatıyor...</span><span class="rp-eta" id="run-progress-eta"></span></div></div>

  <div id="flash-area"><?php if ($message): ?><div class="notice ok"><?= h($message) ?></div><?php endif; ?><?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?></div>
  <?php if ($duplicateUrls): ?><div class="notice">Aynı URL birden fazla kez kayıtlı. Tekrarlanan kaydı silmeden sonuçlar birbirini ezebilir.</div><?php endif; ?>

  <section class="summary">
    <div class="metric"><strong class="metric-value"><?= $siteCount ?></strong><div class="metric-side"><span class="metric-label">İzlenen site</span><span class="metric-note">aktif hedef</span></div></div>
    <div class="metric"><strong class="metric-value good"><?= $okCount ?></strong><div class="metric-side"><span class="metric-label">Googlebot OK</span><span class="metric-note">beklenen alternate bulundu</span></div></div>
    <div class="metric"><strong class="metric-value <?= $attentionCount ? 'bad' : '' ?>"><?= $attentionCount ?></strong><div class="metric-side"><span class="metric-label">Dikkat gerekli</span><span class="metric-note">son ölçüme göre</span></div></div>
    <div class="metric"><strong class="metric-value" style="font-size:17px"><?= h(local_time($lastRun)) ?></strong><div class="metric-side"><span class="metric-label">Son ölçüm</span></div></div>
  </section>

  <section class="section">
    <div class="section-head"><div><div class="section-title"><span class="section-no">02</span><h2>İzlenen siteler</h2></div><p class="section-sub">Googlebot kodundaki alternate domain ile beklenen hedefi karşılaştırır.</p></div><details id="add-details"><summary class="btn btn-dark">+ Site ekle</summary><div class="add-pop"><h3>Yeni izleme hedefi</h3><form method="post" id="add-site-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="add_site"><label class="field"><span>İzlenecek URL</span><input name="s_url" placeholder="site.com" required><small class="hint">http://, https://, www. fark etmez — olduğu gibi yapıştırın.</small></label><label class="field"><span>Beklenen alternate domain</span><input name="s_expect" placeholder="milanbahis.cam" required><small class="hint">Sadece domain yeterli; link yapıştırırsanız domaini ayıklarız.</small></label><label class="field"><span>Panel adı <small>(opsiyonel)</small></span><input name="s_name" placeholder="site adı"></label><div class="add-actions"><button class="btn btn-primary">İzlemeye al</button></div></form></div></details></div>
    <div class="table-shell monitor-shell"><table class="site-table monitor-table"><thead><tr><th>Site</th><th>Beklenen alternate</th><th>Googlebot</th><th>Kullanıcı</th><th>Görülen alternate</th><th>Son kontrol</th><th>HTTP</th><th></th></tr></thead><tbody id="site-rows"><?= render_site_rows($CFG, $states, $last, $csrf) ?></tbody></table></div>
    <nav class="pagination site-pagination" id="site-pagination" aria-label="İzlenen siteler sayfaları"></nav>
  </section>

  <section class="history" id="history"><div class="section-head"><div><div class="section-title"><span class="section-no">03</span><h2>Kontrol geçmişi</h2></div><p class="section-sub">Her sayfada <?= $historyPerPage ?> kayıt gösteriliyor · toplam <?= $historyTotal ?> kayıt</p></div><span class="page-label" id="history-label">Sayfa <?= $historyPage ?> / <?= $historyPages ?></span></div><div class="table-shell history-table"><table class="site-table"><thead><tr><th>Zaman</th><th>Site</th><th>Googlebot</th><th>Kullanıcı</th><th>HTTP</th><th>Alternate</th><th>Not</th></tr></thead><tbody id="history-rows"><?php if (!$history): ?><tr><td colspan="7" class="empty-state">Henüz kontrol kaydı yok.</td></tr><?php endif; ?><?php foreach ($history as $row): ?><tr><td class="time"><?= h(local_time($row['ts'])) ?></td><td><span class="site-url"><?= h($row['site']) ?></span></td><td><?= status_badge($row['status']) ?></td><td><?= status_badge($row['ustatus'] ?? '-') ?></td><td class="http"><?= h($row['http']) ?></td><td class="expect"><?= h($row['alt'] ?: '-') ?></td><td class="note"><?= h($row['note'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div><nav class="pagination" aria-label="Kontrol geçmişi sayfaları"><?php if ($historyPage > 1): ?><a href="?page=<?= $historyPage - 1 ?>#history">Önceki</a><?php endif; ?><?php for ($p = 1; $p <= $historyPages; $p++): if ($p === $historyPage): ?><span class="current"><?= $p ?></span><?php elseif ($p <= 3 || $p > $historyPages - 2 || abs($p - $historyPage) <= 1): ?><a href="?page=<?= $p ?>#history"><?= $p ?></a><?php elseif ($p === 4 || $p === $historyPages - 2): ?><span class="ellipsis">…</span><?php endif; endfor; ?><?php if ($historyPage < $historyPages): ?><a href="?page=<?= $historyPage + 1 ?>#history">Sonraki</a><?php endif; ?></nav></section>
  <dialog class="edit-dialog" id="edit-dialog"><form method="post" id="edit-site-form"><h3>İzlemeyi düzenle</h3><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="edit_site"><input type="hidden" name="idx" id="edit-idx"><label class="field"><span>İzlenecek URL</span><input name="s_url" id="edit-url" placeholder="site.com" required><small class="hint">http://, https://, www. fark etmez — olduğu gibi yapıştırın.</small></label><label class="field"><span>Beklenen alternate domain</span><input name="s_expect" id="edit-expect" placeholder="milanbahis.cam" required><small class="hint">Sadece domain yeterli; link yapıştırırsanız domaini ayıklarız.</small></label><label class="field"><span>Panel adı <small>(opsiyonel)</small></span><input name="s_name" id="edit-name" placeholder="site adı"></label><div class="add-actions"><button type="button" class="btn btn-quiet" id="edit-cancel">Vazgeç</button><button class="btn btn-primary">Kaydet</button></div></form></dialog>
  <div class="footer-note">Son ölçüm zamanları Europe/Istanbul · HTTP kontrolü Googlebot User-Agent ile yapılır</div>
</main>
<script>
(function(){
  const form=document.getElementById('run-form'), button=document.getElementById('run-button'), box=document.getElementById('run-progress'),
    title=document.getElementById('run-progress-title'), count=document.getElementById('run-progress-count'),
    cDone=document.getElementById('run-count-done'), cOk=document.getElementById('run-count-ok'), cDown=document.getElementById('run-count-down'), cWarn=document.getElementById('run-count-warn'),
    sOk=document.getElementById('run-seg-ok'), sDown=document.getElementById('run-seg-down'), sWarn=document.getElementById('run-seg-warn'), sLeft=document.getElementById('run-seg-left'),
    current=document.getElementById('run-progress-current'), eta=document.getElementById('run-progress-eta');
  if(!form) return;
  function resetButton(){button.disabled=false;button.textContent='Tüm siteleri kontrol et';}
  function setSeg(el,v){el.style.width=v+'%';}
  function showError(text){box.hidden=false;box.classList.add('error');title.textContent='Kontrol başlatılamadı';count.textContent='';current.textContent=text;eta.textContent='';resetButton();}
  function showJob(job){
    box.hidden=false; box.classList.remove('error');
    const total=Number(job.total||0), done=Number(job.completed||0);
    const results=Array.isArray(job.results)?job.results:[];
    let ok=0,down=0,warn=0;
    results.forEach(function(r){const s=r.status||'';if(s==='OK')ok++;else if(s==='BLOCKED')warn++;else down++;});
    title.textContent=job.status==='completed' ? 'Kontrol tamamlandı' : (job.status==='queued' ? 'Kontrol sıraya alındı' : 'Kontrol çalışıyor');
    count.textContent=done+' / '+total;
    cDone.textContent=done; cOk.textContent=ok; cDown.textContent=down; cWarn.textContent=warn;
    const base=total||1;
    setSeg(sOk,ok*100/base); setSeg(sDown,down*100/base); setSeg(sWarn,warn*100/base); setSeg(sLeft,Math.max(0,(total-done)*100/base));
    if(job.status==='completed'){current.textContent='Tüm sitelerin sonuçları işlendi. Telegram kanıtları gönderildi.';eta.textContent='';resetButton();setTimeout(function(){location.reload()},900);return;}
    current.textContent=job.current ? '▸ '+job.current+' kontrol ediliyor...' : 'Kontrol hazırlanıyor...';
    if(done>0 && total>done && job.started_at){const t0=Date.parse(job.started_at);if(!isNaN(t0)){eta.textContent='~'+Math.round((Date.now()-t0)/1000/done*(total-done))+' sn kaldı';}}else eta.textContent='';
    button.disabled=true;button.textContent='Kontrol çalışıyor...';
    setTimeout(function(){poll(job.id)},1000);
  }
  async function poll(id){try{const response=await fetch('?job='+encodeURIComponent(id),{cache:'no-store'});const job=await response.json();showJob(job);}catch(e){showError('İlerleme bilgisi alınamadı. Sunucu işi çalışmaya devam ediyor olabilir.');}}
  form.addEventListener('submit',async function(event){event.preventDefault();button.disabled=true;button.textContent='Kontrol başlatılıyor...';box.hidden=false;box.classList.remove('error');title.textContent='Kontrol başlatılıyor';count.textContent='';current.textContent='Sunucu işi hazırlanıyor...';eta.textContent='';cDone.textContent='0';cOk.textContent='0';cDown.textContent='0';cWarn.textContent='0';setSeg(sOk,0);setSeg(sDown,0);setSeg(sWarn,0);setSeg(sLeft,100);try{const response=await fetch(location.pathname,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'}});const data=await response.json();if(!data.ok) throw new Error(data.error||'Bilinmeyen hata');poll(data.job_id);}catch(e){showError(e.message);}});
  fetch('?job=active',{cache:'no-store'}).then(r=>r.json()).then(job=>{if(job&&job.id&&job.status==='running') poll(job.id);}).catch(function(){});
})();
</script>
<script>
(function(){
  const dialog=document.getElementById('edit-dialog');
  const siteRows=document.getElementById('site-rows');
  const siteNav=document.getElementById('site-pagination');
  const flashArea=document.getElementById('flash-area');
  const metricValues=document.querySelectorAll('.summary .metric-value');
  const SITE_PER_PAGE=10;
  let siteAll=[];
  let sitePage=parseInt((location.search.match(/[?&]spage=(\d+)/)||[])[1]||'1',10)||1;

  function showFlash(text, ok){
    if(!flashArea) return;
    flashArea.innerHTML=text ? '<div class="notice'+(ok?' ok':'')+'"></div>' : '';
    if(text) flashArea.firstChild.textContent=text;
  }
  function formError(form, text){
    let ef=form.querySelector('.form-error');
    if(!ef){ef=document.createElement('div');ef.className='form-error';form.insertBefore(ef,form.firstChild);}
    ef.textContent=text;
  }
  function siteNavHtml(page,pages){
    let h='';
    if(page>1) h+='<a href="#" data-sp="'+(page-1)+'">Önceki</a>';
    for(let p=1;p<=pages;p++){
      if(p===page) h+='<span class="current">'+p+'</span>';
      else if(p<=3||p>pages-2||Math.abs(p-page)<=1) h+='<a href="#" data-sp="'+p+'">'+p+'</a>';
      else if(p===4||p===pages-2) h+='<span class="ellipsis">…</span>';
    }
    if(page<pages) h+='<a href="#" data-sp="'+(page+1)+'">Sonraki</a>';
    return h;
  }
  function renderSites(){
    const empty=siteAll.length===1 && siteAll[0].querySelector('.empty-state');
    const pages=empty?1:Math.max(1,Math.ceil(siteAll.length/SITE_PER_PAGE));
    sitePage=Math.min(Math.max(1,sitePage),pages);
    siteRows.innerHTML='';
    (empty?siteAll:siteAll.slice((sitePage-1)*SITE_PER_PAGE, sitePage*SITE_PER_PAGE)).forEach(function(r){siteRows.appendChild(r);});
    if(siteNav) siteNav.innerHTML=pages>1?siteNavHtml(sitePage,pages):'';
    try{
      const u=new URL(location.href);
      if(sitePage>1) u.searchParams.set('spage',sitePage); else u.searchParams.delete('spage');
      history.replaceState(null,'',u);
    }catch(e){}
  }
  function setSiteRows(tbodyHtml){
    const tpl=document.createElement('template');
    tpl.innerHTML='<table><tbody>'+tbodyHtml+'</tbody></table>';
    siteAll=Array.prototype.slice.call(tpl.content.querySelector('tbody').children);
    renderSites();
  }
  async function sendForm(form, jumpLast){
    const fd=new FormData(form); fd.append('ajax','1');
    let d=null;
    try{const r=await fetch(location.pathname,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}); d=await r.json();}
    catch(e){ d={ok:false,error:'İstek gönderilemedi. Bağlantıyı kontrol edin.'}; }
    if(d.ok){
      showFlash(d.message,true);
      if(siteRows && d.tbody!==undefined){ if(jumpLast) sitePage=1e9; setSiteRows(d.tbody); }
      if(metricValues.length>=4){
        metricValues[0].textContent=d.siteCount;
        metricValues[1].textContent=d.okCount;
        metricValues[2].textContent=d.attentionCount;
        metricValues[2].classList.toggle('bad', Number(d.attentionCount)>0);
        metricValues[3].textContent=d.lastRun;
      }
    } else if(form.classList.contains('del-form')) showFlash(d.error,false);
    else formError(form, d.error||'İşlem başarısız.');
    return d;
  }
  ['add-site-form','edit-site-form'].forEach(function(id){
    const f=document.getElementById(id);
    if(!f) return;
    f.addEventListener('submit',async function(e){
      e.preventDefault();
      const d=await sendForm(f, id==='add-site-form');
      if(d && d.ok){
        if(id==='edit-site-form' && dialog) dialog.close();
        if(id==='add-site-form'){f.reset();const det=document.getElementById('add-details');if(det) det.removeAttribute('open');}
      }
    });
  });
  if(siteNav){
    siteNav.addEventListener('click',function(e){
      const a=e.target.closest('a[data-sp]');
      if(!a) return;
      e.preventDefault();
      sitePage=parseInt(a.getAttribute('data-sp'),10)||1;
      renderSites();
    });
  }
  if(siteRows){
    siteRows.addEventListener('submit',function(e){
      const f=e.target;
      if(f && f.classList && f.classList.contains('del-form')){
        e.preventDefault();
        if(confirm('Bu izlemeyi kaldıralım mı?')) sendForm(f,false);
      }
    });
    siteRows.addEventListener('click',function(e){
      const b=e.target.closest('.btn-edit');
      if(!b || !dialog) return;
      document.getElementById('edit-idx').value=b.dataset.idx||'';
      document.getElementById('edit-url').value=b.dataset.url||'';
      document.getElementById('edit-expect').value=b.dataset.expect||'';
      document.getElementById('edit-name').value=b.dataset.name||'';
      dialog.showModal();
    });
    setSiteRows(siteRows.innerHTML);
  }
  if(dialog){
    document.getElementById('edit-cancel').addEventListener('click',function(){dialog.close();});
    dialog.addEventListener('click',function(e){if(e.target===dialog) dialog.close();});
  }
})();
</script>
<script>
(function(){
  const section=document.getElementById('history');
  if(!section) return;
  const tbody=document.getElementById('history-rows'), nav=section.querySelector('.pagination'), label=document.getElementById('history-label'), sub=section.querySelector('.section-sub');
  function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function renderNav(page,pages){
    let h='';
    if(page>1) h+='<a href="?page='+(page-1)+'#history">Önceki</a>';
    for(let p=1;p<=pages;p++){
      if(p===page) h+='<span class="current">'+p+'</span>';
      else if(p<=3||p>pages-2||Math.abs(p-page)<=1) h+='<a href="?page='+p+'#history">'+p+'</a>';
      else if(p===4||p===pages-2) h+='<span class="ellipsis">…</span>';
    }
    if(page<pages) h+='<a href="?page='+(page+1)+'#history">Sonraki</a>';
    return h;
  }
  async function go(page){
    try{
      const r=await fetch('?hist='+page,{cache:'no-store'});
      const d=await r.json();
      if(!d.ok) throw new Error(d.error||'hata');
      tbody.innerHTML=d.rows.map(function(row){
        return '<tr><td class="time">'+esc(row.ts)+'</td><td><span class="site-url">'+esc(row.site)+'</span></td><td>'+row.status+'</td><td>'+row.ustatus+'</td><td class="http">'+esc(row.http)+'</td><td class="expect">'+esc(row.alt)+'</td><td class="note">'+esc(row.note)+'</td></tr>';
      }).join('') || '<tr><td colspan="7" class="empty-state">Henüz kontrol kaydı yok.</td></tr>';
      nav.innerHTML=renderNav(d.page,d.pages);
      label.textContent='Sayfa '+d.page+' / '+d.pages;
      if(sub) sub.textContent='Her sayfada '+d.per+' kayıt gösteriliyor · toplam '+d.total+' kayıt';
      history.replaceState(null,'','?page='+d.page+'#history');
    }catch(e){ location.href='?page='+page+'#history'; }
  }
  nav.addEventListener('click',function(e){
    const a=e.target.closest('a');
    if(!a) return;
    const m=(a.getAttribute('href')||'').match(/[?&]page=(\d+)/);
    if(!m) return;
    e.preventDefault();
    go(parseInt(m[1],10));
  });
})();
</script>
</body></html>
