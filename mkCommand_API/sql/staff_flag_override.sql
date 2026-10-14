-- ---------------------------------------------------------------------------
-- staffHeadcountReport.php — storage for the tick boxes.
--
-- Nothing here touches staff_portal2.users or evaluation.monthly_assign_gw.
-- Those two stay the SOURCE of the values; this schema only records where a
-- person has been ticked or unticked on the report, and the effective value a
-- row shows is "override if one exists here, otherwise the source column".
--
-- Two tables on purpose:
--   staff_flag_override      current state, one row per person+flag. What the
--                            report reads. Small (tens of rows per company).
--   staff_flag_override_log  append-only history. What the change log at the
--                            bottom of the report reads, and what makes
--                            "who turned this person off, and when" answerable
--                            after the override has been toggled back.
--
-- MySQL 5.6 / InnoDB. utf8 (not utf8mb4) and person VARCHAR(100) keep the
-- composite unique key inside the 767-byte index prefix limit. Longest real
-- values today: users.person 20 chars, monthly_assign_gw_code 8.
--
-- `person` holds users.person for staff and monthly_assign_gw_code for GW.
-- Those are different namespaces and CAN collide, which is why person_type is
-- part of the key — the same rule jobspecReport.php follows.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `staff_portal2`.`staff_flag_override` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `person_type`   ENUM('staff','gw') NOT NULL,
  `comp_id`       INT(11)      NOT NULL,
  `person`        VARCHAR(100) NOT NULL COMMENT 'users.person, or monthly_assign_gw_code for GW',
  `flag`          ENUM('in_pool','kpi','mk_command','mk_academy') NOT NULL,
  `value`         TINYINT(1)   NOT NULL COMMENT '1=ticked, 0=unticked',
  `updated_by`    VARCHAR(50)  NOT NULL,
  `updated_by_id` INT(11)      NOT NULL,
  `updated_date`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_flag` (`person_type`, `comp_id`, `person`, `flag`),
  KEY `comp_flag_idx` (`comp_id`, `flag`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `staff_portal2`.`staff_flag_override_log` (
  `id`            BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `person_type`   ENUM('staff','gw') NOT NULL,
  `comp_id`       INT(11)      NOT NULL,
  `person`        VARCHAR(100) NOT NULL,
  `person_name`   VARCHAR(255) DEFAULT NULL COMMENT 'name as it read at the time, so the log survives a rename or a resignation',
  `flag`          ENUM('in_pool','kpi','mk_command','mk_academy') NOT NULL,
  `old_value`     TINYINT(1)   DEFAULT NULL COMMENT 'NULL = no override yet, i.e. it was still the source-table value',
  `new_value`     TINYINT(1)   NOT NULL,
  `changed_by`    VARCHAR(50)  NOT NULL,
  `changed_by_id` INT(11)      NOT NULL,
  `changed_date`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `comp_date_idx` (`comp_id`, `changed_date`),
  KEY `person_idx` (`person_type`, `comp_id`, `person`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
