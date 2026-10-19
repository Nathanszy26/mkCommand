<?php
/**
 * Notification dispatch for the Manpower Requisition module.
 *
 * One event -> the people who must act on it -> push and/or email -> a log row
 * per recipient per channel. Nothing here decides anything and nothing here is
 * read back by the UI, so it is safe to call after the transaction commits.
 *
 * PHP 7.2 -- mysqli only.
 */

declare(strict_types=1);
require_once __DIR__ . '/mrf_common.php';

// ---------------------------------------------------------------------
// CONFIG -- the only lines you edit.
// ---------------------------------------------------------------------

/** Master switches. Both off = nothing is resolved and nothing is sent. */
define('MRF_NOTIFY_PUSH',  true);
define('MRF_NOTIFY_EMAIL', true);

/**
 * Endpoint that turns a users.id into FCM sends. Posted as JSON:
 *   {"id":<users.id>,"title":..,"body":..,"data":{"screen":..}}
 */
define('MRF_PUSH_URL',     'http://app.globportal.com/mymk/api/firebase/fcm/custom');
define('MRF_PUSH_TIMEOUT', 8);

/** Envelope for the email channel. The display name is what the inbox shows. */
define('MRF_NOTIFY_FROM', 'MK Manpower Request <noreply@glob.com.my>');

/**
 * vacancy_access.access roles that receive approval notifications, as CSV.
 * Production: 'top_management'. Testing on your own account: 'admin'.
 */
define('MRF_NOTIFY_ROLES', 'top_management');

/**
 * Testing whitelist: CSV of users.id. When set, only these people are notified,
 * whatever the roles above resolved to. '' = notify everyone the roles resolve to.
 * Set to '3946' to keep every send pointed at one handset.
 */
define('MRF_NOTIFY_TEST_USER_IDS', '');

/**
 * Testing redirect for email: every message goes here instead of the real
 * inbox, and the log still records who it was meant for. '' = send for real.
 */
define('MRF_NOTIFY_EMAIL_OVERRIDE', '');

/**
 * HR mailboxes told when a decision is signed off, keyed by subsidiaries.id --
 * the same id vacancy.subsidiaries_id carries and vacancy_access.comp_id is
 * scoped against, so "company 1" means the same thing here as everywhere else.
 *
 * A company that is not listed is not mailed, and that is the whole rule: there
 * is no fallback address, because a requisition mailed to the wrong subsidiary's
 * HR is worse than one that was not mailed at all. Add a company by adding its
 * row; a company with several mailboxes is a longer list.
 *
 * These are addresses, not people. They are announced to, never asked to decide,
 * so nothing here resolves a users row and nothing here pushes.
 */
// function mrf_notify_hr_mailboxes(): array
// {
//     return [
//         1 => ['hr.glob@cityskill.my', 'prog4.glob@cityskill.my'],
//     ];
// }
function mrf_notify_hr_mailboxes(): array
{
    return [
        1 => ['hr.glob@cityskill.my'],
        8 => ['hr.kineticmotion@gmail.com', 'hr@kineticmotion.com.my'],
    ];
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------

/**
 * What each event is called, where the app should land, and how the email signs
 * off. Named once here so a title cannot be spelt one way in the push and another
 * in the email, and so an approval cannot close with a line asking for approval.
 *
 * `screen` is push-only. The two approval events are emailed to a mailbox rather
 * than pushed to a handset, so there is nowhere for them to land and the key is
 * empty rather than pointing at a screen no one will be sent to.
 */
function mrf_notify_events(): array
{
    $waiting = 'This request is waiting for your approval in the MyMK app.';

    return [
        'manpower'  => ['title' => 'Manpower Approval Pending',  'screen' => 'MkCommandApproval',
                        'note'  => $waiting],
        'candidate' => ['title' => 'Candidate Approval Pending', 'screen' => 'MkCommandApproval',
                        'note'  => $waiting],

        'manpower_approved'  => [
            'title'  => 'Manpower Request Approved',
            'screen' => '',
            'note'   => 'This manpower request has been approved. The position is now open for candidate submission.',
        ],
        'candidate_approved' => [
            'title'  => 'Employment Approved',
            'screen' => '',
            'note'   => 'This employment has been approved.',
        ],
    ];
}

/** Fail fast: an unknown event is a coding mistake, not a runtime condition. */
function mrf_notify_event(string $event): array
{
    $events = mrf_notify_events();
    if (!isset($events[$event])) {
        throw new InvalidArgumentException('Unknown notification event: ' . $event);
    }
    return $events[$event];
}

// ---------------------------------------------------------------------
// Recipients
// ---------------------------------------------------------------------

/** CSV constant to a clean list. Shared by the role list and the test whitelist. */
function mrf_notify_csv(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $value) {
        $value = trim($value);
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

/**
 * Everyone in a notify role with a live users row, before company scoping.
 * Query only. `user_id` is what the push endpoint wants; `email` is what the
 * mail channel wants; the access columns are what the company scoping reads.
 */
function mrf_notify_role_rows(): array
{
    $roles = mrf_notify_csv(MRF_NOTIFY_ROLES);
    if (!$roles) {
        return [];
    }
    $holders = rtrim(str_repeat('?, ', count($roles)), ', ');

    return mrf_select(
        'SELECT a.person, a.comp_id, a.access, a.all_companies, a.assigned_companies,
                u.id AS user_id, u.fullname, u.email
           FROM ' . mrf_t('vacancy_access') . ' a
           JOIN ' . mrf_t('users', MRF_DB_PORTAL) . ' u
             ON u.person = a.person AND u.comp_id = a.comp_id
          WHERE a.status = "1"
            AND a.access IN (' . $holders . ')
            AND u.status = "1" AND u.deleted = "0"
          ORDER BY u.fullname',
        str_repeat('s', count($roles)),
        $roles
    );
}

/**
 * The people to notify about one company's requisition.
 * Company scoping reuses mrf_companies(), so "which companies can this person
 * see" is answered in exactly one place -- admin and all_companies included.
 * One query per role holder; there are a handful of them, so it stays cheap.
 */
function mrf_notify_recipients(int $companyId): array
{
    $testIds = mrf_notify_csv(MRF_NOTIFY_TEST_USER_IDS);
    $out     = [];

    foreach (mrf_notify_role_rows() as $row) {
        if ($testIds && !in_array((string)$row['user_id'], $testIds, true)) {
            continue;
        }
        $visible = array_map('intval', array_column(mrf_companies($row), 'id'));
        if (!in_array($companyId, $visible, true)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

// ---------------------------------------------------------------------
// Message
// ---------------------------------------------------------------------

/**
 * Display name of one subsidiary, or '' when it is unknown. Query only.
 * Uppercased to match how the requisition form names a company on screen.
 * Only the manpower event needs this -- a candidate is announced off a listing
 * row, which already carries company_name, so it does not query again.
 */
function mrf_notify_company_name(int $subsidiariesId): string
{
    $rows = mrf_select(
        'SELECT name FROM ' . mrf_t('subsidiaries', MRF_DB_PORTAL) . ' WHERE id = ? LIMIT 1',
        'i',
        [$subsidiariesId]
    );
    return strtoupper(trim((string)($rows[0]['name'] ?? '')));
}

/**
 * "Label - value" per line, in the order given. The caller owns the labels
 * because only the caller knows what its event is about.
 */
function mrf_notify_body(array $lines): string
{
    $out = [];
    foreach ($lines as $label => $value) {
        $value = trim((string)$value);
        $out[] = $label . ' - ' . ($value !== '' ? $value : '-');
    }
    return implode("\n", $out);
}

/**
 * Only the lines that carry a value, keys and order kept. For facts a position
 * may not have -- a missing location is left out, not printed as "-".
 */
function mrf_notify_present(array $lines): array
{
    return array_filter($lines, function ($value): bool {
        return trim((string)$value) !== '';
    });
}

/**
 * One fact across the positions of a requisition, as one line. When every
 * position gives the same answer it is stated once; otherwise each answer is
 * tagged with its position so it cannot be read against the wrong one.
 * Positions without the fact are left out. '' when none has it.
 * $titles and $values are parallel lists.
 */
function mrf_notify_across(array $titles, array $values): string
{
    $given = mrf_notify_present($values);
    if (!$given) {
        return '';
    }
    if (count($given) === count($values) && count(array_unique($given)) === 1) {
        return trim((string)reset($given));
    }

    $parts = [];
    foreach ($given as $i => $value) {
        $parts[] = $titles[$i] . ': ' . trim((string)$value);
    }
    return implode('; ', $parts);
}

/**
 * Reason for hiring and location of one saved position, each only when set.
 * Query: the names being replaced live in their own table.
 */
function mrf_notify_row_facts(array $row): array
{
    $id = (int)$row['vacancy_id'];

    return mrf_notify_present([
        'Reason for Hiring' => mrf_reason_label((string)$row['vacancy_hire_reason'],
                                                mrf_replacement_staff_map([$id])[$id] ?? [],
                                                (string)$row['vacancy_hire_reason_detail']),
        'Location'          => (string)$row['vacancy_location'],
    ]);
}

// ---------------------------------------------------------------------
// Transports -- each returns ['ok' => bool, 'response' => string]
// ---------------------------------------------------------------------

/** One push, by users.id. The endpoint fans it out to that person's tokens. */
function mrf_push_send(int $userId, string $title, string $body, string $screen): array
{
    if (MRF_PUSH_URL === '') {
        throw new RuntimeException('MRF_PUSH_URL is not configured.');
    }

    $payload = json_encode(
        ['id' => $userId, 'title' => $title, 'body' => $body, 'data' => ['screen' => $screen]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $ch = curl_init(MRF_PUSH_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => MRF_PUSH_TIMEOUT,
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'response' => 'curl: ' . $error];
    }

    // success:true is the endpoint's own verdict. HTTP 200 alone is not enough:
    // it answers 200 with success:false when it finds no token for the id.
    $json = json_decode((string)$response, true);
    $ok   = $code === 200 && is_array($json) && !empty($json['success']);

    return ['ok' => $ok, 'response' => 'HTTP ' . $code . ' ' . mb_substr((string)$response, 0, 2000)];
}

/**
 * The email as HTML: a heading, the reference, then one labelled row per line,
 * closing on whatever the event wants said. Built from the same $lines the push
 * body is flattened from, so the two channels can never describe the same event
 * differently.
 *
 * $note is the closing line, and it is a parameter rather than a fixed sentence
 * because "waiting for your approval" is false in an email announcing that the
 * approval already happened.
 *
 * Styles are inline and the layout is a table on purpose -- Gmail strips
 <style>
 * blocks and ignores most modern CSS, so anything else degrades to unstyled text.
 */
function mrf_notify_email_html(string $title, string $reference, array $lines, string $note): string
{
    $rows = '';
    foreach ($lines as $label => $value) {
        $value = trim((string)$value);
        $rows .= '<tr>'
               . '<td style="padding:7px 18px 7px 0;color:#6b7280;font-size:13px;'
               . 'white-space:nowrap;vertical-align:top">'
               . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td style="padding:7px 0;color:#111827;font-size:14px;font-weight:600">'
               . htmlspecialchars($value !== '' ? $value : '-', ENT_QUOTES, 'UTF-8') . '</td>'
               . '</tr>';
    }

    $ref = $reference !== ''
         ? '<p style="margin:2px 0 0;color:#6b7280;font-size:12px">Reference '
           . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</p>'
         : '';

    return '<div style="margin:0;padding:24px 12px;background:#f4f4f5;'
         . 'font-family:Arial,Helvetica,sans-serif">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;'
         . 'border-radius:10px"><tr><td style="padding:22px 24px">'
         . '<h1 style="margin:0;color:#111827;font-size:17px;line-height:1.35">'
         . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
         . $ref
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="margin-top:16px;border-top:1px solid #e5e7eb;padding-top:4px">'
         . $rows . '</table>'
         . '<p style="margin:20px 0 0;color:#6b7280;font-size:12px;line-height:1.5">'
         . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>'
         . '</td></tr></table>'
         . '<p style="max-width:520px;margin:12px auto 0;color:#9ca3af;font-size:11px;'
         . 'text-align:center">Computer generated. Do not reply to this message.</p>'
         . '</div>';
}

/** One email. The caller owns the wording; this only puts it on the wire. */
function mrf_notify_email_send(string $to, string $subject, string $html): array
{
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-type: text/html; charset=UTF-8\r\n"
             . 'From: ' . MRF_NOTIFY_FROM . "\r\n"
             . 'Reply-To: ' . MRF_NOTIFY_FROM;

    $sent = mail($to, $subject, $html, $headers);

    return ['ok' => $sent, 'response' => $sent ? 'accepted by MTA' : 'mail() refused the message'];
}

// ---------------------------------------------------------------------
// Log
// ---------------------------------------------------------------------

/** One attempt, recorded. Command. */
function mrf_notify_log(array $entry): void
{
    mrf_exec(
        'INSERT INTO ' . mrf_t('vacancy_notification_log') . '
            (`event`, `reference`, `channel`, `recipient_user_id`, `recipient_person`,
             `recipient_comp_id`, `recipient_name`, `recipient_target`, `title`, `body`,
             `status`, `response`, `created_datetime`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        'sssisissssss',
        [
            $entry['event'], $entry['reference'], $entry['channel'],
            (int)$entry['user_id'], $entry['person'], (int)$entry['comp_id'],
            $entry['name'], $entry['target'], $entry['title'], $entry['body'],
            $entry['status'], mb_substr((string)$entry['response'], 0, 4000),
        ]
    );
}

// ---------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------

/**
 * Run one send and record what happened. The transports differ; how an attempt
 * is judged and written down does not, so it is written once.
 */
function mrf_notify_attempt(array $base, string $channel, string $target, callable $send): void
{
    try {
        $result = $send();
        $status = !empty($result['ok']) ? 'success' : 'failed';
        $detail = (string)$result['response'];
    } catch (Throwable $e) {
        $status = 'failed';
        $detail = get_class($e) . ': ' . $e->getMessage();
    }

    if ($status === 'failed') {
        error_log('MRF notify ' . $channel . ' failed for ' . $base['name'] . ': ' . $detail);
    }

    mrf_notify_log($base + ['channel' => $channel, 'target' => $target,
                            'status'  => $status, 'response' => $detail]);
}

/**
 * Send one event to everyone who must act on it, and log every attempt.
 *
 * Called after the record it announces is already committed, so a dead endpoint
 * must not take the save down with it: each attempt is caught and written to the
 * log as 'failed' with the reason. The log row IS the loud failure -- a silent
 * catch would be the thing worth objecting to, and there isn't one here.
 */
function mrf_notify_dispatch(string $event, int $companyId, string $reference, array $lines): void
{
    if (!MRF_NOTIFY_PUSH && !MRF_NOTIFY_EMAIL) {
        return;
    }

    $spec = mrf_notify_event($event);
    $body = mrf_notify_body($lines);

    foreach (mrf_notify_recipients($companyId) as $r) {
        $base = [
            'event'     => $event,
            'reference' => $reference,
            'user_id'   => (int)$r['user_id'],
            'person'    => (string)$r['person'],
            'comp_id'   => (int)$r['comp_id'],
            'name'      => (string)$r['fullname'],
            'title'     => $spec['title'],
            'body'      => $body,
        ];

        if (MRF_NOTIFY_PUSH) {
            mrf_notify_attempt($base, 'push', (string)$r['user_id'], function () use ($r, $spec, $body) {
                return mrf_push_send((int)$r['user_id'], $spec['title'], $body, $spec['screen']);
            });
        }

        if (MRF_NOTIFY_EMAIL) {
            $real = trim((string)$r['email']);
            $to   = MRF_NOTIFY_EMAIL_OVERRIDE !== '' ? MRF_NOTIFY_EMAIL_OVERRIDE : $real;

            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                // Not a failure: nothing was attempted, because there was nothing
                // to attempt it against. Recorded so the gap is visible.
                mrf_notify_log($base + ['channel'  => 'email', 'target' => $real,
                                        'status'   => 'skipped',
                                        'response' => 'No usable email address on the users row.']);
            } else {
                mrf_notify_attempt($base, 'email', $to, function () use ($to, $spec, $reference, $lines) {
                    // Subject is the event and nothing else. The reference belongs
                    // inside the message, where it is read, not in a list of inboxes.
                    return mrf_notify_email_send($to, $spec['title'],
                        mrf_notify_email_html($spec['title'], $reference, $lines, $spec['note']));
                });
            }
        }
    }
}

/**
 * Tell one company's HR mailboxes that something was signed off, and log every
 * attempt.
 *
 * The sibling of mrf_notify_dispatch(), and it exists rather than reusing it
 * because the recipients are a different kind of thing. That one resolves people
 * from vacancy_access and reaches them on two channels; this one reads a fixed
 * list of addresses and only ever emails. Forcing a mailbox through the other
 * would mean inventing a users row for it to push to. Everything the two do share
 * -- the wording, the HTML, the transport, the log -- is shared.
 *
 * Called after the decision is committed, so a refusing MTA must not undo an
 * approval that already landed: each attempt is caught by mrf_notify_attempt()
 * and written to the log as 'failed' with the reason.
 */
function mrf_notify_hr_dispatch(string $event, int $companyId, string $reference, array $lines): void
{
    if (!MRF_NOTIFY_EMAIL) {
        return;
    }

    $spec      = mrf_notify_event($event);
    $body      = mrf_notify_body($lines);
    $mailboxes = mrf_notify_hr_mailboxes()[$companyId] ?? [];

    // A company whose address has not been collected yet is the expected state,
    // not a fault -- but it is still an approval nobody was told about, so it is
    // named in the log rather than passing in silence.
    if (!$mailboxes) {
        error_log('MRF notify: no HR mailbox for company ' . $companyId
                . ' -- ' . $event . ' ' . $reference . ' was not emailed.');
        return;
    }

    foreach ($mailboxes as $mailbox) {
        $real = trim((string)$mailbox);
        $to   = MRF_NOTIFY_EMAIL_OVERRIDE !== '' ? MRF_NOTIFY_EMAIL_OVERRIDE : $real;

        $base = [
            'event'     => $event,
            'reference' => $reference,
            'user_id'   => 0,            // a mailbox has no users row behind it
            'person'    => '',
            'comp_id'   => $companyId,
            'name'      => 'HR mailbox',
            'title'     => $spec['title'],
            'body'      => $body,
        ];

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            // A typo in the config, which no amount of retrying fixes. Logged
            // against the address so it is obvious which line is wrong.
            mrf_notify_log($base + ['channel'  => 'email', 'target' => $real,
                                    'status'   => 'skipped',
                                    'response' => 'Configured HR address is not a valid email.']);
            continue;
        }

        mrf_notify_attempt($base, 'email', $to, function () use ($to, $spec, $reference, $lines) {
            return mrf_notify_email_send($to, $spec['title'],
                mrf_notify_email_html($spec['title'], $reference, $lines, $spec['note']));
        });
    }
}

// ---------------------------------------------------------------------
// Event triggers -- what each caller hands over
// ---------------------------------------------------------------------

/** A new requisition has been raised. */
function mrf_notify_manpower(array $form, array $saved, string $requesterName): void
{
    $titles    = [];
    $reasons   = [];
    $locations = [];
    $total     = 0;
    foreach ($form['positions'] as $p) {
        $titles[]    = $p['gw'] === '1' ? 'General Worker' : (string)$p['title'];
        $reasons[]   = mrf_reason_label((string)$p['reason'], $p['reason_names'],
                                        (string)$p['reason_detail']);
        $locations[] = (string)$p['location'];
        $total      += (int)$p['headcount'];
    }

    $companyId = (int)$form['subsidiaries_id'];

    mrf_notify_dispatch('manpower', $companyId, (string)$saved['ref_no'], [
        'Requested By'  => $requesterName,
        'Company'       => mrf_notify_company_name($companyId),
        'Position'      => implode(', ', $titles),
        'Total Request' => (string)$total,
    ] + mrf_notify_present([
        'Reason for Hiring' => mrf_notify_across($titles, $reasons),
        'Location'          => mrf_notify_across($titles, $locations),
    ]));
}

/** A candidate has been put up for employment approval. */
function mrf_notify_candidate(array $row, array $candidate, array $actor): void
{
    mrf_notify_dispatch('candidate', (int)$row['subsidiaries_id'], (string)$row['mrf_ref_no'], [
        'Requested By' => (string)$actor['name'],
        'Company'      => strtoupper(trim((string)$row['company_name'])),
        'Position'     => (string)$row['vacancy_title'],
        'Candidate'    => (string)$candidate['name'],
    ]);
}

/**
 * A requisition has been approved. HR is told because approval is what releases
 * the sourcing work, so the mail names the headcount that was actually signed off
 * -- the decider may have cut it before approving, and the number HR recruits to
 * is that one, not the number originally asked for.
 */
function mrf_notify_manpower_approved(array $row, int $headcount, array $actor): void
{
    mrf_notify_hr_dispatch('manpower_approved', (int)$row['subsidiaries_id'],
                           (string)$row['mrf_ref_no'], [
        'Company'      => strtoupper(trim((string)$row['company_name'])),
        'Position'     => (string)$row['vacancy_title'],
        'Headcount'    => (string)$headcount,
    ] + mrf_notify_row_facts($row) + [
        'Requested By' => (string)$row['requested_by_name'],
        'Approved By'  => (string)$actor['name'],
    ]);
}

/**
 * A candidate has been approved for employment.
 *
 * $candidate is a vacancy_candidate row, not the form shape that
 * mrf_notify_candidate() is handed, so the name is read from candidate_name.
 */
function mrf_notify_candidate_approved(array $row, array $candidate, array $actor): void
{
    mrf_notify_hr_dispatch('candidate_approved', (int)$row['subsidiaries_id'],
                           (string)$row['mrf_ref_no'], [
        'Company'     => strtoupper(trim((string)$row['company_name'])),
        'Position'    => (string)$row['vacancy_title'],
        'Candidate'   => (string)$candidate['candidate_name'],
        'Approved By' => (string)$actor['name'],
    ]);
}