@extends('layouts.app')

@section('title', __('accounting::lang.bank_reconciliation'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('accounting::lang.bank_reconciliation')</h1>
</section>

<section class="content">
    @can('accounting.reconcile')
    <p>
        <a href="{{ route('accounting.bankReconciliation.create') }}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm">@lang('messages.add')</a>
    </p>
    @endcan

    <div class="box box-solid">
        <div class="box-body">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>@lang('user.name')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accounts as $acc)
                    <tr>
                        <td>{{ $acc->name }}</td>
                        <td>
                            <a href="{{ route('accounting.bankReconciliation.statement', $acc->id) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline">@lang('accounting::lang.statement_lines')</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="2">@lang('accounting::lang.no_records')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

@stop
