-- ============================================================
-- SportSync — Volleyball Module Schema
-- Run in your `sportssync` database
-- ============================================================

-- Volleyball matches
CREATE TABLE IF NOT EXISTS `volleyball_matches` (
  `match_id`       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `owner_user_id`  INT UNSIGNED             DEFAULT NULL,
  `team_a_name`    VARCHAR(80)     NOT NULL DEFAULT 'TEAM A',
  `team_b_name`    VARCHAR(80)     NOT NULL DEFAULT 'TEAM B',
  `team_a_score`   SMALLINT        NOT NULL DEFAULT 0,
  `team_b_score`   SMALLINT        NOT NULL DEFAULT 0,
  `team_a_timeout` TINYINT         NOT NULL DEFAULT 0,
  `team_b_timeout` TINYINT         NOT NULL DEFAULT 0,
  `current_set`    TINYINT         NOT NULL DEFAULT 1,
  `match_result`   VARCHAR(30)     NOT NULL DEFAULT 'ONGOING',
  `committee`      VARCHAR(120)             DEFAULT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Volleyball match players
CREATE TABLE IF NOT EXISTS `volleyball_players` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `match_id`    INT UNSIGNED    NOT NULL,
  `team`        CHAR(1)         NOT NULL,
  `jersey_no`   VARCHAR(5)               DEFAULT NULL,
  `player_name` VARCHAR(80)     NOT NULL DEFAULT '',
  `pts`         SMALLINT        NOT NULL DEFAULT 0,
  `spike`       SMALLINT        NOT NULL DEFAULT 0,
  `ace`         SMALLINT        NOT NULL DEFAULT 0,
  `ex_set`      SMALLINT        NOT NULL DEFAULT 0,
  `ex_dig`      SMALLINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_match` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reuse existing match_state table (no new table needed)
-- match_state (match_id PK, payload LONGTEXT, updated_at DATETIME)
-- Ensure it exists if not already created:
CREATE TABLE IF NOT EXISTS `match_state` (
  `match_id`   INT          NOT NULL,
  `payload`    LONGTEXT     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
