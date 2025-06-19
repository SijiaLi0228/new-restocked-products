<?php
/**
 * Plugin Name: New & Restocked Products
 * Description: 自动将新发布和重新备货的商品加入“new-and-restocked”分类，并在5天后自动清理。
 * Version: 1.2
 * Author: Sijia Li
 * Text Domain: new-restocked-products
 */

// 0. 取回分类的 term_id（避免 slug 识别问题）
function get_new_and_restocked_term_id() {
    $term = get_term_by('slug', 'new-and-restocked', 'product_cat');
    return ($term && !is_wp_error($term)) ? intval($term->term_id) : 0;
}

// 1. 新商品发布时加入分类（首次发布时触发）
add_action('publish_product', function($post_id) {
    if (get_post_meta($post_id, '_new_and_restocked_time', true)) return; // 已处理过
    $term_id = get_new_and_restocked_term_id();
    if (!$term_id) return;
    wp_set_object_terms($post_id, $term_id, 'product_cat', true);
    update_post_meta($post_id, '_new_and_restocked_time', time());
});

// 2. 库存从 0 ➜ 有库存时加入分类
add_action('woocommerce_product_set_stock', function($stock_obj) {
    $term_id    = get_new_and_restocked_term_id();
    $product_id = $stock_obj->get_id();
    $qty        = $stock_obj->get_stock_quantity();
    $prev_qty   = get_post_meta($product_id, '_prev_stock_qty', true);

    if ($term_id && $qty > 0 && $prev_qty !== '' && intval($prev_qty) === 0) {
        wp_set_object_terms($product_id, $term_id, 'product_cat', true);
        update_post_meta($product_id, '_new_and_restocked_time', time());
    }

    update_post_meta($product_id, '_prev_stock_qty', $qty);
}, 10);

// 3. 注册每日清理事件
add_action('wp_loaded', function() {
    if (!wp_next_scheduled('clear_new_and_restocked')) {
        wp_schedule_event(time(), 'daily', 'clear_new_and_restocked');
    }
});

// 4. 执行清理：移除 5 天前加入的商品
add_action('clear_new_and_restocked', function() {
    $term_id  = get_new_and_restocked_term_id();
    if (!$term_id) return;

    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [[
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'post_status'    => 'publish',
    ]);

    foreach ($products as $p) {
        $added = get_post_meta($p->ID, '_new_and_restocked_time', true);
        if ($added && (time() - intval($added) > 5 * DAY_IN_SECONDS)) {
            wp_remove_object_terms($p->ID, [$term_id], 'product_cat');
            delete_post_meta($p->ID, '_new_and_restocked_time');
        }
    }
});
