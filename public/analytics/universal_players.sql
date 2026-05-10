-- ============================================================
-- universal_players.sql
-- Universal Player Identity for SportSync
-- Run in your `sportssync` database
-- ============================================================

USE sportssync;

-- ── UNIVERSAL PLAYER REGISTRY ───────────────────────────────
-- One row per unique identity: full_name + team_name = unique player
-- Same player can play basketball AND volleyball under the same ID
-- Different players with same name but different teams = different IDs

CREATE TABLE IF NOT EXISTS universal_players (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  full_name    VARCHAR(120)    NOT NULL,
  team_name    VARCHAR(120)    NOT NULL DEFAULT '',
  display_name VARCHAR(120)    GENERATED ALWAYS AS (
                  CONCAT(full_name, IF(team_name != '', CONCAT(' (', team_name, ')'), ''))
               ) STORED,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Uniqueness: same name + same team = same person
  UNIQUE KEY `uq_player_identity` (`full_name`, `team_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── STORED PROCEDURE: get_or_create_player ──────────────────
-- Usage: CALL get_or_create_player('Juan Dela Cruz', 'Team A', @pid);
-- Returns the universal player ID in @pid

DROP PROCEDURE IF EXISTS get_or_create_player;

DELIMITER $$
CREATE PROCEDURE get_or_create_player(
  IN  p_full_name  VARCHAR(120),
  IN  p_team_name  VARCHAR(120),
  OUT p_player_id  INT UNSIGNED
)
BEGIN
  -- Try to find existing player
  SELECT id INTO p_player_id
  FROM universal_players
  WHERE full_name = p_full_name
    AND team_name = COALESCE(p_team_name, '')
  LIMIT 1;

  -- If not found, create new
  IF p_player_id IS NULL THEN
    INSERT INTO universal_players (full_name, team_name)
    VALUES (p_full_name, COALESCE(p_team_name, ''))
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
    SET p_player_id = LAST_INSERT_ID();
  END IF;
END$$
DELIMITER ;

-- ── NOTES ───────────────────────────────────────────────────
-- Identity key: TRIM(LOWER(full_name)) + TRIM(LOWER(team_name))
-- "Juan Dela Cruz" + "Team A" ≠ "Juan Dela Cruz" + "Team B"
-- The PHP layer normalizes names before lookup (trim + proper case)
-- No changes needed to existing sport tables — this is additive only
-- The analytics_api.php resolves player_id at query time via JOIN on name+team

SELECT 'Universal player identity table created.' AS status;
