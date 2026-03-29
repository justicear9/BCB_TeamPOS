@if(in_array($contact->type, ['customer', 'both']))
<li>
    <a href="#sms_refill_tab" data-toggle="tab" aria-expanded="false">
        <i class="fas fa-pills" aria-hidden="true"></i> @lang('campaignsms::lang.refill_reminders')
    </a>
</li>
@endif
