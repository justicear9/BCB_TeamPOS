@extends('layouts.app')

@section('title', __('accounting::lang.run_depreciation'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>@lang('accounting::lang.run_depreciation')</h1>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <p>@lang('accounting::lang.run_depreciation_help')</p>

            {!! Form::open(['route' => 'accounting.fixedAssets.depreciateRun', 'method' => 'post']) !!}

            <div class="form-group">
                {!! Form::label('depreciation_period', __('accounting::lang.depreciation_period') . ':*') !!}
                {!! Form::text('depreciation_period', $defaultPeriod, ['class' => 'form-control', 'required', 'pattern' => '\d{4}-\d{2}', 'placeholder' => 'YYYY-MM']); !!}
            </div>

            <div class="form-group">
                {!! Form::label('operation_date', __('messages.date') . ':*') !!}
                {!! Form::text('operation_date', @format_date($defaultDate), ['class' => 'form-control', 'id' => 'operation_date', 'required', 'autocomplete' => 'off']); !!}
            </div>

            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.submit')</button>
            <a href="{{ route('accounting.fixedAssets.index') }}" class="tw-dw-btn tw-dw-btn-neutral">@lang('messages.cancel')</a>

            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop

@section('javascript')
    @include('accounting::fixed_assets.partials.datepicker_init')
@endsection
