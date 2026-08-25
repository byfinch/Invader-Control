<?php
$BASE = '/opt/gbwatch';
$DB_FILE = "$BASE/data/gbwatch.db";
$GB_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

$cfgRaw = @file_get_contents("$BASE/config.json");
if ($cfgRaw === false) { fwrite(STDERR, "config.json yok\n"); exit(1); }
$CFG = json_decode($cfgRaw, true);
if (!is_array($CFG)) { fwrite(STDERR, "config.json bozuk\n"); exit(1); }

function db(): PDO {
    global $DB_FILE;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function init_db(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS checks(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        ts TEXT DEFAULT (datetime('now')),
        status TEXT NOT NULL,
        http INTEGER,
        size INTEGER,
        alt TEXT,
        note TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_checks_site ON checks(site, id)");
    db()->exec("CREATE TABLE IF NOT EXISTS state(
        site TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        since TEXT DEFAULT (datetime('now')),
        blocked_streak INTEGER DEFAULT 0
    )");
}

function fetch_url(string $url): array {
    global $GB_UA;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT => $GB_UA,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return [0, '', '', $err];
    return [$code, substr($raw, 0, $hs), substr($raw, $hs), $err];
}

function host_of(string $href): string {
    $h = strtolower((string) parse_url($href, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $h);
}

function extract_alternates(string $body): array {
    $alts = [];
    if (preg_match_all('/<link\b[^>]*rel=["\']alternate["\'][^>]*>/i', $body, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $h)) {
                $host = host_of($h[1]);
                if ($host !== '') $alts[] = $host;
            }
        }
    }
    return array_values(array_unique($alts));
}

function is_challenge(int $code, string $hdr, string $body): bool {
    if ($code === 403) return true;
    if (stripos($hdr, 'cf-mitigated: challenge') !== false) return true;
    foreach (['just a moment', 'checking your browser', 'attention required', 'cf-browser-verification'] as $needle) {
        if (stripos($body, $needle) !== false) return true;
    }
    return false;
}

function check_site(array $s): array {
    [$code, $hdr, $body, $err] = fetch_url($s['url']);
    if ($code === 0) {
        return ['status' => 'ERROR', 'http' => 0, 'size' => 0, 'alt' => [], 'note' => $err];
    }
    if (is_challenge($code, $hdr, $body)) {
        return ['status' => 'BLOCKED', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => 'bot korumasi'];
    }
    $alts = extract_alternates($body);
    $expect = strtolower((string)($s['expect'] ?? ''));
    $expect = preg_replace('/^www\./', '', $expect);
    if ($expect !== '' && in_array($expect, $alts, true)) {
        return ['status' => 'OK', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => ''];
    }
    if (!$alts) {
        $hasHtml = stripos($body, '<body') !== false || stripos($body, '<title') !== false;
        $note = $hasHtml ? 'alternate link yok' : 'HTML donmedi';
        return ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => $note];
    }
    return ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => 'alternate: ' . implode(', ', $alts)];
}

function tg_send(string $msg): void {
    global $CFG;
    $tk = $CFG['telegram']['token'] ?? '';
    $cid = $CFG['telegram']['chat_id'] ?? '';
    if ($tk === '' || $cid === '') return;
    $ch = curl_init("https://api.telegram.org/bot$tk/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $cid, 'text' => $msg, 'parse_mode' => 'HTML']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function emoji(string $st): string {
    return ['OK' => "\u{2705}", 'DOWN' => "\u{1F534}", 'BLOCKED' => "\u{26A0}\u{FE0F}", 'ERROR' => "\u{2753}"][$st] ?? "\u{2753}";
}

function process_site(array $s): void {
    $site = $s['url'];
    $name = $s['name'] ?? $site;
    $r = check_site($s);

    db()->prepare("INSERT INTO checks(site,status,http,size,alt,note) VALUES(?,?,?,?,?,?)")
        ->execute([$site, $r['status'], $r['http'], $r['size'], implode(',', $r['alt']), $r['note']]);

    $prev = db()->prepare("SELECT status, blocked_streak FROM state WHERE site=?");
    $prev->execute([$site]);
    $row = $prev->fetch();
    $oldSt = $row['status'] ?? null;
    $streak = (int)($row['blocked_streak'] ?? 0);

    $alert = false;
    if ($oldSt === null) {
        db()->prepare("INSERT INTO state(site,status) VALUES(?,?)")->execute([$site, $r['status']]);
    } elseif ($oldSt !== $r['status']) {
        if ($r['status'] === 'BLOCKED') {
            $streak++;
            if ($streak >= 3) {
                $alert = true;
                $streak = 0;
            }
            db()->prepare("UPDATE state SET status=?, blocked_streak=?, since=datetime('now') WHERE site=?")
                ->execute([$r['status'], $streak, $site]);
        } else {
            $alert = true;
            db()->prepare("UPDATE state SET status=?, blocked_streak=0, since=datetime('now') WHERE site=?")
                ->execute([$r['status'], $site]);
        }
    }

    if ($alert) {
        $lines = [
            emoji($r['status']) . " <b>{$name}</b>",
            "Durum degisti: " . emoji($oldSt) . " $oldSt -> " . emoji($r['status']) . " {$r['status']}",
            "HTTP {$r['http']} | alt: " . ($r['alt'] ? implode(', ', $r['alt']) : '-'),
        ];
        if ($r['note'] !== '') $lines[] = "Not: {$r['note']}";
        tg_send(implode("\n", $lines));
    }

    printf("%-12s %s | HTTP %d | alt=%s | %s\n", $r['status'], $site, $r['http'], $r['alt'] ? implode(',', $r['alt']) : '-', $r['note']);
}

function run_all(): void {
    global $CFG;
    init_db();
    foreach ($CFG['sites'] as $s) process_site($s);
}

function test_one(string $url): void {
    $r = check_site(['url' => $url, 'expect' => '']);
    print_r($r);
}

$cmd = $argv[1] ?? 'run';
if ($cmd === 'run') run_all();
elseif ($cmd === 'test' && isset($argv[2])) test_one($argv[2]);
else fwrite(STDERR, "kullanim: php monitor.php run | php monitor.php test <url>\n");
