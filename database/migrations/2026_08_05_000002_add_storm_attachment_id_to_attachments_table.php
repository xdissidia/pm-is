<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STORM's id for the copy of this file on the ticket, learned from the
     * `attachments` it answers an upload with. It is what PMIS sends back as
     * `remove_attachments` when the file is deleted here.
     */
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('storm_attachment_id')->nullable()->after('task_id');

            $table->index('storm_attachment_id');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['storm_attachment_id']);
            $table->dropColumn('storm_attachment_id');
        });
    }
};
