<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STORM tickets can be pre-stored before anyone decides which project they
     * belong to, so a task may sit without a project or group until it is moved
     * into one. The number is handed out per project, so it waits for the same
     * moment; the activity log follows the task that has no project yet.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('group_id')->nullable()->change();
            $table->unsignedInteger('number')->nullable()->change();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreignId('group_id')->nullable(false)->change();
            $table->unsignedInteger('number')->nullable(false)->change();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }
};
