-- ---------------------------------------------------------------------------
-- Custom tags for the MK Command Staff Directory
--
-- Run this ONCE on globportal.com. Order does not matter: staffDirectory.php
-- detects whether each column exists and simply skips tag matching until it
-- does, so uploading the PHP before or after this is equally safe.
--
-- Nothing writes tags yet. This is the storage plus the search path; populate
-- by hand (examples at the bottom) until an editor is built.
--
-- One free-text, comma-separated column per population. Both are declared
-- latin1_swedish_ci to match the table they live in — a column created with the
-- server default would collate differently from the columns it sits beside and
-- break comparisons against them.
-- ---------------------------------------------------------------------------


-- ---- Staff tags -----------------------------------------------------------
ALTER TABLE staff_portal2.users
    ADD COLUMN tags VARCHAR(255)
        CHARACTER SET latin1 COLLATE latin1_swedish_ci
        NULL DEFAULT NULL
        AFTER position;


-- ---- GW tags --------------------------------------------------------------
ALTER TABLE evaluation.monthly_assign_gw
    ADD COLUMN monthly_assign_gw_tags VARCHAR(255)
        CHARACTER SET latin1 COLLATE latin1_swedish_ci
        NULL DEFAULT NULL
        AFTER monthly_assign_gw_district;


-- MySQL 5.6 has no "ADD COLUMN IF NOT EXISTS": if a column is already there the
-- statement errors with 1060 (Duplicate column name) and is safe to ignore.
-- Check with:
--     SHOW COLUMNS FROM staff_portal2.users LIKE 'tags';
--     SHOW COLUMNS FROM evaluation.monthly_assign_gw LIKE 'monthly_assign_gw_tags';


-- ---- Examples -------------------------------------------------------------
-- Staff — comma-separated, any number:
--   UPDATE staff_portal2.users
--      SET tags = 'forklift licence,first aider'
--    WHERE person = 'nathan' AND comp_id = 1;
--
-- GW — same, but note 24 active GW codes own MORE THAN ONE row in
-- monthly_assign_gw, so match on the code and let it update them all:
--   UPDATE evaluation.monthly_assign_gw
--      SET monthly_assign_gw_tags = 'chainsaw,night shift'
--    WHERE monthly_assign_gw_code = 'KKW358';
--
-- The directory reads defensively either way: it matches on any row for a code
-- and merges the distinct tags of all of them, so a tag that lands on only one
-- row still finds the worker.
--
-- Clear tags:
--   UPDATE staff_portal2.users SET tags = NULL WHERE person = 'nathan' AND comp_id = 1;
