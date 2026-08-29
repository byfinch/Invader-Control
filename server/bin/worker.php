<?php
$BASE = '/opt/gbwatch';
require "$BASE/repo/server/lib/common.php";

$jobsDir = "$BASE/data/jobs";
@mkdir($jobsDir, 0770, true);
set_time_limit(0);

function worker_job_file(string $id): string {
    global $jobsDir;
    return $jobsDir . '/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json';
}
function worker_save(array $job): void {
    $file = worker_job_file($job['id']);
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $file);
}
function worker_process(array $job): void {
    global $BASE;
    $cfg = json_decode((string) @file_get_contents("$BASE/config.json"), true) ?: [];
    $sites = $cfg['sites'] ?? [];
    $job['status'] = 'running'; $job['total'] = count($sites); $job['completed'] = 0; $job['current'] = ''; $job['results'] = []; $job['updated_at'] = date('c');
    worker_save($job);
    @file_put_contents("$BASE/data/jobs/active.json", json_encode($job), LOCK_EX);
    foreach ($sites as $site) {
        $job['current'] = $site['name'] ?? $site['url']; $job['updated_at'] = date('c'); worker_save($job);
        try {
            $result = gb_process($site, $cfg, "$BASE/data/gbwatch.db");
            $notified = gb_notify_run($cfg, [$result]);
            $job['results'][] = ['name' => $result['name'], 'status' => $result['status'], 'user_status' => $result['ustatus'] ?? '-', 'http' => $result['http'], 'notified' => $notified, 'error' => ''];
        } catch (Throwable $e) {
            $job['results'][] = ['name' => $site['name'] ?? $site['url'], 'status' => 'ERROR', 'user_status' => 'ERROR', 'http' => 0, 'notified' => false, 'error' => $e->getMessage()];
        }
        $job['completed']++; $job['updated_at'] = date('c'); worker_save($job); @file_put_contents("$BASE/data/jobs/active.json", json_encode($job), LOCK_EX);
    }
    $job['status'] = 'completed'; $job['current'] = ''; $job['finished_at'] = date('c'); $job['updated_at'] = date('c'); worker_save($job); @file_put_contents("$BASE/data/jobs/active.json", json_encode($job), LOCK_EX);
    gb_evidence_cleanup($BASE);
}

while (true) {
    $found = false;
    foreach (glob("$jobsDir/*.json") ?: [] as $file) {
        if (basename($file) === 'active.json') continue;
        $job = json_decode((string) @file_get_contents($file), true);
        if (!is_array($job)) continue;
        if (($job['status'] ?? '') === 'running' && strtotime($job['updated_at'] ?? '') < time() - 900) {
            $job['status'] = 'queued'; worker_save($job);
        }
        if (($job['status'] ?? '') !== 'queued') continue;
        /* cron koşusuyla çakışmayı önle: kilit doluysa iş kuyrukta kalsın, bir sonraki turda dene */
        $runLock = fopen("$BASE/data/run.lock", 'c');
        if ($runLock === false || !flock($runLock, LOCK_EX | LOCK_NB)) break;
        $found = true; worker_process($job);
        flock($runLock, LOCK_UN); fclose($runLock);
        break;
    }
    if (!$found) usleep(500000);
}
