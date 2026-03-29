@extends('layouts.app')
@section('title', __('campaignsms::lang.manage_sms_tokens'))

@section('content')
<section class="content">
    @include('superadmin::layouts.nav')
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            @component('components.widget', ['class' => 'box-primary', 'title' => $business->name . ' — ' . __('campaignsms::lang.sms_token_balance')])
                <p><strong>@lang('campaignsms::lang.tokens'):</strong> {{ $balance }}</p>
                {!! Form::open(['route' => ['campaignsms.superadmin.tokens.update', $business->id], 'method' => 'put']) !!}
                <div class="form-group">
                    {!! Form::label('action', 'Action') !!}
                    {!! Form::select('action', ['set' => __('campaignsms::lang.set_balance'), 'add' => __('campaignsms::lang.add_tokens')], 'add', ['class' => 'form-control']) !!}
                </div>
                <div class="form-group">
                    {!! Form::label('amount', __('campaignsms::lang.amount')) !!}
                    {!! Form::number('amount', 0, ['class' => 'form-control', 'min' => 0, 'required' => true]) !!}
                </div>
                <button type="submit" class="btn btn-primary">@lang('messages.update')</button>
                <a href="{{ action([\Modules\Superadmin\Http\Controllers\BusinessController::class, 'show'], [$business->id]) }}" class="btn btn-default">@lang('messages.cancel')</a>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
</section>
@endsection
