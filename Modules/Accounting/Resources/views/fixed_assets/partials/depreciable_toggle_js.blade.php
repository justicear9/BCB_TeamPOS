<script type="text/javascript">
    function faToggleDepreciationFields() {
        var on = $('#fa_is_depreciable').is(':checked');
        $('#fa_depreciation_fields').toggle(on);
        $('#fa_depreciation_fields').find('select.fa-dep-field, input.fa-dep-field').prop('disabled', !on);
    }
    $(document).ready(function () {
        faToggleDepreciationFields();
        $(document).on('change', '#fa_is_depreciable', faToggleDepreciationFields);
    });
</script>
