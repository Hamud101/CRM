<?php
/** Settings saved from the Setup screen, layered over secrets.php. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google.php';

const SETTINGS_KEYS = [
    'business_name', 'sender', 'timezone', 'mail_provider', 'smtp_host',
    'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'from_name',
    'imap_host', 'imap_port', 'imap_user', 'imap_pass', 'imap_sent',
    'mail_tested_at', 'calls_mode', 'twilio_sid', 'twilio_token',
    'twilio_from', 'twilio_my_phone',
];

const SETTINGS_SECRET_KEYS = ['smtp_pass', 'imap_pass', 'twilio_token'];

const MAIL_PROVIDERS = [
    'gmail' => [
        'label' => 'Gmail or Google Workspace',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 465,
        'imap_host' => 'imap.gmail.com',
        'imap_sent' => 'none',
        'help' => 'Gmail needs an app password, not your normal password. Turn on 2-Step Verification in your Google account first, then make an app password and paste it here.',
        'url' => 'https://myaccount.google.com/apppasswords',
    ],
    'yahoo' => [
        'label' => 'Yahoo Mail',
        'smtp_host' => 'smtp.mail.yahoo.com',
        'smtp_port' => 465,
        'imap_host' => 'imap.mail.yahoo.com',
        'imap_sent' => 'auto',
        'help' => 'Yahoo needs an app password. Make one under Account security, then paste it here.',
        'url' => 'https://login.yahoo.com/account/security',
    ],
    'icloud' => [
        'label' => 'iCloud Mail',
        'smtp_host' => 'smtp.mail.me.com',
        'smtp_port' => 587,
        'imap_host' => 'imap.mail.me.com',
        'imap_sent' => 'auto',
        'help' => 'iCloud needs an app-specific password. Make one under Sign-In and Security, then paste it here.',
        'url' => 'https://account.apple.com',
    ],
    'outlook' => [
        'label' => 'Outlook or Microsoft 365',
        'smtp_host' => 'smtp.office365.com',
        'smtp_port' => 587,
        'imap_host' => 'outlook.office365.com',
        'imap_sent' => 'auto',
        'help' => 'Microsoft has turned off app passwords for most accounts. Outlook.com and Hotmail addresses usually will not work. A Microsoft 365 work address works only if your IT admin allows SMTP sign-in. If the test fails, use a Gmail address or the email that came with your website.',
        'url' => 'https://account.microsoft.com/security',
    ],
    'other' => [
        'label' => "Other (your website's email)",
        'smtp_host' => '',
        'smtp_port' => 465,
        'imap_host' => '',
        'imap_sent' => 'auto',
        'help' => 'Your email host lists these settings, often under "email client setup" or "IMAP and SMTP". Use port 465 if it is offered, otherwise 587.',
        'url' => '',
    ],
];

function settings_file(): string {
    return crm_data_dir() . '/settings.json';
}

/** Decoded fresh every time: a save during this request must be visible now. */
function settings_read(): array {
    $raw = @file_get_contents(settings_file());
    if (!is_string($raw) || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function settings_save(array $changes): bool {
    $data = [];
    foreach (settings_read() as $key => $value)
        if (in_array($key, SETTINGS_KEYS, true)) $data[$key] = $value;
    foreach ($changes as $key => $value)
        if (in_array($key, SETTINGS_KEYS, true)) $data[$key] = $value;

    $tmp = settings_file() . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json) === false) return false;
    if (!@chmod($tmp, 0600)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, settings_file())) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function settings_public(): array {
    $name = (string)biz('name', '');
    if ($name === 'My Business') $name = '';
    return [
        'business_name' => $name,
        // "The team" is the blank install's sign-off; show an empty field instead.
        'sender'        => biz('sender', '') === 'The team' ? '' : (string)biz('sender', ''),
        'timezone'      => (string)cfg('timezone', 'America/Chicago'),
        'timezones'     => DateTimeZone::listIdentifiers(),
        'mail' => [
            'provider'     => (string)cfg('mail_provider', ''),
            'address'      => (string)cfg('smtp_from', ''),
            'from_name'    => (string)cfg('from_name', ''),
            'smtp_host'    => (string)cfg('smtp_host', ''),
            'smtp_port'    => (int)cfg('smtp_port', 465),
            'imap_host'    => (string)cfg('imap_host', ''),
            'password_set' => (string)cfg('smtp_pass', '') !== '',
            'tested_at'    => (int)cfg('mail_tested_at', 0),
            'ready'        => mail_ready(),
        ],
        'providers' => MAIL_PROVIDERS,
        'calls' => [
            'mode'             => calls_mode(),
            'twilio_sid'       => (string)cfg('twilio_sid', ''),
            'twilio_from'      => (string)cfg('twilio_from', ''),
            'twilio_my_phone'  => (string)cfg('twilio_my_phone', ''),
            'twilio_token_set' => (string)cfg('twilio_token', '') !== '',
        ],
        'calendar' => [
            'available' => calendar_available(),
            'connected' => g_connected(),
        ],
    ];
}

function mail_ready(): bool {
    $host = (string)cfg('smtp_host', '');
    return $host !== '' && $host !== 'smtp.example.com'
        && (string)(cfg('smtp_from') ?: cfg('smtp_user')) !== ''
        && (string)cfg('smtp_pass', '') !== '';
}

function calendar_available(): bool {
    return (string)cfg('google_client_id', '') !== '';
}

function calls_mode(): string {
    $mode = (string)cfg('calls_mode', biz('calls', 'phone'));
    return in_array($mode, ['phone', 'gvoice', 'twilio'], true) ? $mode : 'phone';
}

/** Returns [ok, error]. A blank password keeps the saved one; changing any
 *  address detail makes the last test stale. */
function settings_apply_mail(array $in): array {
    $provider = (string)($in['provider'] ?? '');
    if (!isset(MAIL_PROVIDERS[$provider])) return [false, 'Pick an email provider.'];
    $preset = MAIL_PROVIDERS[$provider];

    $address = trim((string)($in['address'] ?? ''));
    if (!filter_var($address, FILTER_VALIDATE_EMAIL))
        return [false, 'That email address does not look right.'];

    if ($provider === 'other') {
        $host = trim((string)($in['smtp_host'] ?? ''));
        $port = (int)($in['smtp_port'] ?? 465);
        $imap = trim((string)($in['imap_host'] ?? ''));
        if (!preg_match('~^[A-Za-z0-9.-]+$~', $host))
            return [false, 'Enter the outgoing mail server, for example mail.yourdomain.com.'];
        if ($port !== 465 && $port !== 587)
            return [false, 'Port must be 465 or 587.'];
        if ($imap !== '' && !preg_match('~^[A-Za-z0-9.-]+$~', $imap))
            return [false, 'Enter the incoming mail server, for example mail.yourdomain.com.'];
    } else {
        $host = (string)$preset['smtp_host'];
        $port = (int)$preset['smtp_port'];
        $imap = (string)$preset['imap_host'];
    }

    // Google shows app passwords in groups of four, and people paste them that way.
    $pass = preg_replace('/\s+/', '', (string)($in['password'] ?? ''));
    $oldPass = (string)cfg('smtp_pass', '');
    if ($pass === '' && $oldPass === '') return [false, 'Paste the app password.'];

    $fromName = substr(preg_replace('~[\x00-\x1F\x7F"<>]~', '', trim((string)($in['from_name'] ?? ''))), 0, 80);

    $oldFrom = (string)cfg('smtp_from', '');
    $oldHost = (string)cfg('smtp_host', '');
    $oldPort = (int)cfg('smtp_port', 465);

    $save = [
        'mail_provider' => $provider,
        'smtp_host'     => $host,
        'smtp_port'     => $port,
        'smtp_user'     => $address,
        'smtp_from'     => $address,
        'from_name'     => $fromName,
        'imap_host'     => $imap,
        'imap_port'     => 993,
        'imap_user'     => $address,
        'imap_sent'     => (string)$preset['imap_sent'],
    ];
    if ($pass !== '') {
        $save['smtp_pass'] = $pass;
        $save['imap_pass'] = $pass;
    }
    if ($pass !== '' || $address !== $oldFrom || $host !== $oldHost || $port !== $oldPort)
        $save['mail_tested_at'] = 0;

    return settings_save($save) ? [true, ''] : [false, 'Could not save your email settings.'];
}

/** Returns [ok, error]. Twilio fields are only touched when Twilio is chosen,
 *  so switching to the phone app does not erase keys someone may return to. */
function settings_apply_calls(array $in): array {
    $mode = (string)($in['mode'] ?? '');
    if (!in_array($mode, ['phone', 'gvoice', 'twilio'], true))
        return [false, 'Pick how calls are made.'];

    $save = ['calls_mode' => $mode];
    if ($mode === 'twilio') {
        $sid = trim((string)($in['twilio_sid'] ?? ''));
        if (!preg_match('~^AC[0-9a-fA-F]{32}$~', $sid))
            return [false, 'The Account SID starts with AC and is 34 characters.'];

        $token = trim((string)($in['twilio_token'] ?? ''));
        if ($token === '') $token = (string)cfg('twilio_token', '');
        if ($token === '') return [false, 'Paste the Auth Token.'];

        $from = phone_e164((string)($in['twilio_from'] ?? ''));
        if ($from === null)
            return [false, 'Enter the phone number with its country code, for example +1 612 555 0100.'];

        $my = phone_e164((string)($in['twilio_my_phone'] ?? ''));
        if ($my === null)
            return [false, 'Enter your phone with its country code, for example +1 612 555 0100.'];

        $save['twilio_sid'] = $sid;
        $save['twilio_token'] = $token;
        $save['twilio_from'] = $from;
        $save['twilio_my_phone'] = $my;
    }

    return settings_save($save) ? [true, ''] : [false, 'Could not save your call settings.'];
}

function phone_e164(string $raw): ?string {
    $s = trim($raw);
    if ($s === '') return null;

    if ($s[0] === '+') {
        $digits = preg_replace('/\D+/', '', substr($s, 1));
        if (strlen($digits) < 8 || strlen($digits) > 15) return null;
        return '+' . $digits;
    }

    $digits = preg_replace('/\D+/', '', $s);
    if (strlen($digits) === 10) return '+1' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
    return null;
}

/** Returns [HTTP status, decoded JSON]. A network failure is status 0. */
function twilio_request(string $method, string $path, array $fields = []): array {
    $sid = (string)cfg('twilio_sid', '');
    $token = (string)cfg('twilio_token', '');
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) $status = 0;
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($json) ? $json : []];
}

/** Ring the operator's phone first, then bridge the lead when they pick up. */
function twilio_dial(string $leadPhone): array {
    $sid = (string)cfg('twilio_sid', '');
    $token = (string)cfg('twilio_token', '');
    $from = (string)cfg('twilio_from', '');
    $my = (string)cfg('twilio_my_phone', '');
    if (calls_mode() !== 'twilio' || $sid === '' || $token === '' || $from === '' || $my === '')
        return [false, 'Calls through Twilio are not set up. Open Setup.'];

    $lead = phone_e164($leadPhone);
    if ($lead === null) return [false, 'That phone number is not complete.'];

    $fromXml = htmlspecialchars($from, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $leadXml = htmlspecialchars($lead, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $twiml = '<Response><Say>Connecting your call.</Say><Dial callerId="'
           . $fromXml . '">' . $leadXml . '</Dial></Response>';

    [$status, $json] = twilio_request('POST', '/Calls.json', [
        'To'    => $my,
        'From'  => $from,
        'Twiml' => $twiml,
    ]);

    if ($status === 201) return [true, 'Calling your phone now. Pick up and you will be connected.'];
    if ($status === 0) return [false, 'Could not reach Twilio.'];
    return [false, 'Twilio said: ' . (string)($json['message'] ?? '')];
}

function twilio_check(): array {
    [$status, $json] = twilio_request('GET', '.json');
    if ($status === 200) return [true, 'Twilio accepted your keys.'];
    if ($status === 401) return [false, 'Twilio did not accept the Account SID and Auth Token.'];
    if ($status === 0) return [false, 'Could not reach Twilio.'];
    return [false, 'Twilio said: ' . (string)($json['message'] ?? '')];
}

function mail_error_plain(string $raw): string {
    if (stripos($raw, 'password:') !== false || strpos($raw, '535') !== false || strpos($raw, '534') !== false)
        return 'The email provider did not accept the address and password. For Gmail, Yahoo and iCloud it must be an app password, not your normal one.';
    if (strpos($raw, 'connect failed') === 0)
        return 'Could not reach the mail server. Check the server name and port, and that this computer is online.';
    if (strpos($raw, 'STARTTLS') !== false)
        return 'The mail server would not start a secure connection on this port. Try the other port (465 or 587).';
    return 'Sending failed: ' . $raw;
}

/** A zip in temp: database, documents and settings, with all passwords left
 *  behind because a backup may be emailed or put on a shared drive. */
function backup_export(PDO $pdo): string {
    $tmpDb = tempnam(sys_get_temp_dir(), 'crmdb');
    $zipPath = tempnam(sys_get_temp_dir(), 'crmzip');
    if ($tmpDb === false || $zipPath === false) {
        if (is_string($tmpDb)) @unlink($tmpDb);
        if (is_string($zipPath)) @unlink($zipPath);
        throw new RuntimeException('Could not make a temporary file.');
    }
    @unlink($tmpDb); // VACUUM INTO needs to create the file itself.

    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmpDb));

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
            throw new RuntimeException('Could not create the zip file.');

        if (!$zip->addFile($tmpDb, 'crm.sqlite'))
            throw new RuntimeException('Could not add the database.');

        $docs = crm_docs_dir();
        foreach (scandir($docs) ?: [] as $folder) {
            if ($folder === '.' || $folder === '..') continue;
            $dir = $docs . '/' . $folder;
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $file) {
                if ($file === '.' || $file === '..') continue;
                $path = $dir . '/' . $file;
                if (!is_file($path)) continue;
                if (!$zip->addFile($path, 'docs/' . $folder . '/' . $file))
                    throw new RuntimeException('Could not add ' . $file . '.');
            }
        }

        $settings = settings_read();
        foreach (SETTINGS_SECRET_KEYS as $key) unset($settings[$key]);
        $zip->addFromString('settings.json', json_encode(
            $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.txt',
            "This is a CRM backup: your leads, documents and settings.\n" .
            "Passwords are not included, so email and Twilio need their password again after an import.\n");

        if (!$zip->close()) throw new RuntimeException('Could not finish the zip file.');
    } catch (Throwable $e) {
        @unlink($tmpDb);
        @unlink($zipPath);
        throw new RuntimeException($e->getMessage(), 0, $e);
    }

    @unlink($tmpDb);
    return $zipPath;
}

/** Replace live data from a backup. The database file is never swapped, because
 *  WAL readers may be using it; rows are copied in one transaction instead. */
function backup_import(PDO $pdo, string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true)
        return [false, 'That file is not a CRM backup.'];
    if ($zip->locateName('crm.sqlite') === false) {
        $zip->close();
        return [false, 'That file is not a CRM backup.'];
    }

    $docs = [];
    $hasSettings = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if ($name === 'crm.sqlite') continue;
        if ($name === 'settings.json') { $hasSettings = true; continue; }
        if ($name === 'README.txt') continue;
        if (strpos($name, '..') !== false) continue;
        if (preg_match('~^docs/[A-Za-z0-9_-]+/[A-Za-z0-9._-]+$~', $name)) $docs[] = $name;
        // Anything else is skipped, never extracted.
    }

    $tmpSqlite = tempnam(sys_get_temp_dir(), 'crmimp');
    if ($tmpSqlite === false) {
        $zip->close();
        return [false, 'Could not make a temporary file.'];
    }

    $in = $zip->getStream('crm.sqlite');
    $out = @fopen($tmpSqlite, 'wb');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        @unlink($tmpSqlite);
        $zip->close();
        return [false, 'That file is not a CRM backup.'];
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    try {
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($tmpSqlite) . ' AS src');
    } catch (Throwable $e) {
        @unlink($tmpSqlite);
        $zip->close();
        return [false, 'That file is not a CRM backup.'];
    }

    try {
        $hasLeads = $pdo->query("SELECT 1 FROM src.sqlite_master WHERE type='table' AND name='leads'")->fetchColumn();
        if (!$hasLeads) throw new RuntimeException('no leads table');

        $srcVersion = (int)($pdo->query("SELECT v FROM src.schema_meta WHERE k='version'")->fetchColumn() ?: 0);
        $mainVersion = (int)($pdo->query("SELECT v FROM main.schema_meta WHERE k='version'")->fetchColumn() ?: 0);
        if ($srcVersion > $mainVersion) {
            $pdo->exec('DETACH DATABASE src');
            @unlink($tmpSqlite);
            $zip->close();
            return [false, 'This backup comes from a newer version of the CRM. Update this CRM first.'];
        }
    } catch (Throwable $e) {
        try { $pdo->exec('DETACH DATABASE src'); } catch (Throwable $e2) {}
        @unlink($tmpSqlite);
        $zip->close();
        return [false, 'That file is not a CRM backup.'];
    }

    try {
        $backupDir = crm_data_dir() . '/backups';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0700, true);
        $safety = $backupDir . '/before-import-' . date('Y-m-d-His') . '.sqlite';
        $pdo->exec('VACUUM INTO ' . $pdo->quote($safety));

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();

        $quoteId = function (string $name): string {
            return '"' . str_replace('"', '""', $name) . '"';
        };
        $tables = $pdo->query("SELECT name FROM main.sqlite_master WHERE type='table'")
                      ->fetchAll(PDO::FETCH_COLUMN);
        $srcTables = array_flip($pdo->query("SELECT name FROM src.sqlite_master WHERE type='table'")
                                    ->fetchAll(PDO::FETCH_COLUMN));

        foreach ($tables as $table) {
            if (in_array($table, ['schema_meta', 'tokens', 'sqlite_sequence'], true)) continue;
            if (strpos($table, 'sqlite_') === 0 || !isset($srcTables[$table])) continue;

            $mainCols = $pdo->query('PRAGMA main.table_info(' . $quoteId($table) . ')')
                            ->fetchAll(PDO::FETCH_ASSOC);
            $srcCols = $pdo->query('PRAGMA src.table_info(' . $quoteId($table) . ')')
                           ->fetchAll(PDO::FETCH_ASSOC);
            $srcNames = array_column($srcCols, 'name');
            $cols = [];
            foreach ($mainCols as $col)
                if (in_array($col['name'], $srcNames, true)) $cols[] = $col['name'];

            $pdo->exec('DELETE FROM main.' . $quoteId($table));
            if ($cols) {
                $list = implode(', ', array_map($quoteId, $cols));
                $pdo->exec('INSERT INTO main.' . $quoteId($table) . ' (' . $list . ')'
                         . ' SELECT ' . $list . ' FROM src.' . $quoteId($table));
            }
        }

        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('DETACH DATABASE src');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $e2) {}
        try { $pdo->exec('DETACH DATABASE src'); } catch (Throwable $e2) {}
        @unlink($tmpSqlite);
        $zip->close();
        return [false, 'The import failed and nothing was changed: ' . $e->getMessage()];
    }

    foreach ($docs as $name) {
        $rel = substr($name, strlen('docs/'));
        $dest = crm_docs_dir() . '/' . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $in = $zip->getStream($name);
        if (!$in) continue;
        $out = @fopen($dest, 'wb');
        if ($out) {
            stream_copy_to_stream($in, $out);
            fclose($out);
        }
        fclose($in);
    }

    $needMail = false;
    if ($hasSettings) {
        $data = json_decode((string)$zip->getFromName('settings.json'), true);
        if (is_array($data)) {
            $hadFrom = (string)($data['smtp_from'] ?? '') !== '';
            foreach (SETTINGS_SECRET_KEYS as $key) unset($data[$key]);
            settings_save($data);
            $needMail = $hadFrom && !mail_ready();
        }
    }

    $leads = (int)$pdo->query('SELECT COUNT(*) FROM main.leads')->fetchColumn();
    $zip->close();
    @unlink($tmpSqlite);

    $message = 'Imported ' . $leads . ($leads === 1 ? ' lead' : ' leads')
             . '. Your previous data was saved in backups/ first.';
    if ($needMail) $message .= ' Enter your email password again in Setup.';
    return [true, $message];
}
