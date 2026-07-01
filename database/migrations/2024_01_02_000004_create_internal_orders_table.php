<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_orders', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['food', 'commercial']);
            $table->enum('status', ['EN_ATTENTE', 'DISPONIBLE', 'PARTIELLEMENT_DISPONIBLE', 'NON_DISPONIBLE'])->default('EN_ATTENTE');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pdv_id')->nullable()->constrained('points_de_vente')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_orders');
    }
};
