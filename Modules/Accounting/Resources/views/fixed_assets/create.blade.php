@extends('layouts.app')

@section('title', __('messages.add'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>@lang('messages.add') — @lang('accounting::lang.fixed_assets')</h1>
</section>

<section class="content">
    <div class="alert alert-info">
        <p class="tw-mb-0">@lang('accounting::lang.fixed_asset_register_gl_help')</p>
    </div>
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="tw-mb-0">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="box box-solid">
        <div class="box-body">
            {!! Form::open(['route' => 'accounting.fixedAssets.store', 'method' => 'post']) !!}

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('name', __('accounting::lang.fixed_asset_name') . ':*') !!}
                        {!! Form::text('name', null, ['class' => 'form-control', 'required']); !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('asset_code', __('accounting::lang.fixed_asset_code')) !!}
                        {!! Form::text('asset_code', null, ['class' => 'form-control', 'placeholder' => __('accounting::lang.fixed_asset_code_placeholder')]); !!}
                        <p class="help-block text-muted tw-mb-0">@lang('accounting::lang.fixed_asset_code_auto_help')</p>
                    </div>
                </div>
            </div>

            <div class="form-group">
                {!! Form::label('location_id', __('accounting::lang.location')) !!}
                {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('accounting::lang.all_locations'), 'style' => 'width:100%']); !!}
            </div>

            <div class="form-group">
                {!! Form::label('asset_account_id', __('accounting::lang.asset_account') . ':*') !!}
                {!! Form::select('asset_account_id', $accounts, null, ['class' => 'form-control select2', 'required', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>

            <div class="form-group">
                <label class="tw-flex tw-items-start tw-gap-2 tw-cursor-pointer">
                    {!! Form::hidden('is_depreciable', 0) !!}
                    {!! Form::checkbox('is_depreciable', 1, true, ['id' => 'fa_is_depreciable']) !!}
                    <span>@lang('accounting::lang.asset_depreciates')</span>
                </label>
                <p class="help-block text-muted tw-mb-0">@lang('accounting::lang.asset_depreciates_help')</p>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('acquisition_date', __('accounting::lang.acquisition_date') . ':*') !!}
                        {!! Form::text('acquisition_date', @format_date(\Carbon\Carbon::now()), ['class' => 'form-control', 'id' => 'acquisition_date', 'required', 'autocomplete' => 'off']); !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('cost', __('sale.cost') . ':*') !!}
                        {!! Form::text('cost', null, ['class' => 'form-control input_number', 'required']); !!}
                    </div>
                </div>
            </div>

            <div id="fa_depreciation_fields">
            <div class="form-group">
                {!! Form::label('accumulated_depreciation_account_id', __('accounting::lang.accumulated_depreciation_account') . ':*') !!}
                {!! Form::select('accumulated_depreciation_account_id', $accounts, null, ['class' => 'form-control select2 fa-dep-field', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>
            <div class="form-group">
                {!! Form::label('depreciation_expense_account_id', __('accounting::lang.depreciation_expense_account') . ':*') !!}
                {!! Form::select('depreciation_expense_account_id', $accounts, null, ['class' => 'form-control select2 fa-dep-field', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('salvage_value', __('accounting::lang.salvage_value')) !!}
                        {!! Form::text('salvage_value', 0, ['class' => 'form-control input_number fa-dep-field']); !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('opening_accumulated_depreciation', __('accounting::lang.opening_accumulated_depreciation')) !!}
                        {!! Form::text('opening_accumulated_depreciation', 0, ['class' => 'form-control input_number fa-dep-field']); !!}
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('useful_life_months', __('accounting::lang.useful_life_months') . ':*') !!}
                        {!! Form::number('useful_life_months', 36, ['class' => 'form-control fa-dep-field', 'min' => 1]); !!}
                    </div>
                </div>
            </div>
            </div>

            <div class="form-group">
                {!! Form::label('notes', __('brand.note')) !!}
                {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3]); !!}
            </div>

            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            <a href="{{ route('accounting.fixedAssets.index') }}" class="tw-dw-btn tw-dw-btn-neutral">@lang('messages.cancel')</a>

            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop

@section('javascript')
    @include('accounting::fixed_assets.partials.datepicker_init')
    @include('accounting::fixed_assets.partials.depreciable_toggle_js')
@endsection
