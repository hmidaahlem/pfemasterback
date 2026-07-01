<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            if (! Schema::hasColumn('plannings', 'shift')) {
                $table->enum('shift', ['MATIN', 'APRES_MIDI', 'SOIR'])->default('MATIN')->after('date');
            }
            if (! Schema::hasColumn('plannings', 'day_status')) {
                $table->enum('day_status', ['ON', 'OFF', 'CONGE'])->default('ON')->after('shift');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            $table->dropColumn(['shift', 'day_status']);
        });
    }
};
