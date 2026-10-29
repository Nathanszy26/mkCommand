-- ---------------------------------------------------------------------------
-- Daily Staff Summary push — what was sent, to which handset, and whether it
-- arrived
-- ---------------------------------------------------------------------------
--
-- ONE ROW PER DEVICE, NOT PER PERSON. staffSummaryCron.php posts one users.id
-- to the FCM endpoint and the endpoint fans it out to every device that id has
-- registered — then answers with the list it sent to. That answer is the only
-- place the token and the platform exist, so it is unpacked here: a manager
-- with a phone and a tablet gets two rows, and "which device was it on" has an
-- answer the morning after.
--
-- A PUSH THAT REACHED NOBODY STILL GETS A ROW, with device_id 0 and status
-- 'no_device' — somebody on the list who never installed the app, or whose
-- token was cleared, is a fact worth being able to look up. Silence and
-- success must never look the same.
--
-- WHY NOT staff_summary_notify. That table is one row per person per day and
-- the acknowledgement hangs off it; its uq_once key is what makes the morning
-- re-runnable. Devices are many per person and belong to no acknowledgement,
-- so they are kept beside it and joined on (notify_date, person, comp_id).
--
-- WHY NOT staff_notify_queue. That is staffNotify.php's write-then-send queue
-- for memos and merits, in staff_profile, drained by another file. This is a
-- log of sends that already happened, written by the cron itself; nothing
-- reads it back to decide anything.
--
-- THE COUNTS AND THE DELTAS ARE KEPT because the push is the only thing that
-- claims them. The body says "Late 28 ▲8"; this row is what that sentence was
-- built from, so a disagreement with the mail can be settled without
-- reconstructing the day.
--
-- Run on globportal (prog4). Safe to run twice — unlike
-- sql/daily_summary_report.sql, which adds a column and cannot be.

CREATE TABLE IF NOT EXISTS `mkPortal`.`staff_summary_push` (
    `id`                bigint(20)   NOT NULL AUTO_INCREMENT,

    -- Who and when. The same three columns staff_summary_notify is keyed by,
    -- so the mail row and its pushes join without a lookup table.
    `notify_date`       date         NOT NULL,
    `person`            varchar(255) NOT NULL,
    `comp_id`           int(11)      NOT NULL DEFAULT '1',
    `fullname`          varchar(150) NOT NULL DEFAULT '',

    -- WHO IT WAS FOR AND WHO IT WENT TO, kept apart on purpose. While
    -- SUMMARY_PUSH_TEST_USER_ID is set every push goes to one handset, and
    -- this is the column that still says whose morning it was.
    `user_id_intended`  int(11)      NOT NULL DEFAULT '0',
    `user_id_sent_to`   int(11)      NOT NULL DEFAULT '0',

    -- What it said, exactly as it went on the wire.
    `title`             varchar(255) NOT NULL DEFAULT '',
    `body`              text,
    `payload`           text                  COMMENT 'the data block — screen, date, counts',

    -- What it claimed. compare_date is the day the arrows are against, NULL
    -- when there was no earlier day to compare with and the push therefore
    -- showed bare numbers.
    `late_count`        int(11)      NOT NULL DEFAULT '0',
    `leave_count`       int(11)      NOT NULL DEFAULT '0',
    `compliance_count`  int(11)      NOT NULL DEFAULT '0',
    `compare_date`      date                  DEFAULT NULL,
    `late_delta`        int(11)               DEFAULT NULL,
    `leave_delta`       int(11)               DEFAULT NULL,
    `compliance_delta`  int(11)               DEFAULT NULL,

    -- The device, straight out of the endpoint's answer. device_id is its own
    -- row id for that registration; 0 means the send reached no device at all.
    `device_id`         int(11)      NOT NULL DEFAULT '0',
    `device_token`      varchar(255) NOT NULL DEFAULT '',
    `device_platform`   varchar(32)  NOT NULL DEFAULT '' COMMENT 'android | ios',
    `device_group`      varchar(32)  NOT NULL DEFAULT '',
    `device_name`       varchar(150) NOT NULL DEFAULT '' COMMENT 'whose handset the endpoint says it is',

    -- How it went.
    `status`            enum('sent','failed','no_device') NOT NULL DEFAULT 'failed',
    `http_code`         smallint(6)  NOT NULL DEFAULT '0',
    `response`          varchar(1000) NOT NULL DEFAULT '',
    `attempts`          int(11)      NOT NULL DEFAULT '1',
    `sent_datetime`     datetime              DEFAULT NULL,
    `created_datetime`  datetime     NOT NULL,
    `updated_datetime`  datetime              DEFAULT NULL,

    PRIMARY KEY (`id`),

    -- RE-RUNNING THE MORNING TOPS THIS UP, IT DOES NOT DOUBLE IT. The cron is
    -- safe to run twice — it re-sends to anybody who has not acknowledged —
    -- and the second pass reaches the same handsets, so the same four columns
    -- that identify a send update the row rather than adding one. attempts is
    -- how many times it went.
    UNIQUE KEY `uq_send` (`notify_date`, `person`, `comp_id`, `device_id`),

    KEY `day_idx`    (`notify_date`, `status`),
    KEY `person_idx` (`person`, `notify_date`),
    KEY `device_idx` (`device_token`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Reading it back
-- ---------------------------------------------------------------------------
--
-- This morning, a line per handset:
--
--   SELECT person, fullname, user_id_sent_to, device_platform, device_name,
--          status, late_count, leave_count, compliance_count
--     FROM mkPortal.staff_summary_push
--    WHERE notify_date = CURDATE()
--    ORDER BY person, device_platform;
--
-- Who is on the list but has no handset:
--
--   SELECT person, fullname, response
--     FROM mkPortal.staff_summary_push
--    WHERE notify_date = CURDATE() AND status = 'no_device';
--
-- The push beside the mail it followed:
--
--   SELECT n.person, n.status AS mail, p.status AS push, p.device_platform
--     FROM mkPortal.staff_summary_notify n
--     LEFT JOIN mkPortal.staff_summary_push p
--       ON p.notify_date = n.notify_date AND p.person = n.person
--      AND p.comp_id = n.comp_id
--    WHERE n.notify_date = CURDATE()
--    ORDER BY n.person;
