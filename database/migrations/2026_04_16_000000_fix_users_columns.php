<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixUsersColumns extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->nullable();
                $table->string('email')->nullable()->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->string('role')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
            return;
        }

        if (!Schema::hasColumn('users', 'name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name')->nullable()->after('id');
            });
        }

        if (!Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->after('name');
            });
        }

        if (!Schema::hasColumn('users', 'password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('password')->nullable()->after('email');
            });
        }

        if (!Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->nullable()->after('password');
            });
        }

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable()->after('role');
            });
        }

        if (!Schema::hasColumn('users', 'remember_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->rememberToken();
            });
        }

        if (!Schema::hasColumn('users', 'created_at') && !Schema::hasColumn('users', 'updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamps();
            });
        } else {
            if (!Schema::hasColumn('users', 'created_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('created_at')->nullable();
                });
            }
            if (!Schema::hasColumn('users', 'updated_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('updated_at')->nullable();
                });
            }
        }

        // Add unique index on email if not present
        try {
            $db = DB::getDatabaseName();
            $index = DB::selectOne("SELECT COUNT(1) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?", [$db, 'users', 'users_email_unique']);
            if ($index && $index->cnt == 0) {
                Schema::table('users', function (Blueprint $table) {
                    $table->unique('email');
                });
            }
        } catch (\Exception $e) {
            // ignore index errors
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
            if (Schema::hasColumn('users', 'password')) {
                $table->dropColumn('password');
            }
            if (Schema::hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
            if (Schema::hasColumn('users', 'remember_token')) {
                $table->dropColumn('remember_token');
            }
            if (Schema::hasColumn('users', 'created_at') && Schema::hasColumn('users', 'updated_at')) {
                $table->dropColumn(['created_at','updated_at']);
            }
            if (Schema::hasColumn('users', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('users', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
}
