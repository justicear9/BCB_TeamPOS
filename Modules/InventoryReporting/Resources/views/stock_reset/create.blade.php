@extends('layouts.app')
@section('title', __('inventoryreporting::lang.stock_reset'))

@section('content')
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('inventoryreporting::lang.stock_reset')</h1>
        <p class="text-muted">@lang('inventoryreporting::lang.stock_reset_help', ['mode' => $lot_n_exp ? __('inventoryreporting::lang.per_lot') : __('inventoryreporting::lang.per_variation')])</p>
    </section>

    <section class="content">
        {!! Form::open(['url' => action([\Modules\InventoryReporting\Http\Controllers\StockResetController::class, 'store']), 'method' => 'post']) !!}
        @component('components.widget', ['class' => 'box-solid'])
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('location_id', __('purchase.business_location') . ':*') !!}
                        {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']) !!}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('transaction_date', __('messages.date') . ':*') !!}
                        {!! Form::text('transaction_date', @format_datetime('now'), ['class' => 'form-control', 'id' => 'transaction_date', 'required']) !!}
                    </div>
                </div>
            </div>
            <div class="alert alert-warning">
                @lang('inventoryreporting::lang.stock_reset_warning')
            </div>
            <div class="text-center">
                <button type="submit" class="tw-dw-btn tw-dw-btn-error tw-text-white" onclick="return confirm('@lang('inventoryreporting::lang.stock_reset_confirm')')">
                    @lang('inventoryreporting::lang.run_stock_reset')
                </button>
            </div>
        @endcomponent
        {!! Form::close() !!}
    </section>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function () {
            $('#transaction_date').datetimepicker({
                format: moment_date_time_format,
                ignoreReadonly: true,
            });
        });
    </script>
@endsection
