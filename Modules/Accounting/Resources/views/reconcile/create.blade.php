@extends('layouts.app')

@section('title', __('messages.add'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>@lang('accounting::lang.bank_accounts')</h1>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            {!! Form::open(['route' => 'accounting.bankReconciliation.store', 'method' => 'post']) !!}
            <div class="form-group">
                {!! Form::label('name', __('user.name') . ':*') !!}
                {!! Form::text('name', null, ['class' => 'form-control', 'required']); !!}
            </div>
            <div class="form-group">
                {!! Form::label('accounting_account_id', __('accounting::lang.account') . ':*') !!}
                @if($gl_accounts->isEmpty())
                    <div class="alert alert-warning">
                        @lang('accounting::lang.bank_reconcile_empty_gl_hint')
                        <a href="{{ action([\Modules\Accounting\Http\Controllers\CoaController::class, 'index']) }}" class="alert-link">@lang('accounting::lang.chart_of_accounts')</a>.
                    </div>
                @endif
                {!! Form::select('accounting_account_id', $gl_accounts, null, ['class' => 'form-control select2', 'required' => !$gl_accounts->isEmpty(), 'style' => 'width:100%', 'placeholder' => __('messages.please_select'), 'disabled' => $gl_accounts->isEmpty()]); !!}
                <p class="help-block">@lang('accounting::lang.bank_reconcile_gl_dropdown_help')</p>
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop
