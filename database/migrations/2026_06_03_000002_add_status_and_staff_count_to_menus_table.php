<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            if (! Schema::hasColumn('menus', 'status')) {
                $table->enum('status', ['BROUILLON', 'VALIDE', 'REFUSE'])->default('BROUILLON')->after('is_active');
            }
            if (! Schema::hasColumn('menus', 'staff_count')) {
                $table->integer('staff_count')->nullable()->after('status');
            }
            if (! Schema::hasColumn('menus', 'comment')) {
                $table->text('comment')->nullable()->after('staff_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'comment')) {
                $table->dropColumn('comment');
            }
            if (Schema::hasColumn('menus', 'staff_count')) {
                $table->dropColumn('staff_count');
            }
            if (Schema::hasColumn('menus', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
