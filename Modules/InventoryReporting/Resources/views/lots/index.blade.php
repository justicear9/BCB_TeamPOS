@extends('layouts.app')
@section('title', __('inventoryreporting::lang.lot_management'))

@section('content')
    <section class="content-header">
        <h1>@lang('inventoryreporting::lang.lot_management')</h1>
    </section>

    <section class="content">
        {!! Form::open(['url' => action([\Modules\InventoryReporting\Http\Controllers\LotController::class, 'index']), 'method' => 'get']) !!}
        @component('components.widget', ['class' => 'box-solid'])
            <div class="row">
                <div class="col-sm-4">
                    {!! Form::label('location_id', __('purchase.business_location')) !!}
                    {!! Form::select('location_id', $locations, $location_id, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select')]) !!}
                </div>
                <div class="col-sm-2">
                    <label class="tw-block">&nbsp;</label>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary">@lang('lang_v1.search')</button>
                </div>
            </div>
        @endcomponent
        {!! Form::close() !!}

        @if($location_id && $table_rows->count())
            @component('components.widget', ['class' => 'box-solid'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>@lang('sale.product')</th>
                                <th>@lang('lang_v1.sku')</th>
                                <th>@lang('lang_v1.lot_number')</th>
                                <th>@lang('report.exp_date')</th>
                                <th>@lang('lang_v1.qty_available')</th>
                                <th>@lang('purchase.ref_no')</th>
                                <th>@lang('messages.date')</th>
                                <th>@lang('messages.action')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($table_rows as $row)
                                <tr>
                                    <td>{{ $row->product_name }}</td>
                                    <td>{{ $row->sub_sku }}</td>
                                    <td>{{ $row->lot_number }}</td>
                                    <td>@if(!empty($row->exp_date)) {{ @format_date($row->exp_date) }} @endif</td>
                                    <td>{{ @format_quantity($row->qty_remaining) }}</td>
                                    <td>{{ $row->ref_no }}</td>
                                    <td>{{ @format_datetime($row->transaction_date) }}</td>
                                    <td>
                                        <a href="{{ action([\Modules\InventoryReporting\Http\Controllers\LotController::class, 'edit'], [$row->id]) }}" class="btn btn-xs btn-primary">@lang('messages.edit')</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endcomponent
        @elseif($location_id)
            <p class="text-muted">@lang('inventoryreporting::lang.no_lots')</p>
        @endif
    </section>
@endsection
