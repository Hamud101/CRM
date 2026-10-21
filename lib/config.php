<?php
/** Secrets live outside the web root; this is the only thing that reads them. */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/business.php';

function cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $path = crm_data_dir() . '/secrets.php';
        $c = is_readable($path) ? require $path : [];
    }
    return $c[$key] ?? $default;
}

date_default_timezone_set(cfg('timezone', 'America/Chicago'));

/** Stages, in pipeline order. The keys are fixed (the code reasons about won,
 *  lost and the demo stages); the business profile can relabel any of them. */
const DEFAULT_STAGES = [
    'new'         => ['label' => 'New',          'open' => true],
    'attempting'  => ['label' => 'No answer',    'open' => true],
    'voicemail'   => ['label' => 'Voicemail',    'open' => true],
    'contacted'   => ['label' => 'Contacted',    'open' => true],
    'demo_set'    => ['label' => 'Demo scheduled', 'open' => true],
    'demo_noshow' => ['label' => 'Demo no-show',   'open' => true],
    'demo_done'   => ['label' => 'Demo held',      'open' => true],
    'proposal'    => ['label' => 'Proposal',     'open' => true],
    'won'         => ['label' => 'Won',          'open' => false],
    'nurture'     => ['label' => 'Nurture',      'open' => true],
    'lost'        => ['label' => 'Lost',         'open' => false],
];



(function () {
    $stages = DEFAULT_STAGES;
    foreach ((array)biz('stage_labels', []) as $k => $label)
        if (isset($stages[$k])) $stages[$k]['label'] = (string)$label;
    define('STAGES', $stages);

    /* Industry buckets come from the business profile. 'other' always exists. */
    $ind = (array)biz('industries', []);
    if (!isset($ind['other'])) $ind['other'] = 'Other';
    define('INDUSTRIES', $ind);
    /* Raw import types (OSM-style tags) -> industry. Anything unmapped is 'other'. */
    define('SERVICE_INDUSTRY', array_change_key_case((array)biz('service_industry', []), CASE_LOWER));
})();

function industry_for_service(string $service): string {
    return SERVICE_INDUSTRY[strtolower(trim($service))] ?? 'other';
}

/* Industries are whatever the leads say they are. INDUSTRIES above only
   supplies nice labels for the buckets we started with; a lead that arrives
   with an industry nobody has seen simply creates it. The key is a slug so
   "Solar & energy", "solar" and "Solar" all land in the same bucket. */
function industry_key(string $raw): string {
    $k = strtolower(trim($raw));
    if ($k === '') return '';
    if (isset(INDUSTRIES[$k])) return $k;
    foreach (INDUSTRIES as $key => $label)
        if (strtolower($label) === $k) return $key;
    $k = preg_replace('~[^a-z0-9]+~', '-', $k);
    $k = trim($k, '-');
    return substr($k, 0, 40);
}

function industry_label(string $key): string {
    if (isset(INDUSTRIES[$key])) return INDUSTRIES[$key];
    if ($key === '') return 'Other';
    return ucfirst(str_replace('-', ' ', $key));
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
