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
        
        // التوجيه
        add_action('template_redirect', array($this, 'smart_redirect_logic'));
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

        foreach ($_GET as $key => $value) {
            if (strpos($key, 'filter_') === 0 && !empty($value)) {
                $slug = str_replace('filter_', '', $key);
                $taxonomy = 'pa_' . $slug;
                $term_value = sanitize_text_field($value);
                
                // تصنيف الفلاتر: هل هي للأب (براند) أم للابن (مقاس)؟
                if (in_array($taxonomy, $parent_level_attrs)) {
                    $parent_tax_query[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term_value ];
                    $has_parent_filter = true;
                } else {
                    $child_tax_query[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term_value ];
                }
                $url_params['attribute_' . $taxonomy] = $term_value;
            }
        }
        
        // لو مفيش سمات رئيسية مختارة (زي البراند)، وجه للمتجر
        if ( !empty($parent_level_attrs) && !$has_parent_filter ) {
            $this->redirect_to_shop();
            return;
        }

        // البحث عن الآباء (Intersection Logic)
        $parent_ids = [];
        if ($has_parent_filter) {
            $parent_args = [ 'post_type' => 'product', 'status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => $parent_tax_query ];
            $parent_ids = get_posts($parent_args);
            if (empty($parent_ids)) { $this->redirect_to_shop(); return; }
        }

        // البحث عن الابن داخل الآباء
        $variation_args = [ 'post_type' => 'product_variation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => $child_tax_query ];
        if (!empty($parent_ids)) { $variation_args['post_parent__in'] = $parent_ids; }
        
        $variations = get_posts($variation_args);

        if (!empty($variations)) {
            $var_id = $variations[0];
            $parent_id = wp_get_post_parent_id($var_id);
            $link = get_permalink($parent_id);
            $redirect_url = add_query_arg($url_params, $link);
            wp_redirect($redirect_url);
            exit;
        }
        
        // لم نجد تطابق 100% -> المتجر
        $this->redirect_to_shop();
    }
    
    private function redirect_to_shop() {
        $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
        $final_url = add_query_arg( $_GET, $shop_url );
        $final_url = remove_query_arg( 'wsf_search', $final_url );
        wp_redirect( $final_url );
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
        wp_localize_script('wsf-frontend-script', 'wsf_vars', array('ajax_url' => admin_url('admin-ajax.php')));
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
            <form action="<?php echo home_url('/'); ?>" method="GET" id="wsf-form">
                <input type="hidden" name="wsf_search" value="1">
                <input type="hidden" name="post_type" value="product">
                <div class="wsf-flex">
                    <?php foreach($fields as $index => $tax): 
                        $label = wc_attribute_label($tax);
                        $is_first = ($index === 0);
                        $is_required = in_array($tax, $reqs);
                        $terms = $is_first ? get_terms(['taxonomy'=>$tax, 'hide_empty'=>true]) : [];
                        $input_name = 'filter_' . str_replace('pa_', '', $tax);
                    ?>
                    <div class="wsf-group">
                        <label><?php echo $label; ?></label>
                        <select name="<?php echo $input_name; ?>" class="wsf-select" data-tax="<?php echo $tax; ?>" data-index="<?php echo $index; ?>" data-req="<?php echo $is_required ? '1' : '0'; ?>" <?php echo $is_first ? '' : 'disabled'; ?>>
                            <option value="">اختار <?php echo $label; ?>...</option>
                            <?php if($is_first && !is_wp_error($terms)): foreach($terms as $term): ?>
                                <option value="<?php echo $term->slug; ?>"><?php echo $term->name; ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                    <div class="wsf-btn-wrap">
                        <button type="submit" class="wsf-submit" id="wsf-btn" disabled>بحث</button>
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
        add_menu_page('إعدادات المحرك', 'المحرك الذكي', 'manage_options', 'wsf-settings', array($this, 'render_admin_view'), 'dashicons-filter', 50);
    }
    
    public function render_admin_view() {
        if (isset($_POST['wsf_save_settings']) && check_admin_referer('wsf_save_action', 'wsf_nonce')) {
            update_option('wsf_search_fields', $_POST['wsf_active_fields'] ?? []);
            update_option('wsf_required_steps', $_POST['wsf_reqs'] ?? []);
            update_option('wsf_parent_attributes', $_POST['wsf_parent_attrs'] ?? []);
            echo '<div class="notice notice-success is-dismissible"><p>تمام يا ريس، الإعدادات اتحفظت بنجاح!</p></div>';
        }
        $saved_fields = get_option('wsf_search_fields', array());
        $saved_reqs = get_option('wsf_required_steps', array());
        $saved_parents = get_option('wsf_parent_attributes', array());
        $all_attributes = wc_get_attribute_taxonomies();
        ?>
        <div class="wrap" dir="rtl" style="font-family: Tahoma, sans-serif;">
            <h1>إعدادات المحرك الذكي (V1.0)</h1>
            <p>الشورت كود: <code>[wsf_search]</code></p>
            <form method="post">
                <?php wp_nonce_field('wsf_save_action', 'wsf_nonce'); ?>
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <div style="flex:1; background:#fff; padding:15px; border:1px solid #ccc;">
                        <h3>خطوات البحث النشطة (اسحب ورتب)</h3>
                        <ul id="wsf-active" style="min-height:100px; padding:10px; background:#f9f9f9;">
                            <?php if(!empty($saved_fields)): foreach($saved_fields as $tax): $label = wc_attribute_label($tax); $is_req = in_array($tax, $saved_reqs); $is_parent = in_array($tax, $saved_parents); ?>
                                <li class="wsf-item" style="padding:10px; background:#fff; margin-bottom:5px; border:1px solid #ddd; cursor:move;">
                                    <b><?php echo $label; ?></b> (<?php echo $tax; ?>)
                                    <input type="hidden" name="wsf_active_fields[]" value="<?php echo $tax; ?>">
                                    <div style="margin-top:5px; display:flex; gap:15px;">
                                        <label><input type="checkbox" name="wsf_reqs[]" value="<?php echo $tax; ?>" <?php checked($is_req); ?>> مطلوب؟</label>
                                        <label><input type="checkbox" name="wsf_parent_attrs[]" value="<?php echo $tax; ?>" <?php checked($is_parent); ?>> سمة رئيسية (أب)؟</label>
                                    </div>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    <div style="flex:1;">
                        <h3>السمات المتاحة (لإضافتها، يجب برمجتها لاحقاً أو إضافتها يدوياً هنا)</h3>
                        <ul><?php foreach($all_attributes as $attr): echo "<li>".$attr->attribute_label." (pa_".$attr->attribute_name.")</li>"; endforeach; ?></ul>
                    </div>
                </div>
                <br><button type="submit" name="wsf_save_settings" class="button button-primary">حفظ التغييرات</button>
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
        // 1. استلام البيانات من الجافاسكريبت
        $selections = isset($_POST['selections']) ? $_POST['selections'] : [];
        $target_taxonomy = isset($_POST['target']) ? sanitize_text_field($_POST['target']) : '';

        if(empty($selections) || empty($target_taxonomy)) wp_send_json_error();

        // 2. (جديد) جلب الإعدادات عشان نعرف مين الأب ومين الابن
        $parent_level_attrs = get_option('wsf_parent_attributes', array());
        
        $parent_tax_query = ['relation' => 'AND'];
        $child_tax_query  = ['relation' => 'AND'];
        $has_parent_filter = false;

        // 3. (جديد) فصل الفلاتر: هل نبحث عن أب أم ابن؟
        foreach($selections as $tax => $val) {
            // لو السمة دي متحددة كـ "أب" في الإعدادات
            if (in_array($tax, $parent_level_attrs)) {
                $parent_tax_query[] = [ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $val ];
                $has_parent_filter = true;
            } else {
                // وإلا فهي سمة ابن (Variation)
                $child_tax_query[] = [ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $val ];
            }
        }

        // 4. (جديد) لو في فلتر أب (زي البراند)، نجيب الآباء الأول
        $parent_ids = [];
        if ($has_parent_filter) {
            $parent_args = [ 
                'post_type' => 'product', 
                'status' => 'publish', 
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
            'fields' => 'ids',
            'tax_query' => $child_tax_query
        ];

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
}