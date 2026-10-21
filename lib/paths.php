<?php
/**
 * Resolve the account home directory.
 *
 * getenv('HOME') is set under the CLI but NOT under LiteSpeed, which is what
 * serves this site — so anything relying on it works when run over SSH and
 * silently fails over HTTP. Try the environment, then the passwd entry, then
 * walk up from this file, and verify the result actually contains appdata/.
 */
function crm_home(): string {
    static $home = null;
    if ($home !== null) return $home;

    $candidates = [];

    $env = getenv('HOME');
    if (is_string($env) && $env !== '') $candidates[] = $env;

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) $candidates[] = $pw['dir'];
    }

    // .../<home>/domains/<site>/public_html/apps/crm/lib  -> up six
    $candidates[] = dirname(__DIR__, 6);
    $candidates[] = dirname(__DIR__, 5);

    // A CRM_HOME override wins, for installs that do not live under domains/.
    $forced = getenv('CRM_HOME');
    if (is_string($forced) && $forced !== '') return $home = rtrim($forced, '/');

    foreach ($candidates as $c) {
        if ($c && is_dir($c . '/appdata')) { return $home = rtrim($c, '/'); }
    }
    // Nothing has appdata/ yet (a fresh install on any host): use the real
    // account home, which is where setup will create it.
    foreach ($candidates as $c) {
        if ($c && is_dir($c) && is_writable($c)) { return $home = rtrim($c, '/'); }
    }
    return $home = rtrim($candidates[0] ?? '/tmp', '/');
}

/**
 * Which install this is. Several can share one hosting account, each in its own
 * web folder (apps/crm, apps/sales ...) with its own data. The name is the
 * folder the code sits in, so a second install needs no configuration to keep
 * its database apart. CRM_INSTANCE overrides it, for scripts run from elsewhere.
 */
function crm_instance(): string {
    $env = getenv('CRM_INSTANCE');
    $name = (is_string($env) && $env !== '') ? $env : basename(dirname(__DIR__));
    $name = preg_replace('~[^A-Za-z0-9_-]~', '', $name);
    return $name !== '' ? $name : 'crm';
}

/**
 * The URL path the app is served from, with a trailing slash: "/crm/",
 * "/sales/". Taken from the running script, so the app works in any folder.
 * Every entry point (index, api, doc, oauth_google) sits in the app root.
 */
function crm_base(): string {
    $script = PHP_SAPI === 'cli' ? '' : (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        $url = parse_url((string)cfg('app_url', ''), PHP_URL_PATH);
        return '/' . trim((string)$url, '/') . '/';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir . '/';
}

/** The full public URL of the app, for links in calendar events and Trello cards. */
function crm_url(): string {
    $u = (string)cfg('app_url', '');
    if ($u !== '') return rtrim($u, '/') . '/';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return 'https://' . $host . crm_base();
}

/**
 * Optional per-install file in the app root, written by bin/setup.php when the
 * data folder is not the default. PHP runs it rather than serving it, and
 * .htaccess blocks it besides. It returns ['data_dir' => '/abs/path'].
 */
function crm_local(): array {
    static $l = null;
    if ($l !== null) return $l;
    $f = dirname(__DIR__) . '/local.php';
    $l = is_readable($f) ? (array)(require $f) : [];
    return $l;
}

/**
 * Where this install keeps its database, settings and documents. Outside the
 * web root. Default: ~/appdata/<install folder>. CRM_DATA_DIR or local.php
 * point it anywhere else.
 */
function crm_data_dir(): string {
    $env = getenv('CRM_DATA_DIR');
    $custom = (is_string($env) && $env !== '') ? $env : (string)(crm_local()['data_dir'] ?? '');
    $dir = $custom !== '' ? rtrim($custom, '/') : crm_home() . '/appdata/' . crm_instance();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/**
 * Where per-lead documents live — a signed agreement, an invoice, whatever was
 * actually sent to that client.
 *
 * Beside the database, i.e. OUTSIDE the web root, and 0700. These are client
 * contracts: they must never be fetchable by guessing a URL, which is exactly
 * what would happen if they sat in attachments/ with the generic collateral.
 * Reaching one always goes through doc.php, which checks the session first.
 */
function crm_docs_dir(): string {
    $dir = crm_data_dir() . '/docs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
