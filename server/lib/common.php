<?php
/* GBWatch ortak kütüphane — motor ve panel birlikte kullanır */

function gb_db(string $dbFile): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function gb_init_db(string $dbFile): void {
    gb_db($dbFile)->exec("CREATE TABLE IF NOT EXISTS checks(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site TEXT NOT NULL,
        ts TEXT DEFAULT (datetime('now')),
        status TEXT NOT NULL,
        http INTEGER, size INTEGER, alt TEXT, note TEXT)");
    gb_db($dbFile)->exec("CREATE INDEX IF NOT EXISTS idx_checks_site ON checks(site, id)");
    gb_db($dbFile)->exec("CREATE TABLE IF NOT EXISTS state(
        site TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        since TEXT DEFAULT (datetime('now')),
        blocked_streak INTEGER DEFAULT 0,
        user_status TEXT DEFAULT '-')");
    $stateCols = gb_db($dbFile)->query("PRAGMA table_info(state)")->fetchAll();
    $stateNames = array_column($stateCols, 'name');
    if (!in_array('user_status', $stateNames, true)) gb_db($dbFile)->exec("ALTER TABLE state ADD COLUMN user_status TEXT DEFAULT '-'");
    /* migration: eski tabloya ustatus/usize ekle */
    $cols = gb_db($dbFile)->query("PRAGMA table_info(checks)")->fetchAll();
    $names = array_column($cols, 'name');
    if (!in_array('ustatus', $names, true)) gb_db($dbFile)->exec("ALTER TABLE checks ADD COLUMN ustatus TEXT DEFAULT '-'");
    if (!in_array('usize', $names, true)) gb_db($dbFile)->exec("ALTER TABLE checks ADD COLUMN usize INTEGER DEFAULT 0");
}

function gb_fetch(string $url, string $ua, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return [0, '', '', $err];
    return [$code, substr($raw, 0, $hs), substr($raw, $hs), $err];
}

function gb_host_of(string $href): string {
    $href = trim($href);
    if ($href === '') return '';
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)) $href = 'https://' . $href;  /* çıplak domain destekle */
    $h = strtolower((string) parse_url($href, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $h);
}

function gb_url_key(string $url): string {
    $url = trim($url);
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) return strtolower($url);
    $scheme = strtolower($p['scheme'] ?? 'https');
    $host = strtolower($p['host']);
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = $p['path'] ?? '/';
    if ($path === '') $path = '/';
    $query = isset($p['query']) ? '?' . $p['query'] : '';
    return $scheme . '://' . $host . $port . $path . $query;
}

function gb_alternates(string $body): array {
    $alts = [];
    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($dom->getElementsByTagName('link') as $link) {
                $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
                if (in_array('alternate', $rels, true)) {
                    $host = gb_host_of($link->getAttribute('href'));
                    if ($host !== '') $alts[] = $host;
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$alts && preg_match_all('/<link\b[^>]*>/i', $body, $tags)) {
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*["\'][^"\']*\balternate\b[^"\']*["\']/i', $tag)) continue;
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) {
                $host = gb_host_of($href[1]);
                if ($host !== '') $alts[] = $host;
            }
        }
    }
    return array_values(array_unique($alts));
}

function gb_is_challenge(int $code, string $hdr, string $body): bool {
    if ($code === 403) return true;
    if (stripos($hdr, 'cf-mitigated: challenge') !== false) return true;
    foreach (['just a moment', 'checking your browser', 'attention required', 'cf-browser-verification'] as $n) {
        if (stripos($body, $n) !== false) return true;
    }
    return false;
}

/* Tek site kontrolü — hata fırlatmaz, her zaman sonuç dizisi döner */
function gb_check(array $site): array {
    $url = $site['url'];
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    try {
        [$code, $hdr, $body, $err] = gb_fetch($url, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
    } catch (Throwable $e) {
        return ['status' => 'ERROR', 'http' => 0, 'size' => 0, 'alt' => [], 'note' => $e->getMessage(), 'ustatus' => 'ERROR', 'usize' => 0];
    }
    if ($code === 0) return ['status' => 'ERROR', 'http' => 0, 'size' => 0, 'alt' => [], 'note' => $err, 'ustatus' => 'ERROR', 'usize' => 0];
    if (gb_is_challenge($code, $hdr, $body)) return ['status' => 'BLOCKED', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => 'bot korumasi', 'ustatus' => '-', 'usize' => 0];

    $alts = gb_alternates($body);
    $expect = gb_host_of((string) ($site['expect'] ?? ''));
    if ($expect === '') {
        $r = ['status' => 'OBSERVED', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => $alts ? '' : 'alternate link yok'];
    } elseif (in_array($expect, $alts, true)) {
        $r = ['status' => 'OK', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => ''];
    } elseif (!$alts) {
        $hasHtml = stripos($body, '<body') !== false || stripos($body, '<title') !== false;
        $r = ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => $hasHtml ? 'alternate link yok' : 'HTML donmedi'];
    } else {
        $r = ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => 'alternate: ' . implode(', ', $alts)];
    }

    /* normal kullanici gorunumu: ayri sinyal */
    $r['ustatus'] = 'OK'; $r['usize'] = 0;
    try {
        [$uc, $uh, $ub, $ue] = gb_fetch($url, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 12);
        $r['usize'] = strlen($ub);
        if ($uc === 0) $r['ustatus'] = 'ERROR';
        elseif (gb_is_challenge($uc, $uh, $ub)) $r['ustatus'] = 'BLOCKED';
        elseif ($uc >= 400) $r['ustatus'] = 'DOWN';
        elseif (strlen($ub) < 512) $r['ustatus'] = 'EMPTY';   /* 200 ama bos/kucuk govde */
    } catch (Throwable $e) { $r['ustatus'] = 'ERROR'; }
    return $r;
}

function gb_tg_send(array $tg, string $msg): bool {
    $tk = $tg['token'] ?? ''; $cid = $tg['chat_id'] ?? '';
    if ($tk === '' || $cid === '') return false;
    $ch = curl_init("https://api.telegram.org/bot$tk/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $cid, 'text' => $msg]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $r = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return ($r['ok'] ?? false) === true;
}

function gb_emoji(string $st): string {
    return ['OK' => "\u{2705}", 'DOWN' => "\u{1F534}", 'BLOCKED' => "\u{26A0}", 'ERROR' => "\u{2754}"][$st] ?? "\u{2754}";
}

/* Kontrol + DB kaydı + durum değişimi + telegram. Hata YUTULMAZ — çağıran try/catch yapsın */
function gb_process(array $site, array $cfg, string $dbFile): array {
    gb_init_db($dbFile);
    $url = $site['url'];
    $name = $site['name'] ?? $url;
    $r = gb_check($site);

    gb_db($dbFile)->prepare("INSERT INTO checks(site,status,http,size,alt,note,ustatus,usize) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$url, $r['status'], $r['http'], $r['size'], implode(',', $r['alt']), $r['note'], $r['ustatus'] ?? '-', $r['usize'] ?? 0]);

    $q = gb_db($dbFile)->prepare("SELECT status, user_status, blocked_streak FROM state WHERE site=?");
    $q->execute([$url]);
    $row = $q->fetch();
    $oldSt = $row['status'] ?? null;
    $oldUserSt = $row['user_status'] ?? null;
    $streak = (int) ($row['blocked_streak'] ?? 0);
    $alertMsg = null;
    $userSt = $r['ustatus'] ?? '-';
    $newStreak = $r['status'] === 'BLOCKED' ? $streak + 1 : 0;
    $gbChanged = $oldSt !== null && $oldSt !== $r['status'];
    $userChanged = $oldUserSt !== null && $oldUserSt !== $userSt;

    if ($oldSt === null) {
        gb_db($dbFile)->prepare("INSERT INTO state(site,status,user_status,blocked_streak) VALUES(?,?,?,?)")
            ->execute([$url, $r['status'], $userSt, $newStreak]);
    } else {
        if ($r['status'] === 'BLOCKED' && $newStreak >= 3 && $streak < 3) $alertMsg = "site:$name";
        elseif ($r['status'] !== 'BLOCKED' && ($gbChanged || $userChanged)) $alertMsg = "site:$name";
        elseif ($r['status'] === 'BLOCKED' && $userChanged) $alertMsg = "site:$name";

        $since = ($gbChanged || $userChanged) ? ", since=datetime('now')" : '';
        gb_db($dbFile)->prepare("UPDATE state SET status=?, user_status=?, blocked_streak=?$since WHERE site=?")
            ->execute([$r['status'], $userSt, $newStreak, $url]);
    }

    if ($alertMsg !== null) {
        gb_tg_send($cfg['telegram'] ?? [], gb_emoji($r['status']) . " " . $name .
            "\nGooglebot: " . ($oldSt ?? '-') . " -> " . $r['status'] .
            "\nKullanici: " . ($oldUserSt ?? '-') . " -> " . $userSt .
            "\nHTTP " . $r['http'] . " | alt: " . ($r['alt'] ? implode(', ', $r['alt']) : '-') .
            ($r['note'] !== '' ? "\nNot: " . $r['note'] : ''));
    }
    $r['alert'] = $alertMsg !== null;
    $r['name'] = $name;
    return $r;
}
