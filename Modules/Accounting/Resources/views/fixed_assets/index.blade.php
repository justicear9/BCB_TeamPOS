@extends('layouts.app')

@section('title', __('accounting::lang.fixed_assets'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('accounting::lang.fixed_assets')</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ !empty(session('status')['success']) && session('status')['success'] ? 'success' : 'danger' }}">
            {{ session('status')['msg'] ?? '' }}
        </div>
    @endif

    <p>
        @can('accounting.manage_fixed_assets')
        <a href="{{ route('accounting.fixedAssets.create') }}" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm">@lang('messages.add')</a>
        @endcan
        @can('accounting.run_depreciation')
        <a href="{{ route('accounting.fixedAssets.depreciateForm') }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-sm">@lang('accounting::lang.run_depreciation')</a>
        @endcan
        @can('accounting.view_fixed_assets')
        @can('accounting.view_reports')
        <a href="{{ route('accounting.fixedAssets.schedule') }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-sm">@lang('accounting::lang.fixed_asset_schedule')</a>
        @endcan
        @endcan
    </p>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>@lang('accounting::lang.asset_account')</th>
                        <th>@lang('accounting::lang.fixed_asset_name')</th>
                        <th>@lang('accounting::lang.depreciates')</th>
                        <th>@lang('accounting::lang.location')</th>
                        <th>@lang('sale.cost')</th>
                        <th>@lang('accounting::lang.net_book_value')</th>
                        <th>@lang('accounting::lang.record_status')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assets as $asset)
                    <tr>
                        <td>{{ $asset->assetAccount->name ?? '—' }}</td>
                        <td>{{ $asset->name }}</td>
                        <td>{{ $asset->is_depreciable ? __('messages.yes') : __('accounting::lang.non_depreciable') }}</td>
                        <td>{{ $asset->location->name ?? '—' }}</td>
                        <td>@format_currency($asset->cost)</td>
                        <td>@format_currency($asset->netBookValue())</td>
                        <td>
                            @switch($asset->status)
                                @case('active')
                                    @lang('accounting::lang.fixed_asset_status_active')
                                    @break
                                @case('fully_depreciated')
                                    @lang('accounting::lang.fixed_asset_status_fully_depreciated')
                                    @break
                                @case('disposed')
                                    @lang('accounting::lang.fixed_asset_status_disposed')
                                    @break
                                @default
                                    {{ $asset->status }}
                            @endswitch
                        </td>
                        <td>
                            <a href="{{ route('accounting.fixedAssets.show', $asset->id) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline">@lang('messages.view')</a>
                            @can('accounting.manage_fixed_assets')
                            <a href="{{ route('accounting.fixedAssets.edit', $asset->id) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline">@lang('messages.edit')</a>
                            <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error delete_fixed_asset_button" data-href="{{ route('accounting.fixedAssets.destroy', $asset->id) }}">@lang('messages.delete')</button>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center">@lang('accounting::lang.no_records')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

@stop

@section('javascript')
<script type="text/javascript">
    $(document).on('click', '.delete_fixed_asset_button', function(e) {
        e.preventDefault();
        var href = $(this).data('href');
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(willDelete) {
            if (willDelete) {
                $.ajax({
                    method: 'DELETE',
                    url: href,
                    dataType: 'json',
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function(result) {
                        if (result.success == 1 || result.success === true) {
                            toastr.success(result.msg);
                            window.location.reload();
                        } else {
                            toastr.error(result.msg);
                        }
                    },
                });
            }
        });
    });
</script>
@endsection
