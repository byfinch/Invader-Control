<?php
/* GBWatch ortak kütüphane — motor ve panel birlikte kullanır */

function gb_db(string $dbFile): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        /* cron + panel aynı anda yazabilir: kilitte 5sn bekle, WAL ile okuma/yazma çakışmasın */
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
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
    if (!in_array('unote', $names, true)) gb_db($dbFile)->exec("ALTER TABLE checks ADD COLUMN unote TEXT DEFAULT ''");
}

/* Ağ katmanı: doğrudan / proxy / Google Apps Script relay
   $net = ['proxy' => '', 'relay' => '', 'relay_key' => ''] — boşsa doğrudan bağlanır */
function gb_net_fetch(string $url, string $ua, string $uaKind, int $timeout, array $net): array {
    $relay = (string) ($net['relay'] ?? '');
    if ($relay !== '') {
        $sep = strpos($relay, '?') === false ? '?' : '&';
        $rurl = $relay . $sep . 'u=' . urlencode($url) . '&ua=' . $uaKind . '&k=' . urlencode((string) ($net['relay_key'] ?? ''));
        $ch = curl_init($rurl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,   /* Apps Script önce googleusercontent'e 302 atar */
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout + 15,   /* relay'ın kendi fetch'i de süre yer */
            CURLOPT_CONNECTTIMEOUT => min($timeout, 20),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $cerr = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string) $raw, true);
        if (!is_array($j) || !($j['ok'] ?? false)) {
            return [0, '', '', $cerr !== '' ? $cerr : (is_array($j) ? (string) ($j['error'] ?? 'relay hatası') : 'relay yanıtı bozuk')];
        }
        $hdr = '';
        foreach (($j['headers'] ?? []) as $k => $v) $hdr .= $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v) . "\n";
        return [(int) ($j['code'] ?? 0), $hdr, (string) ($j['body'] ?? ''), ''];
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_USERAGENT => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 20),   /* kasıtlı yavaşlatan hedefler TLS'i 10sn üstüne taşıyabiliyor */
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $proxy = (string) ($net['proxy'] ?? '');
    if ($proxy !== '') $opts[CURLOPT_PROXY] = $proxy;   /* örn. socks5h://127.0.0.1:1080 veya http://user:pass@host:3128 */
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return [0, '', '', $err];
    return [$code, substr($raw, 0, $hs), substr($raw, $hs), $err];
}

/* eski imza korunuyor */
function gb_fetch(string $url, string $ua, int $timeout = 15, string $proxy = ''): array {
    return gb_net_fetch($url, $ua, 'user', $timeout, ['proxy' => $proxy]);
}

function gb_host_of(string $href): string {
    $href = trim($href);
    if ($href === '') return '';
    if ($href === '') return '';
    if (str_starts_with($href, '//')) $href = 'https:' . $href;   /* protokol-relative //host desteği — aksi halde parse_url bozulur */
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

const GB_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const USER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/* İki görünümü tek seferde paralel çek — site başına bekleme yarıya iner */
function gb_fetch_pair(string $url, int $timeout, array $net = []): array {
    $mk = function (string $ua, string $uaKind) use ($url, $timeout, $net) {
        $relay = (string) ($net['relay'] ?? '');
        if ($relay !== '') {
            $sep = strpos($relay, '?') === false ? '?' : '&';
            $ch = curl_init($relay . $sep . 'u=' . urlencode($url) . '&ua=' . $uaKind . '&k=' . urlencode((string) ($net['relay_key'] ?? '')));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout + 15,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 20),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            return [$ch, true];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_USERAGENT => $ua,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 20),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $proxy = (string) ($net['proxy'] ?? '');
        if ($proxy !== '') $opts[CURLOPT_PROXY] = $proxy;
        curl_setopt_array($ch, $opts);
        return [$ch, false];
    };
    [$a, $aRelay] = $mk(GB_UA, 'bot');
    [$b, $bRelay] = $mk(USER_UA, 'user');
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $a); curl_multi_add_handle($mh, $b);
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running);
    $read = function ($ch, bool $isRelay) {
        $raw = curl_multi_getcontent($ch);
        $err = curl_error($ch);
        if ($isRelay) {
            $j = json_decode((string) $raw, true);
            if (!is_array($j) || !($j['ok'] ?? false)) {
                return [0, '', '', $err !== '' ? $err : (is_array($j) ? (string) ($j['error'] ?? 'relay hatası') : 'relay yanıtı bozuk')];
            }
            $hdr = '';
            foreach (($j['headers'] ?? []) as $k => $v) $hdr .= $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v) . "\n";
            return [(int) ($j['code'] ?? 0), $hdr, (string) ($j['body'] ?? ''), ''];
        }
        $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false || $code === 0) return [0, '', '', $err !== '' ? $err : 'istek başarısız'];
        return [$code, substr($raw, 0, $hs), substr($raw, $hs), $err];
    };
    $ra = $read($a, $aRelay); $rb = $read($b, $bRelay);
    curl_multi_remove_handle($mh, $a); curl_multi_remove_handle($mh, $b);
    curl_multi_close($mh); curl_close($a); curl_close($b);
    return [$ra, $rb];
}

/* Tek site kontrolü — hata fırlatmaz, her zaman sonuç dizisi döner */
function gb_check(array $site, array $net = []): array {
    $url = $site['url'];
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    try {
        /* önce doğrudan/proxy — bazı cloaklar Google altyapısına gerçek sayfa gösterir */
        $direct = ['proxy' => (string) ($net['proxy'] ?? '')];
        [$bot, $usr] = gb_fetch_pair($url, 25, $direct);
        /* kasıtlı yavaşlatmaya bir şans daha — sadece düşen taraf tek başına tekrarlanır */
        if ($bot[0] === 0) { sleep(1); $bot = gb_net_fetch($url, GB_UA, 'bot', 25, $direct); }
        if ($usr[0] === 0) { sleep(1); $usr = gb_net_fetch($url, USER_UA, 'user', 20, $direct); }
        /* doğrudan hiç ulaşılamıyorsa veya bot engelliyorsa relay'e düş */
        $relay = (string) ($net['relay'] ?? '');
        $viaRelay = false;
        if ($relay !== '' && ($bot[0] === 0 || gb_is_challenge($bot[0], $bot[1], $bot[2]))) {
            [$botR, $usrR] = gb_fetch_pair($url, 25, $net);
            if ($botR[0] !== 0 && !gb_is_challenge($botR[0], $botR[1], $botR[2])) { $bot = $botR; $usr = $usrR; $viaRelay = true; }
        }
    } catch (Throwable $e) {
        return ['status' => 'ERROR', 'http' => 0, 'size' => 0, 'alt' => [], 'note' => $e->getMessage(), 'ustatus' => 'ERROR', 'usize' => 0, 'unote' => $e->getMessage()];
    }
    [$code, $hdr, $body, $err] = $bot;
    [$uc, $uh, $ub, $ue] = $usr;

    if ($code === 0) {
        $r = ['status' => 'ERROR', 'http' => 0, 'size' => 0, 'alt' => [], 'note' => $err];
    } elseif (gb_is_challenge($code, $hdr, $body)) {
        $r = ['status' => 'BLOCKED', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => 'bot korumasi'];
    } else {
        $alts = gb_alternates($body);
        $expect = gb_host_of((string) ($site['expect'] ?? ''));
        if ($expect === '') {
            $r = ['status' => 'OBSERVED', 'http' => $code, 'size' => strlen($body), 'alt' => $alts, 'note' => $alts ? '' : 'alternate link yok'];
        } else {
            $allAlts = $alts;
            /* rotasyonlu cloak: beklenen domain etikette ya da sayfanın herhangi bir yerinde geçebilir */
            $hit = in_array($expect, $allAlts, true) || stripos($body, $expect) !== false;
            for ($i = 0; $i < 2 && !$hit; $i++) {
                sleep(1);
                [$rc, $rh, $rb, $re] = $viaRelay
                    ? gb_net_fetch($url, GB_UA, 'bot', 25, $net)
                    : gb_net_fetch($url, GB_UA, 'bot', 25, $direct);
                if ($rc !== 0 && !gb_is_challenge($rc, $rh, $rb)) {
                    $allAlts = array_values(array_unique(array_merge($allAlts, gb_alternates($rb))));
                    if (in_array($expect, $allAlts, true) || stripos($rb, $expect) !== false) $hit = true;
                }
            }
            if ($hit) {
                $r = ['status' => 'OK', 'http' => $code, 'size' => strlen($body), 'alt' => $allAlts, 'note' => ''];
            } elseif (!$allAlts) {
                $hasHtml = stripos($body, '<body') !== false || stripos($body, '<title') !== false;
                $r = ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => [], 'note' => $hasHtml ? 'alternate link yok' : 'HTML donmedi'];
            } else {
                $r = ['status' => 'DOWN', 'http' => $code, 'size' => strlen($body), 'alt' => $allAlts, 'note' => 'alternate: ' . implode(', ', $allAlts)];
            }
            /* relay yedeğinden gelen "alternate yok/uymuyor" güvenilmez (zıt kutuplu cloak):
               DOWN yerine OBSERVED — yanıltıcı alarm üretme */
            if ($viaRelay && $r['status'] === 'DOWN') {
                $r['status'] = 'OBSERVED';
                $r['note'] = 'direct başarısız, relay ile: ' . $r['note'];
            }
        }
    }

    /* normal kullanıcı görünümü: ayrı sinyal + sebep notu (bot düşse bile çalışır) */
    $r['ustatus'] = 'OK'; $r['usize'] = strlen($ub); $r['unote'] = '';
    if ($uc === 0) { $r['ustatus'] = 'ERROR'; $r['unote'] = $ue; }
    elseif (gb_is_challenge($uc, $uh, $ub)) { $r['ustatus'] = 'BLOCKED'; $r['unote'] = 'bot korumasi'; }
    elseif ($uc >= 400) { $r['ustatus'] = 'DOWN'; $r['unote'] = 'HTTP ' . $uc; }
    elseif (strlen($ub) < 512) { $r['ustatus'] = 'EMPTY'; $r['unote'] = 'HTTP 200 ama gövde ' . strlen($ub) . ' bayt — boş/kısmi sayfa'; }
    return $r;
}

/* koşu kilidi şu an dolu mu (cron veya worker taraması sürüyor) */
function gb_run_locked(string $base): bool {
    $f = @fopen("$base/data/run.lock", 'r');   /* dosya root'a ait; panel kullanıcısı yazamaz, okumak yeterli */
    if ($f === false) return false;
    if (flock($f, LOCK_EX | LOCK_NB)) { flock($f, LOCK_UN); fclose($f); return false; }
    fclose($f);
    return true;
}

/* 7 günden eski kanıt dizinlerini sil — disk şişmesin */
function gb_evidence_cleanup(string $base, int $days = 7): void {
    $root = "$base/data/evidence";
    if (!is_dir($root)) return;
    $limit = time() - $days * 86400;
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $siteDir) {
        foreach (glob("$siteDir/*", GLOB_ONLYDIR) ?: [] as $runDir) {
            if (filemtime($runDir) < $limit) {
                foreach (glob("$runDir/*") ?: [] as $f) @unlink($f);
                @rmdir($runDir);
            }
        }
    }
}

/* kontrol geçmişi sınırsız büyümesin — varsayılan 90 gün */
function gb_db_cleanup(string $dbFile, int $days = 90): void {
    gb_db($dbFile)->exec("DELETE FROM checks WHERE ts < datetime('now', '-$days days')");
}

function gb_capture(string $url, string $id, string $proxy = '', string $relay = '', string $relayKey = ''): array {
    $ch = curl_init('http://127.0.0.1:6077/capture');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'id' => $id, 'proxy' => $proxy, 'relay' => $relay, 'relay_key' => $relayKey]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 130,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $result = json_decode((string) $raw, true);
    if (!is_array($result) || !($result['ok'] ?? false)) return ['ok' => false, 'error' => $err ?: ($result['error'] ?? 'capture failed')];
    return $result;
}

function gb_tg_send_evidence(array $tg, array $result, array $capture, ?string $chatId = null, string $prefix = ''): bool {
    $tk = $tg['token'] ?? ''; $cid = $chatId ?? ((string) ($tg['chat_id'] ?? ''));
    $file = $capture['combined']['path'] ?? '';
    if ($tk === '' || $cid === '' || !is_file($file)) return false;
    $caption = $prefix . gb_emoji($result['status']) . ' ' . $result['name'] .
        "\nSite: " . ($result['url'] ?? '-') .
        "\nGooglebot: " . $result['status'] . ' | Kullanıcı: ' . ($result['ustatus'] ?? '-') .
        "\nHTTP " . ($result['http'] ?? 0) . ' | Beklenen alt: ' . gb_host_of($result['expect'] ?? '') .
        "\nGörülen alt: " . ($result['alt'] ? implode(', ', $result['alt']) : '-') .
        ($result['note'] !== '' ? "\nNot: " . $result['note'] : '') .
        (($result['unote'] ?? '') !== '' ? "\nKullanıcı notu: " . $result['unote'] : '');
    $ch = curl_init("https://api.telegram.org/bot$tk/sendPhoto");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $cid,
            'caption' => $caption,
            'photo' => new CURLFile($file, 'image/jpeg', 'evidence.jpg'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $response = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return ($response['ok'] ?? false) === true;
}

function gb_emoji(string $st): string {
    return ['OK' => "\u{2705}", 'DOWN' => "\u{1F534}", 'BLOCKED' => "\u{26A0}", 'ERROR' => "\u{2754}"][$st] ?? "\u{2754}";
}

/* Kanıt üretilemediğinde sessiz kalmamak için düz metin bildirim */
function gb_tg_send_text(array $tg, string $msg, ?string $chatId = null): bool {
    $tk = $tg['token'] ?? ''; $cid = $chatId ?? ((string) ($tg['chat_id'] ?? ''));
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

function gb_notify_run(array $cfg, array $results): bool {
    $telegram = $cfg['telegram'] ?? [];
    $proxy = (string) ($cfg['proxy'] ?? '');
    $alertUsers = array_values(array_filter(array_map('intval', (array) ($cfg['alert_users'] ?? []))));
    $evidenceSent = true;
    foreach ($results as $result) {
        $http = (int) ($result['http'] ?? 0);
        $bad = in_array($result['status'] ?? '', ['DOWN', 'ERROR', 'BLOCKED'], true);
        $prev = $result['previous_status'] ?? null;
        $becameBad = $bad && $prev !== null && $prev !== $result['status'];
        $recovered = !$bad && $prev !== null && in_array($prev, ['DOWN', 'ERROR', 'BLOCKED'], true);

        $capture = null;
        if ($http > 0) {
            $capture = gb_capture($result['url'] ?? '', $result['name'] ?? 'site', $proxy, (string) ($cfg['relay'] ?? ''), (string) ($cfg['relay_key'] ?? ''));
        }

        /* kanal: mevcut davranış — her koşuda kanıt */
        if ($capture && ($capture['ok'] ?? false) && gb_tg_send_evidence($telegram, $result, $capture)) {
            // kanala kanıt gitti
        } else {
            $err = $http > 0 ? (string) ($capture['error'] ?? 'gönderim hatası') : 'siteye bağlantı kurulamadı (HTTP 0)';
            $evidenceSent = false;
            gb_tg_send_text($telegram, gb_emoji($result['status']) . ' ' . ($result['name'] ?? '-') .
                "\nSite: " . ($result['url'] ?? '-') .
                "\nGooglebot: " . $result['status'] . ' | Kullanıcı: ' . ($result['ustatus'] ?? '-') .
                "\nHTTP " . ($result['http'] ?? 0) . ' | Beklenen alt: ' . gb_host_of($result['expect'] ?? '') .
                "\nKanıt görseli üretilemedi: " . $err .
                (($result['unote'] ?? '') !== '' ? "\nKullanıcı notu: " . $result['unote'] : ''));
        }

        /* özel DM: sadece durum değişiminde (düşüş veya düzelme) */
        if (($becameBad || $recovered) && $alertUsers) {
            $now = (new DateTime('now', new DateTimeZone('Europe/Istanbul')))->format('d.m.Y H:i');
            $prefix = $becameBad
                ? '🔴 ' . ($result['name'] ?? '-') . ' düştü' . "\n"
                : '✅ ' . ($result['name'] ?? '-') . ' düzeldi (' . ($result['status'] ?? 'OK') . ')' . "\n";
            $dmText = $prefix . 'Site: ' . ($result['url'] ?? '-') .
                "\nGooglebot: " . ($prev ?? '-') . ' → ' . $result['status'] . ' | Kullanıcı: ' . ($result['previous_user_status'] ?? '-') . ' → ' . ($result['ustatus'] ?? '-') .
                "\nHTTP " . ($result['http'] ?? 0) . ' | Beklenen alt: ' . gb_host_of($result['expect'] ?? '') .
                "\nGörülen alt: " . ($result['alt'] ? implode(', ', $result['alt']) : '-') .
                ($result['note'] !== '' ? "\nNot: " . $result['note'] : '') .
                "\n🕐 $now (TSİ)";
            foreach ($alertUsers as $uid) {
                $sent = false;
                if ($becameBad && $capture && ($capture['ok'] ?? false)) {
                    $sent = gb_tg_send_evidence($telegram, $result, $capture, (string) $uid, $prefix);
                }
                if (!$sent) gb_tg_send_text($telegram, $dmText, (string) $uid);
            }
        }
    }
    return $evidenceSent;
}

/* Kontrol + DB kaydı + durum takibi. Hata YUTULMAZ — çağıran try/catch yapsın */
function gb_process(array $site, array $cfg, string $dbFile): array {
    gb_init_db($dbFile);
    $url = $site['url'];
    $name = $site['name'] ?? $url;
    $r = gb_check($site, [
        'proxy' => (string) ($cfg['proxy'] ?? ''),
        'relay' => (string) ($cfg['relay'] ?? ''),
        'relay_key' => (string) ($cfg['relay_key'] ?? ''),
    ]);

    gb_db($dbFile)->prepare("INSERT INTO checks(site,status,http,size,alt,note,ustatus,usize,unote) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$url, $r['status'], $r['http'], $r['size'], implode(',', $r['alt']), $r['note'], $r['ustatus'] ?? '-', $r['usize'] ?? 0, $r['unote'] ?? '']);

    $q = gb_db($dbFile)->prepare("SELECT status, user_status, blocked_streak FROM state WHERE site=?");
    $q->execute([$url]);
    $row = $q->fetch();
    $oldSt = $row['status'] ?? null;
    $oldUserSt = $row['user_status'] ?? null;
    $streak = (int) ($row['blocked_streak'] ?? 0);
    $userSt = $r['ustatus'] ?? '-';
    $newStreak = $r['status'] === 'BLOCKED' ? $streak + 1 : 0;
    $changed = ($oldSt !== null && $oldSt !== $r['status']) || ($oldUserSt !== null && $oldUserSt !== $userSt);

    if ($oldSt === null) {
        gb_db($dbFile)->prepare("INSERT INTO state(site,status,user_status,blocked_streak) VALUES(?,?,?,?)")
            ->execute([$url, $r['status'], $userSt, $newStreak]);
    } else {
        $since = $changed ? ", since=datetime('now')" : '';
        gb_db($dbFile)->prepare("UPDATE state SET status=?, user_status=?, blocked_streak=?$since WHERE site=?")
            ->execute([$r['status'], $userSt, $newStreak, $url]);
    }
    $r['name'] = $name;
    $r['url'] = $url;
    $r['expect'] = $site['expect'] ?? '';
    $r['previous_status'] = $oldSt;
    $r['previous_user_status'] = $oldUserSt;
    return $r;
}
