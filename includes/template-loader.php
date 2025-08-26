<?php
if ( ! defined('ABSPATH') ) exit;

// SINGLE
add_filter('single_template', function($template){
  if ( is_singular('alpha_story') ) {
    // 1) permite override no tema/child
    $theme_tpl = locate_template(['single-alpha_story.php']);
    if ( $theme_tpl ) return $theme_tpl;

    // 2) fallback do plugin
    $plugin_tpl = ALPHA_STORIES_PATH . 'templates/single-alpha_story.php';
    if ( file_exists($plugin_tpl) ) return $plugin_tpl;
  }
  return $template;
});