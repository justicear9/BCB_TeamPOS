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
                {!! Form::select('accounting_account_id', $gl_accounts, null, ['class' => 'form-control select2', 'required', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
                <p class="help-block">@lang('accounting::lang.is_cash_account')</p>
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            {!! Form::close() !!}
        </div>
    </div>
</section>

@stop
