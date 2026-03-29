@if(in_array($contact->type, ['customer', 'both']))
<div class="tab-pane" id="sms_refill_tab">
    <div class="row">
        <div class="col-md-12">
            <h4>@lang('campaignsms::lang.refill_reminders')</h4>
            <div class="table-responsive">
                <table class="table table-bordered" id="sms_refill_table">
                    <thead>
                        <tr>
                            <th>@lang('campaignsms::lang.product')</th>
                            <th>@lang('campaignsms::lang.interval_days')</th>
                            <th>@lang('campaignsms::lang.next_run')</th>
                            <th>@lang('sale.status')</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <hr>
            <h5>@lang('campaignsms::lang.add_reminder')</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>@lang('campaignsms::lang.product')</label>
                        <select id="refill_product_id" class="form-control" style="width:100%;"></select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>@lang('campaignsms::lang.interval_days')</label>
                        <input type="number" id="refill_interval" class="form-control" value="30" min="1">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>@lang('campaignsms::lang.next_run')</label>
                        <input type="date" id="refill_next_run" class="form-control" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label>@lang('campaignsms::lang.message') (@lang('lang_v1.optional'))</label>
                        <textarea id="refill_template_override" class="form-control" rows="2" placeholder="@lang('campaignsms::lang.placeholders_help')"></textarea>
                    </div>
                </div>
                <div class="col-md-12">
                    <button type="button" class="btn btn-primary" id="btn_add_refill">@lang('campaignsms::lang.save')</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<script type="text/javascript">
(function() {
    // This partial is rendered inside @yield('content') before layouts/partials/javascripts
    // loads vendor.js (jQuery + Select2). Do not call jQuery until it exists.
    var dataUrl = @json(route('campaignsms.refills.data', ['contact_id' => $contact->id]));
    var storeUrl = @json(route('campaignsms.refills.store', ['contact_id' => $contact->id]));
    var productsSearchUrl = @json(route('campaignsms.refills.products'));
    var destroyUrlBase = @json(url('/sms-campaigns/refills'));
    var bootAttempts = 0;
    var maxBootAttempts = 200;

    function bootCampaignSmsRefillTab() {
        bootAttempts++;
        if (typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.select2 !== 'function') {
            if (bootAttempts > maxBootAttempts) {
                console.error('CampaignSms refill tab: jQuery/Select2 did not load in time.');
                return;
            }
            setTimeout(bootCampaignSmsRefillTab, 50);
            return;
        }
        var $ = window.jQuery;
        if (window.__campaignSmsRefillTabReady) {
            return;
        }
        window.__campaignSmsRefillTabReady = true;

        var csrf = $('meta[name="csrf-token"]').attr('content');

        function formatRefillDate(val) {
            if (!val) return '';
            if (typeof val === 'string') {
                return val.length > 10 ? val.substring(0, 16).replace('T', ' ') : val;
            }
            return String(val);
        }

        function loadRefills() {
            $.ajax({
                url: dataUrl,
                dataType: 'json',
                success: function(res) {
                    var tbody = $('#sms_refill_table tbody');
                    tbody.empty();
                    if (!res || !res.data) return;
                    $.each(res.data, function(i, row) {
                        var prod = row.product ? row.product.name : '';
                        var active = row.is_active ? 'Active' : 'Off';
                        var tr = $('<tr/>');
                        tr.append('<td>' + $('<div/>').text(prod).html() + '</td>');
                        tr.append('<td>' + row.interval_days + '</td>');
                        tr.append('<td>' + formatRefillDate(row.next_run_at) + '</td>');
                        tr.append('<td>' + active + '</td>');
                        tr.append('<td><button type="button" class="btn btn-xs btn-danger btn-del-refill" data-id="' + row.id + '">@lang('messages.delete')</button></td>');
                        tbody.append(tr);
                    });
                },
                error: function(xhr) {
                    if (xhr.status === 403) {
                        console.warn('CampaignSms refills data: forbidden');
                    }
                }
            });
        }

        function initRefillProductSelect2() {
            var $el = $('#refill_product_id');
            if (!$el.length) return;

            if ($el.hasClass('select2-hidden-accessible')) {
                try {
                    $el.select2('destroy');
                } catch (e) {}
            }

            $el.select2({
                ajax: {
                    url: productsSearchUrl,
                    type: 'GET',
                    dataType: 'json',
                    delay: 200,
                    data: function(params) {
                        return { q: params.term || '' };
                    },
                    processResults: function(data) {
                        if (data && $.isArray(data.results)) {
                            return { results: data.results };
                        }
                        return { results: [] };
                    }
                },
                placeholder: @json(__('campaignsms::lang.product')),
                minimumInputLength: 0,
                allowClear: true,
                width: '100%',
                dropdownParent: $(document.body),
                minimumResultsForSearch: 0
            });
        }

        function onMedicineRefillTabVisible() {
            setTimeout(function() {
                initRefillProductSelect2();
                loadRefills();
            }, 100);
        }

        // Bootstrap 3: shown.bs.tab fires on the tab pane. Some builds differ — also handle link click.
        $('#sms_refill_tab').on('shown.bs.tab', function() {
            onMedicineRefillTabVisible();
        });
        $(document).on('click', 'a[href="#sms_refill_tab"]', function() {
            setTimeout(onMedicineRefillTabVisible, 200);
        });

        if ($('#sms_refill_tab').hasClass('active')) {
            onMedicineRefillTabVisible();
        }

        $('#btn_add_refill').click(function() {
            var pid = $('#refill_product_id').val();
            if (!pid) {
                alert(@json(__('campaignsms::lang.select_product_first')));
                return;
            }
            $.ajax({
                url: storeUrl,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                data: {
                    product_id: pid,
                    interval_days: $('#refill_interval').val(),
                    next_run_at: $('#refill_next_run').val(),
                    template_body: $('#refill_template_override').val()
                },
                success: function() {
                    $('#refill_product_id').val(null).trigger('change');
                    loadRefills();
                },
                error: function(xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : 'Error';
                    alert(msg);
                }
            });
        });

        $(document).on('click', '.btn-del-refill', function() {
            var id = $(this).data('id');
            if (!confirm('Delete this reminder?')) return;
            $.ajax({
                url: destroyUrlBase + '/' + id,
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf },
                success: function() { loadRefills(); }
            });
        });
    }

    bootCampaignSmsRefillTab();
})();
</script>
