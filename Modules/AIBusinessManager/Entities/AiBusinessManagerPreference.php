<?php

namespace Modules\AIBusinessManager\Entities;

use Illuminate\Database\Eloquent\Model;

class AiBusinessManagerPreference extends Model
{
    protected $table = 'ai_business_manager_preferences';

    protected $fillable = [
        'business_id',
        'preferred_model',
        'business_industry',
        'context_notes',
    ];
}
