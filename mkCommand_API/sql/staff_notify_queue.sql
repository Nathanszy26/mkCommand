-- ---------------------------------------------------------------------------
-- staffNotify.php -- the memo / merit / demerit notification queue.
--
-- One row per recipient per channel. The row IS the record of the attempt:
-- it is written while the memo is being saved (one INSERT .. SELECT, fast
-- even for an all-staff memo) and sent later by staffNotifyDrain.php.
--
-- Why a queue rather than sending inline: an all-staff memo reaches ~720
-- active accounts. That is ~720 curl calls to the FCM endpoint and ~720
-- mail() calls, which no web request can hold open. Writing the rows costs
-- two queries; sending them is somebody else's turn.
--
-- `uq_once` is what makes the enqueue idempotent: re-posting the same memo,
-- or a retry after a half-finished save, tops the queue up instead of
-- doubling it. INSERT IGNORE relies on it -- do not drop it.
--
-- MySQL 5.6 / InnoDB. utf8 (not utf8mb4) so the unique key stays inside the
-- 767-byte index prefix limit; the queue holds no emoji.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `staff_profile`.`staff_notify_queue` (
  `id`                BIGINT(20)   NOT NULL AUTO_INCREMENT,

  -- What happened. `record_id` is staff_memo_id for a memo and
  -- staff_performance_id for a merit/demerit -- the two never share a value
  -- space, hence `event` being part of the unique key.
  `event`             ENUM('memo','merit','demerit') NOT NULL,
  `record_id`         INT(11)      NOT NULL,
  `reference`         VARCHAR(255) NOT NULL DEFAULT '',

  -- Who, and how they are reached.
  `channel`           ENUM('push','email') NOT NULL,
  `recipient_user_id` INT(11)      NOT NULL DEFAULT 0,
  `recipient_name`    VARCHAR(150) NOT NULL DEFAULT '',
  `recipient_comp_id` INT(11)      NOT NULL DEFAULT 0,
  `recipient_role`    ENUM('to','cc','staff') NOT NULL DEFAULT 'to',
  `target`            VARCHAR(255) NOT NULL DEFAULT ''
                      COMMENT 'users.id for push, email address for email',

  -- What is said. `title`/`body` are the push as it will appear; `payload`
  -- is the JSON the email is rendered from plus the push data block, so the
  -- two channels are always describing the same event.
  `title`             VARCHAR(255) NOT NULL DEFAULT '',
  `body`              TEXT         NULL,
  `payload`           MEDIUMTEXT   NULL,

  `status`            ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `attempts`          INT(11)      NOT NULL DEFAULT 0,
  `response`          TEXT         NULL,
  `created_datetime`  DATETIME     NOT NULL,
  `updated_datetime`  DATETIME     NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_once` (`event`, `record_id`, `channel`, `recipient_user_id`),
  KEY `pending_idx` (`status`, `id`),
  KEY `record_idx` (`event`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
