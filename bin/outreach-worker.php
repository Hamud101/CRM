<?php
/**
 * Run the queued Outreach searches against the scraper. CLI only.
 *
 * A scrape takes minutes, so the web request only queues a run and this drains
 * the queue: it sends one search at a time, waits for the job, downloads the
 * results CSV and imports the businesses as leads.
 *
 *   php ~/path/to/crm/bin/outreach-worker.php
 *
 * On the Docker install the worker loop runs this every minute and the scraper
 * container does the searching. Lead search needs the Docker install, because
 * the scraper needs Docker.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("this is a command-line worker\n");
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/outreach.php';

/* A tick can wait on the scraper, so keep overlapping cron ticks out. */
$lock = fopen(crm_data_dir() . '/outreach-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);                       // another worker holds it; nothing to say
}

$pdo = db();
$lines = outreach_tick($pdo);

foreach ($lines as $l) {
    fwrite(STDOUT, date('Y-m-d H:i:s') . '  ' . $l . "\n");
}

flock($lock, LOCK_UN);
fclose($lock);
