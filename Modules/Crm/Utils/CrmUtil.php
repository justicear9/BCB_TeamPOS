<?php

namespace Modules\Crm\Utils;

use App\Contact;
use App\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRM helpers for Connector API. The full Ultimate POS "Crm" module (entities,
 * migrations, Schedule, etc.) is not in this tree; this class exists so
 * {@see \Modules\Connector\Http\Controllers\Api\Crm\FollowUpController} can be
 * constructed. Endpoints that need real CRM data still require the full module
 * and {@see \App\Utils\ModuleUtil::isModuleInstalled()}('Crm').
 */
class CrmUtil
{
    public function getFollowUpForGivenDate(User $user, $start_date, $end_date): Builder
    {
        return Contact::query()->whereRaw('0 = 1');
    }

    public function addFollowUp(array $params, User $user)
    {
        abort(501, 'CRM module is not fully installed.');
    }

    public function updateFollowUp($follow_up_id, array $params, User $user)
    {
        abort(501, 'CRM module is not fully installed.');
    }

    public function getLeadsListQuery(int $business_id): Builder
    {
        return Contact::query()
            ->where('contacts.business_id', $business_id)
            ->where('contacts.type', 'lead');
    }
}
