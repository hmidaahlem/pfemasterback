<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'plat' type to products table enum.
     * 'plat' is a dish created by CHEF_CUISINE that is used in menus,
     * composed of raw materials (matiere_premiere) but without its own stock.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('commercial', 'matiere_premiere', 'food', 'plat') NOT NULL");
        }
    }

    public function down(): void
    {
        // Remove any plat products before reverting the enum
        DB::table('products')->where('type', 'plat')->delete();
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('commercial', 'matiere_premiere', 'food') NOT NULL");
        }
    }
};
