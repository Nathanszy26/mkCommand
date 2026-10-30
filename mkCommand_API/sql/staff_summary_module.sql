-- ---------------------------------------------------------------------------
-- Daily Summary MODULES — which sections a person is shown, and which of them
-- they must acknowledge
-- ---------------------------------------------------------------------------
--
-- TWO TABLES. A CATALOGUE AND A SET OF EXCEPTIONS.
--
--   staff_summary_module        what a module IS: its title, its order, where
--                               its figures come from, and what it does for
--                               somebody nobody has decided about yet.
--   staff_summary_module_staff  what one PERSON gets, where that differs from
--                               the catalogue's default.
--
-- WHY A CATALOGUE AT ALL, when the three sections were hard-coded and worked.
-- Because the fourth one is not on this server. Lateness, Excessive Leaves and
-- Compliance are read from attendanceAPI.php, leaveAPI.php and
-- performanceAPI.php, all of them in hr/ beside the cron, so the cron could
-- name them as filenames and resolve the host from its own request.
-- RO1 Work Achievement lives on glob.com.my, which is a DIFFERENT SERVER with
-- a different document root and its own database. There is no filename that
-- reaches it, so the address has to be stored, and once one module's address
-- is stored it is less confusing — not more — for all four to be in the same
-- place. api_url is the column that makes "where does this figure come from"
-- answerable by reading a row instead of reading the cron.
--
-- THE EXCEPTIONS TABLE IS EMPTY ON DAY ONE AND THAT IS CORRECT. Effective
-- value = the person's row if they have one, otherwise the catalogue default.
-- The three existing modules default to allowed AND must-acknowledge, so
-- running this file changes nothing for anybody: every one of the 33 ticked
-- people keeps the exact three sections and the exact three tick boxes they
-- had yesterday. RO1 Work Achievement defaults to NOT allowed, so it reaches
-- nobody until somebody is ticked for it on the report. Seeding 33 people x 4
-- modules up front would have had the same effect and 132 rows to keep
-- correct; this has none.
--
-- THE KEYS ARE NOT FREE TEXT. 'late', 'leave' and 'comp' are the same three
-- strings summarySections() returns in staffSummaryShared.php, which are the
-- same three strings staff_summary_notify.acked_items stores and the app posts
-- back as items[]. A rename here without a rename there would orphan every
-- acknowledgement ever recorded. Add modules; do not rename these three.
--
-- NO HISTORY IS KEPT. The four pool flags on staffHeadcountReport.php have
-- staff_flag_override_log behind them because those tick boxes PIN a value
-- away from a source column other systems write, and "who pinned this" has to
-- stay answerable. These do not pin anything — they ARE the source — so each
-- row carries updated_by and updated_datetime and that is the whole of the
-- audit trail. If a log is wanted later it is a third table and no change to
-- these two.
--
-- Run on globportal (prog4), then reload staffHeadcountReport.php and open
-- Daily Summary Report. Safe to run twice: both CREATEs are IF NOT EXISTS and
-- the seed is an INSERT IGNORE, so a second run cannot undo an edit made on
-- the page.
--
-- MySQL 5.6 (globportal is 5.6.51, Antelope, innodb_large_prefix off) — every
-- indexed column below is well inside the 767-byte per-column prefix limit.


-- ---------------------------------------------------------------------------
-- 1. The catalogue
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mkPortal`.`staff_summary_module` (
    -- THE KEY IS THE PRIMARY KEY, not an auto-increment with a unique key
    -- beside it. Every other table, every payload and every acked_items list
    -- refers to a module by this string; an id nothing quotes would be a
    -- second name for the same thing.
    `module_key`        varchar(32)  NOT NULL
        COMMENT 'late | leave | comp | ro1_work -- MUST match summarySections()',

    `title`             varchar(100) NOT NULL DEFAULT ''
        COMMENT 'the section heading, as the mail and the app print it',
    `empty_line`        varchar(255) NOT NULL DEFAULT ''
        COMMENT 'what the section says when there is nobody in it',

    -- WHERE THE FIGURES COME FROM. An absolute URL, because the fourth module
    -- is on another host and a relative filename cannot reach it. For the
    -- three on this server it is the same file the cron already calls, written
    -- out in full so all four read alike.
    `api_url`           varchar(255) NOT NULL DEFAULT '',
    `api_server`        varchar(64)  NOT NULL DEFAULT ''
        COMMENT 'a label for the reader: globportal | glob.com.my',

    -- BUILT IN means the cron renders this section from a snapshot it builds
    -- itself, the way it always has. A module that is NOT built in is fetched
    -- per person from api_url. The flag is what lets the fourth module arrive
    -- without the first three changing how they are produced.
    `built_in`          tinyint(1)   NOT NULL DEFAULT 0,

    `sort_order`        int(11)      NOT NULL DEFAULT 0
        COMMENT 'the order sections are shown in; 10, 20, 30, 40 leaves gaps',
    `active`            tinyint(1)   NOT NULL DEFAULT 1
        COMMENT '0 retires a module everywhere; no staff row is deleted',

    -- WHAT SOMEBODY GETS WHEN NOBODY HAS DECIDED ABOUT THEM. This is what
    -- keeps the exceptions table empty, and it is why running this file is a
    -- no-op for the 33 people already on the list.
    `default_allowed`   tinyint(1)   NOT NULL DEFAULT 0,
    `default_must_ack`  tinyint(1)   NOT NULL DEFAULT 0,

    `created_datetime`  datetime     NOT NULL,
    `updated_datetime`  datetime              DEFAULT NULL,

    PRIMARY KEY (`module_key`),
    KEY `shown_idx` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- ---------------------------------------------------------------------------
-- 2. The exceptions
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mkPortal`.`staff_summary_module_staff` (
    `id`                bigint(20)   NOT NULL AUTO_INCREMENT,

    -- (person, comp_id) IS THE IDENTITY THE WHOLE POOL IS KEYED BY, and
    -- comp_id is the HOME company: Trillium people are reported as company 16
    -- and stored under 1, so they are filed here under 1 and a Trillium row
    -- and its users row agree. homeCompId() in staffPoolCore.php is the rule.
    `person`            varchar(255) NOT NULL
        COMMENT 'users.person, stored lower-cased -- see dsModKey()',
    `comp_id`           int(11)      NOT NULL DEFAULT 1,
    `module_key`        varchar(32)  NOT NULL,

    -- THE TWO QUESTIONS, KEPT APART. "Is this section on their summary at
    -- all" and "do they have to tick it to finish the day" are different
    -- decisions: a manager can be shown RO1 Work Achievement for information
    -- and not be held at the gate by it. must_ack is meaningless while
    -- allowed is 0, and the page greys it out to say so.
    `allowed`           tinyint(1)   NOT NULL DEFAULT 0,
    `must_ack`          tinyint(1)   NOT NULL DEFAULT 0,

    -- THE WHOLE AUDIT TRAIL. See the note at the top of this file for why
    -- there is no log table behind these two columns.
    `updated_by`        varchar(100) NOT NULL DEFAULT '',
    `updated_by_id`     int(11)      NOT NULL DEFAULT 0,
    `created_datetime`  datetime     NOT NULL,
    `updated_datetime`  datetime              DEFAULT NULL,

    PRIMARY KEY (`id`),

    -- ONE ROW PER PERSON PER MODULE. The page writes with an upsert on this
    -- key, so saving the same grid twice cannot produce two answers for one
    -- cell. utf8_general_ci is case-insensitive, so 'Fionaliew' and
    -- 'fionaliew' are already the same row here whatever a caller sends.
    UNIQUE KEY `uq_person_module` (`person`, `comp_id`, `module_key`),
    KEY `module_idx` (`module_key`, `allowed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- ---------------------------------------------------------------------------
-- 3. The four modules
-- ---------------------------------------------------------------------------
--
-- INSERT IGNORE, so a second run of this file leaves an edited title, a
-- corrected api_url or a changed default exactly as it was found. To push a
-- new value deliberately, UPDATE it by name — there is a worked example at the
-- foot of this file.
--
-- NOTE THE CASE OF THE LEAVE FILENAME. It is leaveAPI.php with a LOWER-CASE
-- l. The live folder listing has it that way and staffSummaryCron.php calls
-- it that way; 'LeaveAPI.php' is a 404 on a Linux server, which would read as
-- the module being broken rather than as a typo.

INSERT IGNORE INTO `mkPortal`.`staff_summary_module`
    (`module_key`, `title`, `empty_line`, `api_url`, `api_server`,
     `built_in`, `sort_order`, `active`, `default_allowed`, `default_must_ack`,
     `created_datetime`)
VALUES
    ('late',  'Lateness',
     'Nobody was late or missing a check-in.',
     'https://globportal.com/accounts/mkPortal/hr/attendanceAPI.php',
     'globportal', 1, 10, 1, 1, 1, NOW()),

    ('leave', 'Excessive Leaves',
     'No leave taken or booked this month.',
     'https://globportal.com/accounts/mkPortal/hr/leaveAPI.php',
     'globportal', 1, 20, 1, 1, 1, NOW()),

    ('comp',  'Compliance',
     'No compliance recorded this month.',
     'https://globportal.com/accounts/mkPortal/hr/performanceAPI.php',
     'globportal', 1, 30, 1, 1, 1, NOW()),

    -- THE FOURTH, AND THE REASON THIS TABLE EXISTS. Off by default: it
    -- reaches nobody until somebody is ticked for it on the report, which is
    -- the whole point of "only for certain staff".
    ('ro1_work', 'RO1 Work Achievement',
     'No RO1 districts are assigned to you.',
     'https://glob.com.my/it/mkPortal/report/API/ro1_work_achievement.php',
     'glob.com.my', 0, 40, 1, 0, 0, NOW());


-- ---------------------------------------------------------------------------
-- Reading it back
-- ---------------------------------------------------------------------------
--
-- The catalogue as the page will draw it:
--
--   SELECT module_key, title, sort_order, built_in, api_server,
--          default_allowed, default_must_ack, api_url
--     FROM mkPortal.staff_summary_module
--    WHERE active = 1
--    ORDER BY sort_order, module_key;
--
-- Who is on RO1 Work Achievement, and whether they are held at the gate by it
-- (nobody, until the report is used):
--
--   SELECT s.person, u.fullname, u.email, s.allowed, s.must_ack,
--          s.updated_by, s.updated_datetime
--     FROM mkPortal.staff_summary_module_staff s
--     JOIN staff_portal2.users u
--       ON u.person = s.person AND u.comp_id = s.comp_id
--    WHERE s.module_key = 'ro1_work' AND s.allowed = 1
--    ORDER BY u.fullname;
--
-- The whole grid for one person, defaults shown where they have no row:
--
--   SELECT m.module_key, m.title,
--          IFNULL(s.allowed,  m.default_allowed)  AS allowed,
--          IFNULL(s.must_ack, m.default_must_ack) AS must_ack,
--          IF(s.id IS NULL, 'default', 'set') AS source
--     FROM mkPortal.staff_summary_module m
--     LEFT JOIN mkPortal.staff_summary_module_staff s
--       ON s.module_key = m.module_key
--      AND s.person = 'victorchai' AND s.comp_id = 1
--    WHERE m.active = 1
--    ORDER BY m.sort_order;
--
-- Anybody holding a module row who is no longer ticked for the daily summary
-- at all. Harmless — nothing reads them — but they are the rows to clear if
-- the table is ever tidied:
--
--   SELECT s.person, s.module_key, s.allowed
--     FROM mkPortal.staff_summary_module_staff s
--     LEFT JOIN staff_portal2.users u
--       ON u.person = s.person AND u.comp_id = s.comp_id
--      AND u.status = '1' AND u.deleted = '0'
--      AND IFNULL(u.daily_summary_report, 0) = 1
--    WHERE u.id IS NULL;
--
-- ---------------------------------------------------------------------------
-- Changing a module after the fact
-- ---------------------------------------------------------------------------
--
-- Moving the RO1 API to another address — one UPDATE, and every surface
-- follows it, because nothing stores a second copy of this string:
--
--   UPDATE mkPortal.staff_summary_module
--      SET api_url = 'https://glob.com.my/it/mkPortal/report/API/ro1_work_achievement.php',
--          api_server = 'glob.com.my',
--          updated_datetime = NOW()
--    WHERE module_key = 'ro1_work';
--
-- Retiring a module. active = 0 takes it off every summary and off the page
-- and leaves every staff row intact, so putting it back is the same UPDATE
-- the other way round. DELETE would lose who had been ticked for it:
--
--   UPDATE mkPortal.staff_summary_module
--      SET active = 0, updated_datetime = NOW()
--    WHERE module_key = 'ro1_work';
--
-- Giving RO1 Work Achievement to every Area Manager in one go, as information
-- only (allowed, not must-acknowledge). Check the SELECT before the INSERT:
--
--   SELECT LOWER(u.person), u.comp_id, u.fullname, u.position
--     FROM staff_portal2.users u
--    WHERE IFNULL(u.daily_summary_report, 0) = 1
--      AND u.status = '1' AND u.deleted = '0'
--      AND u.position LIKE '%Area Manager%';
--
--   INSERT INTO mkPortal.staff_summary_module_staff
--       (person, comp_id, module_key, allowed, must_ack,
--        updated_by, created_datetime)
--   SELECT LOWER(u.person), u.comp_id, 'ro1_work', 1, 0, 'seed', NOW()
--     FROM staff_portal2.users u
--    WHERE IFNULL(u.daily_summary_report, 0) = 1
--      AND u.status = '1' AND u.deleted = '0'
--      AND u.position LIKE '%Area Manager%'
--   ON DUPLICATE KEY UPDATE allowed = VALUES(allowed),
--                           must_ack = VALUES(must_ack),
--                           updated_by = VALUES(updated_by),
--                           updated_datetime = NOW();
