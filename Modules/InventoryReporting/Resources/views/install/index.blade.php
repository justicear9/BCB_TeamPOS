@extends('layouts.app')
@section('title', __('inventoryreporting::lang.install_title'))

@section('content')
<section class="content">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang('inventoryreporting::lang.install_heading', ['name' => $module_display_name])</h3>
                </div>
                <div class="box-body">
                    <p>@lang('inventoryreporting::lang.install_body')</p>
                    <form method="post" action="{{ $action_url }}">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">@lang('inventoryreporting::lang.install_button')</button>
                        <a href="{{ action([\App\Http\Controllers\Install\ModulesController::class, 'index']) }}" class="btn btn-default">@lang('messages.cancel')</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
