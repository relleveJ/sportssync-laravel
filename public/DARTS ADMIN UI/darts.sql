-- ============================================================
-- darts_setup.sql
-- Full idempotent schema for darts_iskorsit
-- Run in phpMyAdmin or MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS sportssync
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sportssync;

-- Drop in FK-safe order
DROP TABLE IF EXISTS darts_throws;
DROP TABLE IF EXISTS darts_match_summary;
DROP TABLE IF EXISTS darts_legs;
DROP TABLE IF EXISTS darts_players;
DROP TABLE IF EXISTS darts_matches;

-- ---- matches -----------------------------------------------
CREATE TABLE darts_matches (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  game_type    ENUM('301','501','701') NOT NULL DEFAULT '301',
  legs_to_win  TINYINT NOT NULL DEFAULT 3,
  mode         ENUM('one-sided','two-sided') NOT NULL DEFAULT 'one-sided',
  status       ENUM('ongoing','completed') DEFAULT 'ongoing',
  winner_name  VARCHAR(100) DEFAULT NULL,
  owner_session VARCHAR(128) DEFAULT NULL,
  live_state   LONGTEXT DEFAULT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status_updated (status, updated_at)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- players -----------------------------------------------
CREATE TABLE darts_players (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  match_id       INT NOT NULL,
  player_number  TINYINT NOT NULL,
  player_name    VARCHAR(100) NOT NULL DEFAULT 'Player',
  team_name      VARCHAR(100) DEFAULT NULL,
  save_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
  FOREIGN KEY (match_id) REFERENCES darts_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- legs --------------------------------------------------
CREATE TABLE darts_legs (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  match_id          INT NOT NULL,
  leg_number        TINYINT NOT NULL,
  winner_player_id  INT DEFAULT NULL,
  played_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (match_id) REFERENCES darts_matches(id) ON DELETE CASCADE,
  FOREIGN KEY (winner_player_id) REFERENCES darts_players(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- throws ------------------------------------------------
CREATE TABLE darts_throws (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  leg_id        INT NOT NULL,
  player_id     INT NOT NULL,
  throw_number  INT NOT NULL,
  throw_value   INT NOT NULL,
  score_before  INT NOT NULL,
  score_after   INT NOT NULL,
  is_bust       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  FOREIGN KEY (leg_id)    REFERENCES darts_legs(id)    ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES darts_players(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- match_summary -----------------------------------------
CREATE TABLE darts_match_summary (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  match_id          INT NOT NULL UNIQUE,
  total_legs        INT NOT NULL DEFAULT 0,
  player1_legs_won  INT DEFAULT 0,
  player2_legs_won  INT DEFAULT 0,
  player3_legs_won  INT DEFAULT 0,
  player4_legs_won  INT DEFAULT 0,
  winner_player_id  INT DEFAULT NULL,
  declared_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  FOREIGN KEY (match_id)         REFERENCES darts_matches(id) ON DELETE CASCADE,
  FOREIGN KEY (winner_player_id) REFERENCES darts_players(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'Darts tables created successfully.' AS status;