<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMatchStatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('match_states')) {
            Schema::create('match_states', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('match_id')->unique();
                // Use JSON when available; if DB doesn't support JSON it will still accept text in many setups.
                $table->json('payload');
                $table->unsignedInteger('last_user_id')->nullable();
                $table->string('last_role', 50)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('match_states');
    }
}
