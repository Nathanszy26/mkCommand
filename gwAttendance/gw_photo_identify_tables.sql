-- Face-identification cache for GW attendance group photos.
--
-- Run against the `evaluation` database (where gw_attendance_attachments lives).
--
-- Why a cache at all: the report identifies photos automatically, with no button
-- to press, so without this every page view would re-scan every attachment on
-- the page — roughly a minute of face detection per load on a busy day. An
-- attachment's pixels never change, so a scan of it is valid forever and only
-- ever needs doing once.
--
-- scanner_version is what makes that safe. Bump the constant in
-- gwAttendanceIdentify.php whenever the matching changes (thresholds, pool
-- rules, detector settings) and every cached row is quietly treated as stale and
-- re-scanned on next view. No manual cache clearing.

CREATE TABLE IF NOT EXISTS gw_photo_scan (
  gw_attendance_attachment_id INT(11)     NOT NULL,
  faces_detected              INT(11)     NOT NULL DEFAULT 0,
  pool_matched                INT(11)     NOT NULL DEFAULT 0,
  outside_matched             INT(11)     NOT NULL DEFAULT 0,
  unmatched                   INT(11)     NOT NULL DEFAULT 0,
  pool_size                   INT(11)     NOT NULL DEFAULT 0,
  scanner_version             VARCHAR(20) NOT NULL DEFAULT '',
  scanned_at                  DATETIME    NOT NULL,
  PRIMARY KEY (gw_attendance_attachment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- One row per person identified in a photo. Faces that matched nobody are not
-- stored individually — only counted, in gw_photo_scan.unmatched — because the
-- report has nothing to say about a face it cannot name.
CREATE TABLE IF NOT EXISTS gw_photo_face (
  gw_photo_face_id            INT(11)      NOT NULL AUTO_INCREMENT,
  gw_attendance_attachment_id INT(11)      NOT NULL,
  person                      VARCHAR(100) NOT NULL DEFAULT '',   -- GW code, or staff person
  comp_id                     VARCHAR(10)  NOT NULL DEFAULT '',
  is_gw                       TINYINT(1)   NOT NULL DEFAULT 0,
  mymk_id                     INT(11)      DEFAULT NULL,          -- monthly_assign_gw_id, or users.id
  in_pool                     TINYINT(1)   NOT NULL DEFAULT 0,    -- was on this mandor's roster
  confidence                  DECIMAL(6,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (gw_photo_face_id),
  KEY idx_attachment (gw_attendance_attachment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
