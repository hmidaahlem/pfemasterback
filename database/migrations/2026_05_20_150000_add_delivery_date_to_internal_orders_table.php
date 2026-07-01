<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_orders', function (Blueprint $table) {
            $table->date('delivery_date')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('internal_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_date');
        });
    }
};
