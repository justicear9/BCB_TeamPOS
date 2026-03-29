<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sms_campaign_recipients', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sms_campaign_id');
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('mobile_snapshot', 64);
            $table->unsignedSmallInteger('segments')->default(1);
            $table->unsignedInteger('token_cost')->default(0);
            $table->string('send_status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('sms_campaign_id', 'scr_campaign_fk')->references('id')->on('sms_campaigns')->onDelete('cascade');
            $table->foreign('contact_id', 'scr_contact_fk')->references('id')->on('contacts')->onDelete('set null');
            $table->index('sms_campaign_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sms_campaign_recipients');
    }
};
