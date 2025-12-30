jQuery(document).ready(function($){
    if (typeof wsf_fields === 'undefined') return;

    function validateSearch() {
        var valid = true;
        $('.wsf-select').each(function(){
            if ($(this).data('req') == 1 && !$(this).val()) valid = false;
        });
        $('#wsf-btn').prop('disabled', !valid);
    }

    $('.wsf-select').on('change', function(){
        var current = $(this);
        var index = current.data('index');
        
        for(var i = index + 1; i < wsf_fields.length; i++) {
            $('.wsf-select[data-index="'+i+'"]').html('<option value="">...</option>').prop('disabled', true);
        }
        validateSearch();
        if(!current.val() || index === wsf_fields.length - 1) return;

        var nextTax = wsf_fields[index + 1];
        var nextSelect = $('.wsf-select[data-index="'+(index+1)+'"]');
        var selections = {};
        $('.wsf-select').each(function(){
            if($(this).data('index') <= index && $(this).val()) selections[$(this).data('tax')] = $(this).val();
        });

        nextSelect.parent().addClass('wsf-loading');
        
        $.post(wsf_vars.ajax_url, { action: 'wsf_get_next_terms', selections: selections, target: nextTax }, function(res){
            nextSelect.parent().removeClass('wsf-loading');
            if(res.success && Object.keys(res.data).length > 0) {
                var opts = '<option value="">اختار...</option>';
                $.each(res.data, function(slug, name){ opts += '<option value="'+slug+'">'+name+'</option>'; });
                nextSelect.html(opts).prop('disabled', false);
            } else {
                nextSelect.html('<option value="">مفيش خيارات</option>');
            }
        });
    });
});