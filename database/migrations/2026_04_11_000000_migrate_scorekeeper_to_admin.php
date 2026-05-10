<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class MigrateScorekeeperToAdmin extends Migration
{
    public function up()
    {
        // Convert legacy 'scorekeeper' role to 'admin' to align with new naming
        DB::table('users')->where('role', 'scorekeeper')->update(['role' => 'admin']);
    }

    public function down()
    {
        // Revert back (use with caution)
        DB::table('users')->where('role', 'admin')->update(['role' => 'scorekeeper']);
    }
}
