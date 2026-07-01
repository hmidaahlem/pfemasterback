<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->integer('staff_count')->default(0);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('purchase_need_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_need_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('products')->cascadeOnDelete();
            $table->string('ingredient_name');
            $table->string('unit', 20)->default('piece');
            $table->decimal('current_stock', 12, 2)->default(0);
            $table->decimal('required_quantity', 12, 2)->default(0);
            $table->decimal('shortfall', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_need_items');
        Schema::dropIfExists('purchase_needs');
    }
};
