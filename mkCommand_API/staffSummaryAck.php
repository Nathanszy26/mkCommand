<?php
/**
 * STAFF SUMMARY ACKNOWLEDGEMENT — the page the mail's Acknowledge link opens,
 * where a manager reads the three sections and says so.
 *
 * ONE TICK A SECTION. Lateness, Excessive Leaves and Compliance each end in a
 * single box carrying the sentence it stands for — summaryConfirmLine(), the
 * same words the mail prints. A section with nobody in it has no box. The
 * Acknowledge button is held until every box that exists is ticked, and the
 * server checks the same thing, so a browser with no JavaScript is told
 * rather than let through.
 *
 * WAYS IN, AND ALL OF THEM RECORD:
 *   ?t=TOKEN                the three sections, with a box closing each.
 *   ?t=TOKEN&tick=SECTION   that section ticked on arrival, then the page.
 *   a submitted form        from this page (POST), or from the mail (GET),
 *                           because a mail client that submits at all does it
 *                           by opening a browser.
 *
 * TICKS ACCUMULATE. The acknowledgement is recorded the moment the LAST box
 * is ticked, by whichever route tops it up — which is why acked_items is
 * progress and acked_datetime is the verdict. A day with all three sections
 * empty has no boxes at all, and pressing Acknowledge completes it.
 *
 * NO LOGIN, AND THAT IS THE DESIGN. The token IS the authority: one random
 * 64-character string, written by staffSummaryCron.php, unique, and good for
 * exactly one person on exactly one day. Somebody reading their mail on a
 * phone at 7am should not be asked to log into the portal to say "seen". The
 * page can therefore do only two things — read that one row and write ticks to
 * it — and it is wired so that is literally all the SQL there is.
 *
 * IT SHOWS THE SNAPSHOT, NOT TODAY'S DATA. The row's payload is the table as
 * it was sent. Re-reading the APIs here would mean acknowledging a table that
 * is not the one that was shown — somebody whose check-in was corrected at 9am
 * would quietly change under the ticker's hand.
 *
 * EVERY NAME, AND THE BUTTON SAYS SO. The button is always pressable: a
 * disabled one leaves somebody pressing a dead control with no idea why. Press
 * it short and the page says how many are missing and outlines exactly those.
 * The count is checked again on the server, because a counter in a browser is
 * a courtesy and never a rule.
 *
 * Writes one row and only ever that one — see sql/staff_summary_notify.sql.
 *
 * PHP 7.2 compatible.
 */

/* This file lives in hr/ beside the APIs; the ../hr/ form is kept for the day
 * somebody moves it to mkCommand/. */
$ackCore = __DIR__ . '/staffPoolCore.php';
if (!file_exists($ackCore)) {
    $ackCore = __DIR__ . '/../hr/staffPoolCore.php';
}
require_once $ackCore;

/* How a cell is read, shared with the mail so the page cannot disagree with
 * what was sent. The cron itself is never included here, because including it
 * would run it. */
require_once __DIR__ . '/staffSummaryShared.php';

/* ---------- the page ------------------------------------------------------ */

/** Command: everything above the content. The palette is the mail's, so the
 *  page does not look like a different system to somebody who has just come
 *  from it. Here it CAN be a stylesheet — this is a browser, not Outlook. */
function ackHead($title)
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>'

       . ':root{--ink:#0f172a;--body:#334155;--muted:#64748b;--faint:#94a3b8;'
       . '--ghost:#cbd5e1;--line:#e2e8f0;--hair:#f1f5f9;--wash:#f8fafc;--blue:#2563eb;'
       . '--red:#dc2626;--amber:#b45309;--green:#059669;--tint:#fef2f2;--mint:#f0fdf4}'
       . '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}'
       . 'body{margin:0;padding:26px 12px 56px;background:var(--hair);color:var(--body);'
       . 'font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,Helvetica,sans-serif}'
       /* WIDE, because this is the page where the whole department is read.
        * 860 left a nine-column table squeezed into the middle of a desktop
        * window with white space either side; 1280 lets the figures breathe
        * and still centres sensibly on a laptop. */
       . '.wrap{max-width:1280px;margin:0 auto}'

       . '.card{background:#fff;border:1px solid var(--line);border-radius:14px;'
       . 'margin-bottom:14px;overflow:hidden}'
       . '.card>.pad{padding:18px 22px 20px}'

       /* The title, and the same words the mail's leads with. */
       . '.eyebrow{font-size:24px;font-weight:700;color:var(--blue);line-height:1.25}'
       /* Centred: the title block is a heading, not a form field. */
       . '.pad.mid{text-align:center}'
       . '.pad.mid table.hdr{margin-left:auto;margin-right:auto}'
       . '.pad.mid table.hdr th{text-align:right}'
       . '.pad.mid table.hdr td{text-align:left}'
       . 'table.hdr{border-collapse:collapse;margin-top:12px}'.'table.hdr th{font-size:12px;font-weight:400;color:var(--muted);text-align:left;'.'padding:0 10px 2px 0;white-space:nowrap}'.'table.hdr td{font-size:17px;font-weight:700;color:var(--ink);padding:0 0 2px}'
       . '.who{font-size:13px;color:var(--muted);margin-top:5px}.who b{color:var(--body)}'
       . '.units{font-size:11.5px;color:var(--muted)}'
       /* the table */
       . '.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}'
       . 'table.t{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;'
       . 'min-width:680px}'
       . 'table.t th{padding:9px 6px;font-size:11px;font-weight:700;letter-spacing:.04em;'
       . 'text-transform:uppercase;color:var(--muted);background:var(--wash);'
       . 'border-bottom:1px solid var(--line);white-space:nowrap}'
       . 'table.t th.sub{text-transform:none;font-size:12.5px;color:var(--body)}'
       . 'table.t th.l,table.t td.l{border-left:1px solid var(--line)}'
       . 'table.t td{padding:9px 6px;border-top:1px solid var(--hair);vertical-align:middle}'
       . 'table.t td.l{border-left-color:var(--hair)}'
       /* the band that opens a department */
       . 'table.t tr.grp td{background:var(--wash);border-top:1px solid var(--line);'
       . 'border-bottom:1px solid var(--line);padding:8px 10px}'
       /* no doubled line: the band already closes with one */
       . 'table.t tr.grp + tr td{border-top:0}'
       . 'table.t tr.grp .gu{font-size:14px;font-weight:700;color:var(--ink)}'.'table.t tr.grp .gl{font-size:11px;color:var(--muted)}'
       . 'table.t tr.grp .gh2{font-size:13px;color:var(--body)}'
       . 'table.t tr.grp .gn{font-size:12px;color:var(--faint)}'
       . 'table.t tr.grp .gn b{color:var(--red)}'
       . 'table.t td.no{font-size:12px;color:var(--faint)}'
       . ''
       . 'table.t tr.ok td{background:var(--mint)}'
       . 'table.t tr.miss td{background:#fff1f2;box-shadow:inset 0 0 0 1px rgba(220,38,38,.3)}'
       . 'table.t tr:hover td{filter:brightness(.985)}'
       . '.nm{font-size:14px;font-weight:700;color:var(--ink);line-height:1.35}'
       /* Five days of EL in one month, marked beside the name — the same mark
        * the mail prints and the app shows, from the same rule. */
       . '.elb{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:9px;'
       . 'background:#fef2f2;color:var(--red);font-size:11px;font-weight:700;'
       . 'white-space:nowrap;vertical-align:1px}'
       /* The whole row washed, so a flagged person is found while scrolling
        * and not only while reading. The entry sub-row under it is washed
        * with it — see ackLeaveEntries(). */
       . 'tr.elrow>td,tr.elrow.ent>td{background:#fef2f2}'
       . '.un{font-size:11.5px;color:var(--muted);line-height:1.35}'
       . '.c{text-align:center}'
       /* A SECTION HEADING, LOUD ENOUGH TO SPLIT THE PAGE INTO THREE. The
        * number is a filled blue square — nothing else on the page looks
        * like one — and the rule beneath is the section's colour, not the
        * table's grey, so scrolling past one is obvious. */
       . '.sh{display:flex;align-items:center;gap:12px;padding:0 0 11px;'
       . 'border-bottom:3px solid var(--blue);margin:0 0 14px}'
       . '.sh .n{flex:0 0 auto;width:34px;height:34px;border-radius:8px;'
       . 'background:var(--blue);color:#fff;font-size:19px;font-weight:700;'
       . 'display:flex;align-items:center;justify-content:center}'
       . '.sh .t{flex:1;font-size:22px;font-weight:700;color:var(--ink);line-height:1.25}'
       . '.sh .c{font-size:22px;font-weight:700;color:var(--blue);white-space:nowrap}'
       . '.none{font-size:12.5px;color:var(--faint);padding:12px 0 2px}'
       /* the entries listed under a person's figures */
       . 'table.t tr.ent td{padding:1px 10px 11px;font-size:12px;color:var(--muted);'
       . 'line-height:1.75;border-top:0;text-align:left;vertical-align:top}'
       /* The leave dates sit under a centred figure, so they are centred
        * too; compliance keeps text-align:left, being prose across the
        * width of the table rather than dates under a number. */
       . 'table.t tr.ent.mid td{text-align:center}'
       . '.in,.out{font-size:13.5px;line-height:1.4;color:var(--body)}'
       . '.in .lb,.out .lb{font-size:10.5px;color:var(--faint);font-weight:400}'
       . '.bad{color:var(--red);font-weight:700}.lv{color:var(--blue);font-weight:700}'
       . '.lvcell{font-size:13px;line-height:1.35;color:var(--blue);font-weight:700}'
       . '.gh{color:var(--ghost)}'
       . '.tot{font-weight:700;color:var(--ink)}'
       . '.cmp{font-weight:700}'
       . 'input[type=checkbox]{width:44px;height:44px;accent-color:var(--blue);cursor:pointer;'
       . 'margin:0;flex:0 0 auto}'

       /* the one box that closes a section */
       . 'label.cfm{display:flex;gap:22px;align-items:center;cursor:pointer;'
       . 'margin-top:18px;padding:26px 28px;border:1px solid var(--line);border-radius:14px;'
       . 'background:var(--wash);transition:background .12s,border-color .12s}'
       . 'label.cfm:hover{background:#f1f5f9}'
       . 'label.cfm span{font-size:25px;font-weight:700;line-height:1.4;color:var(--blue)}'
       . 'label.cfm.on{border-color:var(--green);background:rgba(5,150,105,.07)}'
       . 'label.cfm.miss{border-color:var(--red);background:rgba(220,38,38,.06)}'
       . 'label.cfm input[disabled]{cursor:default}'

       /* bottom bar */
       . '.bar{background:var(--wash);text-align:center}'
       . '.bar .h{font-size:13.5px;font-weight:700;color:var(--ink)}'
       . '.bar .p{font-size:12px;color:var(--muted);margin:5px 0 14px}'
       /* the undertaking under the button */
       . '.bar .und{max-width:620px;margin:16px auto 0;font-size:13px;color:var(--body);'
       . 'line-height:1.6}'
       /* Three times the button it was. It is the one thing on the page that
        * has to be found without looking for it. */
       . '.btn{font:inherit;font-size:28px;font-weight:700;padding:24px 72px;border:0;'
       . 'border-radius:12px;background:var(--blue);color:#fff;cursor:pointer;'
       . 'max-width:100%}'
       . '.btn:hover{background:#1d4ed8}'
       /* A button that cannot be pressed yet says so by looking it. The
        * server still checks: this is a signpost, not the rule. */
       . '.btn[disabled]{background:var(--ghost);cursor:not-allowed}'
       . '.btn[disabled]:hover{background:var(--ghost)}'
       . '.btn.ghost{background:transparent;color:var(--blue);font-size:13px;'
       . 'font-weight:600;padding:10px 14px}'
       . '.btn.ghost:hover{background:rgba(37,99,235,.07)}'
       . '.btns{display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap}'

       . '.note-card{border-left:4px solid var(--line)}'
       . '.err{border-color:var(--red);background:rgba(220,38,38,.05)}'
       . '.err .h{color:var(--red);font-size:13.5px;font-weight:700}'
       . '.ok-c{border-color:var(--green);background:rgba(5,150,105,.07)}'
       . '.ok-c .h{color:var(--green);font-size:13.5px;font-weight:700}'
       . '.note-card .p{font-size:12.5px;color:var(--body);margin-top:3px}'
       . '.foot{font-size:10.5px;color:var(--faint);text-align:center;line-height:1.6;padding:4px 10px}'
       . '@media(max-width:560px){.card>.pad{padding:15px 16px}h1{font-size:19px}'
       . '}'
       . '</style></head><body><div class="wrap">';
}

/** Command: everything below the content. */
function ackFoot()
{
    echo '<div class="foot">Sent automatically every day except Sundays and public '
       . 'holidays.</div>'
       . '</div></body></html>';
}

/** Command: the page with one message on it, and stop. Every bad way in says
 *  the same thing, because telling a stranger WHICH part of their guess was
 *  wrong is how a token gets guessed. */
function ackStop($line, $status = 200)
{
    http_response_code($status);
    ackHead('Staff Summary');
    echo '<div class="card note-card err"><div class="pad">'
       . '<div class="h">This link cannot be opened</div>'
       . '<div class="p">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>'
       . '</div></div>';
    ackFoot();
    exit;
}

/** Query: one lateness cell, as the two lines the mail drew. The flags decide
 *  the colour and are worked out in staffSummaryShared.php — this only prints
 *  what it is told. */
function ackAttCell(array $cell)
{
    if ($cell['not_read']) {
        return '<span class="gh">' . SUM_DASH . '</span>';
    }
    /* $tone: '' plain, 'bad' red, 'lv' blue. Three states and not a boolean,
     * because "on leave" is neither of the other two. */
    $one = function ($cls, $label, $value, $tone) {
        return '<div class="' . $cls . ($tone !== '' ? ' ' . $tone : '') . '">'
             . '<span class="lb">' . $label . ' </span>'
             . ($value === SUM_DASH
                 ? '<span class="gh">' . SUM_DASH . '</span>'
                 : htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
             . '</div>';
    };

    /* A day off is one thing, said once: the leave and which leave it was. No
     * IN:/OUT: either side of it — they are labels for a day that had neither.
     * See $onLeave in staffSummaryShared.php. */
    if ($cell['on_leave']) {
        return '<div class="lvcell">'
             . htmlspecialchars($cell['leave_label'], ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /* Today carries the check-in and nothing else — see $showOut in
     * staffSummaryShared.php. */
    return $one('in', 'IN:', $cell['in'], ($cell['in_late'] || $cell['in_missing']) ? 'bad' : '')
         . ($cell['show_out']
             ? $one('out', 'OUT:', $cell['out'], $cell['out_missing'] ? 'bad' : '')
             : '');
}

/**
 * Command: the three sections — Lateness, Excessive Leaves, Compliance —
 * each a table of only the people it is about, still grouped by department,
 * with a real tick box on every row.
 *
 * THE SAME SHAPE AND ORDER THE MAIL USED. The groups arrive already built and
 * already sorted in the snapshot, so this only draws them: the page cannot put
 * a department somewhere the mail did not, nor a person in a section the mail
 * left them out of.
 *
 * ONE TICK A SECTION, AND NONE AGAINST A NAME. Each section ends in a single
 * box carrying the sentence it stands for; a head confirms they have read the
 * list, which is one act, not one act per person on it. See
 * summaryItemKeys().
 */
function ackSections(array $groups, array $dayCols, array $ticked, array $missing, $done, $ym)
{
    ackSection('1', 'Lateness', 'late', $groups, $dayCols, $ym, $ticked, $missing, $done,
               'Nobody was late or missing a check-in.');
    ackSection('2', 'Excessive Leaves (' . $ym . ')', 'leave', $groups, $dayCols, $ym,
               $ticked, $missing, $done, 'No leave taken or booked this month.');
    ackSection('3', 'Compliance (' . $ym . ')', 'comp', $groups, $dayCols, $ym,
               $ticked, $missing, $done, 'No compliance recorded this month.');
}

/** Command: one section — its heading, then a table of the people it covers. */
function ackSection($no, $heading, $kind, array $groups, array $dayCols, $ym,
                    array $ticked, array $missing, $done, $emptyLine)
{
    $flag = ($kind === 'late') ? 'in_late' : (($kind === 'leave') ? 'in_leave' : 'in_comp');

    $shown = array();
    $total = 0;
    foreach ($groups as $g) {
        $keep = array();
        foreach (isset($g['rows']) ? $g['rows'] : array() as $r) {
            if (!empty($r[$flag])) { $keep[] = $r; }
        }
        if (!empty($keep)) {
            $g['rows'] = $keep;
            $shown[]   = $g;
            $total    += count($keep);
        }
    }

    echo '<div class="card"><div class="pad">'
       . '<div class="sh">'
       . '<div class="n">' . htmlspecialchars($no, ENT_QUOTES, 'UTF-8') . '</div>'
       . '<div class="t">' . $heading . '</div>'
       . '<div class="c">' . $total . '</div></div>';

    if ($total === 0) {
        /* NO BOX ON AN EMPTY SECTION. There is nothing to have understood,
         * and a tick demanded against an empty list is a tick that means
         * nothing. summaryItemKeys() leaves it out of the total too, so it
         * cannot hold the acknowledgement up. */
        echo '<div class="none">' . htmlspecialchars($emptyLine, ENT_QUOTES, 'UTF-8')
           . '</div></div></div>';
        return;
    }

    $cols = ($kind === 'late') ? 2 + count($dayCols) : (($kind === 'leave') ? 4 : 3);

    echo '<div class="scroll"><table class="t"><thead><tr>'
       . '<th style="width:44px">No</th>'
       . '<th style="text-align:left">Staff</th>';

    if ($kind === 'late') {
        foreach ($dayCols as $col) {
            echo '<th class="sub l" style="width:136px">'
               . htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8') . '</th>';
        }
    } elseif ($kind === 'leave') {
        foreach (array(array('Taken', 240), array('Upcoming', 240)) as $c) {
            echo '<th class="sub l" style="width:' . $c[1] . 'px">' . $c[0] . '</th>';
        }
    } else {
        echo '<th class="sub l" style="width:108px">Records</th>';
    }
    echo '</tr></thead><tbody>';

    $n = 0;
    foreach ($shown as $g) {
        $uw = isset($g['unit_word']) ? $g['unit_word'] : 'Department';
        $hw = isset($g['head_word']) ? $g['head_word'] : 'Department Head';
        echo '<tr class="grp"><td colspan="' . $cols . '">'
           . '<span class="gl">' . htmlspecialchars($uw, ENT_QUOTES, 'UTF-8') . ': </span>'
           . '<span class="gu">' . htmlspecialchars($g['unit'], ENT_QUOTES, 'UTF-8') . '</span>'
           . ($g['head_name'] !== ''
               ? '<span class="gl"> &nbsp;&nbsp;'
                 . htmlspecialchars($hw, ENT_QUOTES, 'UTF-8') . ': </span>'
                 . '<span class="gh2">'
                 . htmlspecialchars($g['head_name'], ENT_QUOTES, 'UTF-8') . '</span>'
               : '')
           . '</td></tr>';

        foreach ($g['rows'] as $row) {
            /* THREE DAYS OF EL, OR THREE OF LWP, IN ONE MONTH — beside the
             * name, one pill a code. The rule lives in summaryLeaveMarks()
             * and is shared with the mail and the app, so the three cannot
             * flag different people. */
            $elMarks = ($kind === 'leave' && $ym !== '')
                     ? summaryLeaveMarks($row, $ym) : array();
            $elRow   = !empty($elMarks);
            $elPills = '';
            foreach ($elMarks as $m) {
                $elPills .= '<span class="elb">'
                          . htmlspecialchars($m['text'], ENT_QUOTES, 'UTF-8') . '</span>';
            }

            echo '<tr' . ($elRow ? ' class="elrow"' : '') . '>'
               . '<td class="c no">' . (++$n) . '</td>'
               . '<td><div class="nm">'
               . htmlspecialchars($row['fullname'], ENT_QUOTES, 'UTF-8')
               . $elPills
               . '</div>'
               . ($row['position'] !== ''
                   ? '<div class="un">'
                     . htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8') . '</div>'
                   : '')
               . '</td>';

            if ($kind === 'late') {
                foreach ($dayCols as $col) {
                    echo '<td class="c l">' . ackAttCell($row['att'][$col['date']]) . '</td>';
                }
                echo '</tr>';
                continue;
            }

            if ($kind === 'leave') {
                foreach (array($row['leave']['taken'], $row['leave']['upcoming']) as $v) {
                    $txt = summaryNum($v);
                    echo '<td class="c l tot">'
                       . ($txt === '' ? '<span class="gh">' . SUM_DASH . '</span>'
                                      : htmlspecialchars($txt, ENT_QUOTES, 'UTF-8'))
                       . '</td>';
                }
                echo '</tr>';
                /* Each application under the figure it belongs to — begun
                 * under Taken, not yet begun under Upcoming. */
                $taken = $coming = array();
                foreach (isset($row['leaves']) ? $row['leaves'] : array() as $l) {
                    $line = ($l['code'] !== '' ? $l['code'] : 'Leave')
                          . ' - ' . $l['from'] . ' to ' . $l['to'];
                    if (isset($l['when']) && $l['when'] === 'upcoming') {
                        $coming[] = $line;
                    } else {
                        $taken[] = $line;
                    }
                }
                ackLeaveEntries($taken, $coming, $elRow);
                continue;
            }

            echo '<td class="c l cmp" style="color:var(--red)">' . (int)$row['comp']
               . '</td></tr>';
            $items = array();
            foreach (isset($row['comp_recs']) ? $row['comp_recs'] : array() as $i => $r) {
                $items[] = ($i + 1) . '. ' . $r['date'] . ' - '
                         . ($r['title'] !== '' ? $r['title'] : $r['type']);
            }
            /* Compliance has one figure and a long description, so its
             * entries run from the name across. */
            ackEntries($items, 1, 2);
        }
    }
    echo '</tbody></table></div>';

    /* THE ONE BOX THAT CLOSES THE SECTION, under the table it is about. */
    $on   = isset($ticked[$kind]);
    $miss = isset($missing[$kind]);
    echo '<label class="cfm' . ($miss ? ' miss' : ($on ? ' on' : '')) . '" for="c' . $kind . '">'
       . '<input type="checkbox" id="c' . $kind . '" name="items[]" '
       . 'value="' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '"'
       . ($on ? ' checked' : '') . ($done ? ' disabled' : '') . '>'
       . '<span>' . htmlspecialchars(summaryConfirmLine($kind), ENT_QUOTES, 'UTF-8')
       . '</span></label>';

    echo '</div></div>';
}

/** Command: the leave sub-row — taken applications under Taken, upcoming
 *  under Upcoming, each numbered from 1 within its own column. */
function ackLeaveEntries(array $taken, array $coming, $tinted = false)
{
    if (empty($taken) && empty($coming)) {
        return;
    }
    echo '<tr class="ent mid' . ($tinted ? ' elrow' : '') . '"><td></td><td></td>';
    foreach (array($taken, $coming) as $items) {
        echo '<td>';
        foreach ($items as $i => $line) {
            echo '<div>' . ($i + 1) . '. '
               . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
    }
    echo '</tr>';
}

/** Command: the sub-row listing a person's entries under their figures.
 *  $skip empty cells, then the list across $span of them — which is what
 *  lands it under the columns it belongs to, exactly as the mail does it. */
function ackEntries(array $items, $skip, $span)
{
    if (empty($items)) {
        return;
    }
    echo '<tr class="ent">' . str_repeat('<td></td>', (int)$skip)
       . '<td colspan="' . (int)$span . '">';
    foreach ($items as $line) {
        echo '<div>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '</td></tr>';
}

/* ---------- the request --------------------------------------------------- */

$token = isset($_GET['t']) ? trim((string)$_GET['t'])
       : (isset($_POST['t']) ? trim((string)$_POST['t']) : '');

/* Shape-checked before it is ever sent to the database: a token is 64 hex
 * characters and nothing else is worth a query. */
if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
    ackStop('Open a box in the email again, or ask for the summary to be re-sent.', 400);
}

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('staffSummaryAck db: ' . $e->getMessage());
    ackStop('The portal is not reachable at the moment. Please try again shortly.', 503);
}

try {
    $stmt = $pdo->prepare(
        /* comp_id is read although this page never shows it: summaryAckWrite()
         * writes it to the event log, and a row logged under the wrong company
         * would be worse than one not logged at all. */
        'SELECT id, notify_date, person, comp_id, fullname, role, payload, item_count,
                acked_datetime, acked_items, acked_platform,
                opened_datetime, opened_platform
           FROM mkPortal.staff_summary_notify
          WHERE token = :t');
    $stmt->execute(array(':t' => $token));
    $row = $stmt->fetch();

    if (!$row) {
        ackStop('Open a box in the email again, or ask for the summary to be re-sent.', 404);
    }

    $payload = json_decode((string)$row['payload'], true);
    $groups  = (is_array($payload) && isset($payload['groups']))   ? $payload['groups']   : array();
    $dayCols = (is_array($payload) && isset($payload['day_cols'])) ? $payload['day_cols'] : array();

    /* Flattened once, for the counts and the item keys. The table itself is
     * drawn from the groups, so the page shows what the mail showed. */
    $rows = array();
    foreach ($groups as $g) {
        foreach (isset($g['rows']) ? $g['rows'] : array() as $r) { $rows[] = $r; }
    }

    /* Every tick box on the page — 'late', 'leave', 'comp', and only those
     * of the three with somebody in them. A posted value that is not one of
     * these counts for nothing. */
    $want = summaryItemKeys($groups);

    /* What has been ticked so far, from the email links and from any earlier
     * submission. summaryTickedSoFar() filters it against $want, so a stale
     * key from a re-sent table cannot inflate the progress. */
    $ticked = summaryTickedSoFar($row['acked_items'], $want);

    $done    = !empty($row['acked_datetime']);
    $missing = array();
    $shortBy = 0;
    $justOne = '';

    /* WHERE THIS PAGE'S ANSWERS COME FROM. A box pressed in the MAIL arrives
     * here as ?tick=, so that route is recorded as 'email' — it is the mail
     * that was acted on, and the browser is only how it got here. Anything
     * else on this page is 'web'. The app uses staffSummaryAPI.php and says
     * 'mobile'. See summaryPlatforms(). */
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $ip    = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    /* ---- a submitted form ---- */
    /* POST from this page, OR GET from the one in the email. The mail's form
     * has to be a GET — a mail client that submits at all does it by opening
     * a browser, and a POST would arrive as a page nobody can reload — so
     * both are accepted and treated identically. A GET WITHOUT items[] is
     * just somebody opening the link, which is why the test is on the
     * parameter being there rather than on the method. */
    $isPost   = ($_SERVER['REQUEST_METHOD'] === 'POST');
    $sentForm = $isPost || isset($_GET['items']);

    if (!$done && $sentForm) {
        $src    = $isPost ? $_POST : $_GET;
        $posted = isset($src['items']) && is_array($src['items']) ? $src['items'] : array();

        /* REPLACE: the submission IS the answer, so un-ticking a box here
         * means what it looks like. A GET from the mail is its own complete
         * answer too — it carries every box that client rendered. */
        $res    = summaryAckWrite($pdo, $row, $want, $posted,
                                  $isPost ? 'web' : 'email', true, $ip, $agent);
        $ticked = $res['ticked'];
        $done   = $res['complete'];

        if (!$done) {
            foreach ($want as $k => $_) {
                if (!isset($ticked[$k])) { $missing[$k] = true; }
            }
            $shortBy = count($missing);
        }
    } elseif (!$done && isset($_GET['tick'])) {
        /* ---- one box pressed in the email ---- */
        /* ADD, not replace: a link can only ever say yes, so arriving twice
         * is arriving once and nothing here can un-tick anything. */
        $one = (string)$_GET['tick'];
        if (isset($want[$one])) {
            $res     = summaryAckWrite($pdo, $row, $want, array($one), 'email',
                                       false, $ip, $agent);
            $ticked  = $res['ticked'];
            $done    = $res['complete'];
            $justOne = $one;
        }
    } else {
        /* First time the link was followed. Separate from acknowledging on
         * purpose: opening a mail is not agreeing with it. */
        summaryAckOpened($pdo, $row, 'web', $ip, $agent);
    }

    if ($done && empty($row['acked_datetime'])) {
        $row['acked_datetime'] = date('Y-m-d H:i:s');
    }

    /* ---- the page ---- */
    $who   = $row['fullname'] !== '' ? $row['fullname'] : $row['person'];
    $units = (is_array($payload) && isset($payload['recipient']['units']))
        ? implode(' · ', array_slice($payload['recipient']['units'], 0, 4)) : '';
    $total   = count($want);

    ackHead('Staff Summary ' . $row['notify_date']);

    /* LABELLED, NOT JUST LISTED — the same two lines the mail leads with, and
     * worded the same way: Department / Department Head for an HOD, District /
     * Area Manager for an Area Manager. The words travel on the first group
     * the recipient actually heads. */
    $uw = 'Department'; $hw = 'Department Head'; $ownU = array();
    foreach ($groups as $g) {
        if (!empty($g['is_own'])) {
            $ownU[] = $g['unit'];
            if (isset($g['unit_word'])) { $uw = $g['unit_word']; $hw = $g['head_word']; }
        }
    }
    if (empty($ownU) && $units !== '') { $ownU = array($units); }

    echo '<div class="card"><div class="pad mid">'
       . '<div class="eyebrow">'
       . htmlspecialchars('Staff Summary ' . $row['notify_date'], ENT_QUOTES, 'UTF-8') . '</div>'
       . '<table class="hdr"><tr><th>' . htmlspecialchars($uw, ENT_QUOTES, 'UTF-8') . ':</th>'
       . '<td>' . htmlspecialchars(implode(' · ', array_slice($ownU, 0, 3)), ENT_QUOTES, 'UTF-8')
       . '</td></tr><tr><th>' . htmlspecialchars($hw, ENT_QUOTES, 'UTF-8') . ':</th>'
       . '<td>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</td></tr></table>'
       . '</div></div>';

    /* ---- EVERYTHING TICKED: say so, and stop ---- */
    /* The whole table again, greyed out, was the wrong answer to "done". It
     * asks somebody to re-read three sections to find out there is nothing to
     * do, when one line says it. The record is already written; this page is
     * only the receipt. */
    if ($done) {
        echo '<div class="card ok-c" style="border-left:4px solid var(--green)">'
           . '<div class="pad" style="text-align:center;padding:34px 22px">'
           . '<div style="font-size:21px;font-weight:700;color:var(--green)">'
           . 'Acknowledged</div>'
           . '<div style="font-size:17px;font-weight:700;color:var(--ink);margin-top:6px">'
           . htmlspecialchars('Staff Summary ' . $row['notify_date'], ENT_QUOTES, 'UTF-8')
           . '</div>'
           . '<div style="font-size:12.5px;color:var(--muted);margin-top:10px">'
           . 'Recorded at '
           . htmlspecialchars($row['acked_datetime'], ENT_QUOTES, 'UTF-8')
           . '.</div></div></div>';
        ackFoot();
        exit;
    }

    if ($shortBy > 0) {
        echo '<div class="card note-card err"><div class="pad">'
           . '<div class="h">Please tick every box</div>'
           . '<div class="p">' . $shortBy . ' of ' . $total . ' not ticked yet, marked below. '
           . 'Your ticks are saved. Nothing has been acknowledged.'
           . '</div></div></div>';
    } elseif ($justOne !== '') {
        echo '<div class="card note-card ok-c"><div class="pad">'
           . '<div class="h">Ticked from the email</div>'
           . '<div class="p">' . count($ticked) . ' of ' . $total . ' ticked. '
           . 'Tick the rest, then press Acknowledge.</div></div></div>';
    }

    echo '<form method="post" id="f">'
       . '<input type="hidden" name="t" value="'
       . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';

    /* Each section draws its own card, so there is no wrapper here. */
    ackSections($groups, $dayCols, $ticked, $missing, $done,
                summaryMonthLabel($row['notify_date']));

    if (!$done) {
        echo '<div class="card bar"><div class="pad">'
           . '<div class="h">'
           . ($total === 0 ? 'Nothing to read today' : 'Tick every box above')
           . '</div>'
           . '<div class="p" id="left"></div>'
           . '<div class="btns">'
           . '<button type="submit" class="btn" id="go">Acknowledge</button>'
           . '</div>'
           /* NOTHING UNDER THE BUTTON. "Acknowledge records my name and the
            * time for <date>" told the reader what the record keeps rather
            * than what they are saying, and the boxes above already say that
            * in the first person. What is recorded has not changed. */
           . '</div></div>';
    }
    echo '</form>';

    if (!$done) {
        /* THE BUTTON IS HELD UNTIL EVERY SECTION IS TICKED, which is what
         * was asked for. With three boxes the reason is never a mystery —
         * the untickied one is a few lines up and the line under the button
         * names how many are left — so this does not strand anybody the way
         * disabling a 140-box form would have.
         *
         * THE SERVER STILL CHECKS. This is a signpost; the rule is the
         * count against $want above, which a browser with no JavaScript, or
         * a hand-built request, meets or does not. */
        echo '<script>(function(){'
           . 'var b=[].slice.call(document.querySelectorAll(\'input[name="items[]"]\')),'
           . 'left=document.getElementById("left"),go=document.getElementById("go");'
           . 'function sync(){var n=0;'
           . 'b.forEach(function(x){var l=x.closest("label.cfm");if(x.checked){n++;'
           . 'if(l){l.classList.remove("miss");l.classList.add("on");}}'
           . 'else if(l){l.classList.remove("on");}});'
           . 'var short=b.length-n;'
           . 'left.textContent=short===0?(b.length?"All ticked.":"Nothing to tick."):'
           . '(short+" of "+b.length+" not ticked yet.");'
           . 'go.disabled=short>0;}'
           . 'b.forEach(function(x){x.addEventListener("change",sync);});'
           . 'sync();'
           /* Land on the first unticked section after a short submission, so
            * the outline is on screen rather than somewhere below the fold. */
           . 'var m=document.querySelector("label.cfm.miss");'
           . 'if(m)m.scrollIntoView({block:"center"});'
           . '})();</script>';
    }

    ackFoot();

} catch (Exception $e) {
    error_log('staffSummaryAck: ' . $e->getMessage());
    if (strpos($e->getMessage(), 'staff_summary_notify') !== false) {
        ackStop('The acknowledgement table is not installed on this server yet. '
              . 'Run sql/staff_summary_notify.sql.', 500);
    }
    ackStop('Something went wrong recording that. Please try again shortly.', 500);
}
