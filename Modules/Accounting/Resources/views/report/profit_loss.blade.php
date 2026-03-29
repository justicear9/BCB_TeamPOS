@extends('layouts.app')

@section('title', __('accounting::lang.profit_and_loss'))

@section('content')

@include('accounting::layouts.nav')

<section class="content">

    <div class="col-md-3">
        <div class="form-group">
            {!! Form::label('date_range_filter', __('report.date_range') . ':') !!}
            {!! Form::text('date_range_filter', null,
                ['placeholder' => __('lang_v1.select_a_date_range'),
                'class' => 'form-control', 'readonly', 'id' => 'date_range_filter']); !!}
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            {!! Form::label('location_id', __('accounting::lang.location') . ':') !!}
            {!! Form::select('location_id', $business_locations, $location_id, ['class' => 'form-control select2', 'id' => 'location_id_pl', 'placeholder' => __('accounting::lang.all_locations'), 'style' => 'width:100%']); !!}
        </div>
    </div>

    <div class="col-md-8 col-md-offset-2">
        <div class="box box-warning">
            <div class="box-header with-border text-center">
                <h2 class="box-title">@lang( 'accounting::lang.profit_and_loss')</h2>
                <p>{{ @format_date($start_date) }} ~ {{ @format_date($end_date) }}</p>
            </div>

            <div class="box-body">
                <table class="table table-stripped">
                    <thead>
                        <tr>
                            <th></th>
                            <th>@lang('accounting::lang.account')</th>
                            <th class="text-right">@lang('lang_v1.balance')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ __('accounting::lang.' . $row->account_primary_type) }}</td>
                            <td>
                                <a href="{{ route('accounting.ledger', $row->id) }}?start_date={{ $start_date }}&end_date={{ $end_date }}@if($location_id !== null && $location_id !== '')&location_id={{ $location_id }}@endif">
                                    @if(!empty($row->gl_code)){{ $row->gl_code }} — @endif{{ $row->name }}
                                </a>
                            </td>
                            <td class="text-right">@format_currency($row->balance)</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2">@lang('accounting::lang.total_income')</th>
                            <th class="text-right">@format_currency($totals['income_total'])</th>
                        </tr>
                        <tr>
                            <th colspan="2">@lang('accounting::lang.total_expenses')</th>
                            <th class="text-right">@format_currency($totals['expense_total'])</th>
                        </tr>
                        <tr>
                            <th colspan="2">@lang('accounting::lang.net_income')</th>
                            <th class="text-right">@format_currency($totals['net_income'])</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</section>

@stop

@section('javascript')

<script type="text/javascript">
    $(document).ready(function(){

        dateRangeSettings.startDate = moment('{{$start_date}}');
        dateRangeSettings.endDate = moment('{{$end_date}}');

        $('#date_range_filter').daterangepicker(
            dateRangeSettings,
            function (start, end) {
                $('#date_range_filter').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
                apply_filter();
            }
        );
        $('#date_range_filter').on('cancel.daterangepicker', function(ev, picker) {
            $('#date_range_filter').val('');
            apply_filter();
        });

        function apply_filter(){
            var start = '';
            var end = '';

            if ($('#date_range_filter').val()) {
                start = $('input#date_range_filter')
                    .data('daterangepicker')
                    .startDate.format('YYYY-MM-DD');
                end = $('input#date_range_filter')
                    .data('daterangepicker')
                    .endDate.format('YYYY-MM-DD');
            }

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('start_date', start);
            urlParams.set('end_date', end);
            var loc = $('#location_id_pl').val();
            if (loc) {
                urlParams.set('location_id', loc);
            } else {
                urlParams.delete('location_id');
            }
            window.location.search = urlParams;
        }

        $('#location_id_pl').change(function () {
            apply_filter();
        });
    });

</script>

@stop
