<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the column a host needs to opt in to soft deletes. Harmless to hosts that do not.
     */
    public function up(): void
    {
        if (!Schema::hasTable('files')) return;
        if (Schema::hasColumn('files', 'deleted_at')) return;

        Schema::table('files', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('files')) return;
        if (!Schema::hasColumn('files', 'deleted_at')) return;

        Schema::table('files', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
