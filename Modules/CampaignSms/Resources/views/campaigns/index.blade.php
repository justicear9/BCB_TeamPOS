@extends('layouts.app')
@section('title', __('campaignsms::lang.campaign_history'))

@section('content')
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => __('campaignsms::lang.campaign_history')])
                <p><strong>@lang('campaignsms::lang.sms_token_balance'):</strong> {{ $balance }}</p>
                @if(auth()->user()->can('campaignsms.send_bulk'))
                    <a href="{{ route('campaignsms.campaigns.create') }}" class="btn btn-primary">@lang('campaignsms::lang.bulk_sms')</a>
                @endif
                <form method="get" action="{{ route('campaignsms.campaigns.index') }}" class="form-inline" style="margin-top:15px;margin-bottom:10px;">
                    <div class="form-group" style="margin-right:8px;">
                        <label class="sr-only" for="campaign_search_q">@lang('lang_v1.search')</label>
                        <input type="search"
                            name="q"
                            id="campaign_search_q"
                            value="{{ $q ?? '' }}"
                            class="form-control"
                            style="min-width:280px;"
                            placeholder="@lang('campaignsms::lang.search_campaigns_placeholder')">
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-right:4px;">@lang('lang_v1.search')</button>
                    @if(!empty($q))
                        <a href="{{ route('campaignsms.campaigns.index') }}" class="btn btn-default">@lang('campaignsms::lang.clear_search')</a>
                    @endif
                </form>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>@lang('campaignsms::lang.campaign_name_optional')</th>
                                <th>@lang('campaignsms::lang.status')</th>
                                <th>@lang('campaignsms::lang.recipient_count')</th>
                                <th>@lang('campaignsms::lang.total_tokens')</th>
                                <th>@lang('campaignsms::lang.sent_at')</th>
                                <th class="text-center">@lang('messages.actions')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($campaigns as $c)
                                <tr>
                                    <td>{{ $c->id }}</td>
                                    <td>{{ $c->name ?: '—' }}</td>
                                    <td>{{ $c->status }}</td>
                                    <td>{{ $c->recipient_count }}</td>
                                    <td>{{ $c->total_tokens_charged }}</td>
                                    <td>{{ $c->created_at }}</td>
                                    <td class="text-center">
                                        <button type="button"
                                            class="btn btn-sm btn-info btn-view-campaign"
                                            data-url="{{ route('campaignsms.campaigns.show', $c->id) }}">
                                            @lang('campaignsms::lang.view_campaign_details')
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center">@lang('lang_v1.no_data')</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $campaigns->links() }}
            @endcomponent
        </div>
    </div>
</section>

<div class="modal fade" id="campaign_detail_modal" tabindex="-1" role="dialog" aria-labelledby="campaign_detail_modal_title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="campaign_detail_modal_title">@lang('campaignsms::lang.campaign_details')</h4>
            </div>
            <div class="modal-body">
                <p><strong>@lang('campaignsms::lang.campaign_name_optional')</strong></p>
                <p id="campaign_detail_name" style="white-space:pre-wrap;margin-bottom:1em;"></p>
                <p><strong>@lang('campaignsms::lang.audience')</strong></p>
                <p id="campaign_detail_audience" style="margin-bottom:1em;"></p>
                <p><strong>@lang('campaignsms::lang.message')</strong></p>
                <div class="well" id="campaign_detail_message" style="white-space:pre-wrap;max-height:320px;overflow:auto;margin-bottom:0;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">@lang('messages.close')</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascript')
<script type="text/javascript">
$(document).on('click', '.btn-view-campaign', function () {
    var url = $(this).data('url');
    var $btn = $(this);
    $btn.prop('disabled', true);
    $.ajax({
        url: url,
        type: 'GET',
        dataType: 'json',
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
    }).done(function (data) {
        var name = (data.name && String(data.name).trim() !== '') ? data.name : '—';
        $('#campaign_detail_name').text(name);
        $('#campaign_detail_audience').text(data.audience || '');
        $('#campaign_detail_message').text(data.message || '');
        $('#campaign_detail_modal').modal('show');
    }).fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : @json(__('messages.something_went_wrong'));
        alert(msg);
    }).always(function () {
        $btn.prop('disabled', false);
    });
});
</script>
@endsection
