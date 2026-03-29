What is a module?
Modules are basically plugins/addons which help you add additional functionality to the core UltimatePOS Application.

We provide many official Modules which can be found in codecanyon & in our official shop.

UltimatePOS is a Point of sales & ERP application that provides all the basic functionality for any type of business.

There is every growing demand for many features to fulfill different business needs. So if you’re a developer having programming knowledge of Web Development & Laravel development then you can easily add new features to UltimatePOS.

Maintaining Modules:
Being a developer it’s your responsibility for the maintenance of modules.

Although the module development system of UltimatePOS is stable as of now, there might be some improvements, breaking & non-breaking changes in future releases. So it’s your responsibility to test & update the modules accordingly.

Modules Support:
For all modules developed by you, it’s your responsibility to provide support for it.

We’re only responsible for providing support for UltimatePOS & all modules developed by our team.

Selling Modules:
If you want to sell modules, you should sell them in codecanyon. Selling it on some other website is against our terms & conditions.

Feel free to decide your own price 🙂

Support for modules development
This document will provide you a comprehensive guide for module development. Beyond this document, it will be hard for us to help you with the module development.

When writing this document we assume you have expertise in Web development & Laravel Development.

UltimatePOS uses the Laravel-Module package for module development. So all the commands provided by this package will be used for the development.

Creating Module:
To create a module follow the commands as given in here

Let us assume you created a Modules called: Superhero

Config file:
Inside the new Modules which you created open the config file.

Superhero/Config/config.php

Add 2 configs here:

name
module_version
So it will be:

return [
‘name’ => ‘Superhero’,
‘module_version’ => “1.0”
];

Data Controller:
1. Create a controller with the name “DataController”.
2. DataController acts as the main controller for interacting with the POS.
3. It helps many different purposes as described in the further section.

Adding New permission related to modules:

1. Add function user_permissions() in the DataController which return multi-dimensional array containing arrays of all module permissions with key “value”, “label”, “default”
where value (string) is the name of the permission, label (string) is the label for the permission, and default (boolean) is the default checked or unchecked state of the permission on the rule add screen
Example:[ [ 'value' => 'superhero.create', 'label' => __('superhero::lang.add'), 'default' => false ], [ 'value' => 'superhero.update', 'label' => __('superhero::lang.edit'), 'default' => false ] ];

2. Add migrations to create the permissions in the module

Adding menu from modules:
1. Create a function modifyAdminMenu in DataController which returns the menu in this format:

Menu::modify(‘admin-sidebar-menu’, function ($menu) { $menu->dropdown(“Label”, function ($sub) { $sub->url( action(‘AnyController@index’), “Label”, [‘icon’ => ‘fa fa-list’, ‘active’ => “conditions to make menu active”)] ); } ) });

Menu::modify(‘admin-sidebar-menu’, function ($menu) { $menu->dropdown(“Label”, function ($sub) { $sub->url( action(‘AnyController@index’), “Label”, [‘icon’ => ‘fa fa-list’, ‘active’ => “conditions to make menu active”)] ); } ) });

2. Add “AdminSidebarMenu” middleware in routes.


Adding New taxonomy or category modules:

1. Define a function addTaxonomies() in the DataController which returns an array in this format:

return [ ‘hrm_department’ => [ ‘heading’ => __(‘superhero::lang.departments’), ‘sub_heading’ => __(‘superhero::lang.manage_departments’), ‘enable_taxonomy_code’ => true, ‘taxonomy_code_label’ => __(‘superhero::lang.department_id’), ‘taxonomy_code_help_text’ => __(‘superhero::lang.department_code_help’), ‘enable_sub_taxonomy’ => false ] ];

return [ ‘hrm_department’ => [ ‘heading’ => __(‘superhero::lang.departments’), ‘sub_heading’ => __(‘superhero::lang.manage_departments’), ‘enable_taxonomy_code’ => true, ‘taxonomy_code_label’ => __(‘superhero::lang.department_id’), ‘taxonomy_code_help_text’ => __(‘superhero::lang.department_code_help’), ‘enable_sub_taxonomy’ => false ] ];


2. Add the taxonomy to menu with url parameter taxonomy type as type, example:

$sub->url( action(‘TaxonomyController@index’) . ‘?type=hrm_department’, __(‘superhero::lang.departments’), [‘icon’ => ‘fa fa-tags’, ‘active’ => request()->get(‘type’) == ‘hrm_department’] );

$sub->url( action(‘TaxonomyController@index’) . ‘?type=hrm_department’, __(‘superhero::lang.departments’), [‘icon’ => ‘fa fa-tags’, ‘active’ => request()->get(‘type’) == ‘hrm_department’] );


Parsing notifications related to modules:

1. Define a function parse_notification($notification) in the DataController which has a notification instance as an argument

2. Check the type of notification and return the formated notification data as an array. For example:

if ($notification->type == ‘Modules\Superhero\Notifications\DocumentShareNotification’) { $notification_data = [ ‘msg’ => “One document has been shared”, ‘icon_class’ => ‘fa fa-envelope-open’, ‘link’ => action(‘\Modules\Superhero\Http\Controllers\DocumentController@index’), ‘read_at’ => $notification->read_at, ‘created_at’ => $notification->created_at->diffForHumans() ]; }

if ($notification->type == ‘Modules\Superhero\Notifications\DocumentShareNotification’) { $notification_data = [ ‘msg’ => “One document has been shared”, ‘icon_class’ => ‘fa fa-envelope-open’, ‘link’ => action(‘\Modules\Superhero\Http\Controllers\DocumentController@index’), ‘read_at’ => $notification->read_at, ‘created_at’ => $notification->created_at->diffForHumans() ]; }


NOTE: To display a notification you should save the notification in the database using toDatabase() method in the Notification class