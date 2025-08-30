<?php

add_action('admin_head', function () {
  // Esconde qualquer campo com a classe wrapper "alpha-storys-hide"
  echo '<style>.acf-field.alpha-storys-hide{display:none !important;}</style>';
});


// 3) Ao salvar o post, se marcado, cria/atualiza a Web Story
add_action('save_post', function ($post_id, $post, $update) {
  if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
  if (!in_array($post->post_type, ['alpha_storys'], true)) return;

  if (!function_exists('get_field')) return;
  $enabled = (bool) get_field('storys_enable', $post_id);
  if (!$enabled) return;

  $pages = alpha_build_storys_pages_from_content($post->post_content);
  if (empty($pages)) return;

  $poster_id = (int) get_field('storys_poster', $post_id);
  $logo_id   = (int) get_field('storys_publisher_logo', $post_id);
  $bg_color  = (string) get_field('storys_background_color', $post_id); 
  $text_color  = (string) get_field('storys_text_color', $post_id); 
  $publisher = get_bloginfo('name');

  $storys_id = (int) get_post_meta($post_id, '_alpha_storys_id', true);
  $args = [
    'post_type'   => 'web_storys',
    'post_title'  => get_the_title($post_id),
    'post_status' => 'draft',
    'post_author' => get_current_user_id(),
  ];

  if ($storys_id && get_post($storys_id)) {
    $args['ID'] = $storys_id;
    wp_update_post($args);
  } else {
    $storys_id = wp_insert_post($args);
    update_post_meta($post_id, '_alpha_storys_id', $storys_id);
  }

  if ($poster_id) set_post_thumbnail($storys_id, $poster_id);

  // metas do destino
  update_post_meta($storys_id, '_alpha_storys_source_post', $post_id);
  update_post_meta($storys_id, '_alpha_storys_pages', $pages);
  update_post_meta($storys_id, '_alpha_storys_publisher', sanitize_text_field($publisher));
  update_post_meta($storys_id, '_alpha_storys_logo_id', $logo_id);
  if (!empty($bg_color)) {
    update_post_meta($storys_id, '_alpha_storys_background_color', $bg_color); 
  }
  if (!empty($text_color)) {
    update_post_meta($storys_id, '_alpha_storys_text_color', $text_color); 
  }

  // (se quiser manter também no post fonte)
  update_post_meta($post_id, '_alpha_storys_pages', $pages);
  update_post_meta($post_id, '_alpha_storys_publisher', get_bloginfo('name'));
  update_post_meta($post_id, '_alpha_storys_logo_id', (int) get_field('storys_publisher_logo', $post_id));
}, 10, 3);



add_action('acf/init', function () {
  if (!function_exists('acf_add_local_field_group')) return;

  acf_add_local_field_group([
    'key' => 'grp_storys_from_post',
    'title' => 'Web Story deste conteúdo',
    'fields' => [
      [
        'key' => 'fld_storys_enable',
        'label' => 'Gerar Web Story deste conteúdo',
        'name'  => 'storys_enable',
        'type'  => 'true_false',
        'ui'    => 1,
        'default_value' => true,
        'wrapper' => ['class' => 'alpha-storys-hide'],

      ],
      [
        'key' => 'fld_storys_poster',
        'label' => 'Capa da Story 1080x1920',
        'name'  => 'storys_poster',
        'type'  => 'image',
        'return_format' => 'id',
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_storys_logo',
        'label' => 'Logo do Publisher 96x96',
        'name'  => 'storys_publisher_logo',
        'type'  => 'image',
        'return_format' => 'id',
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_storys_autoplay',
        'label' => 'Autoplay',
        'name'  => 'storys_autoplay',
        'type'  => 'true_false',
        'default_value' => 1,
        'ui' => 1,
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_storys_duration',
        'label' => 'Tempo por página (s)',
        'name'  => 'storys_duration',
        'type'  => 'select',
        'choices' => ['5' => '5', '7' => '7', '10' => '10', '12' => '12'],
        'default_value' => '7',
        'ui' => 1,
        'conditional_logic' => [[['field' => 'fld_storys_autoplay', 'operator' => '==', 'value' => 1]]],
      ],
      [
        'key' => 'fld_storys_show_controls',
        'label' => 'Mostrar botão Play/Pause',
        'name'  => 'storys_show_controls',
        'type'  => 'true_false',
        'default_value' => 1,
        'ui' => 1,
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_storys_style',
        'label' => 'Preset de estilo',
        'name'  => 'storys_style',
        'type'  => 'select',
        'choices' => [
          'top'      => 'Image top - imagem no topo, em baixo',
          'clean'      => 'Clean - imagem de fundo, texto central',
          'dark-left'  => 'Dark Left - fundo com overlay, texto à esquerda',
          'card'       => 'Card - imagem em cartão, texto abaixo',
          'split'      => 'Split - imagem esquerda, texto direita',
        ],
        'default_value' => 'clean',
        'ui' => 1,
        'return_format' => 'value',
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_storys_font',
        'label' => 'Fonte',
        'name'  => 'storys_font',
        'type'  => 'select',
        'choices' => [
          'system'      => 'System UI',
          'inter'       => 'Inter',
          'poppins'     => 'Poppins',
          'merriweather' => 'Merriweather',
          'plusjakarta' => 'Plus Jakarta Sans',
        ],
        'default_value' => 'plusjakarta',
        'ui' => 1,
        'return_format' => 'value',
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
      'key'   => 'fld_storys_bgcolor',
      'label' => 'Cor de fundo',
      'name'  => 'storys_background_color',
      'type'  => 'color_picker',
      'default_value' => '#ffffff',
      'conditional_logic' => [
        [
          ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
        ]
      ],
    ],
    [
      'key'   => 'fld_storys_textcolor',
      'label' => 'Cor do texto',
      'name'  => 'storys_text_color',
      'type'  => 'color_picker',
      'default_value' => '#ffffff',
      'conditional_logic' => [
        [
          ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
        ]
      ],
    ],

      [
        'key' => 'fld_storys_accent',
        'label' => 'Cor de destaque',
        'name'  => 'storys_accent_color',
        'type'  => 'color_picker',
        'default_value' => '#cc0000',
        'conditional_logic' => [
          [
            ['field' => 'fld_storys_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
    ],
    'location' => [
      [['param' => 'post_type', 'operator' => '==', 'value' => 'alpha_storys']],
    ],
    'position'   => 'side',
    'menu_order' => 0,   
    'style' => 'default',
    'active' => true,
  ]);
});
