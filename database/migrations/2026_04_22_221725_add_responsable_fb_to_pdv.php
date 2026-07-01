<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->foreignId('responsable_fb_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->dropForeign(['responsable_fb_id']);
            $table->dropColumn('responsable_fb_id');
        });
    }
};
