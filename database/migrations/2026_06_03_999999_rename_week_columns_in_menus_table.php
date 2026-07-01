<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'week_start') && ! Schema::hasColumn('menus', 'start_date')) {
                $table->renameColumn('week_start', 'start_date');
            }
            if (Schema::hasColumn('menus', 'week_end') && ! Schema::hasColumn('menus', 'end_date')) {
                $table->renameColumn('week_end', 'end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'start_date') && ! Schema::hasColumn('menus', 'week_start')) {
                $table->renameColumn('start_date', 'week_start');
            }
            if (Schema::hasColumn('menus', 'end_date') && ! Schema::hasColumn('menus', 'week_end')) {
                $table->renameColumn('end_date', 'week_end');
            }
        });
    }
};
