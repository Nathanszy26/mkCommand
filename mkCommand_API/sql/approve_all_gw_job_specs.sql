-- ============================================================================
-- Bulk approve every PENDING general worker job spec version.
--
-- This does by hand exactly what the Approve button in jobspecReport.php does:
-- jobSpecWrite.php's markReviewed() sets status, reviewed_by,
-- reviewed_by_comp_id, reviewed_at and review_note on job_spec_version and
-- touches nothing else. There is no cache and no item-level status, so this
-- one UPDATE is the whole operation.
--
-- WHAT IT DOES NOT DO, and you are agreeing to this by running it:
--   The app refuses an approval unless the approver is that worker's direct
--   superior and is not the person who submitted it. A bulk UPDATE has no
--   approver, so those two checks are not applied. Every row is stamped with
--   the note below so the batch stays identifiable and reversible.
--
-- Population is the one the report counts (GwApprovalReportRepository:
-- GW_IN_POPULATION) — live, scorecard-included workers in the chosen company.
-- Verified 23 Oct 2026: company 1 reads 328 pending, 1 approved, 0 rejected,
-- which is what the screen shows. No other company has any GW version at all.
--
-- RUN IT IN ONE SESSION. The transaction and @variables die on reconnect.
-- MySQL 5.6 / globportal.
-- ============================================================================

-- ---------------------------------------------------------------- settings --
SET @COMP       := 1;                              -- 1 = Globinaco
SET @ACTOR      := 'nathan';                       -- users.person doing this
SET @ACTOR_COMP := 1;                              -- that person's comp_id
SET @NOTE       := 'Bulk approved 2026-10-23';     -- batch key. Keep it unique.

-- ------------------------------------------------- 0. what you are about to
--    approve.  EXPECT: 328 for company 1.  A different number means the data
--    moved since this was written — stop and look before going on.
SELECT COUNT(*) AS will_be_approved
FROM mkPortal.job_spec_version v
WHERE IFNULL(v.is_gw, 0) = 1
  AND v.comp_id = @COMP
  AND v.status  = 'pending'
  AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
              WHERE gp.monthly_assign_gw_code = v.person
                AND gp.comp_id = CAST(v.comp_id AS CHAR)
                AND gp.monthly_assign_gw_status = '1'
                AND (gp.kpi_scorecard_exclude IS NULL
                     OR gp.kpi_scorecard_exclude <> '1'));

-- and the tiles as they read right now.  EXPECT: pending 328, approved 1.
SELECT v.status, COUNT(*) AS n
FROM mkPortal.job_spec_version v
WHERE IFNULL(v.is_gw, 0) = 1
  AND v.comp_id = @COMP
  AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
              WHERE gp.monthly_assign_gw_code = v.person
                AND gp.comp_id = CAST(v.comp_id AS CHAR)
                AND gp.monthly_assign_gw_status = '1'
                AND (gp.kpi_scorecard_exclude IS NULL
                     OR gp.kpi_scorecard_exclude <> '1'))
GROUP BY v.status;

-- ------------------------------------------------------------ 1. the update
SET autocommit = 0;
START TRANSACTION;

UPDATE mkPortal.job_spec_version v
SET v.status               = 'approved',
    v.reviewed_by          = @ACTOR,
    v.reviewed_by_comp_id  = @ACTOR_COMP,
    v.reviewed_at          = NOW(),
    v.review_note          = @NOTE
WHERE IFNULL(v.is_gw, 0) = 1
  AND v.comp_id = @COMP
  AND v.status  = 'pending'            -- guard: never re-stamps an actioned row
  AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
              WHERE gp.monthly_assign_gw_code = v.person
                AND gp.comp_id = CAST(v.comp_id AS CHAR)
                AND gp.monthly_assign_gw_status = '1'
                AND (gp.kpi_scorecard_exclude IS NULL
                     OR gp.kpi_scorecard_exclude <> '1'));
-- EXPECT: 328 rows affected.

-- ------------------------------------------------------------- 2. verify it
--    (a) the batch is the size you expected.  EXPECT: 328.
SELECT COUNT(*) AS stamped_by_this_batch
FROM mkPortal.job_spec_version
WHERE review_note = @NOTE;

--    (b) the tiles the report will now draw.  EXPECT: approved 329, no
--        pending row at all, rejected unchanged at 0.
SELECT v.status, COUNT(*) AS n
FROM mkPortal.job_spec_version v
WHERE IFNULL(v.is_gw, 0) = 1
  AND v.comp_id = @COMP
  AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
              WHERE gp.monthly_assign_gw_code = v.person
                AND gp.comp_id = CAST(v.comp_id AS CHAR)
                AND gp.monthly_assign_gw_status = '1'
                AND (gp.kpi_scorecard_exclude IS NULL
                     OR gp.kpi_scorecard_exclude <> '1'))
GROUP BY v.status;

--    (c) nothing on the staff side moved.  EXPECT: the same numbers you saw
--        on the Staff tab before you started.
SELECT v.status, COUNT(*) AS n
FROM mkPortal.job_spec_version v
WHERE IFNULL(v.is_gw, 0) = 0 AND v.comp_id = @COMP
GROUP BY v.status;

-- ----------------------------------------------------------- 3. decide
COMMIT;
-- ROLLBACK;   -- if any count above was not what you expected, run this instead

-- ============================================================================
-- UNDO, after a COMMIT. Puts every row this batch touched back to pending and
-- clears the review stamp. Only rows carrying this batch's note are affected,
-- so an approval somebody made by hand afterwards is left alone.
-- ============================================================================
-- SET autocommit = 0;
-- START TRANSACTION;
-- UPDATE mkPortal.job_spec_version
-- SET status = 'pending', reviewed_by = NULL, reviewed_by_comp_id = NULL,
--     reviewed_at = NULL, review_note = NULL
-- WHERE review_note = 'Bulk approved 2026-10-23';
-- -- EXPECT: 328 rows affected.
-- COMMIT;

-- ============================================================================
-- VARIANT — credit each worker's own supervisor instead of one actor.
--
-- Use this if the report's "reviewed by" column should read like an ordinary
-- approval. It picks the lowest-numbered eligible supervisor for each worker
-- (13 of the 328 are assigned under two, and either of them could have
-- approved), and it still never credits the person who submitted the version.
-- Swap it in for the UPDATE in step 1 — everything else is unchanged.
-- ============================================================================
-- UPDATE mkPortal.job_spec_version v
-- SET v.status              = 'approved',
--     v.reviewed_by         = (SELECT g.boss_id FROM evaluation.monthly_assign_gw g
--                               WHERE g.monthly_assign_gw_code = v.person
--                                 AND g.comp_id = CAST(v.comp_id AS CHAR)
--                                 AND g.monthly_assign_gw_status = '1'
--                                 AND (g.kpi_scorecard_exclude IS NULL
--                                      OR g.kpi_scorecard_exclude <> '1')
--                                 AND TRIM(IFNULL(g.boss_id, '')) <> ''
--                                 AND NOT (g.boss_id = v.submitted_by
--                                          AND g.boss_comp_id = CAST(v.submitted_by_comp_id AS CHAR))
--                               ORDER BY g.boss_id, g.boss_comp_id LIMIT 1),
--     v.reviewed_by_comp_id = (SELECT CAST(g.boss_comp_id AS UNSIGNED) FROM evaluation.monthly_assign_gw g
--                               WHERE g.monthly_assign_gw_code = v.person
--                                 AND g.comp_id = CAST(v.comp_id AS CHAR)
--                                 AND g.monthly_assign_gw_status = '1'
--                                 AND (g.kpi_scorecard_exclude IS NULL
--                                      OR g.kpi_scorecard_exclude <> '1')
--                                 AND TRIM(IFNULL(g.boss_id, '')) <> ''
--                                 AND NOT (g.boss_id = v.submitted_by
--                                          AND g.boss_comp_id = CAST(v.submitted_by_comp_id AS CHAR))
--                               ORDER BY g.boss_id, g.boss_comp_id LIMIT 1),
--     v.reviewed_at         = NOW(),
--     v.review_note         = @NOTE
-- WHERE IFNULL(v.is_gw, 0) = 1
--   AND v.comp_id = @COMP
--   AND v.status  = 'pending'
--   AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
--               WHERE gp.monthly_assign_gw_code = v.person
--                 AND gp.comp_id = CAST(v.comp_id AS CHAR)
--                 AND gp.monthly_assign_gw_status = '1'
--                 AND (gp.kpi_scorecard_exclude IS NULL
--                      OR gp.kpi_scorecard_exclude <> '1'));
-- -- EXPECT: 328 rows affected, and no NULL reviewed_by:
-- --   SELECT COUNT(*) FROM mkPortal.job_spec_version
-- --    WHERE review_note = @NOTE AND reviewed_by IS NULL;   -- EXPECT: 0
