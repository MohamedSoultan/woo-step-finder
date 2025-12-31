jQuery(document).ready(function($){
    var i18n = (typeof wsf_vars !== 'undefined' && wsf_vars.i18n) ? wsf_vars.i18n : {};
    var textChoose = i18n.choose || 'اختار...';
    var textLoading = i18n.loading || 'جارٍ التحميل...';
    var textNoOptions = i18n.no_options || 'مفيش خيارات';

    if (typeof wsf_fields !== 'undefined') {
        function validateSearch() {
            var valid = true;
            $('.wsf-select').each(function(){
                if ($(this).data('req') == 1 && !$(this).val()) valid = false;
            });
            $('#wsf-btn').prop('disabled', !valid);
        }

        validateSearch();

        $('.wsf-select').on('change', function(){
            var current = $(this);
            var index = current.data('index');
            
            for(var i = index + 1; i < wsf_fields.length; i++) {
                $('.wsf-select[data-index="'+i+'"]').html('<option value="">'+textChoose+'</option>').prop('disabled', true);
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
            nextSelect.html('<option value="">'+textLoading+'</option>');

            $.post(wsf_vars.ajax_url, { action: 'wsf_get_next_terms', selections: selections, target: nextTax, nonce: wsf_vars.nonce }, function(res){
                nextSelect.parent().removeClass('wsf-loading');
                if(res.success && Object.keys(res.data).length > 0) {
                    var opts = '<option value="">'+textChoose+'</option>';
                    $.each(res.data, function(slug, name){ opts += '<option value="'+slug+'">'+name+'</option>'; });
                    nextSelect.html(opts).prop('disabled', false);
                } else {
                    nextSelect.html('<option value="">'+textNoOptions+'</option>');
                }
            });
        });
    }

    var $altWrapper = $('.wsf-alt-wrapper');
    if ($altWrapper.length) {
        var sizeAttrs = ($altWrapper.data('size-attrs') || '').split(',').filter(Boolean);
        var params = new URLSearchParams(window.location.search);
        var sizeSelections = {};
        sizeAttrs.forEach(function(tax){
            var key = 'attribute_' + tax;
            if (params.get(key)) sizeSelections[tax] = params.get(key);
        });

        $('.wsf-alt-item').each(function(){
            var $item = $(this);
            var productId = $item.data('product-id');
            if (!productId || Object.keys(sizeSelections).length === 0) return;
            $.post(wsf_vars.ajax_url, {
                action: 'wsf_get_variation_price',
                nonce: wsf_vars.nonce,
                product_id: productId,
                selections: sizeSelections
            }, function(res){
                var $price = $item.find('.wsf-alt-price');
                if (res.success && res.data && res.data.price_html) {
                    $price.html(res.data.price_html).attr('data-loading', '0');
                } else {
                    $price.text(textNoOptions).attr('data-loading', '0');
                }
            });
        });

        var $carousel = $('.wsf-alt-carousel');
        $('.wsf-alt-prev').on('click', function(){
            $carousel.animate({scrollLeft: $carousel.scrollLeft() - 240}, 200);
        });
        $('.wsf-alt-next').on('click', function(){
            $carousel.animate({scrollLeft: $carousel.scrollLeft() + 240}, 200);
        });
    }
});
