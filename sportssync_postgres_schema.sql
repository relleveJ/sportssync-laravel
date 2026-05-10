CREATE TABLE IF NOT EXISTS "activity_log" (
  "id" serial,
  "action" varchar(255),
  "user_id" integer,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "username" varchar(100),
  "timestamp" varchar(100)
);

CREATE TABLE IF NOT EXISTS "auth_log" (
  "id" serial,
  "user_id" integer,
  "event" varchar(30) NOT NULL,
  "ip_address" varchar(45) NOT NULL DEFAULT '',
  "detail" varchar(255),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "badminton_matches" (
  "id" serial,
  "match_type" text NOT NULL,
  "best_of" smallint NOT NULL DEFAULT 3,
  "team_a_name" varchar(100) NOT NULL DEFAULT 'Team A',
  "team_b_name" varchar(100) NOT NULL DEFAULT 'Team B',
  "team_a_player1" varchar(100),
  "team_a_player2" varchar(100),
  "team_b_player1" varchar(100),
  "team_b_player2" varchar(100),
  "status" text NOT NULL DEFAULT 'ongoing',
  "winner_name" varchar(100),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "committee_official" varchar(255)
);

CREATE TABLE IF NOT EXISTS "badminton_match_state" (
  "match_id" varchar(64) NOT NULL,
  "state_json" text NOT NULL,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "badminton_match_summary" (
  "id" serial,
  "match_id" integer NOT NULL,
  "total_sets_played" smallint NOT NULL DEFAULT 0,
  "team_a_sets_won" integer NOT NULL DEFAULT 0,
  "team_b_sets_won" integer NOT NULL DEFAULT 0,
  "winner_team" text,
  "winner_name" varchar(100),
  "declared_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "badminton_sets" (
  "id" serial,
  "match_id" integer NOT NULL,
  "set_number" smallint NOT NULL,
  "team_a_score" integer NOT NULL DEFAULT 0,
  "team_b_score" integer NOT NULL DEFAULT 0,
  "team_a_timeout_used" smallint NOT NULL DEFAULT 0,
  "team_b_timeout_used" smallint NOT NULL DEFAULT 0,
  "serving_team" text NOT NULL DEFAULT 'A',
  "set_winner" text,
  "saved_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "committee_official" varchar(255)
);

CREATE TABLE IF NOT EXISTS "cache" (
  "key" varchar(255) NOT NULL,
  "value" text NOT NULL,
  "expiration" integer NOT NULL
);

CREATE TABLE IF NOT EXISTS "cache_locks" (
  "key" varchar(255) NOT NULL,
  "owner" varchar(255) NOT NULL,
  "expiration" integer NOT NULL
);

CREATE TABLE IF NOT EXISTS "darts_legs" (
  "id" serial,
  "match_id" integer NOT NULL,
  "leg_number" smallint NOT NULL,
  "winner_player_id" integer,
  "played_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "darts_matches" (
  "id" serial,
  "game_type" text NOT NULL DEFAULT '301',
  "legs_to_win" smallint NOT NULL DEFAULT 3,
  "mode" text NOT NULL DEFAULT 'one-sided',
  "status" text DEFAULT 'ongoing',
  "winner_name" varchar(100),
  "owner_session" varchar(128),
  "live_state" text,
  "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "darts_match_summary" (
  "id" serial,
  "match_id" integer NOT NULL,
  "total_legs" integer NOT NULL DEFAULT 0,
  "player1_legs_won" integer DEFAULT 0,
  "player2_legs_won" integer DEFAULT 0,
  "player3_legs_won" integer DEFAULT 0,
  "player4_legs_won" integer DEFAULT 0,
  "winner_player_id" integer,
  "declared_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "darts_players" (
  "id" serial,
  "match_id" integer NOT NULL,
  "player_number" smallint NOT NULL,
  "player_name" varchar(100) NOT NULL DEFAULT 'Player',
  "team_name" varchar(100),
  "save_enabled" smallint NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS "darts_throws" (
  "id" serial,
  "leg_id" integer NOT NULL,
  "player_id" integer NOT NULL,
  "throw_number" integer NOT NULL,
  "throw_value" integer NOT NULL,
  "score_before" integer NOT NULL,
  "score_after" integer NOT NULL,
  "is_bust" smallint NOT NULL DEFAULT 0,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "draft_match_states" (
  "match_id" integer NOT NULL,
  "payload" text NOT NULL,
  "updated_at" timestamp NOT NULL
);

CREATE TABLE IF NOT EXISTS "failed_jobs" (
  "id" bigserial,
  "uuid" varchar(255) NOT NULL,
  "connection" text NOT NULL,
  "queue" text NOT NULL,
  "payload" text NOT NULL,
  "exception" text NOT NULL,
  "failed_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "jobs" (
  "id" bigserial,
  "queue" varchar(255) NOT NULL,
  "payload" text NOT NULL,
  "attempts" smallint NOT NULL,
  "reserved_at" integer,
  "available_at" integer NOT NULL,
  "created_at" integer NOT NULL
);

CREATE TABLE IF NOT EXISTS "job_batches" (
  "id" varchar(255) NOT NULL,
  "name" varchar(255) NOT NULL,
  "total_jobs" integer NOT NULL,
  "pending_jobs" integer NOT NULL,
  "failed_jobs" integer NOT NULL,
  "failed_job_ids" text NOT NULL,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer NOT NULL,
  "finished_at" integer
);

CREATE TABLE IF NOT EXISTS "matches" (
  "match_id" serial,
  "owner_user_id" integer,
  "saved_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "team_a_name" varchar(100) NOT NULL DEFAULT '',
  "team_b_name" varchar(100) NOT NULL DEFAULT '',
  "team_a_score" integer NOT NULL DEFAULT 0,
  "team_b_score" integer NOT NULL DEFAULT 0,
  "team_a_foul" integer NOT NULL DEFAULT 0,
  "team_a_timeout" integer NOT NULL DEFAULT 0,
  "team_a_quarter" integer NOT NULL DEFAULT 0,
  "team_b_foul" integer NOT NULL DEFAULT 0,
  "team_b_timeout" integer NOT NULL DEFAULT 0,
  "team_b_quarter" integer NOT NULL DEFAULT 0,
  "match_result" varchar(20) NOT NULL DEFAULT '',
  "committee" varchar(255) NOT NULL DEFAULT '',
  "owner_session" varchar(128),
  "live_state" text,
  "updated_at" timestamp,
  "status" varchar(50) DEFAULT 'pending'
);

CREATE TABLE IF NOT EXISTS "match_players" (
  "player_id" serial,
  "match_id" integer NOT NULL,
  "team" char(1) NOT NULL DEFAULT '',
  "jersey_no" varchar(10) NOT NULL DEFAULT '',
  "player_name" varchar(100) NOT NULL DEFAULT '',
  "pts" integer NOT NULL DEFAULT 0,
  "foul" integer NOT NULL DEFAULT 0,
  "reb" integer NOT NULL DEFAULT 0,
  "ast" integer NOT NULL DEFAULT 0,
  "blk" integer NOT NULL DEFAULT 0,
  "stl" integer NOT NULL DEFAULT 0,
  "tech_foul" integer NOT NULL DEFAULT 0,
  "tech_reason" text
);

CREATE TABLE IF NOT EXISTS "match_state" (
  "match_id" integer NOT NULL,
  "payload" text NOT NULL,
  "updated_at" timestamp NOT NULL,
  "last_role" varchar(32),
  "last_user_id" integer
);

CREATE TABLE IF NOT EXISTS "match_states" (
  "id" bigserial,
  "match_id" integer NOT NULL,
  "payload" text NOT NULL,
  "last_user_id" integer,
  "last_role" varchar(50),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "match_timers" (
  "match_id" integer NOT NULL,
  "game_start_ms" bigint,
  "game_duration" integer NOT NULL DEFAULT 600,
  "game_remaining" integer NOT NULL DEFAULT 600,
  "shot_start_ms" bigint,
  "shot_duration" integer NOT NULL DEFAULT 24,
  "shot_remaining" integer NOT NULL DEFAULT 24,
  "updated_at" timestamp NOT NULL,
  "game_total" integer DEFAULT 0,
  "game_running" smallint DEFAULT 0,
  "game_ts" bigint,
  "shot_total" integer DEFAULT 0,
  "shot_running" smallint DEFAULT 0,
  "shot_ts" bigint
);

CREATE TABLE IF NOT EXISTS "migrations" (
  "id" serial,
  "migration" varchar(255) NOT NULL,
  "batch" integer NOT NULL
);

CREATE TABLE IF NOT EXISTS "password_reset_tokens" (
  "email" varchar(255) NOT NULL,
  "token" varchar(255) NOT NULL,
  "created_at" timestamp NULL
);

CREATE TABLE IF NOT EXISTS "player_profiles" (
  "id" serial,
  "universal_id" integer NOT NULL,
  "full_name" varchar(120) NOT NULL,
  "display_name" varchar(120) NOT NULL DEFAULT '',
  "team_override" varchar(120) NOT NULL DEFAULT '',
  "photo_path" varchar(255) NOT NULL DEFAULT '',
  "notes" text,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "player_team_history" (
  "id" serial,
  "player_universal_id" integer NOT NULL DEFAULT 0,
  "player_name" varchar(120) NOT NULL,
  "sport" varchar(30) NOT NULL,
  "side" char(1) NOT NULL DEFAULT '',
  "actual_team_name" varchar(120) NOT NULL DEFAULT '',
  "games_played" integer NOT NULL DEFAULT 0,
  "first_game" timestamp,
  "last_game" timestamp,
  "is_current" smallint NOT NULL DEFAULT 0,
  "source" text NOT NULL DEFAULT 'auto',
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "sessions" (
  "id" varchar(255) NOT NULL,
  "user_id" bigint,
  "ip_address" varchar(45),
  "user_agent" text,
  "payload" text NOT NULL,
  "last_activity" integer NOT NULL
);

CREATE TABLE IF NOT EXISTS "sports" (
  "id" serial,
  "name" varchar(100),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "status" varchar(100)
);

CREATE TABLE IF NOT EXISTS "system_settings" (
  "key" varchar(100) NOT NULL,
  "value" text NOT NULL DEFAULT '',
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "table_tennis_matches" (
  "id" serial,
  "match_type" text NOT NULL DEFAULT 'Singles',
  "best_of" smallint NOT NULL DEFAULT 3,
  "team_a_name" varchar(100) NOT NULL DEFAULT 'Team A',
  "team_b_name" varchar(100) NOT NULL DEFAULT 'Team B',
  "team_a_player1" varchar(100),
  "team_a_player2" varchar(100),
  "team_b_player1" varchar(100),
  "team_b_player2" varchar(100),
  "status" text NOT NULL DEFAULT 'ongoing',
  "winner_name" varchar(100),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "committee" varchar(255)
);

CREATE TABLE IF NOT EXISTS "table_tennis_match_state" (
  "match_id" varchar(64) NOT NULL,
  "state_json" text NOT NULL,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "table_tennis_match_summary" (
  "id" serial,
  "match_id" integer NOT NULL,
  "total_sets_played" smallint NOT NULL DEFAULT 0,
  "team_a_sets_won" integer NOT NULL DEFAULT 0,
  "team_b_sets_won" integer NOT NULL DEFAULT 0,
  "winner_team" text,
  "winner_name" varchar(100),
  "declared_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "table_tennis_sets" (
  "id" serial,
  "match_id" integer NOT NULL,
  "set_number" smallint NOT NULL,
  "team_a_score" integer NOT NULL DEFAULT 0,
  "team_b_score" integer NOT NULL DEFAULT 0,
  "team_a_timeout_used" smallint NOT NULL DEFAULT 0,
  "team_b_timeout_used" smallint NOT NULL DEFAULT 0,
  "serving_team" text NOT NULL DEFAULT 'A',
  "set_winner" text,
  "saved_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "committee" varchar(255)
);

CREATE TABLE IF NOT EXISTS "universal_players" (
  "id" serial,
  "full_name" varchar(120) NOT NULL,
  "team_name" varchar(120) NOT NULL DEFAULT '',
  "display_name" varchar(120) GENERATED ALWAYS AS (("full_name" || (CASE WHEN "team_name" <> '' THEN (' (' || "team_name" || ')') ELSE '' END))) STORED,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "users" (
  "id" serial,
  "name" varchar(255),
  "username" varchar(40) NOT NULL,
  "email" varchar(120) NOT NULL,
  "password" varchar(255),
  "password_hash" varchar(255) NOT NULL,
  "role" text NOT NULL DEFAULT 'scorekeeper',
  "email_verified_at" timestamp NULL,
  "display_name" varchar(80) NOT NULL DEFAULT '',
  "is_active" smallint NOT NULL DEFAULT 1,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "remember_token" varchar(100),
  "status" varchar(50) DEFAULT 'active'
);

CREATE TABLE IF NOT EXISTS "user_sessions" (
  "id" serial,
  "user_id" integer NOT NULL,
  "token" char(64) NOT NULL,
  "ip_address" varchar(45) NOT NULL DEFAULT '',
  "user_agent" varchar(255) NOT NULL DEFAULT '',
  "expires_at" timestamp NOT NULL,
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "volleyball_matches" (
  "match_id" serial,
  "owner_user_id" integer,
  "team_a_name" varchar(80) NOT NULL DEFAULT 'TEAM A',
  "team_b_name" varchar(80) NOT NULL DEFAULT 'TEAM B',
  "team_a_score" smallint NOT NULL DEFAULT 0,
  "team_b_score" smallint NOT NULL DEFAULT 0,
  "team_a_timeout" smallint NOT NULL DEFAULT 0,
  "team_b_timeout" smallint NOT NULL DEFAULT 0,
  "current_set" smallint NOT NULL DEFAULT 1,
  "match_result" varchar(30) NOT NULL DEFAULT 'ONGOING',
  "committee" varchar(120),
  "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "volleyball_players" (
  "id" serial,
  "match_id" integer NOT NULL,
  "team" char(1) NOT NULL,
  "jersey_no" varchar(5),
  "player_name" varchar(80) NOT NULL DEFAULT '',
  "pts" smallint NOT NULL DEFAULT 0,
  "spike" smallint NOT NULL DEFAULT 0,
  "ace" smallint NOT NULL DEFAULT 0,
  "ex_set" smallint NOT NULL DEFAULT 0,
  "ex_dig" smallint NOT NULL DEFAULT 0,
  "blk" integer DEFAULT 0
);

ALTER TABLE "activity_log" ADD PRIMARY KEY ("id");
ALTER TABLE "auth_log" ADD PRIMARY KEY ("id");
CREATE INDEX "idx_user_event" ON "auth_log" ("user_id", "event");
ALTER TABLE "badminton_matches" ADD PRIMARY KEY ("id");
ALTER TABLE "badminton_match_state" ADD PRIMARY KEY ("match_id");
ALTER TABLE "badminton_match_summary" ADD PRIMARY KEY ("id");
ALTER TABLE "badminton_match_summary" ADD UNIQUE ("match_id");
ALTER TABLE "badminton_sets" ADD PRIMARY KEY ("id");
ALTER TABLE "badminton_sets" ADD UNIQUE ("match_id", "set_number");
ALTER TABLE "cache" ADD PRIMARY KEY ("key");
CREATE INDEX "cache_expiration_index" ON "cache" ("expiration");
ALTER TABLE "cache_locks" ADD PRIMARY KEY ("key");
CREATE INDEX "cache_locks_expiration_index" ON "cache_locks" ("expiration");
ALTER TABLE "darts_legs" ADD PRIMARY KEY ("id");
CREATE INDEX "match_id" ON "darts_legs" ("match_id");
CREATE INDEX "winner_player_id" ON "darts_legs" ("winner_player_id");
ALTER TABLE "darts_matches" ADD PRIMARY KEY ("id");
CREATE INDEX "idx_status_updated" ON "darts_matches" ("status", "updated_at");
ALTER TABLE "darts_match_summary" ADD PRIMARY KEY ("id");
ALTER TABLE "darts_match_summary" ADD UNIQUE ("match_id");
CREATE INDEX "winner_player_id" ON "darts_match_summary" ("winner_player_id");
ALTER TABLE "darts_players" ADD PRIMARY KEY ("id");
CREATE INDEX "match_id" ON "darts_players" ("match_id");
ALTER TABLE "darts_throws" ADD PRIMARY KEY ("id");
CREATE INDEX "leg_id" ON "darts_throws" ("leg_id");
CREATE INDEX "player_id" ON "darts_throws" ("player_id");
ALTER TABLE "draft_match_states" ADD PRIMARY KEY ("match_id");
ALTER TABLE "failed_jobs" ADD PRIMARY KEY ("id");
ALTER TABLE "failed_jobs" ADD UNIQUE ("uuid");
ALTER TABLE "jobs" ADD PRIMARY KEY ("id");
CREATE INDEX "jobs_queue_index" ON "jobs" ("queue");
ALTER TABLE "job_batches" ADD PRIMARY KEY ("id");
ALTER TABLE "matches" ADD PRIMARY KEY ("match_id");
ALTER TABLE "match_players" ADD PRIMARY KEY ("player_id");
CREATE INDEX "fk_match_players_match" ON "match_players" ("match_id");
ALTER TABLE "match_state" ADD PRIMARY KEY ("match_id");
CREATE INDEX "idx_match_state_updated" ON "match_state" ("updated_at");
ALTER TABLE "match_states" ADD PRIMARY KEY ("id");
ALTER TABLE "match_states" ADD UNIQUE ("match_id");
ALTER TABLE "match_timers" ADD PRIMARY KEY ("match_id");
CREATE INDEX "idx_match_timers_updated" ON "match_timers" ("updated_at");
ALTER TABLE "migrations" ADD PRIMARY KEY ("id");
ALTER TABLE "password_reset_tokens" ADD PRIMARY KEY ("email");
ALTER TABLE "player_profiles" ADD PRIMARY KEY ("id");
ALTER TABLE "player_profiles" ADD UNIQUE ("universal_id");
ALTER TABLE "player_team_history" ADD PRIMARY KEY ("id");
ALTER TABLE "player_team_history" ADD UNIQUE ("player_universal_id", "sport", "actual_team_name");
CREATE INDEX "idx_is_current" ON "player_team_history" ("player_name", "is_current");
CREATE INDEX "idx_player_sport" ON "player_team_history" ("player_universal_id", "sport");
ALTER TABLE "sessions" ADD PRIMARY KEY ("id");
CREATE INDEX "sessions_user_id_index" ON "sessions" ("user_id");
CREATE INDEX "sessions_last_activity_index" ON "sessions" ("last_activity");
ALTER TABLE "sports" ADD PRIMARY KEY ("id");
ALTER TABLE "system_settings" ADD PRIMARY KEY ("key");
ALTER TABLE "table_tennis_matches" ADD PRIMARY KEY ("id");
ALTER TABLE "table_tennis_match_state" ADD PRIMARY KEY ("match_id");
ALTER TABLE "table_tennis_match_summary" ADD PRIMARY KEY ("id");
ALTER TABLE "table_tennis_match_summary" ADD UNIQUE ("match_id");
ALTER TABLE "table_tennis_sets" ADD PRIMARY KEY ("id");
CREATE INDEX "match_id" ON "table_tennis_sets" ("match_id");
ALTER TABLE "universal_players" ADD PRIMARY KEY ("id");
ALTER TABLE "universal_players" ADD UNIQUE ("full_name", "team_name");
ALTER TABLE "users" ADD PRIMARY KEY ("id");
ALTER TABLE "users" ADD UNIQUE ("username");
ALTER TABLE "users" ADD UNIQUE ("email");
ALTER TABLE "users" ADD UNIQUE ("email");
ALTER TABLE "user_sessions" ADD PRIMARY KEY ("id");
ALTER TABLE "user_sessions" ADD UNIQUE ("token");
CREATE INDEX "idx_user" ON "user_sessions" ("user_id");
ALTER TABLE "volleyball_matches" ADD PRIMARY KEY ("match_id");
ALTER TABLE "volleyball_players" ADD PRIMARY KEY ("id");
CREATE INDEX "idx_match" ON "volleyball_players" ("match_id");
ALTER TABLE "badminton_match_summary" ADD CONSTRAINT "badminton_match_summary_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "badminton_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "badminton_sets" ADD CONSTRAINT "badminton_sets_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "badminton_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "darts_legs" ADD CONSTRAINT "darts_legs_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "darts_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "darts_legs" ADD CONSTRAINT "darts_legs_ibfk_2" FOREIGN KEY ("winner_player_id") REFERENCES "darts_players" ("id") ON DELETE SET NULL;
ALTER TABLE "darts_match_summary" ADD CONSTRAINT "darts_match_summary_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "darts_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "darts_match_summary" ADD CONSTRAINT "darts_match_summary_ibfk_2" FOREIGN KEY ("winner_player_id") REFERENCES "darts_players" ("id") ON DELETE SET NULL;
ALTER TABLE "darts_players" ADD CONSTRAINT "darts_players_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "darts_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "darts_throws" ADD CONSTRAINT "darts_throws_ibfk_1" FOREIGN KEY ("leg_id") REFERENCES "darts_legs" ("id") ON DELETE CASCADE;
ALTER TABLE "darts_throws" ADD CONSTRAINT "darts_throws_ibfk_2" FOREIGN KEY ("player_id") REFERENCES "darts_players" ("id") ON DELETE CASCADE;
ALTER TABLE "match_players" ADD CONSTRAINT "fk_match_players_match" FOREIGN KEY ("match_id") REFERENCES "matches" ("match_id") ON DELETE CASCADE;
ALTER TABLE "table_tennis_match_summary" ADD CONSTRAINT "table_tennis_match_summary_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "table_tennis_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "table_tennis_sets" ADD CONSTRAINT "table_tennis_sets_ibfk_1" FOREIGN KEY ("match_id") REFERENCES "table_tennis_matches" ("id") ON DELETE CASCADE;
ALTER TABLE "user_sessions" ADD CONSTRAINT "fk_sess_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE;
