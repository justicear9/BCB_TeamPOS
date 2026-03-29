/**
 * Stock adjustment create — same behaviour as stock_adjustment.js (product rows + totals)
 * but posts product rows to the Inventory Reporting module URL.
 */
$(document).ready(function () {
    var productRowUrl = $('#inv_rep_get_product_row_url').val() || '/inventory-reporting/adjustment/product-row';

    if ($('#search_product_for_srock_adjustment').length > 0) {
        $('#search_product_for_srock_adjustment')
            .autocomplete({
                source: function (request, response) {
                    $.getJSON('/products/list', { location_id: $('#location_id').val(), term: request.term }, response);
                },
                minLength: 2,
                response: function (event, ui) {
                    if (ui.content.length == 1) {
                        ui.item = ui.content[0];
                        if (ui.item.qty_available > 0 && ui.item.enable_stock == 1) {
                            $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                            $(this).autocomplete('close');
                        }
                    } else if (ui.content.length == 0) {
                        swal(LANG.no_products_found);
                    }
                },
                focus: function (event, ui) {
                    if (ui.item.qty_available <= 0) {
                        return false;
                    }
                },
                select: function (event, ui) {
                    if (ui.item.qty_available > 0) {
                        $(this).val(null);
                        inv_rep_stock_adjustment_product_row(ui.item.variation_id);
                    } else {
                        alert(LANG.out_of_stock);
                    }
                },
            })
            .autocomplete('instance')._renderItem = function (ul, item) {
                if (item.qty_available <= 0) {
                    var string = '<li class="ui-state-disabled">' + item.name;
                    if (item.type == 'variable') {
                        string += '-' + item.variation;
                    }
                    string += ' (' + item.sub_sku + ') (Out of stock) </li>';
                    return $(string).appendTo(ul);
                } else if (item.enable_stock != 1) {
                    return ul;
                } else {
                    var string = '<div>' + item.name;
                    if (item.type == 'variable') {
                        string += '-' + item.variation;
                    }
                    string += ' (' + item.sub_sku + ') </div>';
                    return $('<li>').append(string).appendTo(ul);
                }
            };
    }

    $('select#location_id').change(function () {
        if ($(this).val()) {
            $('#search_product_for_srock_adjustment').removeAttr('disabled');
        } else {
            $('#search_product_for_srock_adjustment').attr('disabled', 'disabled');
        }
        $('table#stock_adjustment_product_table tbody').html('');
        $('#product_row_index').val(0);
        inv_rep_update_table_total();
    });

    $(document).on('change', 'input.product_quantity', function () {
        inv_rep_update_table_row($(this).closest('tr'));
    });
    $(document).on('change', 'input.product_unit_price', function () {
        inv_rep_update_table_row($(this).closest('tr'));
    });

    $(document).on('click', '.remove_product_row', function () {
        var $row = $(this).closest('tr');
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function (willDelete) {
            if (willDelete) {
                $row.remove();
                inv_rep_update_table_total();
            }
        });
    });

    $('#transaction_date').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });

    $('form#inv_rep_stock_adjustment_form').validate();
});

function inv_rep_stock_adjustment_product_row(variation_id) {
    var row_index = parseInt($('#product_row_index').val());
    var location_id = $('select#location_id').val();
    var productRowUrl = $('#inv_rep_get_product_row_url').val() || '/inventory-reporting/adjustment/product-row';
    $.ajax({
        method: 'POST',
        url: productRowUrl,
        data: {
            row_index: row_index,
            variation_id: variation_id,
            location_id: location_id,
            _token: $('meta[name="csrf-token"]').attr('content'),
        },
        dataType: 'html',
        success: function (result) {
            $('table#stock_adjustment_product_table tbody').append(result);
            inv_rep_update_table_total();
            $('#product_row_index').val(row_index + 1);
            __currency_convert_recursively($('#stock_adjustment_product_table'));
        },
    });
}

function inv_rep_update_table_total() {
    var table_total = 0;
    $('table#stock_adjustment_product_table tbody tr').each(function () {
        var this_total = parseFloat(__read_number($(this).find('input.product_line_total')));
        if (this_total) {
            table_total += this_total;
        }
    });
    $('input#total_amount').val(table_total);
    $('span#total_adjustment').text(__number_f(table_total));
}

function inv_rep_update_table_row(tr) {
    var quantity = parseFloat(__read_number(tr.find('input.product_quantity')));
    var unit_price = parseFloat(__read_number(tr.find('input.product_unit_price')));
    var row_total = 0;
    if (quantity && unit_price) {
        row_total = quantity * unit_price;
    }
    tr.find('input.product_line_total').val(__number_f(row_total));
    inv_rep_update_table_total();
}
