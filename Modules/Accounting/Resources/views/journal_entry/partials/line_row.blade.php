@php
    /** @var \Modules\Accounting\Entities\AccountingAccountsTransaction|null $line */
    $line = $line ?? null;
    if ($line) {
        $account_id = $line->accounting_account_id;
        $fmtAmt = function ($amount) {
            $precision = session('business.currency_precision', 2);
            $dec = session('currency')['decimal_separator'] ?? '.';
            $thou = session('currency')['thousand_separator'] ?? ',';

            return number_format((float) $amount, (int) $precision, $dec, $thou);
        };
        $debit = $line->type === 'debit' ? $fmtAmt($line->amount) : '';
        $credit = $line->type === 'credit' ? $fmtAmt($line->amount) : '';
        $line_note = $line->note ?? '';
        $contact_id = $line->contact_id ?? '';
        $line_location_id = $line->location_id ?? '';
        $aat_id = $line->id;
        $default_account = ($line->account) ? [$line->accounting_account_id => $line->account->name] : [];
        $contact_display = '';
        if ($line->contact_id && $line->relationLoaded('contact') && $line->contact) {
            $contact_display = $line->contact->name;
            if (! empty($line->contact->contact_id)) {
                $contact_display .= ' ('.$line->contact->contact_id.')';
            }
        }
        $default_contact = ($line->contact_id && $contact_display !== '') ? [$line->contact_id => $contact_display] : [];
    } else {
        $account_id = '';
        $debit = '';
        $credit = '';
        $line_note = '';
        $contact_id = '';
        $line_location_id = '';
        $aat_id = null;
        $default_account = [];
        $default_contact = [];
    }
@endphp
<tr class="journal-line-row">
    <td class="text-muted tw-whitespace-nowrap">{{ $i }}</td>
    <td>
        {!! Form::select('account_id[' . $i . ']', $default_account, $account_id, [
            'class' => 'form-control accounts-dropdown account_id',
            'placeholder' => __('messages.please_select'),
            'style' => 'width: 100%; min-width: 220px;',
        ]) !!}
    </td>
    <td>
        {!! Form::text('debit[' . $i . ']', $debit, ['class' => 'form-control input_number debit']) !!}
    </td>
    <td>
        {!! Form::text('credit[' . $i . ']', $credit, ['class' => 'form-control input_number credit']) !!}
    </td>
    <td>
        {!! Form::text('journal_line_note[' . $i . ']', $line_note, ['class' => 'form-control input-sm', 'placeholder' => __('accounting::lang.journal_line_memo_placeholder')]) !!}
    </td>
    <td>
        {!! Form::select('journal_line_contact_id[' . $i . ']', $default_contact, $contact_id, [
            'class' => 'form-control journal-line-contact',
            'style' => 'width: 100%; min-width: 180px;',
            'placeholder' => __('accounting::lang.journal_line_name_placeholder'),
        ]) !!}
    </td>
    <td>
        {!! Form::select('journal_line_location_id[' . $i . ']', $business_locations, $line_location_id, [
            'class' => 'form-control select2 journal-line-location',
            'placeholder' => __('accounting::lang.journal_line_location_placeholder'),
            'style' => 'width: 100%; min-width: 160px;',
        ]) !!}
        @if(! empty($aat_id))
            {!! Form::hidden('accounts_transactions_id[' . $i . ']', $aat_id) !!}
        @endif
    </td>
</tr>
