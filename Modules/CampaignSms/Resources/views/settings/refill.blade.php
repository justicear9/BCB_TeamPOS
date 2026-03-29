@extends('layouts.app')
@section('title', __('campaignsms::lang.refill_templates'))

@section('content')
<section class="content">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            @component('components.widget', ['class' => 'box-primary', 'title' => __('campaignsms::lang.refill_templates')])
                <p class="help-block">@lang('campaignsms::lang.placeholders_help')</p>
                {!! Form::open(['route' => 'campaignsms.refill-settings.update', 'method' => 'put']) !!}
                <div class="form-group">
                    {!! Form::label('reminder_days_before', __('campaignsms::lang.reminder_days_before_label')) !!}
                    {!! Form::number('reminder_days_before', $settings->reminder_days_before ?? 3, ['class' => 'form-control', 'min' => 0, 'max' => 365, 'required' => true]) !!}
                    <p class="help-block">@lang('campaignsms::lang.reminder_days_before_help')</p>
                </div>
                <div class="form-group">
                    {!! Form::label('default_refill_template', __('campaignsms::lang.message')) !!}
                    {!! Form::textarea('default_refill_template', $settings->default_refill_template, ['class' => 'form-control', 'rows' => 5, 'required' => true]) !!}
                </div>
                <button type="submit" class="btn btn-primary">@lang('messages.update')</button>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
</section>
@endsection
