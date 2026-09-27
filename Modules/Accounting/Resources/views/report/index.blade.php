@extends('layouts.app')

@section('title', __('accounting::lang.reports'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('accounting::lang.reports')</h1>
    <p class="text-muted tw-mb-0">@lang('accounting::lang.reports_intro')</p>
</section>

<section class="content">
    {{-- Financial statements --}}
    @component('components.widget', ['title' => __('accounting::lang.reports_section_financial')])
        <div class="row">
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                    <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.balance_sheet')</h4>
                    <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.balance_sheet_description')</p>
                    <div class="tw-mt-3">
                        <a href="{{ route('accounting.balanceSheet') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                    <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.profit_and_loss')</h4>
                    <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.profit_and_loss_description')</p>
                    <div class="tw-mt-3">
                        <a href="{{ route('accounting.profitLoss') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                    <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.cash_flow')</h4>
                    <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.cash_flow_description')</p>
                    <div class="tw-mt-3">
                        <a href="{{ route('accounting.cashFlow') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                    <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.trial_balance')</h4>
                    <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.trial_balance_description')</p>
                    <div class="tw-mt-3">
                        <a href="{{ route('accounting.trialBalance') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                    </div>
                </div>
            </div>
        </div>
    @endcomponent

    {{-- Ledger --}}
    @component('components.widget', ['title' => __('accounting::lang.reports_section_ledger')])
        <div class="row">
            <div class="col-md-8 col-sm-12">
                <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-4">
                    <div>
                        <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.ledger_report')</h4>
                        <p class="tw-text-sm tw-text-gray-600 tw-mb-0">@lang('accounting::lang.ledger_report_description')</p>
                    </div>
                    <div class="tw-flex-shrink-0">
                        @if ($ledger_url)
                            <a href="{{ $ledger_url }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                        @else
                            <button type="button" class="btn btn-default btn-sm btn-ledger-missing" data-msg="{{ e(__('accounting::lang.ledger_add_account')) }}">@lang('accounting::lang.view_report')</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endcomponent

    {{-- Ageing --}}
    @component('components.widget', ['title' => __('accounting::lang.reports_section_ageing')])
        <div class="row">
            <div class="col-md-6">
                <p class="tw-text-sm tw-font-semibold tw-text-gray-700 tw-mt-0 tw-mb-2">@lang('sale.sale') / @lang('accounting::lang.accounts_receivable')</p>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-p-3 tw-mb-3 tw-h-full tw-flex tw-flex-col">
                            <h5 class="tw-mt-0 tw-text-sm tw-font-semibold">@lang('accounting::lang.account_recievable_ageing_report')</h5>
                            <p class="tw-text-xs tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.account_recievable_ageing_report_description')</p>
                            <a href="{{ route('accounting.account_receivable_ageing_report') }}" class="btn btn-default btn-xs tw-mt-2">@lang('accounting::lang.view_report')</a>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-p-3 tw-mb-3 tw-h-full tw-flex tw-flex-col">
                            <h5 class="tw-mt-0 tw-text-sm tw-font-semibold">@lang('accounting::lang.account_receivable_ageing_details')</h5>
                            <p class="tw-text-xs tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.account_receivable_ageing_details_description')</p>
                            <a href="{{ route('accounting.account_receivable_ageing_details') }}" class="btn btn-default btn-xs tw-mt-2">@lang('accounting::lang.view_report')</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <p class="tw-text-sm tw-font-semibold tw-text-gray-700 tw-mt-0 tw-mb-2">@lang('purchase.purchases') / @lang('accounting::lang.accounts_payable')</p>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-p-3 tw-mb-3 tw-h-full tw-flex tw-flex-col">
                            <h5 class="tw-mt-0 tw-text-sm tw-font-semibold">@lang('accounting::lang.account_payable_ageing_report')</h5>
                            <p class="tw-text-xs tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.account_payable_ageing_report_description')</p>
                            <a href="{{ route('accounting.account_payable_ageing_report') }}" class="btn btn-default btn-xs tw-mt-2">@lang('accounting::lang.view_report')</a>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-p-3 tw-mb-3 tw-h-full tw-flex tw-flex-col">
                            <h5 class="tw-mt-0 tw-text-sm tw-font-semibold">@lang('accounting::lang.account_payable_ageing_details')</h5>
                            <p class="tw-text-xs tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.account_payable_ageing_details_description')</p>
                            <a href="{{ route('accounting.account_payable_ageing_details') }}" class="btn btn-default btn-xs tw-mt-2">@lang('accounting::lang.view_report')</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcomponent

    {{-- Operations (permission-gated items) --}}
    @if (auth()->user()->can('accounting.reconcile')
        || auth()->user()->can('accounting.view_audit_log')
        || (auth()->user()->can('accounting.view_fixed_assets') && auth()->user()->can('accounting.view_reports')))
        @component('components.widget', ['title' => __('accounting::lang.reports_section_operations')])
            <div class="row">
                @can('accounting.reconcile')
                    <div class="col-lg-4 col-md-4 col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                            <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.bank_reconciliation')</h4>
                            <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.bank_reconciliation_description')</p>
                            <div class="tw-mt-3">
                                <a href="{{ route('accounting.bankReconciliation.index') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                            </div>
                        </div>
                    </div>
                @endcan
                @can('accounting.view_fixed_assets')
                    @can('accounting.view_reports')
                        <div class="col-lg-4 col-md-4 col-sm-6">
                            <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                                <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.fixed_asset_schedule')</h4>
                                <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.fixed_asset_schedule_description')</p>
                                <div class="tw-mt-3">
                                    <a href="{{ route('accounting.fixedAssets.schedule') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                                </div>
                            </div>
                        </div>
                    @endcan
                @endcan
                @can('accounting.view_audit_log')
                    <div class="col-lg-4 col-md-4 col-sm-6">
                        <div class="tw-rounded-lg tw-border tw-border-gray-200 tw-bg-gray-50/80 tw-p-4 tw-mb-4 tw-h-full tw-flex tw-flex-col">
                            <h4 class="tw-mt-0 tw-text-base tw-font-semibold tw-text-gray-900">@lang('accounting::lang.audit_log')</h4>
                            <p class="tw-text-sm tw-text-gray-600 tw-flex-grow">@lang('accounting::lang.audit_log_description')</p>
                            <div class="tw-mt-3">
                                <a href="{{ route('accounting.auditLog') }}" class="btn btn-primary btn-sm">@lang('accounting::lang.view_report')</a>
                            </div>
                        </div>
                    </div>
                @endcan
            </div>
        @endcomponent
    @endif
</section>

@stop

@section('javascript')
<script type="text/javascript">
    $(document).on('click', '.btn-ledger-missing', function () {
        alert($(this).data('msg'));
    });
</script>
@stop
