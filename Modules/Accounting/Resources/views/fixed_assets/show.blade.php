@extends('layouts.app')

@section('title', $asset->name)

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>{{ $asset->name }}</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ !empty(session('status')['success']) && session('status')['success'] ? 'success' : 'danger' }}">
            {{ session('status')['msg'] ?? '' }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>@lang('sale.cost'):</strong> @format_currency($asset->cost)</p>
                    <p><strong>@lang('accounting::lang.opening_accumulated_depreciation'):</strong> @format_currency($asset->opening_accumulated_depreciation)</p>
                    <p><strong>@lang('accounting::lang.accumulated_depreciation_posted'):</strong> @format_currency($asset->accumulated_depreciation_posted)</p>
                    <p><strong>@lang('accounting::lang.accumulated_depreciation_total'):</strong> @format_currency($asset->totalAccumulatedDepreciation())</p>
                    <p><strong>@lang('accounting::lang.net_book_value'):</strong> @format_currency($asset->netBookValue())</p>
                    <p><strong>@lang('accounting::lang.location'):</strong> {{ $asset->location->name ?? '—' }}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>@lang('accounting::lang.asset_account'):</strong> {{ $asset->assetAccount->name ?? '—' }}</p>
                    @if($asset->is_depreciable)
                    <p><strong>@lang('accounting::lang.accumulated_depreciation_account'):</strong> {{ $asset->accumulatedDepreciationAccount->name ?? '—' }}</p>
                    <p><strong>@lang('accounting::lang.depreciation_expense_account'):</strong> {{ $asset->depreciationExpenseAccount->name ?? '—' }}</p>
                    @endif
                    <p><strong>@lang('accounting::lang.depreciates'):</strong>
                        @if($asset->is_depreciable)
                            @lang('messages.yes')
                        @else
                            @lang('accounting::lang.non_depreciable')
                        @endif
                    </p>
                    <p><strong>@lang('accounting::lang.record_status'):</strong>
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
                    </p>
                    @if($asset->disposed_at)
                    <p><strong>@lang('accounting::lang.disposed_at'):</strong> {{ \App\Utils\Util::bladeFormatDate($asset->disposed_at) }}</p>
                    @endif
                    <p><strong>@lang('accounting::lang.useful_life_months'):</strong>
                        {{ $asset->useful_life_months ?? '—' }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if($asset->acquisitionMapping)
    <div class="box box-solid">
        <div class="box-header"><h4 class="box-title">@lang('accounting::lang.acquisition_journal')</h4></div>
        <div class="box-body">
            <p>{{ $asset->acquisitionMapping->ref_no }} — {{ \App\Utils\Util::bladeFormatDate($asset->acquisitionMapping->operation_date) }}</p>
        </div>
    </div>
    @endif

    @if($asset->disposalMapping)
    <div class="box box-solid">
        <div class="box-header"><h4 class="box-title">@lang('accounting::lang.disposal_journal')</h4></div>
        <div class="box-body">
            <p>{{ $asset->disposalMapping->ref_no }} — {{ \App\Utils\Util::bladeFormatDate($asset->disposalMapping->operation_date) }}</p>
        </div>
    </div>
    @endif

    @if($asset->is_depreciable)
    <h4>@lang('accounting::lang.fixed_asset_depreciation')</h4>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>@lang('purchase.ref_no')</th>
                    <th>@lang('messages.date')</th>
                    <th>@lang('accounting::lang.depreciation_period')</th>
                </tr>
            </thead>
            <tbody>
                @forelse($asset->depreciationMappings as $m)
                <tr>
                    <td>{{ $m->ref_no }}</td>
                    <td>{{ $m->operation_date }}</td>
                    <td>{{ $m->depreciation_period }}</td>
                </tr>
                @empty
                <tr><td colspan="3">@lang('accounting::lang.no_records')</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @else
    <p class="text-muted">@lang('accounting::lang.depreciation_not_applicable')</p>
    @endif

    <p class="tw-mt-4">
        <a href="{{ route('accounting.fixedAssets.index') }}">@lang('messages.back')</a>
        @can('accounting.manage_fixed_assets')
            @if($asset->status !== 'disposed')
            <a href="{{ route('accounting.fixedAssets.edit', $asset->id) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline">@lang('messages.edit')</a>
            @endif
            @if(!$asset->acquisition_mapping_id && $asset->status !== 'disposed')
            <a href="{{ route('accounting.fixedAssets.postAcquisitionForm', $asset->id) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white">@lang('accounting::lang.post_acquisition')</a>
            @endif
            @if($asset->status !== 'disposed')
            <a href="{{ route('accounting.fixedAssets.disposeForm', $asset->id) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline">@lang('accounting::lang.dispose_fixed_asset')</a>
            @endif
            <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error delete_fixed_asset_button" data-href="{{ route('accounting.fixedAssets.destroy', $asset->id) }}">@lang('messages.delete')</button>
        @endcan
    </p>
</section>

@stop

@can('accounting.manage_fixed_assets')
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
                            window.location.href = "{{ route('accounting.fixedAssets.index') }}";
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
@endcan
