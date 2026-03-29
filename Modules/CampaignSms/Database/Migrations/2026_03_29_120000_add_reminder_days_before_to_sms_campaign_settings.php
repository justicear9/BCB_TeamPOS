<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sms_campaign_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_days_before')->default(3)->after('default_refill_template');
        });
    }

    public function down()
    {
        Schema::table('sms_campaign_settings', function (Blueprint $table) {
            $table->dropColumn('reminder_days_before');
        });
    }
};
