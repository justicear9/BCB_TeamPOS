<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_business_manager_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('business_id');
            $table->string('preferred_model')->nullable();
            $table->timestamps();

            $table->unique('business_id');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_business_manager_preferences');
    }
};
