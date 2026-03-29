@extends('layouts.app')
@section('title', __('messages.edit'))

@section('content')
    <section class="content-header">
        <h1>@lang('inventoryreporting::lang.edit_lot') — {{ $pl->product->name ?? '' }}</h1>
    </section>

    <section class="content">
        {!! Form::model($pl, ['url' => action([\Modules\InventoryReporting\Http\Controllers\LotController::class, 'update'], [$pl->id]), 'method' => 'put']) !!}
        <input type="hidden" name="location_id" value="{{ $pl->transaction->location_id }}">

        @component('components.widget', ['class' => 'box-solid'])
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('lot_number', __('lang_v1.lot_number')) !!}
                        {!! Form::text('lot_number', $pl->lot_number, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('exp_date', __('product.exp_date')) !!}
                        {!! Form::text('exp_date', ! empty($pl->exp_date) ? \App\Utils\Util::bladeFormatDate($pl->exp_date) : null, ['class' => 'form-control', 'id' => 'exp_date']) !!}
                    </div>
                </div>
            </div>
            <div class="text-center">
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary">@lang('messages.update')</button>
                <a href="{{ action([\Modules\InventoryReporting\Http\Controllers\LotController::class, 'index']) }}" class="tw-dw-btn">@lang('messages.cancel')</a>
            </div>
        @endcomponent
        {!! Form::close() !!}
    </section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('#exp_date').datepicker({
            autoclose: true,
            format: datepicker_date_format
        });
    });
</script>
@endsection
