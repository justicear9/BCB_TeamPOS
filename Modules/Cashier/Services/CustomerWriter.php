<?php

namespace Modules\Cashier\Services;

use App\Contact;
use App\User;
use App\Utils\ContactUtil;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Entities\CashierClientRef;
use Modules\Cashier\Support\CashierContext;

class CustomerWriter
{
    public function __construct(private ContactUtil $contactUtil)
    {
    }

    public function create(User $user, array $payload): array
    {
        CashierContext::bind($user);
        if (! $user->can('customer.create')) {
            abort(403, 'You are not allowed to add customers');
        }

        if ($replay = $this->replay($user, $payload['client_uuid'])) {
            return $replay;
        }

        $this->guardMobile($user, $payload['mobile'], null);

        try {
            return DB::transaction(function () use ($user, $payload) {
                $result = $this->contactUtil->createNewContact([
                    'business_id' => $user->business_id,
                    'type' => 'customer',
                    'name' => trim($payload['name']),
                    'first_name' => trim($payload['name']),
                    'supplier_business_name' => $this->clean($payload['business_name'] ?? null),
                    'mobile' => trim($payload['mobile']),
                    'email' => $this->clean($payload['email'] ?? null),
                    'address_line_1' => $this->clean($payload['address'] ?? null),
                    'created_by' => $user->id,
                    'contact_status' => 'active',
                    'credit_limit' => 0,
                ]);
                $contact = $result['data'];

                $ref = CashierClientRef::create([
                    'business_id' => $user->business_id,
                    'client_uuid' => $payload['client_uuid'],
                    'entity_type' => 'customer',
                    'entity_id' => $contact->id,
                    'device_ref' => '',
                ]);

                return $this->present($ref, $contact->fresh(), false);
            });
        } catch (QueryException $e) {
            if (CashierClientRef::isDuplicate($e) && ($replay = $this->replay($user, $payload['client_uuid']))) {
                return $replay;
            }
            throw $e;
        }
    }

    public function update(User $user, int $contactId, array $payload): array
    {
        CashierContext::bind($user);
        if (! $user->can('customer.update')) {
            abort(403, 'You are not allowed to edit customers');
        }

        $contact = Contact::where('business_id', $user->business_id)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail($contactId);
        if ((int) $contact->is_default === 1) {
            abort(422, 'The walk-in customer cannot be edited here');
        }

        $this->guardMobile($user, $payload['mobile'], $contact->id);

        $contact->name = trim($payload['name']);
        $contact->supplier_business_name = $this->clean($payload['business_name'] ?? null);
        $contact->mobile = trim($payload['mobile']);
        $contact->email = $this->clean($payload['email'] ?? null);
        $contact->address_line_1 = $this->clean($payload['address'] ?? null);
        $contact->save();

        return ['customer' => $this->row($contact->fresh())];
    }

    private function guardMobile(User $user, string $mobile, ?int $exceptId): void
    {
        $taken = Contact::where('business_id', $user->business_id)
            ->whereIn('type', ['customer', 'both'])
            ->where('mobile', trim($mobile))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->value('name');
        if ($taken) {
            abort(422, "{$taken} already uses that phone number");
        }
    }

    private function replay(User $user, string $clientUuid): ?array
    {
        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $clientUuid)
            ->where('entity_type', 'customer')
            ->first();
        if (! $existing) {
            return null;
        }

        return $this->present($existing, Contact::where('business_id', $user->business_id)->findOrFail($existing->entity_id), true);
    }

    private function present(CashierClientRef $ref, Contact $contact, bool $replayed): array
    {
        return [
            'client_uuid' => $ref->client_uuid,
            'replayed' => $replayed,
            'customer' => $this->row($contact),
        ];
    }

    private function row(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'business_name' => $contact->supplier_business_name,
            'mobile' => $contact->mobile,
            'email' => $contact->email,
            'address' => $contact->address_line_1,
            'advance' => (string) ($contact->balance ?? 0),
            'is_default' => (int) $contact->is_default,
            'credit_limit' => $contact->credit_limit === null ? null : (string) $contact->credit_limit,
            'amount_due' => '0',
            'amount_due_here' => '0',
            'pay_term_number' => $contact->pay_term_number,
            'pay_term_type' => $contact->pay_term_type,
            'reward_points' => (int) ($contact->total_rp ?? 0),
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
