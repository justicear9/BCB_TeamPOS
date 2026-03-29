@extends('layouts.app')

@section('title', __('accounting::lang.statement_lines'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>{{ $bank->name }} @if($gl) — {{ $gl->name }} @endif</h1>
</section>

<section class="content">
    @can('accounting.import_bank_statement')
    <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">@lang('messages.add')</h3></div>
        <div class="box-body">
            {!! Form::open(['route' => 'accounting.bankReconciliation.storeLine', 'method' => 'post']) !!}
            {!! Form::hidden('bank_account_id', $bank->id) !!}
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('line_date', __('messages.date') . ':*') !!}
                        {!! Form::text('line_date', null, ['class' => 'form-control datepicker', 'required']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('amount', __('sale.amount') . ':*') !!}
                        {!! Form::text('amount', null, ['class' => 'form-control input_number', 'required']); !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('description', __('lang_v1.description')) !!}
                        {!! Form::text('description', null, ['class' => 'form-control']); !!}
                    </div>
                </div>
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.save')</button>
            {!! Form::close() !!}
        </div>
    </div>
    @endcan

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>@lang('messages.date')</th>
                        <th>@lang('sale.amount')</th>
                        <th>@lang('lang_v1.description')</th>
                        <th>@lang('accounting::lang.matched_gl_line')</th>
                        <th>@lang('accounting::lang.reconcile')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $line)
                    <tr>
                        <td>{{ $line->line_date ? $line->line_date->format('Y-m-d') : '' }}</td>
                        <td>@format_currency($line->amount)</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->matched_aat_id }}</td>
                        <td>
                            @can('accounting.reconcile')
                            {!! Form::open(['route' => 'accounting.bankReconciliation.reconcileLine', 'method' => 'post', 'class' => 'form-inline']) !!}
                            {!! Form::hidden('statement_line_id', $line->id) !!}
                            <div class="form-group">
                                {!! Form::number('matched_aat_id', $line->matched_aat_id, ['class' => 'form-control', 'placeholder' => 'AAT id']); !!}
                            </div>
                            <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-primary">@lang('messages.update')</button>
                            {!! Form::close() !!}
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $lines->links() }}
        </div>
    </div>

    <p><a href="{{ route('accounting.bankReconciliation.index') }}">@lang('messages.back')</a></p>
</section>

@stop
