<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            // 1. Create a standard index on caissier_id first to support the foreign key
            $table->index('caissier_id');
        });

        Schema::table('plannings', function (Blueprint $table) {
            // 2. Drop the unique index safely now that the foreign key is supported by the index above
            $table->dropUnique(['caissier_id', 'date']);

            // 3. Create a combined index for date-based searches
            $table->index(['caissier_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            $table->dropIndex(['caissier_id', 'date']);
            $table->unique(['caissier_id', 'date']);
            $table->dropIndex(['caissier_id']);
        });
    }
};
