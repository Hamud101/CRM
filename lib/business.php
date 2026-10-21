<?php
/**
 * Who this install belongs to: name, logo, prices, email templates, industries,
 * stage labels, and which sales features are switched on.
 *
 * One codebase serves several businesses. Everything that differs between them
 * lives in a profile: PHP files that return arrays, layered so later ones win:
 *
 *   1. profiles/starter/business.php     every setting, with plain defaults
 *   2. profiles/<profile>/business.php   optional shared profile; <profile> is
 *                                        set in secrets.php ('starter' or your
 *                                        own folder name)
 *   3. <data dir>/business.php           private to this install, outside the web
 *                                        root: the real name, prices, people
 *
 * Feature switches merge key by key; every other key is replaced whole.
 */
require_once __DIR__ . '/paths.php';

function biz_profile_name(): string {
    $name = preg_replace('~[^a-z0-9_-]~', '', strtolower((string)cfg('profile', 'starter')));
    return $name !== '' ? $name : 'starter';
}

/** The files that make up this install's profile, most general first. */
function biz_layers(): array {
    $root = dirname(__DIR__) . '/profiles/';
    $layers = [$root . 'starter/business.php'];
    $shared = $root . biz_profile_name() . '/business.php';
    if (biz_profile_name() !== 'starter' && is_readable($shared)) $layers[] = $shared;
    $private = crm_data_dir() . '/business.php';
    if (is_readable($private)) $layers[] = $private;
    return $layers;
}

/** The folder relative paths resolve against: the shared profile's, because
 *  that is where its agreements and collateral live. */
function biz_dir(): string {
    $shared = dirname(__DIR__) . '/profiles/' . biz_profile_name();
    return is_dir($shared) ? $shared : dirname(__DIR__) . '/profiles/starter';
}

function biz_all(): array {
    static $b = null;
    if ($b !== null) return $b;
    $b = [];
    foreach (biz_layers() as $file) {
        $layer = require $file;
        if (!is_array($layer)) continue;
        $features = array_replace($b['features'] ?? [], $layer['features'] ?? []);
        $b = array_replace($b, $layer);
        $b['features'] = $features;
    }
    return $b;
}

function biz(string $key, $default = null) {
    $b = biz_all();
    return array_key_exists($key, $b) ? $b[$key] : $default;
}

function biz_on(string $feature): bool { return !empty(biz_all()['features'][$feature]); }

/** A profile path made absolute: relative paths are relative to the profile folder. */
function biz_path(string $rel): string {
    if ($rel === '') return '';
    return $rel[0] === '/' ? $rel : biz_dir() . '/' . $rel;
}

/** The mail domain, for Message-IDs, calendar UIDs and the SMTP greeting. */
function biz_mail_domain(): string {
    $from = (string)cfg('smtp_from', '');
    $at = strrpos($from, '@');
    if ($at !== false) return strtolower(substr($from, $at + 1));
    $host = parse_url((string)cfg('app_url', ''), PHP_URL_HOST);
    return $host ?: 'localhost';
}

/** What the browser needs to draw this business: sent once with the page. */
function biz_public(): array {
    return [
        'name'      => biz('name'),
        'app_name'  => biz('app_name'),
        'logo'      => biz('logo'),
        'currency'  => biz('currency'),
        'services'  => biz('services'),
        'prepay'    => biz('prepay'),
        'demo_word' => biz('demo_word'),
        'features'  => biz('features'),
        'week_plan' => biz('week_plan'),
        'nurture_list' => biz('nurture_list'),
    ];
}
