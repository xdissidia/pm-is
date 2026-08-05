<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The STORM ticket this task mirrors — set when STORM files the task, or
     * when PMIS files the ticket. Its presence is also what stops a task that
     * came from STORM from being filed straight back as a new ticket.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('storm_ticket_id')->nullable()->after('invoice_id');

            $table->index('storm_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['storm_ticket_id']);
            $table->dropColumn('storm_ticket_id');
        });
    }
};
