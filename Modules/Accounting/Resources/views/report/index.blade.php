@extends('layouts.app')

@section('title', __('accounting::lang.journal_entry'))

@section('content')

@include('accounting::layouts.nav')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'accounting::lang.reports' )</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.trial_balance')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.trial_balance_description')
                    <br/>
                    <a href="{{route('accounting.trialBalance')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.ledger_report')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.ledger_report_description')
                    <br/>
                    <a @if($ledger_url) href="{{$ledger_url}}" @else onclick="alert(' @lang( 'accounting::lang.ledger_add_account') ')" @endif class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.balance_sheet')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.balance_sheet_description')
                    <br/>
                    <a href="{{route('accounting.balanceSheet')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.profit_and_loss')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.profit_and_loss_description')
                    <br/>
                    <a href="{{route('accounting.profitLoss')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.cash_flow')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.cash_flow_description')
                    <br/>
                    <a href="{{route('accounting.cashFlow')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        @can('accounting.view_fixed_assets')
        @can('accounting.view_reports')
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang('accounting::lang.fixed_asset_schedule')</h3>
                </div>
                <div class="box-body">
                    @lang('accounting::lang.fixed_asset_schedule_description')
                    <br/>
                    <a href="{{ route('accounting.fixedAssets.schedule') }}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang('accounting::lang.view_report')</a>
                </div>
            </div>
        </div>
        @endcan
        @endcan

        @can('accounting.view_audit_log')
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.audit_log')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.audit_log_description')
                    <br/>
                    <a href="{{route('accounting.auditLog')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>
        @endcan

        @can('accounting.reconcile')
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.bank_reconciliation')</h3>
                </div>

                <div class="box-body">
                    <a href="{{route('accounting.bankReconciliation.index')}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>
        @endcan

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_recievable_ageing_report')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_recievable_ageing_report_description')
                    <br/>
                    <a href="{{route('accounting.account_receivable_ageing_report')}}" 
                    class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_payable_ageing_report')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_payable_ageing_report_description')
                    <br/>
                    <a href="{{route('accounting.account_payable_ageing_report')}}" 
                    class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_receivable_ageing_details')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_receivable_ageing_details_description')
                    <br/>
                    <a href="{{route('accounting.account_receivable_ageing_details')}}" 
                    class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_payable_ageing_details')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_payable_ageing_details_description')
                    <br/>
                    <a href="{{route('accounting.account_payable_ageing_details')}}" 
                    class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

    </div>
</section>

@stop