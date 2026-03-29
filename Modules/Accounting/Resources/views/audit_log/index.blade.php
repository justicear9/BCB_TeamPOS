@extends('layouts.app')

@section('title', __('accounting::lang.audit_log'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('accounting::lang.audit_log')</h1>
</section>

<section class="content">
    {!! Form::open(['method' => 'get', 'class' => 'form-inline']) !!}
    <div class="box box-solid">
        <div class="box-body">
            <div class="form-group">
                {!! Form::label('from_date', __('report.from_date')) !!}
                {!! Form::text('from_date', request('from_date'), ['class' => 'form-control datepicker']); !!}
            </div>
            <div class="form-group">
                {!! Form::label('to_date', __('report.to_date')) !!}
                {!! Form::text('to_date', request('to_date'), ['class' => 'form-control datepicker']); !!}
            </div>
            <div class="form-group">
                {!! Form::label('action', __('lang_v1.action')) !!}
                {!! Form::text('action', request('action'), ['class' => 'form-control']); !!}
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('report.filters')</button>
        </div>
    </div>
    {!! Form::close() !!}

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>@lang('messages.date')</th>
                        <th>@lang('lang_v1.action')</th>
                        <th>@lang('user.user_type')</th>
                        <th>@lang('lang_v1.description')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->created_at }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->user_id }}</td>
                        <td>
                            @if(!empty($log->before))
                                <pre class="tw-text-xs tw-max-h-32 tw-overflow-auto">{{ json_encode($log->before, JSON_PRETTY_PRINT) }}</pre>
                            @endif
                            @if(!empty($log->after))
                                <pre class="tw-text-xs tw-max-h-32 tw-overflow-auto">{{ json_encode($log->after, JSON_PRETTY_PRINT) }}</pre>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center">@lang('lang_v1.no_data')</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $logs->links() }}
        </div>
    </div>
</section>

@stop
