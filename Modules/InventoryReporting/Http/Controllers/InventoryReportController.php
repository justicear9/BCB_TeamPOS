<?php

namespace Modules\InventoryReporting\Http\Controllers;

use App\Brands;
use App\Category;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\InventoryReporting\Exports\InventoryAgeingExport;
use Modules\InventoryReporting\Exports\StockAsAtExport;
use Modules\InventoryReporting\Services\InventoryReportQueryService;

class InventoryReportController extends Controller
{
    public function __construct(protected ProductUtil $productUtil)
    {
    }

    public function ageing(Request $request, InventoryReportQueryService $reports)
    {
        if (! auth()->user()->can('inventoryreporting.reports')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');
        $locations = \App\BusinessLocation::forDropdown($business_id, false, false);

        $filters = $this->buildAgeingFilters($request);

        $rows = $reports->paginatedInventoryAgeing($business_id, $filters, 25);
        $rows->appends($request->query());

        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);

        $df = session('business.date_format', 'Y-m-d');
        $filter_received_from_display = ! empty($filters['received_from'])
            ? Carbon::parse($filters['received_from'])->format($df) : '';
        $filter_received_to_display = ! empty($filters['received_to'])
            ? Carbon::parse($filters['received_to'])->format($df) : '';

        return view('inventoryreporting::reports.ageing', compact(
            'rows',
            'locations',
            'filters',
            'categories',
            'brands',
            'filter_received_from_display',
            'filter_received_to_display'
        ));
    }

    public function exportAgeing(Request $request, InventoryReportQueryService $reports)
    {
        if (! auth()->user()->can('inventoryreporting.reports')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');
        $filters = $this->buildAgeingFilters($request);
        $collection = $reports->inventoryAgeingCollection($business_id, $filters);

        return Excel::download(
            new InventoryAgeingExport($collection),
            'inventory-ageing-'.date('Y-m-d-His').'.xlsx'
        );
    }

    public function stockAsAt(Request $request, InventoryReportQueryService $reports)
    {
        if (! auth()->user()->can('inventoryreporting.reports')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');

        $as_at_raw = $request->input('as_at', Carbon::now()->format('Y-m-d'));
        $as_at = $this->parseReportDate($as_at_raw) ?? Carbon::now()->format('Y-m-d');

        $location_id = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $detailed = (int) $request->input('detailed', 0) === 1;

        $permitted = auth()->user()->permitted_locations();
        $rows = $reports->stockAsAtDate($business_id, $as_at, $location_id, $permitted, $detailed);
        $locations = \App\BusinessLocation::forDropdown($business_id, false, false);

        $dateFormat = session('business.date_format', 'Y-m-d');
        $as_at_display = Carbon::parse($as_at)->format($dateFormat);

        return view('inventoryreporting::reports.stock_as_at', compact(
            'rows',
            'locations',
            'location_id',
            'as_at',
            'as_at_display',
            'detailed'
        ));
    }

    public function exportStockAsAt(Request $request, InventoryReportQueryService $reports)
    {
        if (! auth()->user()->can('inventoryreporting.reports')) {
            abort(403, 'Unauthorized action.');
        }

        $moduleUtil = app(ModuleUtil::class);
        if (! $moduleUtil->isModuleInstalled('InventoryReporting')) {
            abort(404);
        }

        $business_id = (int) session()->get('user.business_id');

        $as_at_raw = $request->input('as_at', Carbon::now()->format('Y-m-d'));
        $as_at = $this->parseReportDate($as_at_raw) ?? Carbon::now()->format('Y-m-d');

        $location_id = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $detailed = (int) $request->input('detailed', 0) === 1;

        $permitted = auth()->user()->permitted_locations();
        $rows = $reports->stockAsAtDate($business_id, $as_at, $location_id, $permitted, $detailed);
        $includeCosts = auth()->user()->can('view_purchase_price');

        return Excel::download(
            new StockAsAtExport($rows, $detailed, $includeCosts),
            'stock-as-at-'.date('Y-m-d-His').'.xlsx'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAgeingFilters(Request $request): array
    {
        $filters = [
            'location_id' => $request->input('location_id'),
            'search' => $request->input('search'),
            'category_id' => $request->input('category_id'),
            'brand_id' => $request->input('brand_id'),
            'min_days' => $request->input('min_days'),
            'max_days' => $request->input('max_days'),
        ];

        if ($request->filled('received_from')) {
            $filters['received_from'] = $this->parseReportDate($request->input('received_from'));
        }
        if ($request->filled('received_to')) {
            $filters['received_to'] = $this->parseReportDate($request->input('received_to'));
        }

        return $filters;
    }

    /**
     * Normalize date input from datepicker or plain Y-m-d.
     */
    protected function parseReportDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = $this->productUtil->uf_date($value, false);
            if (! empty($parsed)) {
                return Carbon::parse($parsed)->format('Y-m-d');
            }
        } catch (\Throwable $e) {
            // fall through
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e2) {
            return null;
        }
    }
}
