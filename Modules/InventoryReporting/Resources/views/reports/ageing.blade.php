@extends('layouts.app')
@section('title', __('inventoryreporting::lang.report_ageing'))

@section('content')
    <section class="content-header">
        <h1>@lang('inventoryreporting::lang.report_ageing')</h1>
    </section>

    <section class="content">
        {!! Form::open(['url' => action([\Modules\InventoryReporting\Http\Controllers\InventoryReportController::class, 'ageing']), 'method' => 'get', 'id' => 'inv_rep_ageing_filters']) !!}
        @component('components.widget', ['class' => 'box-solid'])
            <div class="row">
                <div class="col-sm-3">
                    {!! Form::label('location_id', __('purchase.business_location')) !!}
                    {!! Form::select('location_id', $locations, $filters['location_id'] ?? null, ['class' => 'form-control select2', 'placeholder' => __('messages.all')]) !!}
                </div>
                <div class="col-sm-3">
                    {!! Form::label('search', __('lang_v1.search')) !!}
                    {!! Form::text('search', $filters['search'] ?? null, ['class' => 'form-control', 'placeholder' => __('inventoryreporting::lang.filter_product_sku')]) !!}
                </div>
                <div class="col-sm-3">
                    {!! Form::label('category_id', __('category.category')) !!}
                    {!! Form::select('category_id', $categories, $filters['category_id'] ?? null, ['class' => 'form-control select2', 'placeholder' => __('messages.all')]) !!}
                </div>
                <div class="col-sm-3">
                    {!! Form::label('brand_id', __('brand.brands')) !!}
                    {!! Form::select('brand_id', $brands, $filters['brand_id'] ?? null, ['class' => 'form-control select2', 'placeholder' => __('messages.all')]) !!}
                </div>
            </div>
            <div class="row">
                <div class="col-sm-3">
                    {!! Form::label('received_from', __('inventoryreporting::lang.date_received_from')) !!}
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        {!! Form::text('received_from', $filter_received_from_display ?? null, ['class' => 'form-control', 'id' => 'received_from', 'readonly']) !!}
                    </div>
                </div>
                <div class="col-sm-3">
                    {!! Form::label('received_to', __('inventoryreporting::lang.date_received_to')) !!}
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        {!! Form::text('received_to', $filter_received_to_display ?? null, ['class' => 'form-control', 'id' => 'received_to', 'readonly']) !!}
                    </div>
                </div>
                <div class="col-sm-2">
                    {!! Form::label('min_days', __('inventoryreporting::lang.min_days_in_stock')) !!}
                    {!! Form::number('min_days', $filters['min_days'] ?? null, ['class' => 'form-control', 'min' => 0, 'placeholder' => '—']) !!}
                </div>
                <div class="col-sm-2">
                    {!! Form::label('max_days', __('inventoryreporting::lang.max_days_in_stock')) !!}
                    {!! Form::number('max_days', $filters['max_days'] ?? null, ['class' => 'form-control', 'min' => 0, 'placeholder' => '—']) !!}
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
                    <a href="{{ route('inventoryreporting.reports.ageing.export', request()->query()) }}" class="tw-dw-btn tw-dw-btn-success">
                        <i class="fa fa-file-excel-o"></i> @lang('inventoryreporting::lang.export_excel')
                    </a>
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>@lang('inventoryreporting::lang.export_location')</th>
                                <th>@lang('sale.product')</th>
                                <th>@lang('lang_v1.sku')</th>
                                <th>@lang('inventoryreporting::lang.date_received')</th>
                                <th>@lang('lang_v1.lot_number')</th>
                                <th>@lang('report.exp_date')</th>
                                <th>@lang('inventoryreporting::lang.qty_sold')</th>
                                <th>@lang('lang_v1.qty_available')</th>
                                <th>@lang('inventoryreporting::lang.days_in_stock')</th>
                                <th>@lang('inventoryreporting::lang.last_sale_date')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr>
                                    <td>{{ $r->location_name ?? '—' }}</td>
                                    <td>{{ $r->product_name }}</td>
                                    <td>{{ $r->sub_sku }}</td>
                                    <td>@if(!empty($r->date_received)) {{ @format_datetime($r->date_received) }} @endif</td>
                                    <td>{{ $r->lot_number }}</td>
                                    <td>@if(!empty($r->exp_date)) {{ @format_date($r->exp_date) }} @endif</td>
                                    <td>{{ @format_quantity($r->qty_sold) }}</td>
                                    <td>{{ @format_quantity($r->qty_remaining) }}</td>
                                    <td>{{ $r->days_in_stock }}</td>
                                    <td>@if(!empty($r->last_sale_date)) {{ @format_datetime($r->last_sale_date) }} @else — @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="text-center">
                    {{ $rows->links() }}
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
    $('#received_from, #received_to').datepicker({
        autoclose: true,
        format: datepicker_date_format,
        todayHighlight: true,
    });
});
</script>
@endsection
