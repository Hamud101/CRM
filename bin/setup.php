<?php
/**
 * First-run setup for a new install. Command line only.
 *
 * Run it from the install folder on the server, once:
 *
 *     cd ~/domains/<site>/public_html/apps/<folder>
 *     php bin/setup.php
 *
 * It creates ~/appdata/<folder>/ with:
 *   secrets.php   login, timezone, mail settings (0600)
 *   business.php  this business's profile, copied from profiles/starter (0600)
 *   crm.sqlite    the empty database
 *
 * Running it again never overwrites those files; it only fills in what is
 * missing, so it is safe to re-run after a partial setup.
 *
 * The Docker entrypoint runs it non-interactively with --from-env, where the
 * answers come from CRM_* environment variables instead of prompts.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("command line only\n"); }

require_once __DIR__ . '/../lib/paths.php';

function ask(string $q, string $default = ''): string {
    $hint = $default !== '' ? " [$default]" : '';
    fwrite(STDOUT, $q . $hint . ': ');
    $a = trim((string)fgets(STDIN));
    return $a !== '' ? $a : $default;
}

function ask_secret(string $q): string {
    fwrite(STDOUT, $q . ': ');
    $tty = @shell_exec('stty -g 2>/dev/null');
    if ($tty) @shell_exec('stty -echo 2>/dev/null');
    $a = trim((string)fgets(STDIN));
    if ($tty) { @shell_exec('stty ' . escapeshellarg(trim($tty)) . ' 2>/dev/null'); fwrite(STDOUT, "\n"); }
    return $a;
}

/** An environment answer for the Docker entrypoint; an empty value is unset. */
function env_or(string $key, string $default): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/** Stop unless PHP knows the timezone. */
function need_tz(string $tz): string {
    if (!in_array($tz, timezone_identifiers_list(), true)) {
        fwrite(STDERR, "Unknown timezone: $tz\n"); exit(1);
    }
    return $tz;
}

/** Stop unless the profile folder exists. */
function need_profile(string $profile, array $profiles): string {
    if (!in_array($profile, $profiles, true)) { fwrite(STDERR, "No profile called $profile\n"); exit(1); }
    return $profile;
}

function export_php(array $a): string {
    return "<?php\n// Written by bin/setup.php. Edit freely; it is never overwritten.\nreturn " .
           var_export($a, true) . ";\n";
}

$from_env = in_array('--from-env', $argv, true);

$instance = crm_instance();
$app = realpath(dirname(__DIR__));
if (!$from_env) {
    echo "\nSetting up the CRM install \"$instance\"\n";
    echo "Code folder: $app\n\n";
}

/* The data folder must sit outside anything the web server serves. Default is
   ~/appdata/<folder>; any other choice is remembered in local.php. The Docker
   entrypoint passes CRM_DATA_DIR and has no web root to worry about. */
$default = crm_home() . '/appdata/' . $instance;
$current = (string)(crm_local()['data_dir'] ?? '');
if ($from_env) {
    $dir = (string)getenv('CRM_DATA_DIR');
    if ($dir === '' || $dir[0] !== '/') {
        fwrite(STDERR, "CRM_DATA_DIR must be a full path, starting with /\n"); exit(1);
    }
} elseif ($current !== '') {
    $dir = $current;
    echo "Data folder (from local.php): $dir\n";
} else {
    $dir = rtrim(ask('Data folder, outside your web root', $default), '/');
    if ($dir === '' || $dir[0] !== '/') { fwrite(STDERR, "Give a full path, starting with /\n"); exit(1); }
}
if (!$from_env) {
    $webroot = dirname($app);
    while ($webroot !== '/' && !preg_match('~/(public_html|www|htdocs|html)$~', $webroot)) $webroot = dirname($webroot);
    if ($webroot === '/') $webroot = $app;
    if (strpos($dir . '/', $webroot . '/') === 0) {
        fwrite(STDERR, "$dir is inside the web root ($webroot). Pick a folder the web server cannot serve.\n");
        exit(1);
    }
}
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Could not create $dir\n"); exit(1);
}
@chmod($dir, 0700);
if (!$from_env && $dir !== $default && $current === '') {
    file_put_contents($app . '/local.php', "<?php\n// Written by bin/setup.php: where this install keeps its data.\nreturn ['data_dir' => " .
        var_export($dir, true) . "];\n");
    echo "Wrote local.php pointing at $dir\n";
}
putenv('CRM_DATA_DIR=' . $dir);

/* secrets.php */
$profiles = array_map('basename', glob(__DIR__ . '/../profiles/*', GLOB_ONLYDIR) ?: []);
$secrets = $dir . '/secrets.php';
if (is_file($secrets)) {
    echo "secrets.php already exists, leaving it alone.\n";
} else {
    if ($from_env) {
        $tz = need_tz(env_or('CRM_TZ', 'America/Chicago'));
        $profile = need_profile(env_or('CRM_PROFILE', 'starter'), $profiles);
        $url = env_or('CRM_URL', 'http://localhost:8080/');
        $pw_hash = '';
    } else {
        $tz = need_tz(ask('Timezone (e.g. America/Chicago, Africa/Mogadishu)', 'America/Chicago'));
        $profile = need_profile(ask('Shared profile to start from (' . implode(', ', $profiles) . ')', 'starter'), $profiles);
        do {
            $url = ask('Public URL of this install, e.g. https://crm.example.com/ or https://example.com/' . $instance . '/');
        } while (!preg_match('~^https?://~', $url));
        do {
            $pw = ask_secret('Choose a login password (12+ characters)');
            if (strlen($pw) < 12) echo "Too short, try again.\n";
        } while (strlen($pw) < 12);
        $pw_hash = password_hash($pw, PASSWORD_DEFAULT);
    }
    $conf = [
        'profile'  => $profile,
        'timezone' => $tz,
        'app_url'  => rtrim($url, '/') . '/',
        'login_password_hash' => $pw_hash,
        // A per-install random secret keeps CSRF tokens unguessable in local
        // mode, where there is no session cookie and no password hash.
        'local_secret' => bin2hex(random_bytes(32)),
        // Fill these in later to send email from the CRM (see INSTALL.md).
        'smtp_host' => '', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => '',
        'imap_host' => '', 'imap_port' => 993, 'imap_user' => '', 'imap_pass' => '', 'imap_sent' => 'INBOX.Sent',
        'google_client_id' => '', 'google_client_secret' => '',
        'google_redirect_uri' => rtrim($url, '/') . '/oauth/google/callback',
        'google_calendar_id' => 'primary',
        'trello_key' => '', 'trello_token' => '', 'trello_list_today' => '',
    ];
    file_put_contents($secrets, export_php($conf));
    chmod($secrets, 0600);
    echo "Wrote secrets.php\n";
}

/* business.php: only what is private to this install. Everything else comes
   from the shared profile named in secrets.php, then profiles/starter. */
$business = $dir . '/business.php';
if (is_file($business)) {
    echo "business.php already exists, leaving it alone.\n";
} else {
    if ($from_env) {
        $name = env_or('CRM_BUSINESS', '');
        $sender = env_or('CRM_SENDER', '');
    } else {
        $name = ask('Business name (Enter to skip)');
        $sender = ask('Your first name, for signing emails (Enter to skip)');
    }
    $lines = '';
    if ($name !== '') {
        $lines .= "    'name'     => " . var_export($name, true) . ",\n";
        $lines .= "    'app_name' => " . var_export($name . ' CRM', true) . ",\n";
    }
    if ($sender !== '') {
        $lines .= "    'sender'   => " . var_export($sender, true) . ",\n";
    }
    $src = "<?php\n" .
        "/**\n" .
        " * This install's private settings. Anything set here wins over the shared\n" .
        " * profile (secrets.php 'profile') and profiles/starter/business.php, which\n" .
        " * lists every setting you can add here. Anything left out uses the starter\n" .
        " * defaults.\n" .
        " */\n" .
        "return [\n" .
        $lines .
        "];\n";
    file_put_contents($business, $src);
    chmod($business, 0600);
    echo "Wrote business.php\n";
}

/* The database is created on first connection. */
require_once __DIR__ . '/../lib/db.php';
db();
@chmod($dir . '/crm.sqlite', 0600);
if ($from_env) {
    echo "Database ready.\n";
} else {
    echo "Database ready.\n\nDone. Open the public URL and sign in with your password.\n";
    echo "Next: edit $business, then see INSTALL.md for email and calendar.\n";
}
