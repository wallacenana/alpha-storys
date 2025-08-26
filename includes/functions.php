<?php

// alpha-stories.php (principal)
if ( ! defined('ABSPATH') ) exit;
// === 1) Constrói as páginas a partir do conteúdo: AGORA divide por <hr> (linha horizontal) ===
function alpha_build_story_pages_from_content($html) {
  $pages   = [];
  $content = do_shortcode($html);

  // Normaliza o bloco Gutenberg de separador para um marcador
  // <!-- wp:separator --> ... <hr ...> ... <!-- /wp:separator -->
  $content = preg_replace(
    '/<!--\s*wp:separator[^>]*-->[\s\S]*?<!--\s*\/wp:separator\s*-->/i',
    '[[ALPHA_SPLIT]]',
    $content
  );

  // Converte QUALQUER <hr> em marcador
  $content = preg_replace('/<hr\b[^>]*>/i', '[[ALPHA_SPLIT]]', $content);

  // Corta por marcador
  $sections = array_filter(array_map('trim', explode('[[ALPHA_SPLIT]]', $content)), 'strlen');

  if (count($sections) > 1) {
    // Modo novo: cada pedaço entre <hr> vira página
    foreach ($sections as $sec_html) {
      $pages[] = alpha_finalize_section('', $sec_html); // título opcional dentro do bloco
    }
  } else {
    // Fallback legado: cada <h2> inicia uma página
    $pattern = '/(<h2[^>]*>.*?<\/h2>)/i';
    $parts   = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $current_title = '';
    $current_body  = '';
    foreach ($parts as $chunk) {
      if (preg_match('/^<h2[^>]*>(.*?)<\/h2>$/is', $chunk, $m)) {
        if ($current_title || $current_body) {
          $pages[] = alpha_finalize_section($current_title, $current_body);
        }
        $current_title = wp_strip_all_tags($m[1]);
        $current_body  = '';
      } else {
        $current_body .= $chunk;
      }
    }
    if ($current_title || $current_body) {
      $pages[] = alpha_finalize_section($current_title, $current_body);
    }
  }

  // Remove páginas totalmente vazias
  $pages = array_values(array_filter($pages, function ($p) {
    return !empty($p['heading']) || !empty($p['body']) || !empty($p['image']);
  }));

  return $pages;
}

// === 2) Fecha/normaliza cada seção: título opcional (1º <h2>), CTA e limpeza do texto ===
function alpha_finalize_section($title, $body_html) {
  // Se não vier título externo, usa o 1º <h2> do bloco (se existir) e remove-o do corpo
  if (empty($title) && preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $body_html, $mh2)) {
    $title     = wp_strip_all_tags($mh2[1]);
    $body_html = str_replace($mh2[0], '', $body_html);
  }

  // Extrai CTA via shortcode [story-cta] (já remove do HTML)
  $cta = alpha_extract_story_cta($body_html);

  // Primeira imagem da seção
  $img = '';
  if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body_html, $im)) {
    $img = esc_url_raw($im[1]);
  }

  // Fallback: usa o 1º link como CTA e REMOVE esse link do texto (pra não duplicar no slide)
  if (empty($cta['url']) && preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $body_html, $alink)) {
    $cta['url'] = esc_url_raw($alink[1]);
    if (empty($cta['text'])) {
      $cta['text'] = wp_strip_all_tags($alink[2]);
    }
    // remove o link usado do corpo
    $body_html = str_replace($alink[0], '', $body_html);
  }
  if (empty($cta['text']) && !empty($cta['url'])) {
    $cta['text'] = 'Saiba mais';
  }

  // Texto final (curto)
  $text = trim(wp_strip_all_tags($body_html));
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($text) > 240) $text = mb_substr($text, 0, 240) . '...';
  } else {
    if (strlen($text) > 240) $text = substr($text, 0, 240) . '...';
  }

  return [
    'heading'   => trim($title),
    'body'      => $text,
    'image'     => $img,
    'cta_text'  => $cta['text'],
    'cta_url'   => $cta['url'],
    'cta_type'  => $cta['type'],   // 'button' | 'swipe' | ''
    'cta_icon'  => $cta['icon'],   // opcional (32x32)
  ];
}


// 2) Helper para extrair o shortcode da seção (Gutenberg salva como bloco "shortcode")
function alpha_extract_story_cta(&$html)
{
  $out = ['type' => '', 'text' => '', 'url' => '', 'icon' => ''];

  // pega o shortcode (mesmo se vier dentro de <!-- wp:shortcode -->)
  if (preg_match('/\[story-cta([^\]]*)\]/i', $html, $m)) {
    $atts = shortcode_parse_atts($m[1] ?? '');
    $type = isset($atts['type']) ? strtolower(trim($atts['type'])) : '';
    if (!in_array($type, ['button', 'swipe'], true)) $type = 'button';

    $out['type'] = $type;
    $out['text'] = isset($atts['text']) ? sanitize_text_field($atts['text']) : '';
    $out['url']  = isset($atts['url'])  ? esc_url_raw($atts['url']) : '';
    $out['icon'] = isset($atts['icon']) ? esc_url_raw($atts['icon']) : '';

    // remove o shortcode do HTML para não “vazar” no texto
    $html = str_replace($m[0], '', $html);
  }

  return $out;
}


function alpha_from_blocks(array $blocks)
{
  $pages = [];
  $current = [
    'heading'  => '',
    'body' => '',
    'image' => '',
    'cta_text' => '',
    'cta_url' => '',
    'cta_type' => '',
    'cta_icon' => ''
  ];

  $push = function () use (&$pages, &$current) {
    if ($current['heading'] || $current['body'] || $current['image']) {
      // limita corpo
      if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($current['body']) > 240) $current['body'] = mb_substr($current['body'], 0, 240) . '...';
      } else {
        if (strlen($current['body']) > 240) $current['body'] = substr($current['body'], 0, 240) . '...';
      }
      $pages[] = $current;
    }
    $current = [
      'heading'  => '',
      'body' => '',
      'image' => '',
      'cta_text' => '',
      'cta_url' => '',
      'cta_type' => '',
      'cta_icon' => ''
    ];
  };

  $walk = function ($bs) use (&$walk, &$current, $push) {
    foreach ($bs as $b) {
      $name  = $b['blockName'] ?? '';
      $attrs = $b['attrs'] ?? [];
      $inner = $b['innerBlocks'] ?? [];
      $html  = $b['innerHTML'] ?? '';

      // H2 inicia nova página
      if ($name === 'core/heading' && !empty($attrs['level']) && (int)$attrs['level'] === 2) {
        $push();
        $current['heading'] = wp_strip_all_tags($html);
      }
      // Parágrafos acumulam texto e capturam 1º link como fallback de CTA
      elseif ($name === 'core/paragraph') {
        // 1) Se o parágrafo contiver [story-cta ...], captura primeiro
        if (preg_match('/\[story-cta\b([^\]]*)\]/i', $html, $mm)) {
          $atts = shortcode_parse_atts($mm[1] ?? '');
          $type = isset($atts['type']) ? strtolower(trim($atts['type'])) : '';
          if (!in_array($type, ['button', 'swipe'], true)) $type = ''; // deixa vazio p/ cair no fallback "swipe"

          if (!empty($atts['text'])) $current['cta_text'] = sanitize_text_field($atts['text']);
          if (!empty($atts['url']))  $current['cta_url']  = esc_url_raw($atts['url']);
          if (!empty($atts['icon'])) $current['cta_icon'] = esc_url_raw($atts['icon']);
          if ($type) $current['cta_type'] = $type;

          // remove o shortcode do HTML antes de extrair texto
          $html = str_replace($mm[0], '', $html);
        }

        // 2) Acumula texto e pega 1º link como fallback
        $text = wp_strip_all_tags($html);
        if ($text) $current['body'] .= ($current['body'] ? ' ' : '') . $text;

        if (empty($current['cta_url']) && preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', $html, $m)) {
          $current['cta_url']  = esc_url_raw($m[1]);
          if (empty($current['cta_text'])) $current['cta_text'] = wp_strip_all_tags($m[2]);
        }
      }
      // Imagem (1ª da seção) vira background
      elseif ($name === 'core/image') {
        if (empty($current['image'])) {
          $url = '';
          if (!empty($attrs['id'])) {
            $url = wp_get_attachment_image_url((int)$attrs['id'], 'story_poster') ?: '';
          }
          if (!$url && !empty($attrs['url'])) $url = esc_url_raw($attrs['url']);
          if (!$url && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $html, $m)) $url = esc_url_raw($m[1]);
          if ($url) $current['image'] = $url;
        }
      }
      // Shortcode: [story-cta type="button|swipe" text="..." url="..." icon="..."]
      elseif ($name === 'core/shortcode') {
        // Gutenberg costuma guardar o shortcode em attrs.text
        $sc = '';
        if (!empty($attrs['text'])) {
          $sc = (string) $attrs['text'];
        } elseif (!empty($html)) {
          $sc = $html;
        }
        if ($sc && preg_match('/\[story-cta\b([^\]]*)\]/i', $sc, $mm)) {
          $atts = shortcode_parse_atts($mm[1] ?? '');
          $type = isset($atts['type']) ? strtolower(trim($atts['type'])) : '';
          if (!in_array($type, ['button', 'swipe'], true)) $type = ''; // vazio -> fallback "swipe" no template

          if (!empty($atts['text'])) $current['cta_text'] = sanitize_text_field($atts['text']);
          if (!empty($atts['url']))  $current['cta_url']  = esc_url_raw($atts['url']);
          if (!empty($atts['icon'])) $current['cta_icon'] = esc_url_raw($atts['icon']);
          if ($type) $current['cta_type'] = $type;
        }
      }
      // Bloco de botão do Gutenberg
      elseif ($name === 'core/button') {
        // tenta pegar attrs primeiro
        if (empty($current['cta_url']) && !empty($attrs['url'])) {
          $current['cta_url'] = esc_url_raw($attrs['url']);
          $current['cta_type'] = $current['cta_type'] ?: 'button';
        }
        if (empty($current['cta_text'])) {
          // WP salva o texto no innerHTML do <a>
          if (preg_match('/<a[^>]*>(.*?)<\/a>/i', $html, $tm)) {
            $current['cta_text'] = wp_strip_all_tags($tm[1]);
          } elseif (!empty($attrs['text'])) {
            $current['cta_text'] = sanitize_text_field($attrs['text']);
          }
        }
      }
      // Container de botões (pode ter innerBlocks com core/button)
      elseif ($name === 'core/buttons') {
        // nada aqui; os botões vêm nos innerBlocks
      }

      // desce na árvore
      if (!empty($inner)) $walk($inner);
    }
  };

  $walk($blocks);
  $push();
  return $pages;
}

function alpha_story_get_or_create_story($source_post_id) {
  $existing = (int) get_post_meta($source_post_id, '_alpha_story_id', true);
  if ($existing && get_post($existing)) {
    return $existing;
  }

  $q = new WP_Query([
    'post_type'      => 'alpha_story',
    'post_status'    => ['publish','draft','pending','future','private'],
    'meta_key'       => '_alpha_story_source_post',
    'meta_value'     => $source_post_id,
    'fields'         => 'ids',
    'posts_per_page' => 1,
    'no_found_rows'  => true,
  ]);
  if ($q->have_posts()) {
    $id = (int) $q->posts[0];
    update_post_meta($source_post_id, '_alpha_story_id', $id);
    return $id;
  }

  $args = [
    'post_type'   => 'alpha_story',
    'post_title'  => get_the_title($source_post_id),
    'post_status' => 'draft',
    'post_author' => (int) get_post_field('post_author', $source_post_id),
  ];
  $id = wp_insert_post($args, true);
  if (is_wp_error($id)) return $id;

  update_post_meta($id, '_alpha_story_source_post', (int)$source_post_id);
  update_post_meta($source_post_id, '_alpha_story_id', (int)$id);

  $thumb_id = get_post_thumbnail_id($source_post_id);
  if ($thumb_id) set_post_thumbnail($id, $thumb_id);

  if (function_exists('alpha_stories_options')) {
    $o = alpha_stories_options();
    if (!empty($o['publisher_logo_id'])) {
      update_post_meta($id, '_alpha_story_logo_id', (int)$o['publisher_logo_id']);
    }
    update_post_meta(
      $id,
      '_alpha_story_publisher',
      !empty($o['publisher_name']) ? sanitize_text_field($o['publisher_name']) : get_bloginfo('name')
    );
  }

  return (int) $id;
}
