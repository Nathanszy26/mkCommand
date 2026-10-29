-- ---------------------------------------------------------------------------
-- Daily Summary Report — who is stopped at Staff Utility until they acknowledge
-- ---------------------------------------------------------------------------
--
-- ONE COLUMN, ON users, AND THAT IS ALL. The tick lives where it is read
-- from: staffHeadcountReport.php writes it, staffSummaryAPI.php reads it when
-- the app asks (source=mobile), and nothing else touches it.
--
-- NOT A staff_flag_override FLAG, deliberately. The four flags in that table
-- exist to PIN a value away from a source column other systems write; this
-- column has no such source to disagree with, so an override layer over it
-- would be indirection with nothing on the other side.
--
-- WHAT IT DOES NOT DO: the morning mail. staffSummaryCron.php still writes to
-- every HOD and AM in the tree, so unticking somebody takes the app pop-up
-- away and leaves their mail exactly as it was.
--
-- Run on globportal (prog4), then reload staffHeadcountReport.php.
-- MySQL 5.6: no IF NOT EXISTS on ADD COLUMN, so this is written to be run once.

ALTER TABLE `staff_portal2`.`users`
    ADD COLUMN `daily_summary_report` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = gets the daily summary pop-up in the app (staffSummaryAPI source=mobile)';

-- The report reads the ticked set for one company at a time.
ALTER TABLE `staff_portal2`.`users`
    ADD INDEX `daily_summary_idx` (`comp_id`, `daily_summary_report`);

-- ---------------------------------------------------------------------------
-- Seeding (optional): tick the same people the mail already goes to.
--
-- The cron picks its recipients from the staff tree, not from a list, so there
-- is no stored set to copy. The nearest thing is who was written to for a day
-- that ran — this takes the most recent one.
--
-- Check it before running the UPDATE:
--
--   SELECT u.person, u.fullname, u.position
--     FROM staff_portal2.users u
--     JOIN mkPortal.staff_summary_notify n
--       ON n.person = u.person AND n.comp_id = u.comp_id
--    WHERE n.notify_date = (SELECT MAX(notify_date) FROM mkPortal.staff_summary_notify)
--      AND u.status = '1' AND u.deleted = '0'
--    ORDER BY u.fullname;
--
-- UPDATE staff_portal2.users u
--   JOIN mkPortal.staff_summary_notify n
--     ON n.person = u.person AND n.comp_id = u.comp_id
--    SET u.daily_summary_report = 1
--  WHERE n.notify_date = (SELECT MAX(notify_date) FROM mkPortal.staff_summary_notify)
--    AND u.status = '1' AND u.deleted = '0';
