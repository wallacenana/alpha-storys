<?php

// 3) Ao salvar o post, se marcado, cria/atualiza a Web Story
add_action('save_post', function ($post_id, $post, $update) {
  if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

  // Agora aceita 'alpha_storys' como fontes
  if (!in_array($post->post_type, ['alpha_storys'], true)) return;

  if (!function_exists('get_field')) return;
  $enabled = (bool) get_field('story_enable', $post_id);
  if (!$enabled) return;

  // monta páginas a partir do conteúdo: cada H2 vira página
  $pages = alpha_build_story_pages_from_content($post->post_content);

  if (empty($pages)) return; // nada para gerar

  $poster_id = (int) get_field('story_poster', $post_id);
  $logo_id   = (int) get_field('story_publisher_logo', $post_id);
  $publisher = get_bloginfo('name');

  // cria ou atualiza o CPT web_story
  $story_id = (int) get_post_meta($post_id, '_alpha_storys_id', true);
  $args = [
    'post_type'   => 'web_story',
    'post_title'  => get_the_title($post_id),
    'post_status' => 'publish',
    'post_author' => get_current_user_id(),
  ];

  if ($story_id && get_post($story_id)) {
    $args['ID'] = $story_id;
    wp_update_post($args);
  } else {
    $story_id = wp_insert_post($args);
    update_post_meta($post_id, '_alpha_storys_id', $story_id);
  }

  if ($poster_id) set_post_thumbnail($story_id, $poster_id);

  // salva metadados da story
  update_post_meta($story_id, '_alpha_storys_source_post', $post_id);
  update_post_meta($story_id, '_alpha_storys_pages', $pages);
  update_post_meta($story_id, '_alpha_storys_publisher', sanitize_text_field($publisher));

  //   story
  update_post_meta($story_id, '_alpha_storys_logo_id', $logo_id);
  update_post_meta($post_id, '_alpha_storys_pages', $pages);
  update_post_meta($post_id, '_alpha_storys_publisher', get_bloginfo('name'));
  update_post_meta($post_id, '_alpha_storys_logo_id', (int) get_field('story_publisher_logo', $post_id));
}, 10, 3);


add_action('acf/init', function () {
  if (!function_exists('acf_add_local_field_group')) return;

  acf_add_local_field_group([
    'key' => 'grp_story_from_post',
    'title' => 'Web Story deste conteúdo',
    'fields' => [
      [
        'key' => 'fld_story_enable',
        'label' => 'Gerar Web Story deste conteúdo',
        'name'  => 'story_enable',
        'type'  => 'true_false',
        'ui'    => 1,
        'default_value' => true,
      ],
      [
        'key' => 'fld_story_poster',
        'label' => 'Capa da Story 1080x1920',
        'name'  => 'story_poster',
        'type'  => 'image',
        'return_format' => 'id',
        'conditional_logic' => [
          [
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_logo',
        'label' => 'Logo do Publisher 96x96',
        'name'  => 'story_publisher_logo',
        'type'  => 'image',
        'return_format' => 'id',
        'conditional_logic' => [
          [
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_autoplay',
        'label' => 'Autoplay',
        'name'  => 'story_autoplay',
        'type'  => 'true_false',
        'default_value' => 1,
        'ui' => 1,
        'conditional_logic' => [
          [
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_duration',
        'label' => 'Tempo por página (s)',
        'name'  => 'story_duration',
        'type'  => 'select',
        'choices' => ['5' => '5', '7' => '7', '10' => '10', '12' => '12'],
        'default_value' => '7',
        'ui' => 1,
        'conditional_logic' => [[['field' => 'fld_story_autoplay', 'operator' => '==', 'value' => 1]]],
      ],
      [
        'key' => 'fld_story_show_controls',
        'label' => 'Mostrar botão Play/Pause',
        'name'  => 'story_show_controls',
        'type'  => 'true_false',
        'default_value' => 1,
        'ui' => 1,
        'conditional_logic' => [
          [
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_style',
        'label' => 'Preset de estilo',
        'name'  => 'story_style',
        'type'  => 'select',
        'choices' => [
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
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_font',
        'label' => 'Fonte',
        'name'  => 'story_font',
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
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
          ]
        ],
      ],
      [
        'key' => 'fld_story_accent',
        'label' => 'Cor de destaque',
        'name'  => 'story_accent_color',
        'type'  => 'color_picker',
        'default_value' => '#cc0000',
        'conditional_logic' => [
          [
            ['field' => 'fld_story_enable', 'operator' => '==', 'value' => 1],
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
