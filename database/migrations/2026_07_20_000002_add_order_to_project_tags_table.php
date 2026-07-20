<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_tags', function (Blueprint $table) {
            $table->integer('order')->default(0)->after('color');
        });

        // Backfill existing rows so their order matches their id.
        DB::table('project_tags')->update(['order' => DB::raw('id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_tags', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
