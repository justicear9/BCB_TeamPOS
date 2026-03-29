@php

    $fmt = $date_format ?? 'Y-m-d';

@endphp

<div class="campaignsms-invoice-refills" style="margin-top:8px;border-top:1px dashed #999;padding-top:6px;font-size:11px;text-align:left;">

    <strong>@lang('campaignsms::lang.invoice_refill_section')</strong>

    <table style="width:100%;margin-top:4px;">

        @foreach ($lines as $line)

            @php

                $dueDate = '—';

                if (! empty($line['refill_due_at'])) {

                    try {

                        $dueDate = \Carbon\Carbon::parse($line['refill_due_at'])->format($fmt);

                    } catch (\Throwable $e) {

                    }

                }

            @endphp

            <tr>

                <td colspan="2" style="padding:2px 0;"><strong>{{ $line['product_name'] ?? '' }}</strong></td>

            </tr>

            <tr>

                <td style="padding:1px 0;">@lang('campaignsms::lang.refill_date')</td>

                <td style="padding:1px 0;text-align:right;">{{ $dueDate }}</td>

            </tr>

        @endforeach

    </table>

</div>

