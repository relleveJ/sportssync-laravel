-- Migration: rename existing tables to darts_ prefix when needed
-- BACKUP your database before running this script.

SET @old = NULL;
SET @new = NULL;

-- Helper: rename if old exists and new does not
-- Usage: replace 'matches' and 'darts_matches' in the blocks below

-- matches -> darts_matches
SET @old = 'matches';
SET @new = 'darts_matches';
SET @exists_old = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @old);
SET @exists_new = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @new);
SET @sql = IF(@exists_old = 1 AND @exists_new = 0, CONCAT('RENAME TABLE `', @old, '` TO `', @new, '`;'), 'SELECT "skip_matches";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- players -> darts_players
SET @old = 'players';
SET @new = 'darts_players';
SET @exists_old = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @old);
SET @exists_new = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @new);
SET @sql = IF(@exists_old = 1 AND @exists_new = 0, CONCAT('RENAME TABLE `', @old, '` TO `', @new, '`;'), 'SELECT "skip_players";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- legs -> darts_legs
SET @old = 'legs';
SET @new = 'darts_legs';
SET @exists_old = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @old);
SET @exists_new = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @new);
SET @sql = IF(@exists_old = 1 AND @exists_new = 0, CONCAT('RENAME TABLE `', @old, '` TO `', @new, '`;'), 'SELECT "skip_legs";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- throws -> darts_throws
SET @old = 'throws';
SET @new = 'darts_throws';
SET @exists_old = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @old);
SET @exists_new = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @new);
SET @sql = IF(@exists_old = 1 AND @exists_new = 0, CONCAT('RENAME TABLE `', @old, '` TO `', @new, '`;'), 'SELECT "skip_throws";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- match_summary -> darts_match_summary
SET @old = 'match_summary';
SET @new = 'darts_match_summary';
SET @exists_old = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @old);
SET @exists_new = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @new);
SET @sql = IF(@exists_old = 1 AND @exists_new = 0, CONCAT('RENAME TABLE `', @old, '` TO `', @new, '`;'), 'SELECT "skip_match_summary";');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration completed. Check output to confirm which renames ran.' AS status;
