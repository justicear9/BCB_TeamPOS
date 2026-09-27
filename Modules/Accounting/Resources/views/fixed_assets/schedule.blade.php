@extends('layouts.app')

@section('title', __('accounting::lang.fixed_asset_schedule'))

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('accounting::lang.fixed_asset_schedule')</h1>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            {!! Form::open(['route' => 'accounting.fixedAssets.schedule', 'method' => 'get', 'class' => 'form-inline']) !!}
            <div class="form-group tw-mb-2">
                {!! Form::label('location_id', __('accounting::lang.location')) !!}
                {!! Form::select('location_id', $business_locations, $location_id, ['class' => 'form-control select2', 'placeholder' => __('accounting::lang.all_locations'), 'style' => 'width:240px']); !!}
            </div>
            <div class="form-group tw-mb-2 tw-ml-2">
                {!! Form::label('status', __('accounting::lang.record_status')) !!}
                {!! Form::select('status', [
                    '' => __('accounting::lang.all'),
                    'active' => __('accounting::lang.fixed_asset_status_active'),
                    'fully_depreciated' => __('accounting::lang.fixed_asset_status_fully_depreciated'),
                    'disposed' => __('accounting::lang.fixed_asset_status_disposed'),
                ], $status, ['class' => 'form-control', 'style' => 'width:180px']); !!}
            </div>
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-ml-2">@lang('report.filters')</button>
            <a href="{{ route('accounting.fixedAssets.scheduleExport', request()->only(['location_id', 'status'])) }}" class="tw-dw-btn tw-dw-btn-outline tw-ml-2">@lang('accounting::lang.export_to_excel')</a>
            {!! Form::close() !!}
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>@lang('accounting::lang.fixed_asset_code')</th>
                        <th>@lang('accounting::lang.fixed_asset_name')</th>
                        <th>@lang('accounting::lang.location')</th>
                        <th>@lang('sale.cost')</th>
                        <th>@lang('accounting::lang.opening_accumulated_depreciation')</th>
                        <th>@lang('accounting::lang.accumulated_depreciation_posted')</th>
                        <th>@lang('accounting::lang.accumulated_depreciation_total')</th>
                        <th>@lang('accounting::lang.net_book_value')</th>
                        <th>@lang('accounting::lang.acquisition_date')</th>
                        <th>@lang('accounting::lang.useful_life_months')</th>
                        <th>@lang('accounting::lang.depreciates')</th>
                        <th>@lang('accounting::lang.record_status')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assets as $a)
                    <tr>
                        <td>{{ $a->asset_code ?? '—' }}</td>
                        <td>{{ $a->name }}</td>
                        <td>{{ $a->location->name ?? '—' }}</td>
                        <td>@format_currency($a->cost)</td>
                        <td>@format_currency($a->opening_accumulated_depreciation)</td>
                        <td>@format_currency($a->accumulated_depreciation_posted)</td>
                        <td>@format_currency($a->totalAccumulatedDepreciation())</td>
                        <td>@format_currency($a->netBookValue())</td>
                        <td>{{ \App\Utils\Util::bladeFormatDate($a->acquisition_date) }}</td>
                        <td>{{ $a->useful_life_months ?? '—' }}</td>
                        <td>{{ $a->is_depreciable ? __('messages.yes') : __('accounting::lang.non_depreciable') }}</td>
                        <td>
                            @switch($a->status)
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
                                    {{ $a->status }}
                            @endswitch
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="12" class="text-center">@lang('accounting::lang.no_records')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

@stop

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        if ($('.select2').length) {
            $('.select2').select2();
        }
    });
</script>
@endsection
