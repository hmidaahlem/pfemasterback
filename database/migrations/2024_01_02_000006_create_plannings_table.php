<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plannings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caissier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pdv_id')->constrained('points_de_vente')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_day_off')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['caissier_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plannings');
    }
};
