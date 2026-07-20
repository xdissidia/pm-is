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
            $table->boolean('hide_by_default')->default(false)->after('order');
        });

        // Projects carrying a "Completed" tag should be hidden unless that
        // tag is explicitly selected in the filter.
        DB::table('project_tags')->where('name', 'Completed')->update(['hide_by_default' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_tags', function (Blueprint $table) {
            $table->dropColumn('hide_by_default');
        });
    }
};
