@extends('layouts.app')
@section('title', 'Install Campaign SMS')

@section('content')
<section class="content">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Install {{ $module_display_name }} module</h3>
                </div>
                <div class="box-body">
                    <p>This will run database migrations for bulk SMS, token balances, and refill reminders.</p>
                    <form method="post" action="{{ $action_url }}">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">Install module</button>
                        <a href="{{ action([\App\Http\Controllers\Install\ModulesController::class, 'index']) }}" class="btn btn-default">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
