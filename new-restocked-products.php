<?php
/**
 * Plugin Name: New & Restocked Products
 * Description: 自动将新发布和重新备货的商品加入“new-and-restocked”分类，并在5天后或库存为0时自动清理。
 * Version: 1.4
 * Author: Sijia Li
 * Text Domain: new-restocked-products
 */

// 0. 获取分类 term_id（使用 slug）
function get_new_and_restocked_term_id() {
    $term = get_term_by('slug', 'new-and-restocked', 'product_cat');
    return ($term && !is_wp_error($term)) ? intval($term->term_id) : 0;
}

// 1. 仅在首次从草稿/待审 ➜ 发布 时加入分类
add_action('transition_post_status', function($new_status, $old_status, $post) {
    if ($post->post_type !== 'product') return;
    if ($old_status === 'publish') return; // 避免已发布后更新触发
    if ($new_status !== 'publish') return;

    if (get_post_meta($post->ID, '_new_and_restocked_time', true)) return;

    $term_id = get_new_and_restocked_term_id();
    if (!$term_id) return;

    wp_set_object_terms($post->ID, $term_id, 'product_cat', true);
    update_post_meta($post->ID, '_new_and_restocked_time', time());
}, 10, 3);

// 2. 库存变化时的处理（增加/移除分类）
add_action('woocommerce_product_set_stock', function($stock_obj) {
    $term_id    = get_new_and_restocked_term_id();
    $product_id = $stock_obj->get_id();
    $qty        = $stock_obj->get_stock_quantity();
    $prev_qty   = get_post_meta($product_id, '_prev_stock_qty', true);

    // 如果库存从 0 ➜ 有库存，加入分类
    if ($term_id && $qty > 0 && $prev_qty !== '' && intval($prev_qty) === 0) {
        wp_set_object_terms($product_id, $term_id, 'product_cat', true);
        update_post_meta($product_id, '_new_and_restocked_time', time());
    }

    // 如果库存变为 0，移除分类
    if ($term_id && $qty <= 0) {
        wp_remove_object_terms($product_id, [$term_id], 'product_cat');
        delete_post_meta($product_id, '_new_and_restocked_time');
    }

    update_post_meta($product_id, '_prev_stock_qty', $qty);
}, 10);

// 3. 注册每日清理任务（仅注册一次）
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('clear_new_and_restocked')) {
        wp_schedule_event(time(), 'daily', 'clear_new_and_restocked');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('clear_new_and_restocked');
});

// 4. 清理 5 天前加入的产品
add_action('clear_new_and_restocked', function() {
    if (get_transient('clear_new_and_restocked_lock')) return;
    set_transient('clear_new_and_restocked_lock', 1, 10 * MINUTE_IN_SECONDS);

    $term_id = get_new_and_restocked_term_id();
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

    delete_transient('clear_new_and_restocked_lock');
});
