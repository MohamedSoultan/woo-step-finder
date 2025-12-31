<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WSF_Search_Engine {

    public function __construct() {
        // التحميل
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public'));
        
        // الشورت كود
        add_shortcode('wsf_search', array($this, 'render_shortcode'));
        
        // Ajax
        add_action('wp_ajax_wsf_get_next_terms', array($this, 'ajax_handler'));
        add_action('wp_ajax_nopriv_wsf_get_next_terms', array($this, 'ajax_handler'));
        add_action('wp_ajax_wsf_get_variation_price', array($this, 'ajax_price_handler'));
        add_action('wp_ajax_nopriv_wsf_get_variation_price', array($this, 'ajax_price_handler'));
        
        // التوجيه
        add_action('template_redirect', array($this, 'smart_redirect_logic'));

        // بدائل البراند على صفحة المنتج
        add_action('woocommerce_before_add_to_cart_form', array($this, 'render_brand_alternatives'), 5);
    }

    // =========================================================
    // 1. منطق التوجيه الذكي (Smart Redirect Logic)
    // =========================================================
    public function smart_redirect_logic() {
        if ( !isset($_GET['wsf_search']) ) return;

        // جلب الإعدادات (السمات الرئيسية)
        $parent_level_attrs = get_option('wsf_parent_attributes', array());
        
        $parent_tax_query = ['relation' => 'AND'];
        $child_tax_query  = ['relation' => 'AND'];
        $url_params = [];
        $has_parent_filter = false;
        $has_child_filter = false;
        $size_selections = array();

        foreach ($_GET as $key => $value) {
            if (strpos($key, 'filter_') === 0 && !empty($value)) {
                $slug = sanitize_key(str_replace('filter_', '', $key));
                $taxonomy = 'pa_' . $slug;
                $term_value = sanitize_text_field(wp_unslash($value));
                
                // تصنيف الفلاتر: هل هي للأب (براند) أم للابن (مقاس)؟
                if (in_array($taxonomy, $parent_level_attrs)) {
                    $parent_tax_query[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term_value ];
                    $has_parent_filter = true;
                } else {
                    $child_tax_query[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term_value ];
                    $has_child_filter = true;
                    $size_selections[$taxonomy] = $term_value;
                }
                $url_params['attribute_' . $taxonomy] = $term_value;
            }
        }
        
        // لو مفيش مقاسات مختارة، وجه للمتجر
        if ( !$has_child_filter ) {
            $this->redirect_to_shop();
            return;
        }

        // البحث عن الآباء (Intersection Logic)
        $parent_ids = [];
        if ($has_parent_filter) {
            $parent_args = [ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => $parent_tax_query ];
            $parent_ids = get_posts($parent_args);
            if (empty($parent_ids)) { $this->redirect_to_shop(); return; }
        }

        // البحث عن الابن داخل الآباء
        $var_id = $this->find_first_variation_id($size_selections, $parent_ids);
        if ($var_id) {
            $parent_id = wp_get_post_parent_id($var_id);
            $link = get_permalink($parent_id);
            $redirect_url = add_query_arg($url_params, $link);
            wp_safe_redirect($redirect_url);
            exit;
        }
        
        // لم نجد تطابق 100% -> المتجر
        $this->redirect_to_shop();
    }
    
    private function redirect_to_shop() {
        $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
        $allowed = array();
        foreach ( $_GET as $key => $value ) {
            if ( strpos( $key, 'filter_' ) === 0 || strpos( $key, 'attribute_' ) === 0 ) {
                $allowed[$key] = is_scalar($value) ? sanitize_text_field(wp_unslash($value)) : '';
            }
        }
        $final_url = add_query_arg( $allowed, $shop_url );
        $final_url = remove_query_arg( 'wsf_search', $final_url );
        wp_safe_redirect( $final_url );
        exit;
    }

    // =========================================================
    // 2. تحميل ملفات Assets
    // =========================================================
    public function enqueue_admin($hook) {
        if ($hook != 'toplevel_page_wsf-settings') return;
        wp_enqueue_script('jquery-ui-sortable');
    }

    public function enqueue_public() {
        wp_enqueue_style('wsf-frontend-style', WSF_PLUGIN_URL . 'assets/css/wsf-frontend.css', array(), WSF_VERSION);
        wp_enqueue_script('wsf-frontend-script', WSF_PLUGIN_URL . 'assets/js/wsf-frontend.js', array('jquery'), WSF_VERSION, true);
        wp_localize_script('wsf-frontend-script', 'wsf_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsf_ajax'),
            'i18n' => array(
                'choose' => __('اختار...', 'woo-step-finder'),
                'loading' => __('جارٍ التحميل...', 'woo-step-finder'),
                'no_options' => __('مفيش خيارات', 'woo-step-finder'),
                'alt_title' => __('بدائل البراند', 'woo-step-finder'),
            ),
        ));
    }

    // =========================================================
    // 3. الشورت كود والواجهة
    // =========================================================
    public function render_shortcode($atts) {
        $fields = get_option('wsf_search_fields', array());
        $reqs = get_option('wsf_required_steps', array());
        if(empty($fields)) return '';

        wp_enqueue_script('wsf-frontend-script');
        wp_add_inline_script('wsf-frontend-script', 'var wsf_fields = ' . json_encode($fields) . ';', 'before');

        ob_start();
        ?>
        <div class="wsf-wrapper">
            <form action="<?php echo esc_url(home_url('/')); ?>" method="GET" id="wsf-form">
                <input type="hidden" name="wsf_search" value="1">
                <input type="hidden" name="post_type" value="product">
                <div class="wsf-flex">
                    <?php foreach($fields as $index => $tax): 
                        $label_raw = wc_attribute_label($tax);
                        $label = esc_html($label_raw);
                        $is_first = ($index === 0);
                        $is_required = in_array($tax, $reqs);
                        $terms = $is_first ? get_terms(['taxonomy'=>$tax, 'hide_empty'=>true]) : [];
                        $input_name = 'filter_' . str_replace('pa_', '', $tax);
                    ?>
                    <div class="wsf-group">
                        <label><?php echo $label; ?></label>
                        <select name="<?php echo esc_attr($input_name); ?>" class="wsf-select" data-tax="<?php echo esc_attr($tax); ?>" data-index="<?php echo esc_attr($index); ?>" data-req="<?php echo $is_required ? '1' : '0'; ?>" <?php echo $is_first ? '' : 'disabled'; ?>>
                            <option value=""><?php echo esc_html(sprintf(__('اختار %s...', 'woo-step-finder'), $label_raw)); ?></option>
                            <?php if($is_first && !is_wp_error($terms)): foreach($terms as $term): ?>
                                <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                    <div class="wsf-btn-wrap">
                        <button type="submit" class="wsf-submit" id="wsf-btn" disabled><?php echo esc_html__('بحث', 'woo-step-finder'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================
    // 4. لوحة التحكم (بالعربي)
    // =========================================================
    public function register_admin_page() {
        add_menu_page(__('إعدادات المحرك', 'woo-step-finder'), __('المحرك الذكي', 'woo-step-finder'), 'manage_options', 'wsf-settings', array($this, 'render_admin_view'), 'dashicons-filter', 50);
    }
    
    public function render_admin_view() {
        if (isset($_POST['wsf_save_settings']) && check_admin_referer('wsf_save_action', 'wsf_nonce')) {
            $all_attributes = wc_get_attribute_taxonomies();
            $valid_taxonomies = array();
            foreach ($all_attributes as $attr) {
                $valid_taxonomies[] = 'pa_' . $attr->attribute_name;
            }
            $active_fields = array_map('sanitize_text_field', wp_unslash($_POST['wsf_active_fields'] ?? array()));
            $reqs = array_map('sanitize_text_field', wp_unslash($_POST['wsf_reqs'] ?? array()));
            $parents = array_map('sanitize_text_field', wp_unslash($_POST['wsf_parent_attrs'] ?? array()));

            $active_fields = array_values(array_intersect($active_fields, $valid_taxonomies));
            $reqs = array_values(array_intersect($reqs, $active_fields));
            $parents = array_values(array_intersect($parents, $active_fields));

            update_option('wsf_search_fields', $active_fields);
            update_option('wsf_required_steps', $reqs);
            update_option('wsf_parent_attributes', $parents);

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('تمام يا ريس، الإعدادات اتحفظت بنجاح!', 'woo-step-finder') . '</p></div>';
        }
        $saved_fields = get_option('wsf_search_fields', array());
        $saved_reqs = get_option('wsf_required_steps', array());
        $saved_parents = get_option('wsf_parent_attributes', array());
        $all_attributes = wc_get_attribute_taxonomies();
        ?>
        <div class="wrap" dir="rtl" style="font-family: Tahoma, sans-serif;">
            <h1><?php echo esc_html(sprintf(__('إعدادات المحرك الذكي (V%s)', 'woo-step-finder'), WSF_VERSION)); ?></h1>
            <p><?php echo esc_html__('الشورت كود:', 'woo-step-finder'); ?> <code>[wsf_search]</code></p>
            <form method="post">
                <?php wp_nonce_field('wsf_save_action', 'wsf_nonce'); ?>
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <div style="flex:1; background:#fff; padding:15px; border:1px solid #ccc;">
                        <h3><?php echo esc_html__('خطوات البحث النشطة (اسحب ورتب)', 'woo-step-finder'); ?></h3>
                        <ul id="wsf-active" style="min-height:100px; padding:10px; background:#f9f9f9;">
                            <?php if(!empty($saved_fields)): foreach($saved_fields as $tax): $label = wc_attribute_label($tax); $is_req = in_array($tax, $saved_reqs); $is_parent = in_array($tax, $saved_parents); ?>
                                <li class="wsf-item" style="padding:10px; background:#fff; margin-bottom:5px; border:1px solid #ddd; cursor:move;">
                                    <b><?php echo esc_html($label); ?></b> (<?php echo esc_html($tax); ?>)
                                    <input type="hidden" name="wsf_active_fields[]" value="<?php echo esc_attr($tax); ?>">
                                    <div style="margin-top:5px; display:flex; gap:15px;">
                                        <label><input type="checkbox" name="wsf_reqs[]" value="<?php echo esc_attr($tax); ?>" <?php checked($is_req); ?>> <?php echo esc_html__('مطلوب؟', 'woo-step-finder'); ?></label>
                                        <label><input type="checkbox" name="wsf_parent_attrs[]" value="<?php echo esc_attr($tax); ?>" <?php checked($is_parent); ?>> <?php echo esc_html__('سمة رئيسية (أب)؟', 'woo-step-finder'); ?></label>
                                    </div>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    <div style="flex:1;">
                        <h3><?php echo esc_html__('السمات المتاحة (لإضافتها، يجب برمجتها لاحقاً أو إضافتها يدوياً هنا)', 'woo-step-finder'); ?></h3>
                        <ul><?php foreach($all_attributes as $attr): echo "<li>" . esc_html($attr->attribute_label) . " (pa_" . esc_html($attr->attribute_name) . ")</li>"; endforeach; ?></ul>
                    </div>
                </div>
                <br><button type="submit" name="wsf_save_settings" class="button button-primary"><?php echo esc_html__('حفظ التغييرات', 'woo-step-finder'); ?></button>
            </form>
            <script>jQuery("#wsf-active").sortable({axis:"y"});</script>
        </div>
        <?php
    }

    // =========================================================
    // 5. AJAX Handler
    // =========================================================
  // =========================================================
    // 5. AJAX Handler (المعدل لدعم سمات الأب)
    // =========================================================
    public function ajax_handler() {
        check_ajax_referer('wsf_ajax', 'nonce');
        // 1. استلام البيانات من الجافاسكريبت
        $selections = isset($_POST['selections']) ? (array) wp_unslash($_POST['selections']) : [];
        $target_taxonomy = isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '';

        if(empty($selections) || empty($target_taxonomy)) wp_send_json_error();

        // 2. (جديد) جلب الإعدادات عشان نعرف مين الأب ومين الابن
        $parent_level_attrs = get_option('wsf_parent_attributes', array());
        $active_fields = get_option('wsf_search_fields', array());
        if (!in_array($target_taxonomy, $active_fields, true)) wp_send_json_error();
        
        $parent_tax_query = ['relation' => 'AND'];
        $child_selections = array();
        $has_parent_filter = false;

        // 3. (جديد) فصل الفلاتر: هل نبحث عن أب أم ابن؟
        foreach($selections as $tax => $val) {
            $tax = sanitize_key($tax);
            $val = sanitize_text_field(wp_unslash($val));
            if (!in_array($tax, $active_fields, true)) continue;
            // لو السمة دي متحددة كـ "أب" في الإعدادات
            if (in_array($tax, $parent_level_attrs)) {
                $parent_tax_query[] = [ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $val ];
                $has_parent_filter = true;
            } else {
                // وإلا فهي سمة ابن (Variation)
                $child_selections[$tax] = $val;
            }
        }

        // 4. (جديد) لو في فلتر أب (زي البراند)، نجيب الآباء الأول
        $parent_ids = [];
        if ($has_parent_filter) {
            $parent_args = [ 
                'post_type' => 'product', 
                'post_status' => 'publish', 
                'fields' => 'ids', 
                'posts_per_page' => -1, 
                'tax_query' => $parent_tax_query 
            ];
            $parent_ids = get_posts($parent_args);

            // لو مفيش منتج أب بالمواصفات دي، يبقى مفيش داعي نكمل
            if (empty($parent_ids)) wp_send_json_error();
        }

        // 5. البحث عن الـ Variations (الأبناء)
        $args = [
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        $meta_query = $this->build_variation_meta_query($child_selections);
        if ( ! empty($meta_query) ) {
            $args['meta_query'] = $meta_query;
        }

        // أهم نقطة: لو جبنا آباء، نقول للبحث "دور جوا الآباء دول بس"
        if (!empty($parent_ids)) {
            $args['post_parent__in'] = $parent_ids;
        }

        $variation_ids = get_posts($args);

        if(empty($variation_ids)) wp_send_json_error();

        // 6. استخراج القيم المتاحة للحقل التالي (Target)
        $available_terms = [];
        foreach($variation_ids as $vid) {
            $product_object = wc_get_product($vid);
            if(!$product_object) continue;
            
            $attribute_value = $product_object->get_attribute($target_taxonomy);

            if ( !empty($attribute_value) ) {
                $term = get_term_by('name', $attribute_value, $target_taxonomy);
                if (!$term) $term = get_term_by('slug', $attribute_value, $target_taxonomy);

                if ($term && !is_wp_error($term)) {
                    $available_terms[$term->slug] = $term->name;
                } else {
                    $slug = sanitize_title($attribute_value);
                    $available_terms[$slug] = $attribute_value;
                }
            }
        }
        asort($available_terms); 
        wp_send_json_success($available_terms);
    }

    private function get_active_fields() {
        return get_option('wsf_search_fields', array());
    }

    private function get_parent_attributes() {
        return get_option('wsf_parent_attributes', array());
    }

    private function get_size_attributes() {
        $active = $this->get_active_fields();
        $parents = $this->get_parent_attributes();
        return array_values(array_diff($active, $parents));
    }

    private function get_size_selection_from_request($size_attrs) {
        $selections = array();
        foreach ($size_attrs as $tax) {
            $key = 'attribute_' . $tax;
            if (!empty($_GET[$key])) {
                $selections[$tax] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }
        return $selections;
    }

    private function find_first_variation_id($size_selections, $parent_ids = array()) {
        if (empty($size_selections)) return 0;
        $args = array(
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        $meta_query = $this->build_variation_meta_query($size_selections);
        if ( ! empty($meta_query) ) {
            $args['meta_query'] = $meta_query;
        }
        if (!empty($parent_ids)) {
            $args['post_parent__in'] = $parent_ids;
        }
        $ids = get_posts($args);
        return !empty($ids) ? (int) $ids[0] : 0;
    }

    private function get_parent_ids_by_size($size_selections) {
        if (empty($size_selections)) return array();
        $args = array(
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        $meta_query = $this->build_variation_meta_query($size_selections);
        if ( ! empty($meta_query) ) {
            $args['meta_query'] = $meta_query;
        }
        $variation_ids = get_posts($args);
        if (empty($variation_ids)) return array();
        $parent_ids = array();
        foreach ($variation_ids as $vid) {
            $parent_id = wp_get_post_parent_id($vid);
            if ($parent_id) $parent_ids[$parent_id] = $parent_id;
        }
        return array_values($parent_ids);
    }

    private function get_matching_variation_for_parent($parent_id, $size_selections) {
        if (empty($size_selections)) return 0;
        $args = array(
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_parent' => $parent_id
        );
        $meta_query = $this->build_variation_meta_query($size_selections);
        if ( ! empty($meta_query) ) {
            $args['meta_query'] = $meta_query;
        }
        $ids = get_posts($args);
        return !empty($ids) ? (int) $ids[0] : 0;
    }

    private function build_variation_meta_query($selections) {
        $meta_query = array();
        foreach ($selections as $tax => $val) {
            if (empty($val)) continue;
            $meta_query[] = array(
                'key' => 'attribute_' . $tax,
                'value' => $val,
                'compare' => '=',
            );
        }
        if (empty($meta_query)) return array();
        return array_merge(array('relation' => 'AND'), $meta_query);
    }

    public function render_brand_alternatives() {
        if ( ! is_product() ) return;

        global $product;
        if ( ! $product || ! $product->is_type('variable') ) return;

        $size_attrs = $this->get_size_attributes();
        if (empty($size_attrs)) return;

        $size_selections = $this->get_size_selection_from_request($size_attrs);
        if (empty($size_selections)) return;

        $parent_ids = $this->get_parent_ids_by_size($size_selections);
        if (empty($parent_ids)) return;

        $current_id = $product->get_id();
        $parent_ids = array_values(array_diff($parent_ids, array($current_id)));
        if (empty($parent_ids)) return;

        $parent_ids = array_slice($parent_ids, 0, 12);
        $parent_attrs = $this->get_parent_attributes();
        $brand_attr = !empty($parent_attrs) ? $parent_attrs[0] : '';

        $size_param_map = array();
        foreach ($size_selections as $tax => $val) {
            $size_param_map['attribute_' . $tax] = $val;
        }

        ?>
        <div class="wsf-alt-wrapper" data-size-attrs="<?php echo esc_attr(implode(',', $size_attrs)); ?>">
            <div class="wsf-alt-header">
                <h3 class="wsf-alt-title"><?php echo esc_html__('بدائل البراند', 'woo-step-finder'); ?></h3>
                <div class="wsf-alt-controls">
                    <button type="button" class="wsf-alt-prev" aria-label="<?php echo esc_attr__('السابق', 'woo-step-finder'); ?>">&larr;</button>
                    <button type="button" class="wsf-alt-next" aria-label="<?php echo esc_attr__('التالي', 'woo-step-finder'); ?>">&rarr;</button>
                </div>
            </div>
            <div class="wsf-alt-carousel">
                <?php foreach ($parent_ids as $pid): 
                    $alt_product = wc_get_product($pid);
                    if ( ! $alt_product ) continue;
                    $label = $brand_attr ? $alt_product->get_attribute($brand_attr) : '';
                    if (empty($label)) $label = $alt_product->get_name();
                    $url = add_query_arg($size_param_map, get_permalink($pid));
                ?>
                    <a class="wsf-alt-item" href="<?php echo esc_url($url); ?>" data-product-id="<?php echo esc_attr($pid); ?>">
                        <span class="wsf-alt-thumb"><?php echo wp_kses_post($alt_product->get_image('woocommerce_thumbnail')); ?></span>
                        <span class="wsf-alt-brand"><?php echo esc_html($label); ?></span>
                        <span class="wsf-alt-price" data-loading="1"><?php echo esc_html__('...', 'woo-step-finder'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function ajax_price_handler() {
        check_ajax_referer('wsf_ajax', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $selections = isset($_POST['selections']) ? (array) wp_unslash($_POST['selections']) : array();
        if ( ! $product_id || empty($selections) ) wp_send_json_error();

        $size_attrs = $this->get_size_attributes();
        $size_selections = array();
        foreach ($selections as $tax => $val) {
            $tax = sanitize_key($tax);
            $val = sanitize_text_field(wp_unslash($val));
            if (in_array($tax, $size_attrs, true)) {
                $size_selections[$tax] = $val;
            }
        }
        if (empty($size_selections)) wp_send_json_error();

        $variation_id = $this->get_matching_variation_for_parent($product_id, $size_selections);
        if ( ! $variation_id ) wp_send_json_error();

        $variation = wc_get_product($variation_id);
        if ( ! $variation ) wp_send_json_error();

        wp_send_json_success(array(
            'variation_id' => $variation_id,
            'price_html' => $variation->get_price_html(),
        ));
    }
}
