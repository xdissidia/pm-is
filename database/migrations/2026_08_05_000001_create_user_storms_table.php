<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The STORM account a PMIS user is known by — one row per user. STORM's
     * lookup can answer with several accounts on the same address; the one
     * recorded here is the one PMIS files tickets against.
     */
    public function up(): void
    {
        Schema::create('user_storms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique();
            $table->unsignedBigInteger('storm_user_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            // What STORM has on file as the PMIS user — null until STORM links
            // it, and worth keeping to spot the two sides disagreeing.
            $table->unsignedBigInteger('pmis_user_id')->nullable();
            $table->timestamps();

            $table->index('storm_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_storms');
    }
};
