<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->text('body');
            $table->string('audience_type', 32);
            $table->unsignedInteger('customer_group_id')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('total_tokens_charged')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('sms_campaigns');
    }
};
