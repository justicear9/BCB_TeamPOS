# TeamPOS module hooks — reference

Grep the codebase for the authoritative list:

```bash
rg "getModuleData\\(\\s*['\\\"]" app/ Modules/ -g'*.php'
```

Below is a **non-exhaustive** inventory as of typical Ultimate POS / TeamPOS merges. Argument shapes vary—read the call site.

## DataController method names (alphabetical by area)

| Hook | Typical caller | Notes |
|------|----------------|-------|
| `addDocumentAndNotes` | `DocumentAndNoteController` | `$module_parameters` |
| `addTaxonomies` | `ModuleUtil` | Taxonomy data |
| `after_business_created` | `BusinessController`, Superadmin | `['business' => $business]` |
| `after_contact_saved` | `ContactController` | `['contact' => ..., 'input' => ...]` |
| `after_model_saved` / `afterModelSaved` | `ManageUserController`, `Util` | `['event' => 'user_saved', 'model_instance' => $user]` |
| `after_payment_status_updated` | `TransactionUtil` | `['transaction' => $transaction]` |
| `after_product_saved` | `ProductController` | `['product' => $product, 'request' => $request]` |
| `after_sale_saved` | `SellPosController`, `TransactionUtil` | `['transaction' => $transaction, 'input' => $input]` — **input may be empty** in some code paths |
| `after_sales` | `SellPosController` | After sale operations |
| `after_sales_return` | `SellReturnController` | `['transaction' => $sell_return]` |
| `calendarEvents` | `HomeController` | Calendar data |
| `contact_form_part` | `ContactController` | Form fragments |
| `dashboard_widget` | `HomeController` | Widgets array |
| `dummy_data` | `CreateDummyBusiness` command | Seeding |
| `eventTypes` | `HomeController` | Event types |
| `get_additional_script` | `AppServiceProvider` | Extra scripts |
| `getAssets` | `ModuleAssetServiceProvider` | `moduleAssets` css/js |
| `get_contact_view_tabs` | `ContactController` | `['contact' => $contact]` — return tab definitions |
| `get_filters_for_list_product_screen` | `ProductController` | POS-style filters |
| `getModuleOutputTax` | `ReportController` | Tax module output |
| `get_pos_screen_view` | `SellPosController` | `['sub_type' => ..., 'job_sheet_id' => ...]` — return rows with `module_js_path`, `view_data` |
| `get_product_screen_top_view` | `ProductController` | Top-of-screen partials |
| `getTaxReportViewTabs` | `ReportController` | Tax report tabs |
| `grossProfit` | `TransactionUtil` | Report hook |
| `InvoiceQrCode` | `TransactionUtil` | ZATCA etc.; optional third arg limits modules |
| `modifyAdminMenu` | `AdminSidebarMenu` middleware | Sidebar entries |
| `moduleViewPartials` | `ManageUserController` | `['view' => 'manage_user.create', ...]` |
| `notification_list` | `NotificationTemplateController` | `['notification_for' => 'customer'\|'supplier']` |
| `parse_notification` | `Util` | Parse notification payload |
| `product_form_fields` | `ProductController` | Extra fields |
| `product_form_part` | `ProductController` | Form partials |
| `profitLossReportData` | `TransactionUtil` | P&L module data |
| `superadmin_package` | Superadmin `PackagesController`, etc. | Package permissions JSON |

## Return shape conventions

- **`get_contact_view_tabs`**: Array of arrays, each with `tab_menu_path`, `tab_content_path`, optional `tab_data` (merged into `@include`).
- **`get_pos_screen_view`**: Array of arrays, each with `module_js_path` (blade in module), optional `view_data`.
- **`user_permissions`**: Array of `['value', 'label', 'default' => bool]`.
- **`modifyAdminMenu`**: Void; use `Menu` facade.

## POS view inclusion (core)

`resources/views/sale_pos/create.blade.php` and `edit.blade.php` iterate `$pos_module_data` from `getModuleData('get_pos_screen_view', ...)` and `@includeIf` each `module_js_path`.

## Contact view inclusion (core)

`resources/views/contact/show.blade.php` merges `getModuleData('get_contact_view_tabs', ...)` and includes `tab_menu_path` / `tab_content_path`.

## RoleController

`getModuleData('user_permissions')` merges module permissions into role edit UI.

## Install / version

`ModuleUtil::isModuleInstalled` requires:

1. `Module::has('Name')`
2. `System::getProperty(strtolower(name).'_version')` non-empty

Install flow should set version in `system` table.

## New module bootstrap (minimal)

1. `php artisan module:make YourModule` (if artisan available) **or** copy structure from `Modules/CampaignSms` / official docs.
2. `module.json` + `ServiceProvider` + `RouteServiceProvider` + `Routes/web.php`.
3. Create `Http/Controllers/DataController.php` with at least empty class (hooks added as needed).
4. Register permissions if any UI is permission-gated.
5. Run `composer dump-autoload` if needed.

## Files that should stay clean

- `app/Http/Controllers/SellPosController.php` — use hooks.
- `resources/views/sale_pos/partials/product_row.blade.php` — use injected JS.
- `resources/views/sale_pos/receipts/*.blade.php` — use view composers or minimal includes from module.

Allowed exception: **`app/Console/Kernel.php`** for `schedule()` entries (documented).
