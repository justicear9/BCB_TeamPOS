@extends('layouts.app')
@section('title', __('inventoryreporting::lang.settings'))

@section('content')
    <section class="content-header">
        <h1>@lang('inventoryreporting::lang.settings')</h1>
        <p class="text-muted">@lang('inventoryreporting::lang.settings_help')</p>
    </section>

    <section class="content">
        {!! Form::open(['url' => action([\Modules\InventoryReporting\Http\Controllers\SettingsController::class, 'update']), 'method' => 'put']) !!}
        @component('components.widget', ['class' => 'box-solid'])
            @foreach($locations as $loc)
                @php
                    $row = $settings[$loc->id] ?? null;
                    $val = $row ? $row->inventory_adjustment_offset_account_id : null;
                @endphp
                <h4>{{ $loc->name }}</h4>
                <div class="row">
                    <div class="col-sm-8">
                        <div class="form-group">
                            {!! Form::label("location_accounts[{$loc->id}][inventory_adjustment_offset_account_id]", __('inventoryreporting::lang.offset_account')) !!}
                            {!! Form::select(
                                "location_accounts[{$loc->id}][inventory_adjustment_offset_account_id]",
                                $accounts,
                                $val,
                                ['class' => 'form-control select2', 'placeholder' => __('messages.please_select')]
                            ) !!}
                            <p class="help-block">@lang('inventoryreporting::lang.offset_account_help')</p>
                        </div>
                    </div>
                </div>
                <hr>
            @endforeach
            <div class="text-center">
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary">@lang('messages.update')</button>
            </div>
        @endcomponent
        {!! Form::close() !!}
    </section>
@endsection
