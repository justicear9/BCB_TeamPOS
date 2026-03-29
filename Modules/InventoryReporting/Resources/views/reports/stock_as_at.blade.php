@extends('layouts.app')
@section('title', __('inventoryreporting::lang.report_stock_as_at'))

@section('content')
    <section class="content-header">
        <h1>@lang('inventoryreporting::lang.report_stock_as_at')</h1>
    </section>

    <section class="content">
        {!! Form::open(['url' => action([\Modules\InventoryReporting\Http\Controllers\InventoryReportController::class, 'stockAsAt']), 'method' => 'get', 'id' => 'inv_rep_stock_as_at_form']) !!}
        @component('components.widget', ['class' => 'box-solid'])
            <div class="row">
                <div class="col-sm-3">
                    {!! Form::label('as_at', __('inventoryreporting::lang.as_at_date')) !!}
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        {!! Form::text('as_at', $as_at_display ?? $as_at, ['class' => 'form-control', 'id' => 'as_at', 'required', 'readonly']) !!}
                    </div>
                </div>
                <div class="col-sm-3">
                    {!! Form::label('location_id', __('purchase.business_location')) !!}
                    {!! Form::select('location_id', $locations, $location_id, ['class' => 'form-control select2', 'placeholder' => __('messages.all')]) !!}
                </div>
                <div class="col-sm-3">
                    {!! Form::label('detailed', __('inventoryreporting::lang.detail_mode')) !!}
                    {!! Form::select('detailed', [0 => __('inventoryreporting::lang.combined'), 1 => __('inventoryreporting::lang.detailed')], $detailed ? 1 : 0, ['class' => 'form-control']) !!}
                </div>
                <div class="col-sm-2">
                    <label class="tw-block">&nbsp;</label>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary">@lang('lang_v1.search')</button>
                </div>
            </div>
        @endcomponent
        {!! Form::close() !!}

        @if($rows->count())
            @component('components.widget', ['class' => 'box-solid'])
                <p class="tw-mb-3">
                    <a href="{{ route('inventoryreporting.reports.stock-as-at.export', request()->query()) }}" class="tw-dw-btn tw-dw-btn-success">
                        <i class="fa fa-file-excel-o"></i> @lang('inventoryreporting::lang.export_excel')
                    </a>
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            @if($detailed)
                                <tr>
                                    <th>@lang('sale.product')</th>
                                    <th>@lang('lang_v1.sku')</th>
                                    <th>@lang('inventoryreporting::lang.export_location')</th>
                                    <th>@lang('lang_v1.lot_number')</th>
                                    <th>@lang('report.exp_date')</th>
                                    <th>@lang('lang_v1.qty_available')</th>
                                    <th>@lang('purchase.unit_cost_after_tax')</th>
                                </tr>
                            @else
                                <tr>
                                    <th>@lang('sale.product')</th>
                                    <th>@lang('lang_v1.sku')</th>
                                    <th>@lang('lang_v1.qty_available')</th>
                                    <th>@lang('purchase.unit_cost_after_tax')</th>
                                </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                @if($detailed)
                                    <tr>
                                        <td>{{ $r->product_name }}</td>
                                        <td>{{ $r->sub_sku }}</td>
                                        <td>{{ $r->location_name ?? '—' }}</td>
                                        <td>{{ $r->lot_number }}</td>
                                        <td>@if(!empty($r->exp_date)) {{ @format_date($r->exp_date) }} @endif</td>
                                        <td>{{ @format_quantity($r->qty_on_hand) }}</td>
                                        <td>@if(auth()->user()->can('view_purchase_price')) {{ @num_format($r->unit_cost) }} @else — @endif</td>
                                    </tr>
                                @else
                                    <tr>
                                        <td>{{ $r->product_name }}</td>
                                        <td>{{ $r->sub_sku }}</td>
                                        <td>{{ @format_quantity($r->qty_on_hand) }}</td>
                                        <td>@if(auth()->user()->can('view_purchase_price')) {{ @num_format($r->unit_cost) }} @else — @endif</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endcomponent
        @else
            <p class="text-muted">@lang('inventoryreporting::lang.no_data')</p>
        @endif
    </section>
@endsection

@section('javascript')
<script type="text/javascript">
$(document).ready(function() {
    $('#as_at').datepicker({
        autoclose: true,
        format: datepicker_date_format,
        todayHighlight: true,
    });
});
</script>
@endsection
