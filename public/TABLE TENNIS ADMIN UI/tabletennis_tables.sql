-- Table Tennis schema for sportssync (matches badminton structure with table_tennis_ prefix)
USE sportssync;

DROP TABLE IF EXISTS table_tennis_match_summary;
DROP TABLE IF EXISTS table_tennis_sets;
DROP TABLE IF EXISTS table_tennis_matches;

CREATE TABLE IF NOT EXISTS table_tennis_matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_type ENUM('Singles','Doubles','Mixed Doubles') NOT NULL DEFAULT 'Singles',
  best_of TINYINT NOT NULL DEFAULT 3,
  team_a_name VARCHAR(100) NOT NULL DEFAULT 'Team A',
  team_b_name VARCHAR(100) NOT NULL DEFAULT 'Team B',
  team_a_player1 VARCHAR(100) DEFAULT NULL,
  team_a_player2 VARCHAR(100) DEFAULT NULL,
  team_b_player1 VARCHAR(100) DEFAULT NULL,
  team_b_player2 VARCHAR(100) DEFAULT NULL,
  status ENUM('ongoing','completed','reset') NOT NULL DEFAULT 'ongoing',
  winner_name VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_tennis_sets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  set_number TINYINT NOT NULL,
  team_a_score INT NOT NULL DEFAULT 0,
  team_b_score INT NOT NULL DEFAULT 0,
  team_a_timeout_used TINYINT(1) NOT NULL DEFAULT 0,
  team_b_timeout_used TINYINT(1) NOT NULL DEFAULT 0,
  serving_team ENUM('A','B') NOT NULL DEFAULT 'A',
  set_winner ENUM('A','B') DEFAULT NULL,
  saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (match_id) REFERENCES table_tennis_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_tennis_match_summary (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL UNIQUE,
  total_sets_played TINYINT NOT NULL DEFAULT 0,
  team_a_sets_won INT NOT NULL DEFAULT 0,
  team_b_sets_won INT NOT NULL DEFAULT 0,
  winner_team ENUM('A','B') DEFAULT NULL,
  winner_name VARCHAR(100) DEFAULT NULL,
  declared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (match_id) REFERENCES table_tennis_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Table Tennis tables created successfully in sportssync.' AS status;
