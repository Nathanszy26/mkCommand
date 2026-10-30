<?php

/**
 * oe_summary_lib.php — Summary O&E Performance report library.
 *
 * Mirrors the architecture that already works in heatmap_lib.php (small classes,
 * CQS split, dated history rows) but for a different report SHAPE:
 *
 *   heatmap : item -> one frequency -> current/previous period -> 4 colour buckets
 *   O&E     : routine -> a COLUMN MODE -> two labelled date ranges (A and B)
 *                     -> an ORDERED LIST OF METRIC LINES supplied by the API
 *
 * Why a separate module rather than a third report_type in heatmap_lib:
 * the period model, the payload contract and the layout are all different, and
 * scorecard_heatmap_results.report_type is an ENUM('heatmap','ranking') on a live
 * table. Bolting this on would mean schema churn plus a renderer that branches
 * three ways. Two small parallel modules beat one wide one.
 *
 * The one invariant everything is built around (same as the heatmap):
 *   Both columns are fetched from the API on every run and written as ONE dated
 *   history row per (item, period, generation-day). Yesterday's row for the same
 *   period is the day-over-day baseline — that is what produces the change
 *   figures without ever recomputing history.
 *
 * Class order matters (PHP compiles top-to-bottom).
 */

/* ============================================================================
 * OeDatabase — PDO provider, fail-loud.
 *
 * NOTE: these are the same credentials heatmap_lib.php's Database class uses.
 * They are duplicated here rather than shared because extracting them would mean
 * editing that live file. If you want one source of truth, pull this block into
 * a db_config.php and have both classes read it — 3 lines each side.
 * ========================================================================== */
class OeDatabase
{
    const HOST = 'localhost';
    const USER = 'root';
    const PASS = 'kRXvkeUGyi5er7';
    const NAME = 'scorecard_heatmap';   // config + results tables live alongside the heatmap's

    private static $pdo = null;

    public static function conn()
    {
        if (self::$pdo === null) {
            try {
                $dsn = 'mysql:host=' . self::HOST . ';dbname=' . self::NAME . ';charset=utf8mb4';
                $pdo = new PDO($dsn, self::USER, self::PASS);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo = $pdo;
            } catch (PDOException $e) {
                throw new RuntimeException('DB connection failed (' . self::NAME . '): ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}


/* ============================================================================
 * ColumnPairResolver — column_mode -> the two date ranges the report shows.
 *
 * This is the whole "which two columns does this routine get" rule, in one
 * switch. Add a mode here and any routine can use it from the items table.
 *
 *   mtd_ytd             Month to Date        | Year to Date          (routines 1, 2)
 *   ro3week_cur_last    Reporting Week       | Previous Week         (routines 3, 4, 5&7)
 *   month_this_last     This Month           | Last Month            (routine 6)
 *   month_last_prev     Last Month           | Previous Month        (Routine Subcon Assessment)
 *   ro3week_this_last   This Week            | Last Week             (routine 7)
 *   sunweek_this_last   This Week            | Last Week             (Periodic Progress)
 *   day_today_yesterday Today                | Yesterday             (routine 8)
 *   day_today_mtd       Today                | Month to Date         (Emergency Progress)
 *   day_yesterday_prev  Yesterday            | The day before it
 *   week_last_prev / week_this_last          ISO Mon-Sun equivalents of the two
 *                                            week modes, if a routine files by
 *                                            ISO week instead of RO3 weeks.
 *
 *
 * THREE different weeks live in this switch, and they are not interchangeable.
 * Each one matches how its SOURCE TABLE files a week, which is the only thing
 * that makes the range line up with the data:
 *   ro3week_*  RO3 calendar-month weeks  - weekly_am_reports.report_week
 *   week_*     ISO Mon-Sun               - sources with no week of their own
 *   sunweek_*  Sun-Sat                   - pmw_progress.cDate, which carries a
 *                                          SUNDAY date (worksProgramSummary.php
 *                                          anchors both its bars on Sunday)
 * Putting a Sunday-filed source on ISO weeks is not a cosmetic one-day shift:
 * the ISO week IN PROGRESS never reaches its own Sunday, so the whole week's
 * claim lands in the PREVIOUS column and the current one reads 0.00% every
 * weekday. It looks like "no work done", not like a mis-set boundary.
 * "Week" defaults to the RO3 calendar-month week (1-7, 8-14, 15-21, 22-end)
 * because that is how the AM weekly reports are actually filed — see
 * ro3_grass_heatmap.php. Do not assume ISO weeks for those routines; they do not
 * line up and the query silently returns nothing.
 *
 * period key = the stable identity written to the results table, so a range
 * computed today is the same key looked up tomorrow as the baseline.
 * ========================================================================== */
class ColumnPairResolver
{
    /**
     * How a column's change figure is measured. This lives with the period pair
     * because it IS a property of the period pair, not of the routine reading
     * it: "Month to Date" against yesterday's Month to Date is a real
     * day-over-day movement, while "Previous Week" against yesterday's
     * Previous Week is not a movement at all — the week is closed, only the
     * clock moved, and any figure there is a late resubmission.
     *
     * Every case below states its own rule; OeSummaryService::baseline() is the
     * only reader, and an unknown value throws there rather than defaulting.
     *
     *   DELTA_DAY   this column's own previous generation-day (the default)
     *   DELTA_PEER  the OTHER column of the pair — period over period
     *   DELTA_NONE  no change figure at all
     */
    const DELTA_DAY  = 'day';
    const DELTA_PEER = 'peer';
    const DELTA_NONE = 'none';

    /** @return array ['a'=>descriptor, 'b'=>descriptor]
     *  @throws InvalidArgumentException on an unknown mode (fail loud). */
    public static function resolve($modeRaw, ?DateTime $today = null)
    {
        $today = $today ? clone $today : new DateTime('today');
        $mode  = strtolower(trim((string)$modeRaw));

        switch ($mode) {

            case 'mtd_ytd':
                return [
                    'a' => self::desc($today->format('Y-m'), (clone $today)->modify('first day of this month'), clone $today, 'Month to Date'),
                    'b' => self::desc($today->format('Y'), new DateTime($today->format('Y') . '-01-01'), clone $today, 'Year to Date'),
                ];

            case 'month_this_last':
                $lastStart = (clone $today)->modify('first day of last month');
                return [
                    'a' => self::desc($today->format('Y-m'), (clone $today)->modify('first day of this month'), clone $today, 'This Month'),
                    'b' => self::desc($lastStart->format('Y-m'), $lastStart, (clone $lastStart)->modify('last day of this month'), 'Last Month'),
                ];

            // Both columns are WHOLE, CLOSED months — neither is a month in progress,
            // unlike month_this_last's column A. That matters for any source keyed by
            // yearmonth rather than by date: a partial month would report a partial
            // figure as if it were final.
            case 'month_last_prev':
                $lastStart = (clone $today)->modify('first day of last month');
                $prevStart = (clone $lastStart)->modify('first day of last month');
                return [
                    'a' => self::desc($lastStart->format('Y-m'), $lastStart, (clone $lastStart)->modify('last day of this month'), 'Last Month'),
                    'b' => self::desc($prevStart->format('Y-m'), $prevStart, (clone $prevStart)->modify('last day of this month'), 'Previous Month'),
                ];

            case 'week_this_last':
                $thisStart = self::isoWeekStart($today);
                $lastStart = (clone $thisStart)->modify('-7 days');
                return [
                    'a' => self::isoDesc($thisStart, clone $today, 'This Week'),
                    'b' => self::isoDesc($lastStart, (clone $lastStart)->modify('+6 days'), 'Last Week'),
                ];

            // Sun-Sat, matching worksProgramSummary.php's own week (lines 19-28:
            // 'this sunday' minus a week, through the Saturday after it). Column A
            // is the week in progress and ends TODAY, so the pair reproduces that
            // report's two bars exactly: "As at <today>" against "Last week <the
            // preceding Saturday>".
            //
            // No change chips (DELTA_NONE on both). Line (b) of this routine is
            // ALREADY the period-over-period movement — it is column A's
            // cumulative minus column B's cumulative, computed in the endpoint
            // against the one denominator. A day-over-day chip beside it would
            // be a second, differently-measured "how much did it move" on the
            // same row, and the two do not agree: the chip moves whenever a
            // claim is backdated into a closed week, the line does not.
            case 'sunweek_this_last':
                $thisStart = self::sundayWeekStart($today);
                $lastStart = (clone $thisStart)->modify('-7 days');
                return [
                    'a' => self::sunDesc($thisStart, clone $today, 'This Week', self::DELTA_NONE),
                    'b' => self::sunDesc($lastStart, (clone $lastStart)->modify('+6 days'), 'Last Week', self::DELTA_NONE),
                ];

            case 'week_last_prev':
                $lastStart = (clone self::isoWeekStart($today))->modify('-7 days');
                $prevStart = (clone $lastStart)->modify('-7 days');
                return [
                    'a' => self::isoDesc($lastStart, (clone $lastStart)->modify('+6 days'), 'Last Week'),
                    'b' => self::isoDesc($prevStart, (clone $prevStart)->modify('+6 days'), 'Previous Week'),
                ];

            case 'ro3week_this_last':
                $cur  = self::ro3Week($today);
                $last = self::ro3Week((new DateTime($cur['start']))->modify('-1 day'));
                return [
                    // Column A is the week in progress, so it ends TODAY, not on the week's last day.
                    'a' => self::desc($cur['key'], new DateTime($cur['start']), clone $today, 'This Week'),
                    'b' => self::desc($last['key'], new DateTime($last['start']), new DateTime($last['end']), 'Last Week'),
                ];

            // "Reporting Week" — the RO3 week IN PROGRESS, against the week
            // before it. Column A is the week the report is generated INSIDE: a
            // report dated 31 Aug reads 22-31 Aug, not 15-21. Reading the last
            // CLOSED week instead put a fortnight-old figure under a heading the
            // reader takes as current, and pushed "Previous Week" back to a week
            // two removed from the one being read.
            //
            // Column A spans the WHOLE current week — to the week's own last day,
            // not to today. The sources file ONE row per week keyed on
            // weekly_am_reports.report_week, a fixed date inside the week; cut the
            // window at today and that row falls outside its own column on every
            // day before that date, and column A reads zero. Nothing is filed past
            // the week's end, so the wider window adds nothing — it only stops the
            // boundary from slicing the week's single row out of the week.
            //
            // Column A's chip is the gap ACROSS THE PAIR (DELTA_PEER), not
            // against yesterday. Week over week is the movement being read for:
            // previous 66.927, reporting 102.250, chip (+35.323). A day-over-day
            // chip here would report submissions arriving, not delay moving.
            //
            // Column B carries no chip. It is the baseline the chip beside it is
            // measured from, and a chip there could only be measured against the
            // column to ITS left, which is not on the page.
            case 'ro3week_last_prev':   // deprecated key — un-migrated oe_summary_items rows
            case 'ro3week_cur_last':
                $cur  = self::ro3Week($today);
                $last = self::ro3Week((new DateTime($cur['start']))->modify('-1 day'));
                return [
                    'a' => self::desc($cur['key'], new DateTime($cur['start']), new DateTime($cur['end']),
                                      'Reporting Week', null, self::DELTA_PEER),
                    'b' => self::desc($last['key'], new DateTime($last['start']), new DateTime($last['end']),
                                      'Previous Week',  null, self::DELTA_NONE),
                ];

            case 'day_today_yesterday':
                $yest = (clone $today)->modify('-1 day');
                return [
                    'a' => self::desc($today->format('Y-m-d'), clone $today, clone $today, 'Today'),
                    'b' => self::desc($yest->format('Y-m-d'), $yest, clone $yest, 'Yesterday'),
                ];

            // Today (a day IN PROGRESS) against the month it sits in, to the
            // same moment. Both columns end TODAY, so a range-driven endpoint
            // answers the same question at two widths — that is the whole point
            // of the pair.
            //
            // Column A's key is the DATE, which occurs on exactly one
            // generation day, so "Today" never carries a change figure and
            // never should: yesterday's Today is a different period, not an
            // earlier reading of this one. Column B's key is the MONTH, so its
            // dated history does give a real day-over-day delta — that delta IS
            // today's contribution to the month.
            //
            // Caveat worth knowing before you wire a routine to this mode: a
            // day in progress is only final at midnight, and refresh() runs
            // when the report is generated. The 06:00 cron mail therefore shows
            // 06:00's figure in column A. The web and ?test=1 paths refresh on
            // load, so a page opened in the afternoon shows the afternoon's.
            case 'day_today_mtd':
                return [
                    'a' => self::desc($today->format('Y-m-d'), clone $today, clone $today,
                                      'Today', $today->format('d M Y'), self::DELTA_NONE),
                    'b' => self::desc($today->format('Y-m'), (clone $today)->modify('first day of this month'), clone $today,
                                      'Month to Date', null, self::DELTA_NONE),
                ];

            // Two CLOSED days: yesterday against the day before it. Not
            // day_today_yesterday — today is a day in progress, and a source
            // whose cases are still being closed reports a partial figure that
            // keeps climbing until midnight. Both columns here are final.
            case 'day_yesterday_prev':
                return [
                    'a' => self::dayDesc((clone $today)->modify('-1 day')),
                    'b' => self::dayDesc((clone $today)->modify('-2 days')),
                ];
        }

        throw new InvalidArgumentException("Unsupported column_mode: '{$modeRaw}'");
    }

    private static function isoWeekStart(DateTime $d)
    {
        $s   = clone $d;
        $dow = (int)$s->format('N');
        if ($dow > 1) $s->modify('-' . ($dow - 1) . ' days');
        return $s;
    }

    private static function isoDesc(DateTime $start, DateTime $end, $label)
    {
        return self::desc($start->format('o') . '-W' . $start->format('W'), $start, $end, $label);
    }

    /**
     * A single closed day. The LABEL is the date and the caption is empty: a
     * one-day column headed "Yesterday" over the range "23 Jul - 23 Jul" says
     * the same thing twice, and "Yesterday" stops being true the moment the
     * report is printed, mailed or read the next morning. The date is the only
     * caption that stays correct wherever the page ends up.
     */
    private static function dayDesc(DateTime $d)
    {
        return self::desc($d->format('Y-m-d'), clone $d, clone $d, $d->format('d M Y'), '');
    }

    /** Sunday that opens the Sun-Sat week containing $d. */
    private static function sundayWeekStart(DateTime $d)
    {
        $s = clone $d;
        $dow = (int)$s->format('w');   // 0 = Sunday
        if ($dow > 0) $s->modify('-' . $dow . ' days');
        return $s;
    }

    /**
     * Sun-Sat descriptor. Keyed on the week's OPENING DATE, not on an ISO week
     * number: a Sunday-anchored week straddles two ISO weeks, so an ISO number
     * would name the wrong one and two consecutive weeks could resolve to the
     * same key. The start date is unique and fixed for the whole week, which is
     * what the results table needs to look a period up again tomorrow.
     */
    private static function sunDesc(DateTime $start, DateTime $end, $label, $delta = self::DELTA_DAY)
    {
        // Captioned "As at <end>", not as a range: this pair backs a CUMULATIVE
        // figure, and the two columns are the two bars worksProgramSummary.php
        // draws — "As at 24 Jul" against "Last week 18 Jul". Printing the span
        // instead would read as work done DURING the week, which is a different
        // number sitting on the line below it.
        return self::desc(
            'W' . $start->format('Y-m-d'),
            $start,
            $end,
            $label,
            'As at ' . $end->format('d M Y'),
            $delta
        );
    }

    /** RO3 calendar-month week containing $d: 1-7, 8-14, 15-21, 22-end. */
    private static function ro3Week(DateTime $d)
    {
        $y   = (int)$d->format('Y');
        $m   = (int)$d->format('n');
        $day = (int)$d->format('j');

        if     ($day <= 7)  { $s = 1;  $e = 7;                    $w = 1; }
        elseif ($day <= 14) { $s = 8;  $e = 14;                   $w = 2; }
        elseif ($day <= 21) { $s = 15; $e = 21;                   $w = 3; }
        else                { $s = 22; $e = (int)$d->format('t'); $w = 4; }

        return [
            'key'   => sprintf('%04d-%02d-W%d', $y, $m, $w),
            'start' => sprintf('%04d-%02d-%02d', $y, $m, $s),
            'end'   => sprintf('%04d-%02d-%02d', $y, $m, $e),
        ];
    }

    /**
     * @param string|null $caption overrides the caption printed under the column
     *        label. Default is the range the column covers, which is right for a
     *        metric AGGREGATED over that range. A metric that is a SNAPSHOT at
     *        the end of the range has to say so instead — see sunDesc() — or the
     *        reader takes a cumulative total for a period total.
     *        Caption only: 'start' and 'end' are untouched, so what the endpoint
     *        is asked for never depends on how the column is captioned.
     * @param string $delta one of the DELTA_* constants — how this column's
     *        change figure is measured. Caption and delta are both presentation
     *        of the SAME period; neither changes what the endpoint is asked for.
     */
    private static function desc($key, DateTime $start, DateTime $end, $label, $caption = null, $delta = self::DELTA_DAY)
    {
        return [
            'key'   => $key,
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
            'label' => $label,
            'delta' => $delta,
            'range' => ($caption !== null)
                ? $caption
                : $start->format('d M') . ' - ' . $end->format('d M Y'),
        ];
    }
}


/* ============================================================================
 * OeResultRepository — the only class that touches oe_summary_results.
 *   getSnapshot()  query   — latest row for a period + the PRIOR day's row.
 *   saveSnapshot() command — idempotent upsert of today's row.
 * ========================================================================== */
class OeResultRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Latest snapshot for a period, with the previous GENERATION-DAY's snapshot
     * of the same period attached as 'prev_payload'. That second row is the whole
     * point of the dated history: it is "what this same column said yesterday",
     * which is what the change figures are measured against.
     */
    public function getSnapshot($itemId, $periodKey)
    {
        $stmt = $this->pdo->prepare(
            "SELECT payload, period_key, period_start, period_end, period_generated_date, fetched_at
             FROM oe_summary_results
             WHERE item_id = :item_id AND period_key = :period_key AND status = '1'
             ORDER BY period_generated_date DESC
             LIMIT 2"
        );
        $stmt->execute([':item_id' => $itemId, ':period_key' => $periodKey]);
        $rows = $stmt->fetchAll();
        if (!$rows) return null;

        $latest = $rows[0];
        $latest['payload']      = self::decode($latest['payload']);
        $latest['prev_payload'] = isset($rows[1]) ? self::decode($rows[1]['payload']) : null;
        return $latest;
    }

    /**
     * Upsert ONE dated row per (item, period, generation-day). Same-day re-runs
     * update today's row; a new day inserts a new one, so each period keeps a
     * daily trail. REQUIRES the unique key (item_id, period_key,
     * period_generated_date) — without it this overwrites and the trail is lost.
     */
    public function saveSnapshot(array $item, $slot, array $period, array $payload)
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO oe_summary_results
                (item_id, column_slot, period_key, period_start, period_end,
                 payload, period_generated_date, fetched_at, status)
             VALUES
                (:item_id, :column_slot, :period_key, :period_start, :period_end,
                 :payload, CURDATE(), NOW(), '1')
             ON DUPLICATE KEY UPDATE
                column_slot  = VALUES(column_slot),
                payload      = VALUES(payload),
                period_start = VALUES(period_start),
                period_end   = VALUES(period_end),
                fetched_at   = NOW(),
                status       = '1'"
        );
        $stmt->execute([
            ':item_id'      => $item['id'],
            ':column_slot'  => $slot,
            ':period_key'   => $period['key'],
            ':period_start' => $period['start'],
            ':period_end'   => $period['end'],
            ':payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function decode($raw)
    {
        return ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
    }
}


/* ============================================================================
 * OeMetricFetcher — the single seam to each routine's data source.
 *
 * Request (POST JSON to item.api_endpoint):
 *   { comp_id, item_id, routine_code, column_mode, column_slot,
 *     period_key, period_start, period_end }
 *
 * Response:
 *   { "ok": true, "metrics": [
 *       { "key":"work_done_m2",
 *         "label":"a. Work done in m2",
 *         "value":20951.00,
 *         "format":"number"|"percent"|"integer",   // optional, default number
 *         "decimals":2,                            // optional, default 2
 *         "color":"green"|"amber"|"red"|"black",   // optional, heatmap tint
 *         "emphasis": true,                        // optional, renders as a total line
 *         "lower_is_better": true,                 // optional, flips the CHANGE
 *                                                  //   CHIP's colour: down is
 *                                                  //   the good direction. See
 *                                                  //   OeSummaryRenderer::deltaChip.
 *         "delta":  -3,                            // optional; an EXPLICIT change
 *                                                  //   figure the endpoint
 *                                                  //   computed itself. Used
 *                                                  //   verbatim; no baseline is
 *                                                  //   consulted for this row.
 *                                                  //   For a movement no pair of
 *                                                  //   stored readings can give
 *                                                  //   — e.g. "how many did this
 *                                                  //   move TODAY", which the
 *                                                  //   day-over-day baseline
 *                                                  //   answers wrongly whenever
 *                                                  //   a generation day was
 *                                                  //   missed. Preferred over
 *                                                  //   the baseline by any
 *                                                  //   RANGE-DRIVEN endpoint,
 *                                                  //   which can simply read its
 *                                                  //   range twice and subtract
 *                                                  //   (RO1, RO2): that needs no
 *                                                  //   stored history, so it is
 *                                                  //   right on a first run and
 *                                                  //   after a missed cron day.
 *                                                  //   Mutually exclusive with
 *                                                  //   delta_mode.
 *         "delta_mode":"none"|"mirror",            // optional; omit to use the
 *                                                  //   column pair's own rule.
 *                                                  //   "none"   no change chip
 *                                                  //            on either column
 *                                                  //   "mirror" column B shows
 *                                                  //            column A's chip
 *                                                  //   See mergeRows() for when
 *                                                  //   each is legitimate.
 *         "heading": true                          // optional, group label: no
 *       }, ... ] }                                 //   value, no cells, just a
 *                                                  //   caption over the lines
 *                                                  //   that follow it
 *   { "ok": false, "error": "..." }  -> logged, column renders "No data yet"
 *
 * The API owns the line items: their keys, their labels, their order and their
 * formatting. Adding line "e." to a routine is an API change only — this file and
 * the renderer do not change. `color` is the hook for the heatmap colours you
 * said would be decided later; omit it and the value renders plain.
 *
 * fetch() returns an ENVELOPE, never a bare null:
 *   ['payload' => array, 'error' => null]      success
 *   ['payload' => null,  'error' => 'reason']  failure
 * The reason travels back to the refresh log so ?test=1 shows WHY a column is
 * empty. Failing loudly into a log nobody reads is not failing loudly.
 * ========================================================================== */
interface OeMetricFetcher
{
    /** @return array ['payload' => array|null, 'error' => string|null] */
    public function fetch(array $item, $slot, array $period, $compId);
}

class OeHttpMetricFetcher implements OeMetricFetcher
{
    const FORMATS = ['number', 'percent', 'integer'];
    const COLORS  = ['green', 'amber', 'red', 'black'];

    /**
     * Per-metric override of the column pair's change-chip rule. ONE field with
     * three values rather than a flag per behaviour: 'none' and 'mirror' are
     * mutually exclusive, and two independent booleans would let an endpoint ask
     * for both at once — a state with no defined meaning that would have to be
     * resolved by precedence nobody could see from the call site.
     */
    const DELTA_MODES = ['inherit', 'none', 'mirror'];

    private $timeout;

    public function __construct($timeoutSeconds = 30)
    {
        $this->timeout = $timeoutSeconds;
    }

    public function fetch(array $item, $slot, array $period, $compId)
    {
        $endpoint = trim((string)($item['api_endpoint'] ?? ''));
        if ($endpoint === '') {
            return self::err("item {$item['id']} ('{$item['routine_name']}') has no api_endpoint");
        }

        $res = $this->post($endpoint, [
            'comp_id'      => (int)$compId,
            'item_id'      => $item['id'],
            'routine_code' => $item['routine_code'],
            'column_mode'  => $item['column_mode'],
            'column_slot'  => $slot,
            'period_key'   => $period['key'],
            'period_start' => $period['start'],
            'period_end'   => $period['end'],
        ]);
        if ($res['payload'] === null) return $res;

        $response = $res['payload'];
        if (empty($response['ok'])) {
            return self::err('endpoint returned ok=false: '
                . (isset($response['error']) ? $response['error'] : '(no error message supplied)'));
        }
        return $this->normalise($response);
    }

    /** Coerce whatever the endpoint sent into exactly the shape the renderer reads. */
    private function normalise(array $r)
    {
        if (!isset($r['metrics']) || !is_array($r['metrics'])) {
            return self::err("response is missing its 'metrics' array");
        }

        $out = [];
        foreach ($r['metrics'] as $m) {
            if (!is_array($m) || !isset($m['key']) || trim((string)$m['key']) === '') continue;

            $format = isset($m['format']) ? strtolower(trim((string)$m['format'])) : 'number';
            $color  = isset($m['color'])  ? strtolower(trim((string)$m['color']))  : '';

            // Unlike format and color, an unrecognised delta_mode is NOT coerced
            // to the default. Both of those fall back to something visibly
            // neutral — a plain number, no tint. A mistyped delta_mode would
            // fall back to a CHANGE FIGURE, which is a number the reader has no
            // way to know was never asked for.
            $deltaMode = isset($m['delta_mode']) ? strtolower(trim((string)$m['delta_mode'])) : 'inherit';
            if (!in_array($deltaMode, self::DELTA_MODES, true)) {
                return self::err("metric '{$m['key']}' declares delta_mode '{$deltaMode}' — expected one of "
                    . implode(', ', self::DELTA_MODES));
            }

            // An EXPLICIT change figure, computed by the endpoint and used
            // verbatim. It exists for a metric whose movement CANNOT be derived
            // from two stored readings — see the contract note above.
            //
            // Rejected alongside delta_mode rather than resolved by precedence:
            // one says "here is the answer", the other says "work it out this
            // way", and an endpoint asking for both has not decided which it
            // means. Same rule, same reason, as the delta_mode check above.
            $hasDelta = isset($m['delta']) && $m['delta'] !== '';
            if ($hasDelta && $deltaMode !== 'inherit') {
                return self::err("metric '{$m['key']}' supplies an explicit delta AND delta_mode "
                    . "'{$deltaMode}' — send one or the other, not both");
            }

            $out[] = [
                'key'      => (string)$m['key'],
                'label'    => (isset($m['label']) && trim((string)$m['label']) !== '') ? (string)$m['label'] : (string)$m['key'],
                'value'    => (isset($m['value']) && $m['value'] !== '' && $m['value'] !== null) ? (float)$m['value'] : null,
                'format'   => in_array($format, self::FORMATS, true) ? $format : 'number',
                'decimals' => isset($m['decimals']) ? max(0, (int)$m['decimals']) : 2,
                'color'    => in_array($color, self::COLORS, true) ? $color : null,
                'emphasis' => !empty($m['emphasis']),
                'heading'  => !empty($m['heading']),
                'lower_is_better' => !empty($m['lower_is_better']),
                'delta_mode'      => $deltaMode,
                'delta'           => $hasDelta ? (float)$m['delta'] : null,
            ];
        }

        if (empty($out)) {
            return self::err('response carried no usable metric rows (every entry lacked a key)');
        }
        return self::ok($out);
    }

    private function post($url, array $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return self::err("cannot reach {$url}: {$cerr}");
        }
        if ($code < 200 || $code >= 300) {
            return self::err("{$url} returned HTTP {$code}: " . self::snippet($raw));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Almost always a PHP warning/fatal printed ahead of the JSON.
            return self::err("{$url} returned non-JSON: " . self::snippet($raw));
        }
        return self::ok($decoded);
    }

    /** First 300 chars, whitespace flattened so it stays on one log line. */
    private static function snippet($raw)
    {
        $s = trim(preg_replace('/\s+/', ' ', (string)$raw));
        return ($s === '') ? '(empty response body)' : substr($s, 0, 300);
    }

    private static function ok($payload)
    {
        return ['payload' => $payload, 'error' => null];
    }

    private static function err($message)
    {
        error_log('OeMetricFetcher: ' . $message);
        return ['payload' => null, 'error' => $message];
    }
}


/* ============================================================================
 * OeSummaryService — orchestrator (CQS).
 *   refresh()     COMMAND — pull both columns from every API, upsert.
 *   buildReport() QUERY   — assemble render-ready data from the table only.
 * ========================================================================== */
class OeSummaryService
{
    private $pdo;
    private $repo;
    private $fetcher;
    private $compId;
    private $today;

    public function __construct(PDO $pdo, OeResultRepository $repo, OeMetricFetcher $fetcher, $compId = 1, ?DateTime $today = null)
    {
        $this->pdo     = $pdo;
        $this->repo    = $repo;
        $this->fetcher = $fetcher;
        $this->compId  = $compId;
        $this->today   = $today ? clone $today : new DateTime('today');
    }

    /** QUERY: configured routines, each with its two resolved column ranges. */
    public function loadItems()
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, comp_id, routine_code, routine_name, column_mode, api_endpoint, sort_order
             FROM oe_summary_items
             WHERE comp_id = :comp_id AND status = '1'
             ORDER BY sort_order IS NULL, sort_order ASC, routine_code ASC"
        );
        $stmt->execute([':comp_id' => $this->compId]);
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['error']    = null;
            $item['columns']  = null;
            try {
                $item['columns'] = ColumnPairResolver::resolve($item['column_mode'], $this->today);
            } catch (InvalidArgumentException $e) {
                $item['error'] = $e->getMessage();
                error_log("OeSummaryService: item {$item['id']} — {$e->getMessage()}");
            }
        }
        unset($item);
        return $items;
    }

    /**
     * COMMAND: fetch and store BOTH columns for every active routine, every run.
     * Storing column A again each day is exactly what makes tomorrow's comparison
     * possible: today's row becomes tomorrow's 'prev_payload'.
     */
    public function refresh()
    {
        $log = [];
        foreach ($this->loadItems() as $item) {
            $label = $item['routine_code'] . ' / ' . $item['routine_name'];

            if ($item['error'] !== null) {
                $log[] = ['type' => 'skip', 'message' => "SKIP {$label}: {$item['error']}"];
                continue;
            }

            foreach ($item['columns'] as $slot => $period) {
                $res   = $this->fetcher->fetch($item, $slot, $period, $this->compId);
                $where = "{$label} (col {$slot} — {$period['label']} / {$period['key']})";

                if ($res['payload'] === null) {
                    $log[] = ['type' => 'error', 'message' => "NODATA {$where}: {$res['error']}"];
                    continue;
                }
                $this->repo->saveSnapshot($item, $slot, $period, $res['payload']);
                $log[] = ['type' => 'success', 'message' => "SAVED {$where}"];
            }
        }
        return $log;
    }

    /** QUERY: the render-ready report, read from the results table only. */
    public function buildReport()
    {
        $routines = [];
        foreach ($this->loadItems() as $item) {
            $routines[] = $this->buildRoutine($item);
        }
        return ['generated_at' => date('d M Y, h:i A'), 'routines' => $routines];
    }

    private function buildRoutine(array $item)
    {
        $base = [
            'item_id'      => $item['id'],
            'routine_code' => $item['routine_code'],
            'routine_name' => $item['routine_name'],
            'error'        => $item['error'],
        ];
        if ($item['error'] !== null) {
            return $base + ['columns' => null, 'rows' => []];
        }

        $sides = [];
        foreach ($item['columns'] as $slot => $period) {
            $snap = $this->repo->getSnapshot($item['id'], $period['key']);
            $sides[$slot] = [
                'metrics'  => ($snap && is_array($snap['payload']))      ? $snap['payload']      : null,
                'previous' => ($snap && is_array($snap['prev_payload'])) ? $snap['prev_payload'] : null,
                'delta'    => $period['delta'],
            ];
        }

        // Second pass, deliberately: DELTA_PEER reads the OTHER column, so no
        // baseline can be resolved until both sides are loaded.
        foreach (array_keys($sides) as $slot) {
            $sides[$slot]['baseline'] = self::baseline($sides, $slot);
        }

        return $base + [
            'columns' => $item['columns'],
            'rows'    => self::mergeRows($sides['a'], $sides['b']),
        ];
    }

    /**
     * QUERY: the metric list a column's change figure is measured against, per
     * the rule its period pair declared in ColumnPairResolver. null = no chip.
     *
     * An unrecognised mode throws instead of falling back to day-over-day. A
     * typo would otherwise print a plausible change figure nobody configured,
     * and a wrong number that looks right is the one failure a reader cannot
     * catch on a printed sheet.
     */
    private static function baseline(array $sides, $slot)
    {
        $mode = $sides[$slot]['delta'];
        switch ($mode) {
            case ColumnPairResolver::DELTA_NONE:
                return null;
            case ColumnPairResolver::DELTA_DAY:
                return $sides[$slot]['previous'];
            case ColumnPairResolver::DELTA_PEER:
                return $sides[$slot === 'a' ? 'b' : 'a']['metrics'];
        }
        throw new InvalidArgumentException("Unsupported delta mode: '{$mode}'");
    }

    /**
     * Fold the two columns' metric lists into one ordered row list keyed by metric
     * key. Column A defines the order and the label (that is the authoritative
     * column); any key only column B knows about is appended so nothing is lost.
     *
     * 'delta_mode' is applied here, per metric:
     *
     *   'mirror'  column B takes column A's change figure verbatim. Legitimate
     *             only where the two columns provably move by the SAME
     *             increment. Applied per ROW, because it is inherently a
     *             cross-column instruction.
     *   'none'    THAT COLUMN carries no chip. Applied per COLUMN: a column
     *             declaring it says nothing about the other one.
     *   omitted   the column pair's own rule stands (ColumnPairResolver).
     *
     * 'none' is per column because it answers "can THIS column measure its own
     * change", which is a question about that column's period. RO1 and RO2 send
     * it on Month to Date every 1st of the month — that column covers a single
     * day and has nothing earlier inside it — while Year to Date on the same
     * morning sends a real measured delta. Collected row-level, as it first was,
     * column A's honest 'none' deleted column B's correct figure and both cells
     * printed bare.
     *
     * PER METRIC, NOT PER ROUTINE, and the distinction is not cosmetic. RO1 is
     * the case that forced it: its response-time RATE and outstanding-pothole
     * COUNT declare 'none' on both columns and carry no chip at all, because
     * those columns measure different populations and genuinely move by
     * different amounts (+0.95 points month-to-date against +0.15 year-to-date;
     * -25 against -28), while its work-done lines on the same routine do carry
     * one.
     *
     * Those work-done lines no longer use 'mirror'. They send an explicit
     * 'delta' the endpoint measured itself by reading its range twice — see
     * oe_previous_end() in oe_api_common.php. 'mirror' remains here for a
     * genuinely paired metric, but note the trap RO1 hit: it copies column A's
     * chip verbatim INCLUDING a null, so on the 1st of a month, when column A's
     * period key is new and has no baseline, it silently erases a column B chip
     * that was computable.
     *
     * Only the emitting endpoint knows which of its lines are which, so only the
     * endpoint may declare it. Both columns come from the same literal array in
     * that endpoint, so the two sides cannot disagree; the first declaration seen
     * wins if they ever do.
     */
    private static function mergeRows(array $a, array $b)
    {
        $order  = [];
        $labels = [];
        $emph   = [];
        $head   = [];
        $lower  = [];
        // 'none' is collected PER COLUMN, 'mirror' per row, and the split is the
        // whole point. Whether a column can measure its own change is a fact
        // about THAT COLUMN'S PERIOD, not about the metric: on 01 Aug, Month to
        // Date covers a single day and has nothing earlier inside it, while Year
        // to Date covers 01 Jan-01 Aug and measures against 01 Jan-31 Jul
        // perfectly well. Held row-level, column A's honest "I cannot measure
        // this" silently deleted column B's correct chip.
        //
        // 'mirror' stays row-level because it is inherently a cross-column
        // instruction — it can only mean "column B takes column A's figure".
        $mirror = [];
        $none   = ['a' => [], 'b' => []];
        foreach (['a' => $a['metrics'], 'b' => $b['metrics']] as $slot => $list) {
            if (!is_array($list)) continue;
            foreach ($list as $m) {
                if (!isset($order[$m['key']])) $order[$m['key']] = true;
                if (!isset($labels[$m['key']])) $labels[$m['key']] = $m['label'];
                // Either column may declare these; one column marking a line as
                // a total (or as a group label) is enough, so the two sides can
                // never disagree on styling.
                if (!empty($m['emphasis'])) $emph[$m['key']] = true;
                if (!empty($m['heading']))  $head[$m['key']] = true;
                // Row-level, not per-column: a metric key means the same thing
                // in both columns, so its good direction cannot differ between
                // them. Same rule as emphasis and heading above.
                if (!empty($m['lower_is_better'])) $lower[$m['key']] = true;

                $dm = isset($m['delta_mode']) ? $m['delta_mode'] : 'inherit';
                if ($dm === 'none') {
                    $none[$slot][$m['key']] = true;
                } elseif ($dm === 'mirror') {
                    $mirror[$m['key']] = true;
                }
            }
        }

        $rows = [];
        foreach (array_keys($order) as $key) {
            $lowerIsBetter = isset($lower[$key]);
            $cellA = self::cell($a, $key, $lowerIsBetter);
            $cellB = self::cell($b, $key, $lowerIsBetter);

            // Applied AFTER both cells are built, because these rewrite a
            // finished result rather than choosing a baseline to compute from —
            // which is what baseline() does, and why this does not live there.
            //
            // Each column's own 'none' first, then 'mirror' last: mirror is the
            // explicit cross-column instruction and must see the finished
            // column A. Copying a null through it is a correct outcome, not a
            // failure — the two figures move together or not at all.
            if (isset($none['a'][$key])) $cellA['delta'] = null;
            if (isset($none['b'][$key])) $cellB['delta'] = null;
            if (isset($mirror[$key]))    $cellB['delta'] = $cellA['delta'];

            $rows[] = [
                'key'      => $key,
                'label'    => $labels[$key],
                'emphasis' => isset($emph[$key]),
                'heading'  => isset($head[$key]),
                'a'        => $cellA,
                'b'        => $cellB,
            ];
        }
        return $rows;
    }

    /**
     * One rendered cell: the value plus its change against whatever baseline()
     * chose for this column — the previous generation-day, the other column, or
     * nothing. Delta is null (nothing shown) when either side is missing: an
     * absent baseline must never read as a change of zero.
     */
    private static function cell(array $side, $key, $lowerIsBetter = false)
    {
        $today = self::pick($side['metrics'], $key);
        if ($today === null) {
            return ['available' => false, 'value' => null, 'format' => 'number', 'decimals' => 2,
                    'color' => null, 'delta' => null, 'lower_is_better' => $lowerIsBetter];
        }
        // An endpoint that computed its own change figure wins outright, and no
        // baseline is consulted for that row. array_key_exists, not isset: a
        // payload stored before this field existed has no 'delta' key at all and
        // must keep falling through to the baseline, unchanged.
        if (array_key_exists('delta', $today) && $today['delta'] !== null) {
            $delta = (float)$today['delta'];
        } else {
            $base  = self::pick($side['baseline'], $key);
            $delta = ($base !== null && $today['value'] !== null && $base['value'] !== null)
                ? $today['value'] - $base['value']
                : null;
        }

        return [
            'available' => true,
            'value'     => $today['value'],
            'format'    => $today['format'],
            'decimals'  => $today['decimals'],
            'color'     => $today['color'],
            'delta'     => $delta,
            'lower_is_better' => $lowerIsBetter,
        ];
    }

    private static function pick($list, $key)
    {
        if (!is_array($list)) return null;
        foreach ($list as $m) {
            if (isset($m['key']) && $m['key'] === $key) return $m;
        }
        return null;
    }
}


/* ============================================================================
 * OeSummaryRenderer — email-safe HTML shared verbatim by web, email and PDF.
 *
 * Styled after the printed GLOBINACO "SUMMARY O&E PERFORMANCE" sheet: a serif
 * ledger with a left number gutter, indented a./b./c. metric lines, ruled
 * columns and an airy gap between routine groups. No coloured banner bands.
 *
 * The printed sheet carries ONE header row (Jan..Jun) because every routine
 * shares those columns. Ours do not — routine 1 is Month/Year to Date while
 * routine 3 is Last Week / Previous Week — so a single global header would be a
 * lie. Each routine's group-head row carries its own two column captions
 * instead, sitting exactly where the month names sit on the sheet.
 *
 * Purely presentational: it formats numbers, it does not compute them.
 * ========================================================================== */
class OeSummaryRenderer
{
    const INK      = '#1f2937';   // body text
    const HEADINK  = '#111827';   // titles and figures
    const MUTE     = '#6b7280';   // captions, ranges
    const RULE     = '#d9dde3';   // hairline grid
    const RULE_DARK = '#334155';  // the rule under the masthead

    /**
     * The change chip. Named for the JUDGEMENT, not the direction: on a work-done
     * line the good move is up, on a delay line it is down, and the same green
     * has to serve both. Naming these UP and DOWN is what produced 19km of extra
     * delay printed in green.
     */
    const BETTER = '#15803d';
    const WORSE  = '#b91c1c';

    /**
     * Navy — routine titles, column labels, and the rule that closes the header
     * row. The one coloured ink in the report.
     *
     * It works where the brighter blue did not because it is dark enough to read
     * as INK FIRST and colour second: at 14px on white it sits a shade off
     * near-black, so the header gains warmth without gaining a highlight. That
     * is also why it cannot be mistaken for a status. Green, amber, red and
     * black are spoken for by $tints and deltaChip(), where they MEAN something
     * — an amber cell is a routine sitting on 1-2 late cases. Navy is not in
     * that vocabulary and is too dark to read as a flag anyway.
     *
     * Keep any replacement dark. A mid-tone blue turns every routine title into
     * something that looks clickable, and the report is also printed.
     */
    const NAVY = '#1e3a8a';

    /**
     * Typeface. NOT Georgia — Georgia ships old-style (text) figures, where 3 4 5
     * 7 9 drop below the baseline. Lovely in prose, wrong in a money column: the
     * digits visibly wobble and the columns stop reading as a single number.
     * This stack has lining figures everywhere and degrades cleanly to Arial.
     *
     * If you would rather stay with a serif to match the printed sheet, the only
     * change needed is this constant — Cambria,'Times New Roman',Times,serif also
     * has lining figures. Do not go back to Georgia.
     */
    const FONT = "'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif";

    /**
     * Tabular (fixed-width) figures, so every digit occupies the same advance and
     * the decimal points line up down the column regardless of value. Ignored
     * harmlessly where unsupported; the PDF path renders in DejaVu Sans, which is
     * tabular by default.
     */
    const NUM = "font-variant-numeric:tabular-nums;font-feature-settings:'tnum';";

    const W_NUM   = 34;
    const W_LABEL = 400;
    const W_COL   = 280;

    /** Optional heatmap tint per cell: [background, text]. Matches the green
     *  highlight the printed sheet uses on its cumulative figures. */
    private static $tints = [
        'green' => ['#dcfce7', '#14532d'],
        'amber' => ['#fef3c7', '#78350f'],
        'red'   => ['#fee2e2', '#7f1d1d'],
        'black' => ['#e2e8f0', '#0f172a'],
    ];

    /**
     * Build stamp, emitted as an HTML comment ahead of the table. When the output
     * does not match what you just deployed, this is what tells you whether the
     * server is running your file or a cached copy of the previous one. Bump it
     * on any change to this class.
     */
    const BUILD = 'oe-renderer/2026-08-02-group-per-table';

    /** Column widths, left to right — also fed to report_render_pdf(). */
    public static function columnWidths()
    {
        return [self::W_NUM, self::W_LABEL, self::W_COL, self::W_COL];
    }

    /**
     * ONE TABLE PER ROUTINE, not one table for the report.
     *
     * A routine is the unit a reader takes in at once — its head row states the
     * two periods, and the a./b./c. lines under it are only meaningful beside
     * that head. Split across a page break, line (b) appears on the next page
     * under no heading at all and beside no column captions, which is how RO4
     * printed "0 (-1)   1" alone at the top of page 2.
     *
     * The single-table version could not prevent that: page-break-inside on a
     * <tbody> is ignored by Dompdf (measured — the group still split), and there
     * is no row-level rule that means "keep these six rows together". A block
     * element wrapping its own table is the one structure Dompdf does honour, so
     * each routine gets one and the whole group moves down intact.
     *
     * Column alignment survives because every table carries the SAME colgroup
     * and the same total width, and report_render_pdf() pins all of them to
     * fixed layout together. The px widths are kept as-is for the email path,
     * which is where they are already known to be correct.
     */
    public static function render(array $report, $title = 'Summary O&E Performance', $company = 'GLOBINACO SDN BHD')
    {
        $span = 4;
        $out  = self::groupTable(self::masthead($company, $title, $report['generated_at'], $span));

        if (empty($report['routines'])) {
            $out .= self::group(self::noteRow('No routines configured for this company.', $span, true));
        }

        $n = 1;
        foreach ($report['routines'] as $routine) {
            $hasError = !empty($routine['error']);

            $rows = self::groupHeadRow($n++, $routine['routine_name'], $hasError ? null : $routine['columns']);

            if ($hasError) {
                $rows .= self::noteRow('Cannot compute: ' . $routine['error'], $span);
            } elseif (empty($routine['rows'])) {
                $rows .= self::noteRow('No data yet.', $span);
            } else {
                foreach ($routine['rows'] as $row) {
                    $rows .= self::metricRow($row);
                }
            }
            $out .= self::group($rows);
        }

        // id="report-root" is the anchor report_render_pdf() checks for. It is
        // deliberately not the font-family string or a width: restyling the
        // report must not silently break the PDF path.
        return '<!-- ' . self::BUILD . ' | font: ' . self::FONT . ' -->'
            . '<div id="report-root" style="width:' . self::totalWidth() . 'px;margin:0 auto;'
            . 'font-family:' . self::FONT . ';color:' . self::INK . ';background:#ffffff;">'
            . $out . '</div>';
    }

    /** The report's full width — one definition, used by the container and every table. */
    private static function totalWidth()
    {
        return array_sum(self::columnWidths());
    }

    /**
     * One routine, wrapped so a page break cannot land inside it, followed by
     * the air between groups on the printed sheet.
     *
     * The gap is OUTSIDE the avoid-box on purpose: inside it, the break would
     * be pushed past the blank space and the next group would start hard against
     * the top margin.
     */
    private static function group($rows)
    {
        return '<div class="scb-group" style="page-break-inside:avoid;">'
            . self::groupTable($rows) . '</div>'
            . '<div class="scb-gap" style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>';
    }

    /** The shared table shell. Identical colgroup everywhere, so columns line up. */
    private static function groupTable($rows)
    {
        return '<table class="scb-t" align="center" cellpadding="0" cellspacing="0" width="' . self::totalWidth() . '" '
            . 'style="border-collapse:collapse;font-family:' . self::FONT . ';color:' . self::INK
            . ';background:#ffffff;">'
            . '<colgroup><col width="' . self::W_NUM . '"><col width="' . self::W_LABEL . '">'
            . '<col width="' . self::W_COL . '"><col width="' . self::W_COL . '"></colgroup>'
            . $rows . '</table>';
    }

    /** Company / report name top-left, as-at top-right, closed by a heavy rule. */
    private static function masthead($company, $title, $generatedAt, $span)
    {
        return '<tr>'
            . '<td colspan="2" style="padding:4px 0 0 6px;">'
            . '<div style="font-size:10.5px;font-weight:600;letter-spacing:1.4px;color:' . self::MUTE . ';">'
            . self::esc(strtoupper($company)) . '</div>'
            . '<div style="font-size:18px;font-weight:600;letter-spacing:.4px;color:' . self::HEADINK . ';margin-top:4px;">'
            . self::esc(strtoupper($title)) . '</div></td>'
            . '<td colspan="2" style="padding:4px 6px 0 0;text-align:right;vertical-align:bottom;color:' . self::MUTE . ';font-size:11px;">'
            . 'As at ' . self::esc($generatedAt) . '</td></tr>'
            . '<tr><td colspan="' . $span . '" style="padding:0;height:10px;line-height:10px;font-size:0;'
            . 'border-bottom:2px solid ' . self::RULE_DARK . ';">&nbsp;</td></tr>';
    }

    /**
     * The numbered routine line. Its two right-hand cells carry that routine's
     * own column captions — the position the month names occupy on the sheet.
     *
     * This row is the report's only navigation: it says which routine you are
     * reading and what the two columns beside it cover. Both facts have to
     * survive a skim.
     *
     * Three devices, no filled band and no background:
     *   the title one step up in size and weight from the metric labels below;
     *   the title and both column labels set in NAVY rather than near-black;
     *   a single 1.5px navy rule closing the row, against the 1px grey hairlines
     *   between metric lines — the same trick metricRow() already uses to mark
     *   a total.
     * The colour is the only thing that changed from the monochrome version; the
     * structure is identical, which is what keeps it quiet.
     *
     * The period line moved from MUTE to INK. "01 Jan - 24 Jul 2026" is the only
     * thing on the page that says what the figures beside it actually cover; at
     * grey 10px it was the faintest text in the report. It stays at regular
     * weight — legible, not loud.
     */
    private static function groupHeadRow($num, $routineName, $columns)
    {
        $pad  = 'padding:14px 10px 7px;border-bottom:1.5px solid ' . self::NAVY . ';';

        $cap  = function ($col) use ($pad) {
            $inner = ($col === null) ? '&nbsp;'
                : '<div style="font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:'
                  . self::NAVY . ';">' . self::esc($col['label']) . '</div>'
                  . '<div style="font-size:10.5px;color:' . self::INK . ';margin-top:4px;' . self::NUM . '">'
                  . self::esc($col['range']) . '</div>';
            return '<td style="' . $pad . 'border-left:1px solid ' . self::RULE
                . ';text-align:right;vertical-align:bottom;">' . $inner . '</td>';
        };

        return '<tr class="scb-band">'
            . '<td style="' . $pad . 'text-align:center;vertical-align:bottom;color:' . self::MUTE . ';font-size:12px;">'
            . (int)$num . '</td>'
            . '<td style="' . $pad . 'vertical-align:bottom;font-size:14px;font-weight:700;letter-spacing:.2px;color:'
            . self::NAVY . ';">' . self::esc($routineName) . '</td>'
            . $cap(($columns === null) ? null : $columns['a'])
            . $cap(($columns === null) ? null : $columns['b'])
            . '</tr>';
    }

    /**
     * One metric line. A line the API flagged 'emphasis' is a TOTAL: it gets a
     * rule above it and heavier type, so it reads as the sum of the lines above
     * rather than as one more of them. Without that it is just a sixth band.
     */
    private static function metricRow(array $row)
    {
        if (!empty($row['heading'])) return self::headingRow($row);

        $emph = !empty($row['emphasis']);
        $sep  = 'border-bottom:1px solid ' . self::RULE . ';';
        $rule = $emph ? 'border-top:1.5px solid ' . self::RULE_DARK . ';' : '';
        $lbl  = $emph
            ? 'font-size:12.5px;font-weight:700;color:' . self::HEADINK . ';'
            : 'font-size:12px;color:' . self::INK . ';';

        return '<tr>'
            . '<td style="' . $sep . $rule . 'padding:8px 0;"></td>'
            . '<td style="' . $sep . $rule . 'padding:8px 10px 8px 26px;' . $lbl . '">'
            . self::esc($row['label']) . '</td>'
            . self::valueCell($row['a'], $sep . $rule, $emph)
            . self::valueCell($row['b'], $sep . $rule, $emph)
            . '</tr>';
    }

    /**
     * A group label INSIDE a routine — "ET Closed", with its a./b./c. lines
     * beneath it. Its two value cells are deliberately empty: a heading is not
     * a figure, and anything printed there would be read as the sum of the
     * lines below it. That is what 'emphasis' is for, and the two must not look
     * alike.
     *
     * It reads as a heading purely by position: set at the label column's LEFT
     * margin while metric lines are indented 26px, so the indent alone groups
     * them. No rule above, no background — the routine head is the only strong
     * divider on the page and a second one would compete with it.
     */
    private static function headingRow(array $row)
    {
        $sep  = 'border-bottom:1px solid ' . self::RULE . ';';
        $cell = '<td style="' . $sep . 'border-left:1px solid ' . self::RULE . ';padding:10px;"></td>';

        return '<tr>'
            . '<td style="' . $sep . 'padding:10px 0 6px;"></td>'
            . '<td style="' . $sep . 'padding:10px 10px 6px;font-size:12.5px;font-weight:700;'
            . 'letter-spacing:.2px;color:' . self::HEADINK . ';">' . self::esc($row['label']) . '</td>'
            . $cell . $cell . '</tr>';
    }

    private static function valueCell(array $cell, $sep, $emph = false)
    {
        list($bg, $fg) = self::tint($cell['color']);
        $style = $sep . 'border-left:1px solid ' . self::RULE . ';padding:8px 10px;text-align:right;'
            . 'white-space:nowrap;font-size:' . ($emph ? '13px' : '12.5px') . ';' . self::NUM
            . ($bg === null ? '' : 'background:' . $bg . ';');

        if (!$cell['available']) {
            return '<td style="' . $style . 'color:' . self::MUTE . ';font-style:italic;">No data yet</td>';
        }
        return '<td style="' . $style . '">'
            . '<span style="font-weight:' . ($emph ? '700' : '600') . ';color:'
            . ($emph ? self::HEADINK : $fg) . ';">'
            . self::fmt($cell['value'], $cell['format'], $cell['decimals']) . '</span>'
            . self::deltaChip($cell) . '</td>';
    }

    /**
     * Signed change against whatever baseline this column was given; blank when
     * 0 or unknown.
     *
     * THE SIGN AND THE COLOUR ANSWER DIFFERENT QUESTIONS, and only the colour
     * is allowed to flip. The sign is arithmetic — 58.341 against 38.876 is
     * +19.465 whatever the number measures, and a delay line that printed
     * (-19.465) to mean "worse" would be a false statement about the two figures
     * printed either side of it. The colour is the judgement: up is good on a
     * work-done line, down is good on a delay line, and 'lower_is_better' is the
     * emitting endpoint saying which of the two it is. It owns that fact; the
     * renderer cannot know it, and inferring it from the label would be a
     * guess that breaks the first time a line is renamed.
     */
    private static function deltaChip(array $cell)
    {
        if ($cell['delta'] === null) return '';
        $d = round($cell['delta'], $cell['decimals']);
        if (abs($d) < pow(10, -$cell['decimals']) / 2) return '';

        $improved = empty($cell['lower_is_better']) ? ($d > 0) : ($d < 0);

        return ' <span style="font-size:10.5px;color:' . ($improved ? self::BETTER : self::WORSE) . ';">('
            . ($d > 0 ? '+' : '-') . self::fmt(abs($d), $cell['format'], $cell['decimals']) . ')</span>';
    }

    private static function noteRow($text, $span, $center = false)
    {
        $align = $center ? 'text-align:center;' : '';
        return '<tr><td colspan="' . $span . '" style="padding:12px 10px 12px 26px;color:' . self::MUTE
            . ';font-size:12px;font-style:italic;' . $align . 'border-bottom:1px solid ' . self::RULE . ';">'
            . self::esc($text) . '</td></tr>';
    }

    private static function fmt($value, $format, $decimals)
    {
        if ($value === null) return '&mdash;';
        if ($format === 'integer') return number_format($value, 0);
        if ($format === 'percent') return number_format($value, $decimals) . '%';
        return number_format($value, $decimals);
    }

    /** @return array [background|null, text colour] */
    private static function tint($color)
    {
        if ($color !== null && isset(self::$tints[$color])) {
            return self::$tints[$color];
        }
        return [null, self::HEADINK];
    }

    private static function esc($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}


/* ============================================================================
 * Wiring — one factory every entry path uses.
 * ========================================================================== */
function oe_build_service($compId = 1, ?OeMetricFetcher $fetcher = null, ?DateTime $today = null)
{
    $pdo = OeDatabase::conn();
    return new OeSummaryService(
        $pdo,
        new OeResultRepository($pdo),
        $fetcher ?: new OeHttpMetricFetcher(),
        $compId,
        $today
    );
}