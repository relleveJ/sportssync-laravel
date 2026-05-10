<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only alter if table/column exists and enum doesn't already contain superadmin
        try {
            if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
                return;
            }
            $db = DB::getDatabaseName();
            $row = DB::selectOne('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db, 'users', 'role']);
            if (! $row) return;
            $type = $row->COLUMN_TYPE;
            if (stripos($type, "superadmin") !== false) {
                return; // already contains superadmin
            }

            // Append superadmin to enum
            // Build new enum preserving existing values
            preg_match("/enum\((.*)\)/i", $type, $m);
            if (empty($m[1])) return;
            $vals = array_map(function($v){ return trim($v, "'\""); }, explode(',', $m[1]));
            $vals[] = 'superadmin';
            $list = implode("','", $vals);
            $sql = "ALTER TABLE `users` MODIFY `role` ENUM('".$list."') NOT NULL DEFAULT 'scorekeeper'";
            DB::statement($sql);
        } catch (\Throwable $e) {
            // don't break migrations on unexpected errors
        }
    }

    public function down(): void
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
                return;
            }
            $db = DB::getDatabaseName();
            $row = DB::selectOne('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db, 'users', 'role']);
            if (! $row) return;
            $type = $row->COLUMN_TYPE;
            // Remove superadmin value if present
            $new = str_ireplace("'superadmin',", '', $type);
            $new = str_ireplace(", 'superadmin'", '', $new);
            preg_match("/enum\((.*)\)/i", $new, $m);
            if (empty($m[1])) return;
            $vals = array_map(function($v){ return trim($v, "'\""); }, explode(',', $m[1]));
            $list = implode("','", $vals);
            $sql = "ALTER TABLE `users` MODIFY `role` ENUM('".$list."') NOT NULL DEFAULT 'scorekeeper'";
            DB::statement($sql);
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
