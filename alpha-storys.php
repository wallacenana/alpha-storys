<?php

/**
 * Plugin Name:  Alpha Storys
 * Description:  Cria Web Storys com 1 clique com ChatGPT e visualização e edição no editor do Wordpress
 * Version:      1.0.2 
 * Author: Wallace Tavares
 * Author URI: https://wallacetavares.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: https://pluginsalpha.com/wp-content/uploads/storys/update
 * Text Domain:  alpha-storys
 */

if (!defined('ABSPATH')) exit;

define('ALPHA_STORYS_FILE', __FILE__);
define('ALPHA_STORYS_PATH', plugin_dir_path(__FILE__));
define('ALPHA_STORYS_URL',  plugin_dir_url(__FILE__));

require_once ALPHA_STORYS_PATH . 'includes/plugin.php';

// registra 
add_action('init', 'alpha_register_cpt_storys');
function alpha_register_cpt_storys()
{

  $labels = [ /* ... seus labels iguais ... */];

  $args = [
    'label'               => __('Alpha Storys', 'alpha-storys'),
    'labels'              => $labels,
    'public'              => true,
    'publicly_queryable'  => true,
    'show_ui'             => true,
    // 👉 coloca o CPT como SUBMENU do seu menu de plugin
    'show_in_menu'        => 'alpha-storys',
    'show_in_admin_bar'   => true,
    'show_in_nav_menus'   => true,
    'show_in_rest'        => true,
    'menu_icon'           => 'dashicons-slides', // ignorado quando show_in_menu aponta pra outro menu
    'menu_position'       => null,
    'capability_type'     => 'post',
    'map_meta_cap'        => true,
    'hierarchical'        => false,
    'has_archive'         => true,
    'exclude_from_search' => false,
    'rewrite'             => ['slug' => 'alpha-storys', 'with_front' => true],
    'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'custom-fields', 'revisions'],
    'taxonomies'          => ['category'],
  ];

  register_post_type('alpha_storys', $args);
}


// flush rewrites ao ativar/desativar
register_activation_hook(__FILE__, function () {
  alpha_register_cpt_storys();
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
  flush_rewrite_rules();
});
