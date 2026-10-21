<?php
/**
 * Email templates: a subject, a short body, and the PDF that body introduces.
 *
 * The PDFs live in ../attachments and are built from the print sources in
 * attachments/src (see build.py there) — same document system as the client
 * proposals, so what a prospect receives looks like everything else we send.
 *
 * Adding one: drop the PDF in attachments/, add an entry here. Nothing else in
 * the app needs to change; the front end lists whatever this returns.
 *
 * Bodies are plain text. They are a starting point, not a script — the sheet
 * fills them in and the sender edits before it goes.
 */

/** Placeholders any template body may use. Kept small on purpose: every one of
 *  these comes off the lead record, so nothing is ever invented for a prospect. */
function tpl_fields(array $lead): array {
    $contact = trim((string)($lead['contact'] ?? ''));
    // "Dana Whitfield (owner)" → "Dana". No contact on file → "there", which
    // reads fine as "Hi there,".
    $first = $contact !== '' ? preg_split('/\s+/', $contact)[0] : '';
    return [
        '{{first_name}}' => $first !== '' ? $first : 'there',
        '{{company}}'    => trim((string)($lead['name'] ?? '')) ?: 'your business',
        '{{sender}}'     => (string)biz('sender', ''),
        '{{business}}'   => (string)biz('name', ''),
    ];
}

/** The templates come from the business profile (see profiles/). */
function email_templates(): array {
    return (array)biz('email_templates', []);
}

function tpl_attachment_dir(): string {
    $dir = (string)biz('attachments_dir', '');
    return $dir !== '' ? biz_path($dir) : dirname(__DIR__) . '/attachments';
}

/** One template with its placeholders filled from the lead, ready for the sheet. */
function tpl_render(string $id, array $lead): ?array {
    $all = email_templates();
    if (!isset($all[$id])) return null;
    $t = $all[$id];
    $f = tpl_fields($lead);

    return [
        'id'         => $id,
        'name'       => $t['name'],
        'blurb'      => $t['blurb'],
        'subject'    => strtr($t['subject'], $f),
        'body'       => strtr($t['body'], $f),
        'attachment' => ($t['file'] ?? '') !== '' ? ($t['as'] ?? $t['file']) : null,
        // A template whose PDF hasn't been deployed is worse than no template:
        // it fails at the moment someone hits Send. No file means plain email.
        'ready'      => ($t['file'] ?? '') === ''
                        || is_readable(tpl_attachment_dir() . '/' . $t['file']),
    ];
}

/** Every template, rendered for this lead. Order is the order they appear in. */
function tpl_render_all(array $lead): array {
    $out = [];
    foreach (array_keys(email_templates()) as $id) $out[] = tpl_render($id, $lead);
    return $out;
}

/** The attachment list send_mail() wants: null for an unknown template, [] for
 *  a template that is a plain email with nothing attached. */
function tpl_attachments(string $id): ?array {
    $all = email_templates();
    if (!isset($all[$id])) return null;
    $t = $all[$id];
    if (($t['file'] ?? '') === '') return [];
    return [[
        'path' => tpl_attachment_dir() . '/' . $t['file'],
        'name' => $t['as'] ?? $t['file'],
        'type' => 'application/pdf',
    ]];
}
