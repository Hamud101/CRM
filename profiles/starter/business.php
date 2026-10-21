<?php
/**
 * The starter profile: every setting a profile can have, with plain defaults.
 *
 * Copy this file to <data dir>/business.php (private) or to
 * profiles/<yourname>/business.php (committed) and change what you need. Any key
 * you delete falls back to the value here.
 */
return [
    // Shown in the header, the browser tab, email "From" names and documents.
    'name'     => 'My Business',
    'app_name' => 'CRM',
    // Signs off templated emails ("Thanks,\n<sender>").
    'sender'   => 'The team',
    // Header logo, relative to the app folder. Empty shows the name instead.
    'logo'     => '',
    'currency' => '$',

    // What a booked sales meeting is called: "demo", "site visit", "meeting".
    'demo_word' => 'demo',

    /* Stage labels. The keys are fixed because the app reasons about them
       (won = client, lost = closed); only the words people see change.
       Any stage left out keeps its default label. */
    'stage_labels' => [],

    /* Industry buckets for filtering leads: key => label. Leads can also bring
       new industries with them; these are just the ones listed first. */
    'industries' => [
        'other' => 'Other',
    ],
    /* Raw service or category strings from imports, mapped to an industry key. */
    'service_industry' => [],

    /* The proposal catalogue. Each line is tickable on its own and becomes a
       bullet and a fee line on the agreement.
       kind: 'once' or 'monthly'. */
    'services' => [
        ['id' => 'service', 'label' => 'Service', 'amount' => 0, 'kind' => 'once',
         'desc' => 'Describe what the client gets.'],
    ],

    /* Retainer plans: service id => [name, term]. When one of these is ticked
       the retainer agreement is used instead of the services agreement. */
    'plans' => [],

    /* Optional prepay deals: key => [label, ids that must be the whole package,
       amount]. Empty turns the prepay switch off. */
    'prepay' => [],

    /* Agreement templates, relative to the profile folder. Missing files turn
       proposal generation off with a clear message rather than an error. */
    'agreements' => [
        'services' => '',
        'retainer' => '',
    ],

    /* Templated emails offered on a lead. 'file' is a PDF in attachments_dir,
       or '' for an email with no attachment. Placeholders: {{first_name}},
       {{company}}, {{sender}}, {{business}}. */
    'email_templates' => [],
    'attachments_dir' => '',

    /* Weekly targets shown on the dashboard. */
    'week_plan' => ['dials_per_held' => 70, 'held_per_close' => 4],

    /* Where leads go after the stop loss sweep. */
    'nurture_list' => 'the nurture list',

    /* Sales features. Switch off what this business does not use. */
    'features' => [
        'proposals'   => false, // tick services, generate a signable agreement
        'free_audit'  => false, // "Free audit leads" panel, fed by a website form
        'sales_rules' => false, // texting consent, contact form test, audit sent
        'week_plan'   => true,  // "This week vs the plan" panel
        'stop_loss'   => true,  // 6 touches, 3 weeks, no reply sweep
        'win_followups' => false, // baseline, first report and review reminders after a win
    ],
];
