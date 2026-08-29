<?php
/* GBWatch motoru (CLI) — cron burayı çağırır */
$BASE = '/opt/gbwatch';
require "$BASE/repo/server/lib/common.php";

$cfgRaw = @file_get_contents("$BASE/config.json");
if ($cfgRaw === false) { fwrite(STDERR, "config.json yok\n"); exit(1); }
$CFG = json_decode($cfgRaw, true);
if (!is_array($CFG)) { fwrite(STDERR, "config.json bozuk\n"); exit(1); }

$cmd = $argv[1] ?? 'run';

if ($cmd === 'test' && isset($argv[2])) {
    $r = gb_check(['url' => $argv[2], 'expect' => '']);
    printf("%-10s HTTP %d | alt=%s | %s | kullanıcı=%s(%d b)\n", $r['status'], $r['http'], $r['alt'] ? implode(',', $r['alt']) : '-', $r['note'], $r['ustatus'], $r['usize']);
    exit(0);
}

if ($cmd !== 'run') { fwrite(STDERR, "kullanim: php monitor.php run | test <url>\n"); exit(1); }

/* panelden başlatılan tur ile çakışma: kilit doluysa bu turu atla */
$runLock = fopen("$BASE/data/run.lock", 'c');
if ($runLock === false || !flock($runLock, LOCK_EX | LOCK_NB)) { fwrite(STDOUT, "onceki kosu suruyor, atlandi\n"); exit(0); }

$runResults = [];
foreach ($CFG['sites'] as $s) {
    try {
        $r = gb_process($s, $CFG, "$BASE/data/gbwatch.db");
        $runResults[] = $r;
        printf("%-10s %s | HTTP %d | alt=%s | %s | kullanıcı=%s%s\n", $r['status'], $s['url'], $r['http'],
            $r['alt'] ? implode(',', $r['alt']) : '-', $r['note'], $r['ustatus'] ?? '-', '');
    } catch (Throwable $e) {
        $runResults[] = ['status' => 'ERROR', 'ustatus' => 'ERROR', 'http' => 0, 'alt' => [], 'note' => $e->getMessage(), 'name' => $s['name'] ?? $s['url']];
        printf("HATA       %s | %s\n", $s['url'], $e->getMessage());
    }
}
if ($runResults) printf("Telegram: %s\n", gb_notify_run($CFG, $runResults) ? 'gönderildi' : 'gönderilemedi');
gb_evidence_cleanup($BASE);
