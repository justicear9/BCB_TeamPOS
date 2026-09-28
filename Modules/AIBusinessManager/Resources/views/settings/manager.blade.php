@php
    $locationSelect = function (string $name, $selected) use ($manager) {
        $html = '<select name="'.e($name).'" class="form-control input-sm"><option value="">'.e(__('aibusinessmanager::lang.mgr_whole_business')).'</option>';
        foreach ($manager['locations'] as $id => $label) {
            $html .= '<option value="'.e($id).'"'.((string) $selected === (string) $id ? ' selected' : '').'>'.e($label).'</option>';
        }

        return $html.'</select>';
    };
    $optionSelect = function (string $name, array $options, $selected) {
        $html = '<select name="'.e($name).'" class="form-control input-sm">';
        foreach ($options as $option) {
            $html .= '<option value="'.e($option).'"'.((string) $selected === (string) $option ? ' selected' : '').'>'.e(__('aibusinessmanager::lang.mgr_opt_'.$option)).'</option>';
        }

        return $html.'</select>';
    };
    $targets = array_merge($manager['targets'], [[], []]);
    $costs = array_merge($manager['costs'], [[], []]);
    $events = array_merge($manager['events'], [[], []]);
@endphp

<div class="box box-solid" style="border-radius:12px;border-color:rgba(var(--aibm-accent-rgb),0.25);margin-top:18px;">
    <div class="box-header with-border">
        <h3 class="box-title">@lang('aibusinessmanager::lang.mgr_heading')</h3>
        <p class="text-muted" style="margin:6px 0 0;font-size:13px;">@lang('aibusinessmanager::lang.mgr_intro')</p>
    </div>
    <div class="box-body" style="padding:18px;">
        @if (! $manager['ready'])
            <div class="alert alert-warning">@lang('aibusinessmanager::lang.mgr_not_ready')</div>
        @elseif (! $manager['can_edit'])
            <div class="alert alert-info">@lang('aibusinessmanager::lang.mgr_all_locations_only')</div>
        @else
            <form method="post" action="{{ action([\Modules\AIBusinessManager\Http\Controllers\SettingsController::class, 'updateManager']) }}" id="aibm-manager-form">
                {{ csrf_field() }}
                @method('PUT')

                <h4 style="margin-top:0;">@lang('aibusinessmanager::lang.mgr_targets')</h4>
                <p class="help-block text-muted">@lang('aibusinessmanager::lang.mgr_targets_help')</p>
                <div class="table-responsive">
                    <table class="table table-condensed aibm-mgr-table" data-prefix="targets">
                        <thead><tr><th>@lang('aibusinessmanager::lang.mgr_col_shop')</th><th>@lang('aibusinessmanager::lang.mgr_col_metric')</th><th>@lang('aibusinessmanager::lang.mgr_col_month')</th><th>@lang('aibusinessmanager::lang.mgr_col_value')</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($targets as $i => $t)
                                <tr>
                                    <td>{!! $locationSelect("targets[$i][location_id]", $t['location_id'] ?? '') !!}</td>
                                    <td>{!! $optionSelect("targets[$i][metric]", $manager['metrics'], $t['metric'] ?? 'revenue') !!}</td>
                                    <td><input type="month" name="targets[{{ $i }}][month]" class="form-control input-sm" value="{{ isset($t['month']) ? substr($t['month'], 0, 7) : now()->format('Y-m') }}"></td>
                                    <td><input type="number" step="0.01" name="targets[{{ $i }}][value]" class="form-control input-sm" value="{{ isset($t['value']) ? (float) $t['value'] : '' }}"></td>
                                    <td><button type="button" class="btn btn-link btn-xs aibm-mgr-remove">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-default btn-xs aibm-mgr-add" data-table="targets">@lang('aibusinessmanager::lang.mgr_add_row')</button>

                <h4 style="margin-top:24px;">@lang('aibusinessmanager::lang.mgr_costs')</h4>
                <p class="help-block text-muted">@lang('aibusinessmanager::lang.mgr_costs_help')</p>
                <div class="table-responsive">
                    <table class="table table-condensed aibm-mgr-table" data-prefix="costs">
                        <thead><tr><th>@lang('aibusinessmanager::lang.mgr_col_shop')</th><th>@lang('aibusinessmanager::lang.mgr_col_name')</th><th>@lang('aibusinessmanager::lang.mgr_col_category')</th><th>@lang('aibusinessmanager::lang.mgr_col_monthly')</th><th>@lang('aibusinessmanager::lang.mgr_col_from')</th><th>@lang('aibusinessmanager::lang.mgr_col_to')</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($costs as $i => $c)
                                <tr>
                                    <td>{!! $locationSelect("costs[$i][location_id]", $c['location_id'] ?? '') !!}</td>
                                    <td><input type="text" maxlength="120" name="costs[{{ $i }}][name]" class="form-control input-sm" value="{{ $c['name'] ?? '' }}" placeholder="@lang('aibusinessmanager::lang.mgr_cost_placeholder')"></td>
                                    <td>{!! $optionSelect("costs[$i][category]", $manager['categories'], $c['category'] ?? 'labour') !!}</td>
                                    <td><input type="number" step="0.01" min="0" name="costs[{{ $i }}][monthly_amount]" class="form-control input-sm" value="{{ isset($c['monthly_amount']) ? (float) $c['monthly_amount'] : '' }}"></td>
                                    <td><input type="date" name="costs[{{ $i }}][starts_on]" class="form-control input-sm" value="{{ $c['starts_on'] ?? '' }}"></td>
                                    <td><input type="date" name="costs[{{ $i }}][ends_on]" class="form-control input-sm" value="{{ $c['ends_on'] ?? '' }}"></td>
                                    <td><button type="button" class="btn btn-link btn-xs aibm-mgr-remove">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-default btn-xs aibm-mgr-add" data-table="costs">@lang('aibusinessmanager::lang.mgr_add_row')</button>

                <h4 style="margin-top:24px;">@lang('aibusinessmanager::lang.mgr_events')</h4>
                <p class="help-block text-muted">@lang('aibusinessmanager::lang.mgr_events_help')</p>
                <div class="table-responsive">
                    <table class="table table-condensed aibm-mgr-table" data-prefix="events">
                        <thead><tr><th>@lang('aibusinessmanager::lang.mgr_col_shop')</th><th>@lang('aibusinessmanager::lang.mgr_col_name')</th><th>@lang('aibusinessmanager::lang.mgr_col_kind')</th><th>@lang('aibusinessmanager::lang.mgr_col_from')</th><th>@lang('aibusinessmanager::lang.mgr_col_to')</th><th>@lang('aibusinessmanager::lang.mgr_col_effect')</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($events as $i => $ev)
                                <tr>
                                    <td>{!! $locationSelect("events[$i][location_id]", $ev['location_id'] ?? '') !!}</td>
                                    <td><input type="text" maxlength="160" name="events[{{ $i }}][name]" class="form-control input-sm" value="{{ $ev['name'] ?? '' }}" placeholder="@lang('aibusinessmanager::lang.mgr_event_placeholder')"></td>
                                    <td>{!! $optionSelect("events[$i][kind]", $manager['kinds'], $ev['kind'] ?? 'term') !!}</td>
                                    <td><input type="date" name="events[{{ $i }}][starts_on]" class="form-control input-sm" value="{{ $ev['starts_on'] ?? '' }}"></td>
                                    <td><input type="date" name="events[{{ $i }}][ends_on]" class="form-control input-sm" value="{{ $ev['ends_on'] ?? '' }}"></td>
                                    <td><input type="number" step="1" min="-100" max="500" name="events[{{ $i }}][effect_pct]" class="form-control input-sm" value="{{ isset($ev['effect_pct']) && $ev['effect_pct'] !== null ? (float) $ev['effect_pct'] : '' }}" placeholder="@lang('aibusinessmanager::lang.mgr_effect_placeholder')"></td>
                                    <td><button type="button" class="btn btn-link btn-xs aibm-mgr-remove">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-default btn-xs aibm-mgr-add" data-table="events">@lang('aibusinessmanager::lang.mgr_add_row')</button>

                <div style="margin-top:22px;">
                    <button type="submit" class="btn btn-primary" style="background:var(--aibm-accent);border-color:var(--aibm-accent);">@lang('aibusinessmanager::lang.settings_save')</button>
                </div>
            </form>
        @endif
    </div>
</div>

@if ($manager['ready'] && $manager['can_edit'])
<script>
(function () {
    var form = document.getElementById('aibm-manager-form');
    if (!form) { return; }
    form.addEventListener('click', function (e) {
        var remove = e.target.closest('.aibm-mgr-remove');
        if (remove) {
            var row = remove.closest('tr');
            row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            if (row.parentNode.children.length > 1) { row.parentNode.removeChild(row); }
            return;
        }
        var add = e.target.closest('.aibm-mgr-add');
        if (!add) { return; }
        var table = form.querySelector('.aibm-mgr-table[data-prefix="' + add.getAttribute('data-table') + '"] tbody');
        var last = table.lastElementChild;
        var clone = last.cloneNode(true);
        var next = table.children.length;
        clone.querySelectorAll('input, select').forEach(function (field) {
            field.name = field.name.replace(/\[\d+\]/, '[' + next + ']');
            if (field.tagName === 'INPUT' && field.type !== 'month') { field.value = ''; }
        });
        table.appendChild(clone);
    });
})();
</script>
@endif
