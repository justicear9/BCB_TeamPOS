@extends('layouts.app')

@section('title', __('accounting::lang.post_acquisition'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>@lang('accounting::lang.post_acquisition') — {{ $asset->name }}</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ !empty(session('status')['success']) && session('status')['success'] ? 'success' : 'danger' }}">
            {{ session('status')['msg'] ?? '' }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <p class="text-muted">@lang('accounting::lang.post_acquisition_help')</p>
            <p><strong>@lang('sale.cost'):</strong> @format_currency($asset->cost)</p>
            <p><strong>@lang('accounting::lang.asset_account'):</strong> {{ $asset->assetAccount->name ?? '—' }}</p>

            {!! Form::open(['route' => ['accounting.fixedAssets.postAcquisition', $asset->id], 'method' => 'post']) !!}

            <div class="form-group">
                {!! Form::label('counter_account_id', __('accounting::lang.counter_account') . ':*') !!}
                {!! Form::select('counter_account_id', $accounts, null, ['class' => 'form-control select2', 'required', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>

            <div class="form-group">
                {!! Form::label('operation_date', __('messages.date') . ':*') !!}
                {!! Form::text('operation_date', @format_date($defaultDate), ['class' => 'form-control', 'id' => 'operation_date', 'required', 'autocomplete' => 'off']); !!}
            </div>

            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            <a href="{{ route('accounting.fixedAssets.show', $asset->id) }}" class="tw-dw-btn tw-dw-btn-neutral">@lang('messages.cancel')</a>

            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop

@section('javascript')
    @include('accounting::fixed_assets.partials.datepicker_init')
@endsection
