@extends('layouts.app')

@section('title', __('accounting::lang.journal_entry'))

@section('content')

@include('accounting::layouts.nav')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang( 'accounting::lang.journal_entry' ) - {{$journal->ref_no}}</h1>
</section>
<section class="content">

{!! Form::open(['url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'update'], $journal->id),
    'method' => 'PUT', 'id' => 'journal_add_form']) !!}

	@component('components.widget', ['class' => 'box-primary'])

        <div class="alert alert-info tw-mb-4">
            <p class="tw-mb-0">@lang('accounting::lang.journal_entry_qb_intro')</p>
        </div>

        <div class="row">

            <div class="col-sm-3">
				<div class="form-group">
					{!! Form::label('journal_date', __('accounting::lang.journal_date') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-calendar"></i>
						</span>
						{!! Form::text('journal_date', @format_datetime($journal->operation_date), ['class' => 'form-control datetimepicker', 'readonly', 'required']); !!}
					</div>
				</div>
			</div>

        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="form-group">
                    {!! Form::label('note', __('lang_v1.additional_notes')) !!}
                    {!! Form::textarea('note', $journal->note, ['class' => 'form-control', 'rows' => 3]); !!}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-12 table-responsive">

            <table class="table table-bordered table-striped hide-footer" id="journal_table">
                <thead>
                    <tr>
                        <th class="tw-whitespace-nowrap">#</th>
                        <th>@lang('accounting::lang.account')</th>
                        <th>@lang('accounting::lang.debit')</th>
                        <th>@lang('accounting::lang.credit')</th>
                        <th>@lang('accounting::lang.journal_line_memo')</th>
                        <th>@lang('accounting::lang.journal_line_name')</th>
                        <th>@lang('accounting::lang.journal_line_location')</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    @for($i = 1; $i <= $size; $i++)
                        @php
                            $line = $lines->get($i - 1);
                        @endphp
                        @include('accounting::journal_entry.partials.line_row', ['i' => $i, 'line' => $line, 'business_locations' => $business_locations])
                    @endfor
                </tbody>

                <tfoot>
                    <tr>
                        <td colspan="7" class="text-right">
                            <button type="button" id="addRow" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm">@lang('accounting::lang.add_more_row')</button>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2"></td>
                        <th class="text-right">@lang('accounting::lang.total')</th>
                        <th><input type="hidden" class="total_debit_hidden"><span class="total_debit"></span></th>
                        <th><input type="hidden" class="total_credit_hidden"><span class="total_credit"></span></th>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            </div>
        </div>

        <div class="row">
            <div class="col-sm-12">
                <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white pull-right journal_add_btn">@lang('messages.save')</button>
            </div>
        </div>

    @endcomponent

    {!! Form::close() !!}
</section>

@stop

@section('javascript')
@include('accounting::accounting.common_js')
<script type="text/javascript">
    $(document).ready(function(){
        initJournalLineContacts($('#tableBody .journal-line-contact'));
        initJournalLineLocations($('#tableBody .journal-line-location'));

        calculate_total();

        $('.journal_add_btn').click(function(){
            calculate_total();

            var is_valid = true;

            if($('.total_credit_hidden').val() != $('.total_debit_hidden').val()){
                is_valid = false;
                alert("@lang('accounting::lang.credit_debit_equal')");
            }

            var hasLocChoices = false;
            for (var _lid in journalLineLocationOptions) {
                if (Object.prototype.hasOwnProperty.call(journalLineLocationOptions, _lid)) {
                    hasLocChoices = true;
                    break;
                }
            }

            $('table > tbody  > tr').each(function(index, tr) {
                var credit = __read_number($(tr).find('.credit'));
                var debit = __read_number($(tr).find('.debit'));

                if(credit != 0 || debit != 0){
                    if($(tr).find('.account_id').val() == ''){
                        is_valid = false;
                        alert("@lang('accounting::lang.select_all_accounts')");
                    }
                    if (hasLocChoices) {
                        var locVal = $(tr).find('.journal-line-location').val();
                        if(!locVal || locVal === ''){
                            is_valid = false;
                            alert("@lang('accounting::lang.journal_line_location_required')");
                        }
                    }
                }
            });

            if(is_valid){
                $('form#journal_add_form').submit();
            }

            return is_valid;
        });

        $(document).on('change', '.credit', function(){
            if(__read_number($(this)) > 0){
                $(this).parents('tr').find('.debit').val('');
            }
            calculate_total();
        });
        $(document).on('change', '.debit', function(){
            if (__read_number($(this)) > 0) {
                $(this).parents('tr').find('.credit').val('');
            }
            calculate_total();
        });

        var rowCount = {{ (int) $size }};
        var journalLineLocationOptions = @json($business_locations);

        $('#addRow').click(function() {
            rowCount++;
            var placeholders = {
                memo: @json(__('accounting::lang.journal_line_memo_placeholder')),
                name: @json(__('accounting::lang.journal_line_name_placeholder')),
            };
            var locationPlaceholder = @json(__('accounting::lang.journal_line_location_placeholder'));
            var pleaseSelect = @json(__('messages.please_select'));

            var locSelect = '<select name="journal_line_location_id[' + rowCount + ']" class="form-control select2 journal-line-location" style="width:100%;min-width:160px">';
            locSelect += '<option value="">' + $('<div/>').text(locationPlaceholder).html() + '</option>';
            for (var lid in journalLineLocationOptions) {
                if (Object.prototype.hasOwnProperty.call(journalLineLocationOptions, lid)) {
                    locSelect += '<option value="' + lid + '">' + $('<div/>').text(journalLineLocationOptions[lid]).html() + '</option>';
                }
            }
            locSelect += '</select>';

            var newRow = '<tr class="journal-line-row">' +
                '<td class="text-muted">' + rowCount + '</td>' +
                '<td><select name="account_id[' + rowCount + ']" class="form-control accounts-dropdown account_id" style="width:100%;min-width:220px"></select></td>' +
                '<td><input type="text" name="debit[' + rowCount + ']" class="form-control input_number debit"></td>' +
                '<td><input type="text" name="credit[' + rowCount + ']" class="form-control input_number credit"></td>' +
                '<td><input type="text" name="journal_line_note[' + rowCount + ']" class="form-control input-sm" placeholder="' + $('<div/>').text(placeholders.memo).html() + '"></td>' +
                '<td><select name="journal_line_contact_id[' + rowCount + ']" class="form-control journal-line-contact" style="width:100%;min-width:180px"></select></td>' +
                '<td>' + locSelect + '</td>' +
                '</tr>';

            $('#tableBody').append(newRow);

            var $last = $('#tableBody tr:last-child');
            $last.find('select.accounts-dropdown').select2({
                ajax: {
                    url: '{{route("accounts-dropdown")}}',
                    dataType: 'json',
                    processResults: function (data) {
                        return { results: data };
                    },
                },
                placeholder: pleaseSelect,
                allowClear: true,
                escapeMarkup: function(markup) { return markup; },
                templateResult: function(data) { return data.html; },
                templateSelection: function(data) { return data.text; }
            });
            initJournalLineContacts($last.find('.journal-line-contact'));
            initJournalLineLocations($last.find('.journal-line-location'));
        });
    });

    function initJournalLineContacts($els) {
        $els.each(function() {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) {
                return;
            }
            $el.select2({
                ajax: {
                    url: '/contacts/customers',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return { q: params.term, page: params.page, all_contact: true };
                    },
                    processResults: function(data) {
                        return { results: data };
                    },
                },
                placeholder: @json(__('accounting::lang.journal_line_name_placeholder')),
                allowClear: true,
                minimumInputLength: 1,
                escapeMarkup: function(markup) { return markup; },
                templateResult: function (data) {
                    var template = '';
                    if (data.supplier_business_name) {
                        template += data.supplier_business_name + '<br>';
                    }
                    template += data.text + '<br>' + LANG.mobile + ': ' + (data.mobile || '');
                    return template;
                },
            });
        });
    }

    function initJournalLineLocations($els) {
        $els.each(function() {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) {
                return;
            }
            $el.select2({
                allowClear: true,
                placeholder: @json(__('accounting::lang.journal_line_location_placeholder')),
            });
        });
    }

    function calculate_total(){
        var total_credit = 0;
        var total_debit = 0;
        $('table > tbody  > tr').each(function(index, tr) {
            var credit = __read_number($(tr).find('.credit'));
            total_credit += credit;

            var debit = __read_number($(tr).find('.debit'));
            total_debit += debit;
        });

        $('.total_credit_hidden').val(total_credit);
        $('.total_debit_hidden').val(total_debit);

        $('.total_credit').text(__currency_trans_from_en(total_credit));
        $('.total_debit').text(__currency_trans_from_en(total_debit));
    }

</script>
@endsection
