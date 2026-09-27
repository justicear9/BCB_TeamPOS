@extends('layouts.app')

@section('title', __('accounting::lang.statement_lines'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>{{ $bank->name }} @if($gl) — {{ $gl->name }} @endif</h1>
    @can('accounting.manage_accounts')
        @if($gl)
            <p class="text-muted"><a href="{{ route('accounting.ledger', $gl->id) }}">@lang('accounting::lang.open_ledger')</a></p>
        @endif
    @endcan
</section>

<section class="content">
    @can('accounting.reconcile')
    <div class="alert alert-info">
        {{ __('accounting::lang.bank_reconcile_help') }}
    </div>
    @endcan

    @can('accounting.import_bank_statement')
    <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">@lang('accounting::lang.bank_statement_import_csv')</h3></div>
        <div class="box-body">
            <p class="text-muted">@lang('accounting::lang.bank_statement_import_help')</p>
            <p><a href="{{ route('accounting.bankReconciliation.importTemplate', $bank->id) }}" class="btn btn-default btn-sm">@lang('accounting::lang.bank_statement_download_template')</a></p>
            {!! Form::open(['route' => ['accounting.bankReconciliation.import', $bank->id], 'method' => 'post', 'files' => true]) !!}
            <div class="row">
                <div class="col-md-8">
                    <div class="form-group">
                        {!! Form::label('statement_file', __('accounting::lang.bank_statement_csv_file') . ':*') !!}
                        {!! Form::file('statement_file', ['class' => 'form-control', 'accept' => '.csv,.txt', 'required' => true]); !!}
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="control-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block">@lang('accounting::lang.bank_statement_upload')</button>
                    </div>
                </div>
            </div>
            {!! Form::close() !!}
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">@lang('messages.add')</h3></div>
        <div class="box-body">
            {!! Form::open(['route' => 'accounting.bankReconciliation.storeLine', 'method' => 'post']) !!}
            {!! Form::hidden('bank_account_id', $bank->id) !!}
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('line_date', __('messages.date') . ':*') !!}
                        {!! Form::text('line_date', null, ['class' => 'form-control', 'id' => 'bank_statement_line_date', 'required']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('amount', __('sale.amount') . ':*') !!}
                        {!! Form::text('amount', null, ['class' => 'form-control input_number', 'required']); !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('description', __('lang_v1.description')) !!}
                        {!! Form::text('description', null, ['class' => 'form-control']); !!}
                    </div>
                </div>
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            {!! Form::close() !!}
        </div>
    </div>
    @endcan

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">@lang('accounting::lang.statement_lines')</h3>
            <div class="box-tools pull-right">
                @php
                    $filterBase = route('accounting.bankReconciliation.statement', $bank->id);
                @endphp
                <div class="btn-group">
                    <a href="{{ $filterBase }}?status=all" class="btn btn-default btn-sm @if(($status ?? 'all') === 'all') active @endif">@lang('accounting::lang.statement_filter_all')</a>
                    <a href="{{ $filterBase }}?status=unreconciled" class="btn btn-default btn-sm @if(($status ?? '') === 'unreconciled') active @endif">@lang('accounting::lang.statement_filter_unreconciled')</a>
                    <a href="{{ $filterBase }}?status=reconciled" class="btn btn-default btn-sm @if(($status ?? '') === 'reconciled') active @endif">@lang('accounting::lang.statement_filter_reconciled')</a>
                </div>
            </div>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>@lang('messages.date')</th>
                        <th>@lang('sale.amount')</th>
                        <th>@lang('lang_v1.description')</th>
                        <th>@lang('accounting::lang.matched_gl_line')</th>
                        <th>@lang('accounting::lang.reconcile')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $line)
                    <tr>
                        <td>{{ $line->line_date ? $line->line_date->format('Y-m-d') : '' }}</td>
                        <td>@format_currency($line->amount)</td>
                        <td>{{ $line->description }}</td>
                        <td>
                            @if($line->matchedAat)
                                @php $m = $line->matchedAat; @endphp
                                <div class="small">
                                    <strong>@lang('messages.date'):</strong> {{ $m->operation_date ? \Carbon\Carbon::parse($m->operation_date)->format('Y-m-d') : '' }}<br>
                                    <strong>@lang('account.debit'):</strong> @if($m->type === 'debit') @format_currency($m->amount) @else — @endif
                                    <strong>@lang('account.credit'):</strong> @if($m->type === 'credit') @format_currency($m->amount) @else — @endif<br>
                                    @if($m->note)
                                        <strong>@lang('brand.note'):</strong> {{ $m->note }}<br>
                                    @endif
                                    @if($m->accTransMapping && $m->accTransMapping->ref_no)
                                        <strong>@lang('purchase.ref_no'):</strong> {{ $m->accTransMapping->ref_no }}<br>
                                    @endif
                                    <span class="text-muted">#{{ $m->id }}</span>
                                </div>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @can('accounting.reconcile')
                            {!! Form::open(['route' => 'accounting.bankReconciliation.reconcileLine', 'method' => 'post', 'class' => 'form-reconcile-line']) !!}
                            {!! Form::hidden('statement_line_id', $line->id) !!}
                            {!! Form::hidden('matched_aat_id', $line->matched_aat_id, ['class' => 'input-matched-aat-id']) !!}
                            <div class="form-group">
                                <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline btn-open-gl-picker" data-statement-line-id="{{ $line->id }}">@lang('accounting::lang.choose_gl_line')</button>
                                @if($line->matched_aat_id)
                                <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-ghost btn-clear-match">@lang('accounting::lang.clear_match')</button>
                                @endif
                            </div>
                            <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-primary">@lang('messages.update')</button>
                            {!! Form::close() !!}
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $lines->links() }}
        </div>
    </div>

    <p><a href="{{ route('accounting.bankReconciliation.index') }}">@lang('messages.back')</a></p>
</section>

<div class="modal fade" id="gl-line-picker-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">@lang('accounting::lang.choose_gl_line')</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="gl-picker-statement-line-id" value="">
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('picker_date_range', __('report.date_range') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                                {!! Form::text('picker_date_range', null, ['class' => 'form-control', 'readonly', 'id' => 'picker_date_range', 'placeholder' => __('report.date_range')]) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('picker_location_id', __('accounting::lang.location') . ':') !!}
                            {!! Form::select('picker_location_id', $business_locations ?? [], request('picker_location_id'), ['class' => 'form-control select2', 'id' => 'picker_location_id', 'placeholder' => __('accounting::lang.all_locations'), 'style' => 'width:100%']); !!}
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="gl-lines-picker-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>@lang('messages.date')</th>
                                <th>@lang('lang_v1.description')</th>
                                <th>@lang('brand.note')</th>
                                <th>@lang('lang_v1.added_by')</th>
                                <th>@lang('account.debit')</th>
                                <th>@lang('account.credit')</th>
                                <th>@lang('accounting::lang.gl_signed_movement')</th>
                                <th>@lang('accounting::lang.view_source_document')</th>
                                <th>@lang('messages.action')</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@stop

@section('javascript')
@include('accounting::accounting.common_js')
<script>
    $(document).ready(function () {
        if (typeof datepicker_date_format !== 'undefined' && $('#bank_statement_line_date').length) {
            $('#bank_statement_line_date').datepicker({
                autoclose: true,
                todayHighlight: true,
                format: datepicker_date_format,
            });
        }

        var glPickerTable = null;
        var glLinesUrl = @json(route('accounting.bankReconciliation.glLines', $bank->id));

        $(document).on('click', '#gl-line-picker-modal a.btn-modal', function () {
            $('#gl-line-picker-modal').modal('hide');
        });

        $('#picker_date_range').daterangepicker(
            $.extend(true, {}, dateRangeSettings, {
                startDate: moment().subtract(29, 'days'),
                endDate: moment()
            }),
            function (start, end) {
                $('#picker_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
                if (glPickerTable) {
                    glPickerTable.ajax.reload();
                }
            }
        );
        $('#picker_date_range').val(
            moment().subtract(29, 'days').format(moment_date_format) + ' ~ ' + moment().format(moment_date_format)
        );

        $('.btn-open-gl-picker').on('click', function () {
            var lineId = $(this).data('statement-line-id');
            $('#gl-picker-statement-line-id').val(lineId);
            $('#gl-line-picker-modal').modal('show');
            if (!glPickerTable) {
                glPickerTable = $('#gl-lines-picker-table').DataTable({
                    processing: true,
                    serverSide: true,
                    ordering: false,
                    ajax: {
                        url: glLinesUrl,
                        data: function (d) {
                            d.statement_line_id = $('#gl-picker-statement-line-id').val();
                            var start = '';
                            var end = '';
                            if ($('#picker_date_range').val() && $('#picker_date_range').data('daterangepicker')) {
                                start = $('#picker_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                                end = $('#picker_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
                            }
                            d.start_date = start;
                            d.end_date = end;
                            d.location_id = $('#picker_location_id').val() || '';
                        }
                    },
                    columns: [
                        { data: 'operation_date', name: 'accounting_accounts_transactions.operation_date' },
                        { data: 'ref_no', name: 'ATM.ref_no' },
                        { data: 'note', name: 'ATM.note' },
                        { data: 'added_by', name: 'added_by' },
                        { data: 'debit', name: 'accounting_accounts_transactions.amount', searchable: false },
                        { data: 'credit', name: 'accounting_accounts_transactions.amount', searchable: false },
                        { data: 'signed_amount', name: 'signed_amount', searchable: false, orderable: false },
                        { data: 'document_link', name: 'document_link', searchable: false, orderable: false },
                        { data: 'picker_action', name: 'picker_action', searchable: false, orderable: false }
                    ],
                    fnDrawCallback: function () {
                        __currency_convert_recursively($('#gl-lines-picker-table'));
                    }
                });
            } else {
                glPickerTable.ajax.reload();
            }
        });

        $('#gl-line-picker-modal').on('click', '.btn-select-gl-line', function () {
            var aatId = $(this).data('aat-id');
            var lineId = $('#gl-picker-statement-line-id').val();
            var $form = $('form.form-reconcile-line').filter(function () {
                return $(this).find('input[name="statement_line_id"]').val() == String(lineId);
            });
            $form.find('.input-matched-aat-id').val(aatId);
            $('#gl-line-picker-modal').modal('hide');
        });

        $('#picker_location_id').on('change', function () {
            if (glPickerTable) {
                glPickerTable.ajax.reload();
            }
        });

        $('.btn-clear-match').on('click', function (e) {
            var $form = $(this).closest('form');
            $form.find('.input-matched-aat-id').val('');
        });
    });
</script>
@stop
