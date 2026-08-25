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
    printf("%-10s HTTP %d | alt=%s | %s\n", $r['status'], $r['http'], $r['alt'] ? implode(',', $r['alt']) : '-', $r['note']);
    exit(0);
}

if ($cmd !== 'run') { fwrite(STDERR, "kullanim: php monitor.php run | test <url>\n"); exit(1); }

foreach ($CFG['sites'] as $s) {
    try {
        $r = gb_process($s, $CFG, "$BASE/data/gbwatch.db");
        printf("%-10s %s | HTTP %d | alt=%s | %s%s\n", $r['status'], $s['url'], $r['http'],
            $r['alt'] ? implode(',', $r['alt']) : '-', $r['note'], $r['alert'] ? ' [ALERT]' : '');
    } catch (Throwable $e) {
        printf("HATA       %s | %s\n", $s['url'], $e->getMessage());
    }
}
