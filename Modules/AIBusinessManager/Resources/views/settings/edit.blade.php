@extends('layouts.app')
@section('title', __('aibusinessmanager::lang.settings_title'))

@section('content')
<section class="content-header">
    <h1>@lang('aibusinessmanager::lang.settings_heading')</h1>
    <p class="text-muted" style="margin-top:4px;margin-bottom:4px;font-size:13px;">
        @lang('aibusinessmanager::lang.eli_brand_line', ['eli' => __('aibusinessmanager::lang.eli_name'), 'meaning' => __('aibusinessmanager::lang.eli_meaning')])
    </p>
    <p class="text-muted" style="margin-top:6px;">@lang('aibusinessmanager::lang.settings_intro')</p>
</section>

<section class="content">
    <div
        class="aibm-settings-scope"
        style="--aibm-accent: {{ $theme_accent['hex'] }}; --aibm-accent-rgb: {{ $theme_accent['rgb'] }};"
    >
        @if (session('status'))
            <div class="alert alert-success alert-dismissible" style="border-radius:8px;">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('status') }}
            </div>
        @endif

        @if (! \Illuminate\Support\Facades\Schema::hasTable('ai_business_manager_preferences'))
            <div class="alert alert-warning">@lang('aibusinessmanager::lang.settings_error_no_preferences_table')</div>
        @else
            <div class="box box-solid" style="border-radius:12px;border-color:rgba(var(--aibm-accent-rgb),0.25);">
                <div class="box-body" style="padding:18px;">
                    <form method="post" action="{{ action([\Modules\AIBusinessManager\Http\Controllers\SettingsController::class, 'update']) }}">
                        {{ csrf_field() }}
                        @method('PUT')

                        <div class="form-group">
                            <label for="business_industry">@lang('aibusinessmanager::lang.field_industry')</label>
                            <input
                                type="text"
                                name="business_industry"
                                id="business_industry"
                                class="form-control"
                                maxlength="191"
                                value="{{ old('business_industry', optional($preference)->business_industry ?? '') }}"
                                placeholder="@lang('aibusinessmanager::lang.field_industry_placeholder')"
                            >
                            <p class="help-block text-muted" style="margin-top:6px;">@lang('aibusinessmanager::lang.field_industry_help')</p>
                        </div>

                        <div class="form-group">
                            <label for="context_notes">@lang('aibusinessmanager::lang.field_context_notes')</label>
                            <textarea
                                name="context_notes"
                                id="context_notes"
                                class="form-control"
                                rows="8"
                                maxlength="12000"
                                placeholder="@lang('aibusinessmanager::lang.field_context_notes_placeholder')"
                            >{{ old('context_notes', optional($preference)->context_notes ?? '') }}</textarea>
                            <p class="help-block text-muted" style="margin-top:6px;">@lang('aibusinessmanager::lang.field_context_notes_help')</p>
                        </div>

                        <button type="submit" class="btn btn-primary" style="background:var(--aibm-accent);border-color:var(--aibm-accent);">
                            @lang('aibusinessmanager::lang.settings_save')
                        </button>
                        <a href="{{ action([\Modules\AIBusinessManager\Http\Controllers\AssistantController::class, 'index']) }}" class="btn btn-default" style="margin-left:8px;">
                            @lang('aibusinessmanager::lang.back_to_chat')
                        </a>
                    </form>
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
