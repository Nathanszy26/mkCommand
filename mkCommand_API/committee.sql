-- ---------------------------------------------------------------------------
-- Committees for MK Command
--
-- Run this ONCE on globportal.com. Order does not matter: staffHierarchy.php
-- probes for these two tables and simply returns no committees until they are
-- there, so uploading the PHP before or after this is equally safe. Same
-- contract staffDirectory_tags.sql has with staffDirectory.php.
--
-- Nothing writes committees yet. This is the storage plus the read path;
-- populate by hand (examples at the bottom) until an editor is built.
--
-- They live in mkPortal because that is where MK Command's OWN tables live
-- (job_spec_version, mk_command_access) — staff_portal2 holds the HR records
-- this app only reads. prog4@localhost reaches both, so staffHierarchy.php
-- reads them fully-qualified over its existing connection.
--
-- A committee is deliberately NOT confined to one company: membership is keyed
-- on (person, comp_id), so one committee can seat staff from several
-- subsidiaries at once. mk_committee.comp_id names the company that OWNS the
-- committee (NULL = group-wide); it never limits who may sit on it.
--
-- Scope: staff only. GW (evaluation.monthly_assign_gw) are not seatable here —
-- the join below is to staff_portal2.users, and a GW code lives in a different
-- namespace that can collide with a users.person. Add a separate seat table if
-- that is ever needed, rather than overloading these columns.
-- ---------------------------------------------------------------------------


-- ---- The committee itself -------------------------------------------------
CREATE TABLE IF NOT EXISTS mkPortal.mk_committee (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    code        VARCHAR(50)  DEFAULT NULL COMMENT 'short label, e.g. OSH, 5S',
    description VARCHAR(500) DEFAULT NULL,
    comp_id     INT(11)      DEFAULT NULL COMMENT 'owning company; NULL = group-wide. Does NOT limit membership',
    date_from   DATE         DEFAULT NULL COMMENT 'NULL = unbounded, same rule as monthly_assign',
    date_to     DATE         DEFAULT NULL COMMENT 'NULL = unbounded',
    status      ENUM('1','0') NOT NULL DEFAULT '1',
    created_at  DATETIME     DEFAULT NULL,
    updated_at  DATETIME     DEFAULT NULL,
    deleted_at  DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    -- One committee of a given name per company. MySQL treats NULLs as
    -- distinct, so this does NOT stop two group-wide committees sharing a
    -- name — check for that by hand when adding one.
    UNIQUE KEY uq_name_comp (name, comp_id),
    KEY idx_live (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---- Who sits on it -------------------------------------------------------
-- `person` is declared latin1_swedish_ci on purpose: it is joined to
-- staff_portal2.users.person, which is latin1_swedish_ci. Left on this table's
-- utf8mb4 default the join still RETURNS the right rows -- MySQL converts
-- latin1 to utf8mb4 to compare them -- but the conversion makes users' unique
-- (comp_id, person) index unusable past its first column. Measured on this
-- server against mk_command_access, which is utf8mb4 and has exactly that
-- mismatch:
--
--   utf8mb4 person -> type=ref,    key_len=4,  rows=34 per company
--   latin1  person -> type=eq_ref, key_len=56, rows=1
--
-- 34 rows scanned per seat instead of 1, on every Staff tab load. Declaring the
-- column latin1 keeps the lookup an eq_ref.
CREATE TABLE IF NOT EXISTS mkPortal.mk_committee_member (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    committee_id   INT UNSIGNED NOT NULL,
    person         VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    comp_id        INT(11)     NOT NULL COMMENT 'the company this member belongs to; need not match mk_committee.comp_id',
    committee_role VARCHAR(60) DEFAULT NULL COMMENT 'Chairman, Secretary, Member, ...',
    sort_order     INT(11)     NOT NULL DEFAULT 0 COMMENT 'display order; ties fall back to name',
    date_from      DATE        DEFAULT NULL COMMENT 'NULL = unbounded',
    date_to        DATE        DEFAULT NULL COMMENT 'NULL = unbounded',
    status         ENUM('1','0') NOT NULL DEFAULT '1',
    created_at     DATETIME    DEFAULT NULL,
    updated_at     DATETIME    DEFAULT NULL,
    deleted_at     DATETIME    DEFAULT NULL,
    PRIMARY KEY (id),
    -- One live seat per person per committee. Re-seating someone after they
    -- left means re-dating the existing row, not inserting a second one.
    UNIQUE KEY uq_seat (committee_id, person, comp_id),
    -- The read path starts from a person: "which committees is this staff on".
    KEY idx_person (person, comp_id, status),
    KEY idx_committee (committee_id, status),
    CONSTRAINT fk_committee_member_committee
        FOREIGN KEY (committee_id) REFERENCES mkPortal.mk_committee (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- MySQL 5.6 honours CREATE TABLE IF NOT EXISTS, so re-running this is safe.
-- Check with:
--     SHOW TABLES FROM mkPortal LIKE 'mk_committee%';


-- ---- Examples -------------------------------------------------------------
-- A group-wide committee (comp_id NULL) seating staff of three companies:
--
--   INSERT INTO mkPortal.mk_committee (name, code, description, comp_id, status, created_at)
--   VALUES ('Safety & Health Committee', 'OSH',
--           'Statutory OSH committee for the group', NULL, '1', NOW());
--
--   SET @c = LAST_INSERT_ID();
--
--   INSERT INTO mkPortal.mk_committee_member
--       (committee_id, person, comp_id, committee_role, sort_order, status, created_at)
--   VALUES (@c, 'nathan', 1, 'Secretary', 2, '1', NOW()),
--          (@c, 'kevin',  1, 'Chairman',  1, '1', NOW()),
--          (@c, 'someone', 3, 'Member',   3, '1', NOW());
--
-- A committee owned by one company (still free to seat outsiders):
--
--   INSERT INTO mkPortal.mk_committee (name, code, comp_id, status, created_at)
--   VALUES ('5S Audit Committee', '5S', 1, '1', NOW());
--
-- End someone seat without deleting the history:
--
--   UPDATE mkPortal.mk_committee_member
--      SET date_to = CURDATE(), updated_at = NOW()
--    WHERE committee_id = 1 AND person = 'nathan' AND comp_id = 1;
--
-- Retire a whole committee:
--
--   UPDATE mkPortal.mk_committee SET status = '0', updated_at = NOW() WHERE id = 1;
--
-- person/comp_id must match staff_portal2.users exactly — check before inserting:
--
--   SELECT person, comp_id, fullname FROM staff_portal2.users
--    WHERE fullname LIKE '%nathan%' AND status = 1 AND IFNULL(deleted,0) = 0;
