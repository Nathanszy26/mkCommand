-- ---------------------------------------------------------------------------
-- mk_committee.comp_id becomes a VIEWER LIST
--
-- Run this ONCE on globportal.com, BEFORE uploading the new staffDirectory.php
-- and staffHierarchy.php. This one is order-dependent, unlike committee.sql:
-- the old column is int(11) and the new code writes `FIND_IN_SET(?, comp_id)`
-- against it. MySQL will happily run that on an int and quietly give the wrong
-- answer rather than erroring, so do the ALTER first.
--
-- ---- What changes ---------------------------------------------------------
--
-- comp_id used to mean "the company that OWNS this committee", and it limited
-- nothing - it was a label. It now means "the companies allowed to SEE this
-- committee in the Committees list", which is a rule the server enforces:
--
--     comp_id IS NULL or ''   ->  every company sees it   (group-wide)
--     comp_id = '1'           ->  only comp 1 sees it
--     comp_id = '1,2,3'       ->  only comps 1, 2 and 3 see it
--
-- It still does NOT limit MEMBERSHIP. A committee visible only to comp 1 may
-- seat staff of comps 3 and 8, and those members are listed in full to anyone
-- who can see the committee. Seats are keyed on (person, comp_id) in
-- mk_committee_member and that is the only thing membership depends on.
--
-- The one place the two meet: a member of a committee their own company cannot
-- see still sees it on their own card, because "which committees am I on" is
-- answered from their seat, not from this list. Hiding a committee someone
-- actually sits on would be a lie, not a permission.
--
-- ---- Why a string and not a join table ------------------------------------
--
-- A proper mk_committee_company table would be the textbook answer. This is a
-- hand-maintained list of at most a dozen subsidiary ids on a table with one
-- row in it today, edited by whoever adds a committee by hand in phpMyAdmin.
-- A CSV column they can read and retype is the honest fit for that; revisit it
-- when a committee editor exists and the ids stop being typed by a human.
-- ---------------------------------------------------------------------------


-- ---- The column -----------------------------------------------------------
-- int(11) -> VARCHAR(100). The existing values survive the cast: NULL stays
-- NULL and 1 becomes '1', both of which mean the same thing afterwards as they
-- did before. 100 chars holds ~25 comma-separated ids, well past the number of
-- subsidiaries that exist.
--
-- uq_name_comp (name, comp_id) survives the type change and keeps doing its
-- job, with one new wrinkle worth knowing: '1,2' and '2,1' are different
-- strings, so the index will not stop you entering the same committee twice
-- with its ids in a different order. Keep the lists ascending.
ALTER TABLE mkPortal.mk_committee
    MODIFY COLUMN comp_id VARCHAR(100) DEFAULT NULL
    COMMENT 'companies that may SEE this committee, comma-separated ids; NULL/empty = all. Does NOT limit membership';


-- ---- Tidy what is already in there ----------------------------------------
-- Nothing to convert on a column that only ever held one id, but an empty
-- string and a NULL now mean the same thing and only one of them should exist.
-- Collapsing to NULL keeps "group-wide" a single value in the data.
UPDATE mkPortal.mk_committee
   SET comp_id = NULL
 WHERE comp_id IS NOT NULL AND TRIM(comp_id) = '';


-- ---- Check ----------------------------------------------------------------
--     SHOW COLUMNS FROM mkPortal.mk_committee LIKE 'comp_id';
--
-- Who can see what, as the server reads it:
--
--     SELECT c.id, c.name, c.comp_id,
--            CASE WHEN c.comp_id IS NULL OR TRIM(c.comp_id) = ''
--                 THEN 'all companies' ELSE c.comp_id END AS visible_to
--       FROM mkPortal.mk_committee c
--      WHERE c.status = '1' AND c.deleted_at IS NULL;
--
-- Does comp 3 see committee 1?  (1 = yes)
--
--     SELECT c.id, c.name,
--            (c.comp_id IS NULL OR TRIM(c.comp_id) = ''
--             OR FIND_IN_SET('3', REPLACE(c.comp_id, ' ', '')) > 0) AS visible
--       FROM mkPortal.mk_committee c;


-- ---- Examples -------------------------------------------------------------
-- Group IT stays visible to everyone - it is already NULL, nothing to do.
--
-- Restrict a committee to Globinaco and two others:
--
--   UPDATE mkPortal.mk_committee
--      SET comp_id = '1,2,3', updated_at = NOW()
--    WHERE id = 1;
--
-- Put it back to group-wide:
--
--   UPDATE mkPortal.mk_committee
--      SET comp_id = NULL, updated_at = NOW()
--    WHERE id = 1;
--
-- Add a committee only comp 2 may see, seating people from anywhere:
--
--   INSERT INTO mkPortal.mk_committee (name, code, comp_id, status, created_at)
--   VALUES ('Safety & Health Committee', 'OSH', '2', '1', NOW());
--
-- Ids come from staff_portal2.subsidiaries - write them WITHOUT spaces
-- ('1,2,3', not '1, 2, 3'). The server strips spaces before matching so a
-- stray one is survivable, but the column is read by people too:
--
--   SELECT id, name FROM staff_portal2.subsidiaries ORDER BY id;
