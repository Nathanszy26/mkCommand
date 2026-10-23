-- ============================================================================
-- Issue a standard job spec to every Arus Sawit (comp_id 4) general worker,
-- approved by asyrafy.
--
-- This writes the same two tables the app writes, in the same shape
-- jobSpecWrite.php uses when a superior approves a worker's list outright
-- (approveCapability): one job_spec_version header with status 'approved',
-- submitted_by = the worker, reviewed_by = the approver, both stamps the same
-- moment, and one job_spec_version_item per task with sort_order from 0.
-- No job_spec_version_item_app rows: these tasks reference no application.
--
-- WHAT IT DOES NOT DO, and you are agreeing to this by running it:
--   The app will only let a worker's DIRECT SUPERIOR approve, and will not let
--   the submitter review their own. A bulk INSERT applies neither check — all
--   585 are recorded as reviewed by asyrafy. Every row carries the batch note
--   below so the whole batch stays identifiable and reversible.
--
-- Verified 23 Oct 2026: company 4 has 585 live general workers and ZERO job
-- spec versions of any kind, so nothing here can overwrite existing work. The
-- population is the one jobspecReport.php counts (live, scorecard-included)
-- and it is also exactly the staff pool's 585.
--
-- EXPECT: 585 versions and 6,435 items (585 x 11 tasks).
--
-- RUN IT IN ONE SESSION. The transaction and @variables die on reconnect.
-- MySQL 5.6 / globportal.
-- ============================================================================

-- ---------------------------------------------------------------- settings --
SET @COMP       := 4;                                   -- Arus Sawit
SET @ACTOR      := 'asyrafy';                           -- approver (users.person)
SET @ACTOR_COMP := 4;                                   -- approver's comp_id
SET @NOW        := NOW();                               -- one stamp for the batch
-- Plain ASCII on purpose: this string is the batch key, and it has to match
-- byte for byte in the undo block whatever client you paste it into.
SET @NOTE       := 'Bulk issued 2026-10-23 standard GW job spec';

-- -------------------------------------------- 0. who is about to get one.
--    EXPECT: 585 targets, 0 of them already holding a version.
SELECT COUNT(*) AS workers_to_receive,
       SUM(already_has) AS already_have_a_spec
FROM (
  SELECT g.monthly_assign_gw_code AS code,
         EXISTS (SELECT 1 FROM mkPortal.job_spec_version v
                 WHERE v.comp_id = @COMP AND IFNULL(v.is_gw,0) = 1
                   AND v.person = g.monthly_assign_gw_code) AS already_has
  FROM evaluation.monthly_assign_gw g
  WHERE g.comp_id = CAST(@COMP AS CHAR)
    AND g.monthly_assign_gw_status = '1'
    AND IFNULL(g.test_user, 0) <> 1
    AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')
  GROUP BY g.monthly_assign_gw_code
) t;

-- ------------------------------------------------------ 1. the headers
SET autocommit = 0;
START TRANSACTION;

INSERT INTO mkPortal.job_spec_version
    (person, comp_id, is_gw, status,
     submitted_by, submitted_by_comp_id, submitted_at,
     reviewed_by, reviewed_by_comp_id, reviewed_at, review_note)
SELECT g.monthly_assign_gw_code, @COMP, 1, 'approved',
       g.monthly_assign_gw_code, @COMP, @NOW,
       @ACTOR, @ACTOR_COMP, @NOW, @NOTE
FROM evaluation.monthly_assign_gw g
WHERE g.comp_id = CAST(@COMP AS CHAR)
  AND g.monthly_assign_gw_status = '1'
  AND IFNULL(g.test_user, 0) <> 1
  AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')
  -- never a second copy for somebody who already holds one
  AND NOT EXISTS (SELECT 1 FROM mkPortal.job_spec_version v
                  WHERE v.comp_id = @COMP AND IFNULL(v.is_gw,0) = 1
                    AND v.person = g.monthly_assign_gw_code)
GROUP BY g.monthly_assign_gw_code;   -- one per worker: a GW under two
                                     -- supervisors has two assignment rows
-- EXPECT: 585 rows affected.

-- ------------------------------------------------------- 2. the 11 tasks
--    One statement: every header just written, crossed with the task list.
--    sort_order runs from 0, which is how the app writes it. `category` is
--    left NULL — it is a section heading, and this list is one block.
INSERT INTO mkPortal.job_spec_version_item (version_id, sort_order, category, task)
SELECT v.id, t.so, NULL, t.task
FROM mkPortal.job_spec_version v
CROSS JOIN (
    SELECT  0 AS so, 'Carry out daily field operations as assigned by the Supervisor or Mandore' AS task
    UNION ALL SELECT  1, 'Operate and maintain basic plantation tools and equipment in a safe and proper manner'
    UNION ALL SELECT  2, 'Report any damaged infrastructure, pest infestation, disease outbreak, or unsafe conditions to the Supervisor.'
    UNION ALL SELECT  3, 'Ensure proper use of Personal Protective Equipment (PPE) and comply with all estate safety requirements.'
    UNION ALL SELECT  4, 'Attend toolbox meetings, safety briefings, and training programs conducted by the estate.'
    UNION ALL SELECT  5, 'Record daily work output accurately when required.'
    UNION ALL SELECT  6, 'Follow all company policies, standard operating procedures (SOP), and sustainability requirements.'
    UNION ALL SELECT  7, 'Perform any other duties assigned by Management from time to time.'
    UNION ALL SELECT  8, 'Perform general plantation field work as assigned.'
    UNION ALL SELECT  9, 'Maintain field cleanliness and plantation infrastructure.'
    UNION ALL SELECT 10, 'Assist in achieving estate productivity targets.'
) t
WHERE v.review_note = @NOTE;
-- EXPECT: 6,435 rows affected (585 x 11).

-- ------------------------------------------------------------- 3. verify it
--    (a) the batch is the size you expected.  EXPECT: 585 and 6,435.
SELECT (SELECT COUNT(*) FROM mkPortal.job_spec_version WHERE review_note = @NOTE) AS versions,
       (SELECT COUNT(*) FROM mkPortal.job_spec_version_item i
         JOIN mkPortal.job_spec_version v ON v.id = i.version_id
        WHERE v.review_note = @NOTE) AS items;

--    (b) every worker got exactly 11 tasks, and nobody got two specs.
--        EXPECT: one row reading 11 / 585, and no second row.
SELECT items_per_worker, COUNT(*) AS workers FROM (
  SELECT v.id, COUNT(i.id) AS items_per_worker
  FROM mkPortal.job_spec_version v
  LEFT JOIN mkPortal.job_spec_version_item i ON i.version_id = v.id
  WHERE v.review_note = @NOTE GROUP BY v.id
) z GROUP BY items_per_worker;

SELECT COUNT(*) AS workers_with_more_than_one_spec FROM (
  SELECT person FROM mkPortal.job_spec_version
  WHERE comp_id = @COMP AND IFNULL(is_gw,0) = 1
  GROUP BY person HAVING COUNT(*) > 1) z;

--    (c) one worker's spec, read the way the app reads it. Eyeball it.
SELECT v.person, v.status, v.reviewed_by, i.sort_order, i.task
FROM mkPortal.job_spec_version v
JOIN mkPortal.job_spec_version_item i ON i.version_id = v.id
WHERE v.review_note = @NOTE
  AND v.person = (SELECT MIN(person) FROM mkPortal.job_spec_version WHERE review_note = @NOTE)
ORDER BY i.sort_order;

--    (d) the tiles the general worker report will now draw for Arus Sawit.
--        EXPECT: approved 585, no pending row, no rejected row.
SELECT v.status, COUNT(*) AS n
FROM mkPortal.job_spec_version v
WHERE IFNULL(v.is_gw, 0) = 1 AND v.comp_id = @COMP
  AND EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
              WHERE gp.monthly_assign_gw_code = v.person
                AND gp.comp_id = CAST(v.comp_id AS CHAR)
                AND gp.monthly_assign_gw_status = '1'
                AND (gp.kpi_scorecard_exclude IS NULL
                     OR gp.kpi_scorecard_exclude <> '1'))
GROUP BY v.status;

-- ----------------------------------------------------------- 4. decide
COMMIT;
-- ROLLBACK;   -- if any count above was not what you expected, run this instead

-- ============================================================================
-- UNDO, after a COMMIT. Items first, then the headers — nothing else in the
-- database points at either. Only rows carrying this batch's note are touched,
-- so a spec somebody edited by hand afterwards keeps its own new version.
-- ============================================================================
-- SET autocommit = 0;
-- START TRANSACTION;
-- DELETE i FROM mkPortal.job_spec_version_item i
--   JOIN mkPortal.job_spec_version v ON v.id = i.version_id
--  WHERE v.review_note = 'Bulk issued 2026-10-23 standard GW job spec';
-- -- EXPECT: 6,435 rows.
-- DELETE FROM mkPortal.job_spec_version
--  WHERE review_note = 'Bulk issued 2026-10-23 standard GW job spec';
-- -- EXPECT: 585 rows.
-- COMMIT;

-- ============================================================================
-- If the tasks should sit under a heading in the app, give the FIRST task a
-- category — that is how the existing general worker specs are stored, one
-- label on the first row of a block and NULL on the rest. Run it inside the
-- same transaction, before COMMIT:
--
--   UPDATE mkPortal.job_spec_version_item i
--     JOIN mkPortal.job_spec_version v ON v.id = i.version_id
--      SET i.category = 'General Worker'
--    WHERE v.review_note = @NOTE AND i.sort_order = 0;
--   -- EXPECT: 585 rows.
-- ============================================================================
