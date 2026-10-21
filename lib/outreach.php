<?php
/**
 * Lead search: saved Outreach searches, the runs that read Google Maps through
 * the scraper, and the import of what comes back.
 *
 * The web request only ever queues. bin/outreach-worker.php hands each job to
 * the scraper through the shared /outreach folder and imports the results,
 * because a scrape takes minutes and a web request must not.
 */

require_once __DIR__ . '/db.php';

/* The folder the CRM shares with the scraper. CRM_OUTREACH_DIR wins so the
   Docker install can point both at the mounted volume without touching
   secrets.php. Empty means this install has no scraper. */
function outreach_dir(): string {
    $d = getenv('CRM_OUTREACH_DIR');
    if (!is_string($d) || trim($d) === '') $d = (string)cfg('outreach_dir', '');
    return rtrim(trim($d), '/');
}

/* Where one run's job folder lives. The run id is the folder name. */
function outreach_job_dir(array $run): string {
    return outreach_dir() . '/jobs/' . $run['id'];
}

/* The scraper's loop rewrites the heartbeat every few seconds; a stale one
   means it is gone or stuck. */
function outreach_scraper_up(): bool {
    $dir = outreach_dir();
    if ($dir === '') return false;
    $f = $dir . '/heartbeat';
    if (!is_readable($f)) return false;
    $t = (int)trim((string)@file_get_contents($f));
    return $t > 0 && time() - $t <= 90;
}

/* Drop a job folder and the files in it. One level deep is all the protocol
   has, so anything that cannot be removed is left alone. */
function outreach_rmdir(string $dir): void {
    foreach (glob($dir . '/*', GLOB_NOSORT) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}

/* Ask the scraper's loop to stop the job. The loop is the only writer on its
   side, so a file is the whole message. */
function outreach_job_cancel(array $run): void {
    $dir = outreach_job_dir($run);
    if (is_dir($dir)) @file_put_contents($dir . '/cancel', time());
}

/* One clean line per search term, or per place. */
function outreach_lines(string $text): array {
    $out = [];
    foreach (preg_split('~\r\n|\r|\n~', $text) as $line) {
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

/* Every keyword crossed with every place: "plumber in Austin, TX". No places
   means the keywords are already the whole query. */
function outreach_queries(array $search): array {
    $keywords = outreach_lines((string)($search['keywords'] ?? ''));
    $places   = outreach_lines((string)($search['places'] ?? ''));
    $out = [];
    foreach ($keywords as $kw) {
        if (!$places) { $out[] = $kw; continue; }
        foreach ($places as $place) $out[] = $kw . ' in ' . $place;
    }
    return array_slice(array_values(array_unique($out)), 0, 200);
}

/* Queue a run for a search, unless one is already queued or running. */
function outreach_queue(PDO $pdo, string $searchId, string $note = ''): ?string {
    $busy = $pdo->prepare("SELECT 1 FROM outreach_runs
                           WHERE search_id=? AND status IN ('queued','running') LIMIT 1");
    $busy->execute([$searchId]);
    if ($busy->fetchColumn()) return null;

    $id = 'r_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $pdo->prepare("INSERT INTO outreach_runs (id,search_id,status,note,created_at)
                   VALUES (?,?,'queued',?,?)")
        ->execute([$id, $searchId, $note, time()]);
    return $id;
}

function outreach_run_failed(PDO $pdo, array $run, string $note): void {
    $pdo->prepare("UPDATE outreach_runs SET status='failed', note=?, finished_at=? WHERE id=?")
        ->execute([$note, time(), $run['id']]);
}

/* A search's time budget: five minutes per query, kept inside the limits the
   scraper's loop accepts. Used when writing job.env and when checking a run
   that has gone quiet. */
function outreach_max_time(int $queries): int {
    return max(600, min(14400, $queries * 300));
}

/* One worker pass. Returns log lines so the CLI has something to print. */
function outreach_tick(PDO $pdo): array {
    $lines = [];

    /* A daily search queues itself once its last run is more than 20 hours old,
       so a missed tick does not mean a missed day. */
    $cutoff = time() - 20 * 3600;
    foreach ($pdo->query("SELECT s.id,
            COALESCE((SELECT MAX(r.created_at) FROM outreach_runs r
                      WHERE r.search_id=s.id), 0) AS last
        FROM outreach_searches s WHERE s.daily=1")->fetchAll() as $s) {
        if ((int)$s['last'] < $cutoff && outreach_queue($pdo, (string)$s['id'], 'daily')) {
            $lines[] = 'Queued daily search ' . $s['id'] . '.';
        }
    }

    $dir = outreach_dir();
    if ($dir === '') return $lines;

    /* Clear jobs nothing is waiting on: finished ones, ones the scraper never
       picked up, and old ones whose run is gone. */
    foreach (glob($dir . '/jobs/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $jobDir) {
        $st = $pdo->prepare("SELECT status FROM outreach_runs WHERE id=?");
        $st->execute([basename($jobDir)]);
        if ($st->fetchColumn() === 'running') continue;
        $mtime = @filemtime($jobDir);
        if (is_file($jobDir . '/done') || !is_file($jobDir . '/started')
            || ($mtime !== false && time() - $mtime > 6 * 3600)) {
            outreach_rmdir($jobDir);
        }
    }

    /* At most one run is in flight, so the scraper is never asked to do two
       things and the operator always has one thing to watch. */
    $run = $pdo->query("SELECT * FROM outreach_runs WHERE status='running'
                        ORDER BY created_at, id LIMIT 1")->fetch();
    if ($run) {
        $job = outreach_job_dir($run);

        if (!is_dir($job)) {
            outreach_run_failed($pdo, $run, 'The scraper lost this search. Run it again.');
            return $lines;
        }

        if (is_file($job . '/done')) {
            $rc = (int)trim((string)@file_get_contents($job . '/done'));

            if (is_file($job . '/results.csv')) {
                $s = $pdo->prepare("SELECT * FROM outreach_searches WHERE id=?");
                $s->execute([$run['search_id']]);
                $search = $s->fetch();
                if (!$search) {
                    outreach_rmdir($job);
                    $pdo->prepare("UPDATE outreach_runs SET status='cancelled', note='The search was deleted.', finished_at=? WHERE id=?")
                        ->execute([time(), $run['id']]);
                    return $lines;
                }
                $csv = (string)@file_get_contents($job . '/results.csv');
                /* The import is one transaction: half a CSV must not land. */
                try {
                    $pdo->beginTransaction();
                    list($found, $imported, $skipped) = outreach_import($pdo, $search, $csv, (string)$run['id']);
                    $pdo->commit();
                    if (is_file($job . '/timedout')) {
                        $note = 'Stopped at the time limit. Added what it found.';
                    } elseif ($rc === 0) {
                        $note = '';
                    } else {
                        $note = 'The scraper stopped early. Added what it found.';
                    }
                    $pdo->prepare("UPDATE outreach_runs SET status='done', found=?, imported=?, skipped=?, note=?, finished_at=? WHERE id=?")
                        ->execute([$found, $imported, $skipped, $note, time(), $run['id']]);
                    $lines[] = 'Run ' . $run['id'] . ': found ' . $found . ', added ' . $imported . ', skipped ' . $skipped . '.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    outreach_run_failed($pdo, $run, 'Import failed: ' . $e->getMessage());
                }
                outreach_rmdir($job);
                return $lines;
            }

            $note = 'The scraper could not finish this search.';
            if (is_file($job . '/log.txt')) {
                $last = '';
                foreach (preg_split('~\r\n|\r|\n~', (string)@file_get_contents($job . '/log.txt')) as $l) {
                    if (trim($l) !== '') $last = trim($l);
                }
                if ($last !== '') $note .= ' Last message: ' . substr($last, 0, 200);
            }
            outreach_rmdir($job);
            outreach_run_failed($pdo, $run, $note);
            return $lines;
        }

        if (!outreach_scraper_up()) {
            $pdo->prepare("UPDATE outreach_runs SET note=? WHERE id=?")
                ->execute(['Waiting for the scraper to answer.', $run['id']]);
            return $lines;
        }

        /* The loop stops a job at MAXTIME; the extra half hour covers a slow
           kill or a container that went away mid-job. */
        $max = outreach_max_time((int)$run['queries']);
        if ((int)$run['started_at'] > 0 && time() - (int)$run['started_at'] > $max + 1800) {
            outreach_job_cancel($run);
            outreach_run_failed($pdo, $run, 'Took too long and was stopped.');
            return $lines;
        }
        return $lines;
    }

    /* Nothing running: start the oldest queued run. */
    $run = $pdo->query("SELECT * FROM outreach_runs WHERE status='queued'
                        ORDER BY created_at, id LIMIT 1")->fetch();
    if (!$run) return $lines;

    $s = $pdo->prepare("SELECT * FROM outreach_searches WHERE id=?");
    $s->execute([$run['search_id']]);
    $search = $s->fetch();
    if (!$search) {
        $pdo->prepare("UPDATE outreach_runs SET status='cancelled', note='The search was deleted.', finished_at=? WHERE id=?")
            ->execute([time(), $run['id']]);
        return $lines;
    }

    $queries = outreach_queries($search);
    if (!$queries) {
        outreach_run_failed($pdo, $run, 'This search has nothing to look for.');
        return $lines;
    }

    if (!outreach_scraper_up()) {
        $pdo->prepare("UPDATE outreach_runs SET note=? WHERE id=?")
            ->execute(['Waiting for the scraper to answer.', $run['id']]);
        return $lines;
    }

    /* Hand the job over as a folder. ready goes last, so the loop never starts
       a job that is still being written. */
    $job   = outreach_job_dir($run);
    $lang  = trim((string)$search['lang']);
    if (!preg_match('~^[a-z]{2}$~', $lang)) $lang = 'en';
    $depth = (int)$search['depth'];
    if ($depth < 1 || $depth > 10) $depth = 2;
    $env = "DEPTH=$depth\nLANG=$lang\nEMAIL=" . ((int)$search['emails'] === 1 ? '1' : '0')
         . "\nMAXTIME=" . outreach_max_time(count($queries)) . "\n";

    $ok = @mkdir($job, 0775, true)
        && @file_put_contents($job . '/queries.txt', implode("\n", $queries) . "\n") !== false
        && @file_put_contents($job . '/job.env', $env) !== false
        && @file_put_contents($job . '/ready', (string)time()) !== false;
    if (!$ok) {
        outreach_rmdir($job);
        outreach_run_failed($pdo, $run, 'Could not hand the search to the scraper.');
        return $lines;
    }

    $pdo->prepare("UPDATE outreach_runs SET status='running', job_id=?, queries=?, started_at=?, note='' WHERE id=?")
        ->execute([$run['id'], count($queries), time(), $run['id']]);
    $lines[] = 'Started run ' . $run['id'] . ' with ' . count($queries) . ' queries.';
    return $lines;
}

/* Turn one results CSV into leads. Returns [found, imported, skipped]. */
function outreach_import(PDO $pdo, array $search, string $csv, string $runId): array {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return [0, 0, 0]; }
    $header = array_map('trim', $header);

    /* Duplicate checks run against the whole lead list, loaded once: a query
       per row would be a query per business on a big CSV. Phones compare on the
       last 10 digits so +1 and punctuation do not hide a duplicate. */
    $phoneTails = [];
    foreach ($pdo->query("SELECT phone FROM leads WHERE phone <> ''")->fetchAll(PDO::FETCH_COLUMN) as $phone) {
        $digits = preg_replace('~\D~', '', (string)$phone);
        if (strlen($digits) >= 10) $phoneTails[substr($digits, -10)] = true;
    }
    $nameCity = [];
    foreach ($pdo->query("SELECT name, city FROM leads")->fetchAll() as $lead) {
        $nameCity[strtolower(trim((string)$lead['name'])) . '|' . strtolower(trim((string)$lead['city']))] = true;
    }

    $industry  = trim((string)($search['industry'] ?? ''));
    if ($industry === '') $industry = 'other';
    $srcName   = trim((string)($search['name'] ?? ''));
    $minRev    = (int)($search['min_reviews'] ?? 0);
    $maxRev    = (int)($search['max_reviews'] ?? 0);
    $needPhone = (int)($search['require_phone'] ?? 0) === 1;
    $noSite    = (int)($search['no_site_only'] ?? 0) === 1;
    $now       = time();

    $found = 0; $imported = 0; $skipped = 0;
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($header)) continue;
        $r = array_combine($header, $row);
        $found++;

        $name = trim((string)($r['title'] ?? ''));
        if ($name === '') { $skipped++; continue; }

        $phone   = trim((string)($r['phone'] ?? ''));
        $reviews = (int)trim((string)($r['review_count'] ?? ''));
        $rating  = (float)trim((string)($r['review_rating'] ?? ''));
        $website = preg_replace('~^https?://~i', '',
            rtrim(trim((string)($r['website'] ?? '')), '/. '));

        if ($needPhone && $phone === '') { $skipped++; continue; }
        if ($reviews < $minRev) { $skipped++; continue; }
        if ($maxRev > 0 && $reviews > $maxRev) { $skipped++; continue; }
        if ($noSite && $website !== '') { $skipped++; continue; }

        /* complete_address is a JSON object when Google gave one. Anything else
           falls back to the plain address line. */
        $city = ''; $street = trim((string)($r['address'] ?? ''));
        $addr = json_decode((string)($r['complete_address'] ?? ''), true);
        if (is_array($addr)) {
            $c  = trim((string)($addr['city'] ?? ''));
            $st = trim((string)($addr['state'] ?? ''));
            if ($c !== '') $city = $st !== '' ? $c . ', ' . $st : $c;
            $s  = trim((string)($addr['street'] ?? ''));
            if ($s !== '') $street = $s;
        }

        $parts = explode(',', (string)($r['emails'] ?? ''));
        $email = trim((string)($parts[0] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        $key  = strtolower($name) . '|' . strtolower($city);
        $tail = '';
        $digits = preg_replace('~\D~', '', $phone);
        if (strlen($digits) >= 10) $tail = substr($digits, -10);
        if (isset($nameCity[$key]) || ($tail !== '' && isset($phoneTails[$tail]))) {
            $skipped++;
            continue;
        }

        $id = 'l_' . substr(bin2hex(random_bytes(6)), 0, 8);
        $pdo->prepare("INSERT INTO leads
            (id,name,phone,email,contact,service,industry,city,address,stage,reason,next_action,
             owner,value,attempts,vm_count,seq,last_call_at,first_vm_at,called_back_at,
             passed_at,created_at,updated_at,website,socials,source,defect,pitch,severity)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'','',0,0,0,0,0,0,0,0,?,?,?,?,?,?,?,?)")
            ->execute([$id, $name, $phone, $email, '',
                       trim((string)($r['category'] ?? '')), $industry, $city, $street, 'new', '',
                       $now, $now, $website, '', 'Outreach: ' . $srcName, '', '', 0]);

        /* The log line records what the scraper actually saw, never a claim the
           CRM cannot back up. */
        $bits = ['Found by the Outreach search "' . $srcName . '"'];
        $link = trim((string)($r['link'] ?? ''));
        if ($link !== '') $bits[] = 'Google Maps: ' . $link;
        if ($reviews > 0) $bits[] = 'Rated ' . number_format($rating, 1) . ' from ' . $reviews . ' reviews';
        if ($website === '') $bits[] = 'No website listed on Google Maps';
        $pdo->prepare("INSERT INTO activities (lead_id,ts,type,body) VALUES (?,?,'note',?)")
            ->execute([$id, $now, implode('. ', $bits)]);

        $nameCity[$key] = true;
        if ($tail !== '') $phoneTails[$tail] = true;
        $imported++;
    }
    fclose($fh);
    return [$found, $imported, $skipped];
}

function outreach_ints(array $row, array $keys): array {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) $row[$k] = (int)$row[$k];
    }
    return $row;
}

/* Everything the Outreach tab needs in one response. */
function outreach_state(PDO $pdo): array {
    $searchKeys = ['depth','emails','min_reviews','max_reviews','require_phone',
                   'no_site_only','daily','created_at','updated_at'];
    $runKeys    = ['queries','found','imported','skipped','created_at','started_at','finished_at'];

    $searches = [];
    foreach ($pdo->query("SELECT * FROM outreach_searches ORDER BY updated_at DESC")->fetchAll() as $s) {
        $s = outreach_ints($s, $searchKeys);
        $s['last_run'] = null;
        $searches[$s['id']] = $s;
    }

    /* Newest first, so the first run seen for a search is its last. */
    $lastBySearch = [];
    foreach ($pdo->query("SELECT * FROM outreach_runs ORDER BY created_at DESC, id DESC")->fetchAll() as $r) {
        if (!isset($lastBySearch[$r['search_id']])) $lastBySearch[$r['search_id']] = outreach_ints($r, $runKeys);
    }
    foreach ($searches as $id => $s) {
        $searches[$id]['last_run'] = $lastBySearch[$id] ?? null;
    }

    $runs = [];
    foreach ($pdo->query("SELECT r.*, COALESCE(s.name,'') AS search_name
                          FROM outreach_runs r LEFT JOIN outreach_searches s ON s.id=r.search_id
                          ORDER BY r.created_at DESC, r.id DESC LIMIT 25")->fetchAll() as $r) {
        $runs[] = outreach_ints($r, $runKeys);
    }

    return [
        'configured' => outreach_dir() !== '',
        'up'         => outreach_scraper_up(),
        'searches'   => array_values($searches),
        'runs'       => $runs,
    ];
}
