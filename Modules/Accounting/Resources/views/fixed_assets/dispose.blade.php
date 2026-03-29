@extends('layouts.app')

@section('title', __('accounting::lang.dispose_fixed_asset'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>@lang('accounting::lang.dispose_fixed_asset') — {{ $asset->name }}</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ !empty(session('status')['success']) && session('status')['success'] ? 'success' : 'danger' }}">
            {{ session('status')['msg'] ?? '' }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <p class="text-muted">@lang('accounting::lang.dispose_help')</p>
            <p><strong>@lang('sale.cost'):</strong> @format_currency($asset->cost)</p>
            <p><strong>@lang('accounting::lang.accumulated_depreciation_total'):</strong> @format_currency($asset->totalAccumulatedDepreciation())</p>
            <p><strong>@lang('accounting::lang.net_book_value'):</strong> @format_currency($asset->netBookValue())</p>

            {!! Form::open(['route' => ['accounting.fixedAssets.dispose', $asset->id], 'method' => 'post']) !!}

            <div class="form-group">
                {!! Form::label('proceeds', __('accounting::lang.disposal_proceeds')) !!}
                {!! Form::text('proceeds', 0, ['class' => 'form-control input_number']); !!}
            </div>

            <div class="form-group">
                {!! Form::label('proceeds_account_id', __('accounting::lang.proceeds_account') . ':*') !!}
                {!! Form::select('proceeds_account_id', $accounts, null, ['class' => 'form-control select2', 'required', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>

            <div class="form-group">
                {!! Form::label('gain_loss_account_id', __('accounting::lang.gain_loss_account') . ':*') !!}
                {!! Form::select('gain_loss_account_id', $accounts, null, ['class' => 'form-control select2', 'required', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
            </div>

            <div class="form-group">
                {!! Form::label('operation_date', __('messages.date') . ':*') !!}
                {!! Form::text('operation_date', @format_date($defaultDate), ['class' => 'form-control', 'id' => 'operation_date', 'required', 'autocomplete' => 'off']); !!}
            </div>

            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.submit')</button>
            <a href="{{ route('accounting.fixedAssets.show', $asset->id) }}" class="tw-dw-btn tw-dw-btn-neutral">@lang('messages.cancel')</a>

            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop

@section('javascript')
    @include('accounting::fixed_assets.partials.datepicker_init')
@endsection
