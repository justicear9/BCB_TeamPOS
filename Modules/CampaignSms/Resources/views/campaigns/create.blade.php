@extends('layouts.app')
@section('title', __('campaignsms::lang.bulk_sms'))

@section('content')
<section class="content">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            @component('components.widget', ['class' => 'box-primary', 'title' => __('campaignsms::lang.bulk_sms')])
                @if(!$sms_ok)
                    <div class="alert alert-danger">@lang('campaignsms::lang.configure_sms_in_business_settings')</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <p><strong>@lang('campaignsms::lang.sms_token_balance'):</strong> <span id="token_balance">{{ $balance }}</span></p>
                {!! Form::open(['route' => 'campaignsms.campaigns.store', 'method' => 'post', 'id' => 'bulk_sms_form']) !!}
                <div class="form-group">
                    {!! Form::label('name', __('campaignsms::lang.campaign_name_optional')) !!}
                    {!! Form::text('name', null, ['class' => 'form-control']) !!}
                </div>
                <div class="form-group">
                    {!! Form::label('audience_type', __('campaignsms::lang.audience')) !!}
                    {!! Form::select('audience_type', [
                        'all_customers' => __('campaignsms::lang.all_customers'),
                        'customer_group' => __('campaignsms::lang.customer_group'),
                        'specific_contacts' => __('campaignsms::lang.specific_contacts'),
                    ], 'all_customers', ['class' => 'form-control', 'id' => 'audience_type']) !!}
                </div>
                <div class="form-group" id="group_wrap" style="display:none;">
                    {!! Form::label('customer_group_id', __('lang_v1.customer_group')) !!}
                    {!! Form::select('customer_group_id', $customer_groups, null, ['class' => 'form-control select2', 'id' => 'customer_group_id', 'style' => 'width:100%;']) !!}
                </div>
                <div class="form-group" id="contacts_wrap" style="display:none;">
                    {!! Form::label('contact_ids', __('campaignsms::lang.specific_contacts')) !!}
                    {!! Form::select('contact_ids[]', [], null, ['class' => 'form-control', 'id' => 'contact_ids', 'multiple' => true, 'style' => 'width:100%;']) !!}
                </div>
                <div class="form-group">
                    {!! Form::label('body', __('campaignsms::lang.message')) !!}
                    {!! Form::textarea('body', old('body'), ['class' => 'form-control', 'rows' => 5, 'id' => 'sms_body', 'required' => true]) !!}
                </div>
                <p class="help-block">
                    @lang('campaignsms::lang.character_count'): <span id="char_count">0</span>
                    &mdash; @lang('campaignsms::lang.segments'): <span id="seg_count">1</span>
                    &mdash; @lang('campaignsms::lang.recipients'): <span id="rec_count">0</span>
                    &mdash; @lang('campaignsms::lang.estimated_tokens'): <span id="est_tokens">0</span>
                </p>
                <p class="help-block text-muted small">@lang('campaignsms::lang.bulk_placeholders_help')</p>
                <button type="submit" class="btn btn-primary" id="submit_btn" @if(!$sms_ok) disabled @endif>@lang('campaignsms::lang.send_sms')</button>
                <a href="{{ route('campaignsms.campaigns.index') }}" class="btn btn-default">@lang('messages.cancel')</a>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    var SEG_LEN = 159;
    function segmentCount(text) {
        var len = text ? text.length : 0;
        return Math.max(1, Math.ceil(len / SEG_LEN));
    }
    function charCount(text) {
        return text ? text.length : 0;
    }
    function refreshAudienceCount() {
        $.ajax({
            url: '{{ route('campaignsms.audience-count') }}',
            data: {
                audience_type: $('#audience_type').val(),
                customer_group_id: $('#customer_group_id').val(),
                contact_ids: $('#contact_ids').val() ? $('#contact_ids').val().join(',') : ''
            },
            success: function(data) {
                $('#rec_count').text(data.count);
                updateEstTokens();
            }
        });
    }
    function updateEstTokens() {
        var raw = $('#sms_body').val() || '';
        $('#char_count').text(charCount(raw));
        var seg = segmentCount(raw);
        $('#seg_count').text(seg);
        var rec = parseInt($('#rec_count').text(), 10) || 0;
        $('#est_tokens').text(seg * rec);
    }
    $(document).ready(function() {
        $('#customer_group_id').select2({ width: '100%' });
        $('#contact_ids').select2({
            ajax: {
                url: '{{ route('campaignsms.contacts.search') }}',
                dataType: 'json',
                delay: 250,
                data: function(params) { return { q: params.term }; },
                processResults: function(data) { return data; }
            },
            placeholder: 'Search customers',
            minimumInputLength: 0
        });
        $('#audience_type').change(function() {
            $('#group_wrap').toggle($(this).val() === 'customer_group');
            $('#contacts_wrap').toggle($(this).val() === 'specific_contacts');
            refreshAudienceCount();
        });
        $('#customer_group_id').change(refreshAudienceCount);
        $('#contact_ids').on('change', refreshAudienceCount);
        $('#sms_body').on('input', updateEstTokens);
        refreshAudienceCount();
        updateEstTokens();
    });
</script>
@endsection
