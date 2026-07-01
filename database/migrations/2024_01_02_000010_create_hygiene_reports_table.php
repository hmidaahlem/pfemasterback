<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hygiene_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inspected_by')->constrained('users');
            $table->boolean('allergens_verified')->default(false);
            $table->boolean('expiration_verified')->default(false);
            $table->enum('status', ['conforme', 'non_conforme', 'en_cours'])->default('en_cours');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_reports');
    }
};
