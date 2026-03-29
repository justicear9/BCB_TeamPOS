{{-- Injected only via DataController::get_pos_screen_view — keeps core POS blades unchanged. --}}
<script type="text/x-campaignsms-template" id="campaignsms_pos_refill_tpl">
<input type="hidden" name="products[__ROW__][campaignsms_add_refill]" value="0" class="campaignsms_add_refill_hidden">
<div class="campaignsms-refill-wrap text-muted" style="font-size:11px;margin-top:6px;">
	<label class="tw-mb-0" style="font-weight:normal;cursor:pointer;">
		<input type="checkbox" class="campaignsms_refill_cb" />
		{{ __('campaignsms::lang.pos_add_refill_reminder') }}
	</label>
	<span class="campaignsms-interval-wrap" style="display:none;white-space:nowrap;">
		<input type="number" name="products[__ROW__][campaignsms_interval_days]" class="campaignsms_interval_input input-sm" style="width:52px;display:inline-block;" min="1" max="3650" value="30" />
		{{ __('campaignsms::lang.days') }}
	</span>
</div>
</script>
<script type="text/javascript">
$(function () {
	var $tpl = $('#campaignsms_pos_refill_tpl');
	if (!$tpl.length) {
		return;
	}
	var tplHtml = $tpl.html();

	function campaignsmsGetRowIndex($tr) {
		var $inp = $tr.find('input[name*="[product_id]"]').first();
		var name = $inp.attr('name') || '';
		var m = name.match(/^products\[(\d+)\]\[product_id\]$/);
		return m ? m[1] : null;
	}

	function campaignsmsAttachRow($tr) {
		if ($tr.data('campaignsmsRefillAttached')) {
			return;
		}
		if ($tr.attr('data-so_id')) {
			return;
		}
		var idx = campaignsmsGetRowIndex($tr);
		if (!idx) {
			return;
		}
		if (!$tr.closest('#pos_table').length) {
			return;
		}
		var $anchor = $tr.find('input[name="products[' + idx + '][enable_stock]"]');
		if (!$anchor.length) {
			return;
		}
		$tr.data('campaignsmsRefillAttached', true);
		$anchor.after(tplHtml.replace(/__ROW__/g, idx));
	}

	function campaignsmsScanRows() {
		$('#pos_table tbody tr.product_row').each(function () {
			campaignsmsAttachRow($(this));
		});
	}

	campaignsmsScanRows();

	var tb = document.querySelector('#pos_table tbody');
	if (tb && typeof MutationObserver !== 'undefined') {
		var mo = new MutationObserver(function () {
			campaignsmsScanRows();
		});
		mo.observe(tb, { childList: true, subtree: true });
	}

	function campaignsmsPosToggleWalkIn() {
		var wid = $('#default_customer_id').val();
		var cid = $('#customer_id').val();
		var hide = cid && wid && String(cid) === String(wid);
		$('.campaignsms-refill-wrap').toggle(!hide);
	}

	$(document).on('change', '#customer_id', campaignsmsPosToggleWalkIn);
	$(document).on('change', '.campaignsms_refill_cb', function () {
		var row = $(this).closest('tr.product_row');
		var hidden = row.find('.campaignsms_add_refill_hidden');
		var wrap = row.find('.campaignsms-interval-wrap');
		if ($(this).is(':checked')) {
			hidden.val('1');
			wrap.show();
		} else {
			hidden.val('0');
			wrap.hide();
		}
	});

	campaignsmsPosToggleWalkIn();
});
</script>
