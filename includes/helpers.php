<?php
if (!defined('ABSPATH')) exit;

/** Lê todas as opções do plugin (array) */
function alpha_stories_options(){
  $o = get_option('alpha_stories_options', []);
  return is_array($o) ? $o : [];
}

function alpha_opt($key, $default = null) {
  $opts = alpha_stories_options();
  return array_key_exists($key, $opts) ? $opts[$key] : $default;
}

function alpha_ai_get_api_key(){
  if (defined('ALPHA_OPENAI_KEY') && ALPHA_OPENAI_KEY) return ALPHA_OPENAI_KEY;
  $o = alpha_stories_options();
  return isset($o['ai_api_key']) ? trim((string)$o['ai_api_key']) : '';
}

function alpha_ai_get_model(){
  $o = alpha_stories_options();
  return !empty($o['ai_model']) ? (string)$o['ai_model'] : 'gpt-4o-mini';
}

function alpha_ai_get_temperature(){
  $o = alpha_stories_options();
  $t = isset($o['ai_temperature']) ? (float)$o['ai_temperature'] : 0.4;
  return max(0, min(1, $t));
}

function alpha_ai_get_default_brief(){
  $o = alpha_stories_options();
  return isset($o['ai_brief_default']) ? (string)$o['ai_brief_default'] : '';
}

/** Tenta descobrir o GA4 Measurement ID automaticamente (Site Kit) e cai pro manual */
function alpha_get_ga4_id() {
  // 1) Modo fixo manual > se marcado, prioriza
  $mode = alpha_opt('ga_mode', 'auto'); // auto|manual|off
  if ($mode === 'off') return '';
  if ($mode === 'manual') {
    $id = trim((string) alpha_opt('ga_manual_id', ''));
    return preg_match('/^G-[A-Z0-9\-]{4,}$/i', $id) ? $id : '';
  }

  // 2) AUTO: tentar Site Kit (melhor esforço; nomes de opções podem variar por versão)
  $candidates = [
    'googlesitekit_analytics-4_settings',
    'googlesitekit_analytics-4',
    'googlesitekit_analytics_settings',
    'googlesitekit_gtag_settings',
  ];
  foreach ($candidates as $opt_name) {
    $opt = get_option($opt_name);
    if (is_array($opt)) {
      // procurar campos com cara de Measurement ID
      foreach (['measurementID','measurementId','measurement_id','ga4MeasurementId'] as $k) {
        if (!empty($opt[$k]) && preg_match('/^G-[A-Z0-9\-]{4,}$/i', $opt[$k])) {
          return $opt[$k];
        }
      }
      // às vezes vem dentro de um subarray 'stream' / 'property'
      $flat = json_decode(json_encode($opt), true);
      $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($flat));
      foreach ($it as $v) {
        if (is_string($v) && preg_match('/^G-[A-Z0-9\-]{4,}$/i', $v)) {
          return $v;
        }
      }
    }
  }

  // 3) fallback: manual, se existir
  $id = trim((string) alpha_opt('ga_manual_id', ''));
  return preg_match('/^G-[A-Z0-9\-]{4,}$/i', $id) ? $id : '';
}

/** URL do logo do publisher a partir do ID salvo nas opções globais */
function alpha_get_publisher_logo_url($size = 'full') {
  $id = (int) alpha_opt('publisher_logo_id', 0);
  return $id ? wp_get_attachment_image_url($id, $size) : '';
}

/**
 * Retorna o ID do alpha_story vinculado ao $post_id.
 * Se não existir, cria um novo alpha_story espelhando título/autor/thumbnail,
 * seta a relação e devolve o ID.
 */
function alpha_get_or_create_story_for_post($post_id){
  $post = get_post($post_id);
  if (!$post) return 0;

  // 1) Já tem um ID salvo no próprio post?
  $story_id = (int) get_post_meta($post_id, '_alpha_story_id', true);
  if ($story_id && get_post($story_id)) return $story_id;

  // 2) Tenta localizar por meta reversa (caso tenha vindo de outra rotina)
  $q = new WP_Query([
    'post_type'      => 'alpha_story',
    'posts_per_page' => 1,
    'post_status'    => ['publish','draft','pending'],
    'meta_query'     => [[
      'key'   => '_alpha_story_source_post',
      'value' => $post_id,
    ]]
  ]);
  if ($q->have_posts()){
    $story_id = (int)$q->posts[0]->ID;
    wp_reset_postdata();
    update_post_meta($post_id, '_alpha_story_id', $story_id);
    return $story_id;
  }

  // 3) Criar
  $args = [
    'post_type'   => 'alpha_story',
    'post_title'  => $post->post_title,
    'post_status' => 'publish',
    'post_author' => (int)$post->post_author,
  ];
  $story_id = wp_insert_post($args);

  if ($story_id){
    // relação bidirecional
    update_post_meta($story_id, '_alpha_story_source_post', $post_id);
    update_post_meta($post_id,  '_alpha_story_id',         $story_id);

    // poster = thumbnail do post (se tiver)
    $thumb = get_post_thumbnail_id($post_id);
    if ($thumb) set_post_thumbnail($story_id, $thumb);

    // publisher + logo (globais)
    $publisher = alpha_opt('publisher_name', get_bloginfo('name'));
    update_post_meta($story_id, '_alpha_story_publisher', sanitize_text_field($publisher));
    $logo_id = (int) alpha_opt('publisher_logo_id', 0);
    if ($logo_id) update_post_meta($story_id, '_alpha_story_logo_id', $logo_id);
  }

  return (int)$story_id;
}

// Retorna a OpenAI API Key a partir de várias fontes.
// includes/helpers-ai.php
if (!function_exists('alpha_ai_get_api_key')) {
  function alpha_ai_get_api_key(): string {
    // 1) constante (wp-config.php)
    if (defined('ALPHA_OPENAI_KEY') && ALPHA_OPENAI_KEY) return trim(ALPHA_OPENAI_KEY);
    if (defined('OPENAI_API_KEY')   && OPENAI_API_KEY)   return trim(OPENAI_API_KEY);

    // 2) env
    $env = getenv('OPENAI_API_KEY');
    if (is_string($env) && trim($env) !== '') return trim($env);

    // 3) option do plugin
    if (function_exists('alpha_stories_options')) {
      $o = alpha_stories_options();
      if (!empty($o['ai_api_key'])) return trim((string)$o['ai_api_key']);
    }

    // 4) fallback para nomes antigos (se tiver)
    foreach (['alpha_ai_openai_api_key','alpha_ai_api_key','openai_api_key'] as $name) {
      $v = get_option($name);
      if (is_string($v) && trim($v) !== '') return trim($v);
    }

    return '';
  }
}


function alpha_ai_generate_for_post($post_id) {
  $o   = function_exists('alpha_stories_options') ? alpha_stories_options() : [];
  $key = alpha_ai_get_api_key();
  if (!$key) return new WP_Error('alpha_ai_key', 'Configure sua OpenAI API Key nas Configurações.');

  $post = get_post($post_id);
  if (!$post) return new WP_Error('alpha_ai_post', 'Post inválido.');

  $raw_html = apply_filters('the_content', $post->post_content);
  $title    = get_the_title($post);
  $brief    = !empty($o['ai_brief_default']) ? (string)$o['ai_brief_default'] : '';

  // Instruções + material de entrada
  $system_pt = "Você transforma posts em Web Stories AMP. Gere slides concisos (até ~240 caracteres no corpo). 
Retorne APENAS um JSON válido no formato:
{\"pages\":[{\"heading\":\"\",\"body\":\"\",\"image\":\"\",\"cta_text\":\"\",\"cta_url\":\"\",\"cta_type\":\"button|swipe|\",\"cta_icon\":\"\"}]}";

  $input_text = $system_pt
    . "\n\nTÍTULO:\n" . $title
    . "\n\nHTML DO POST:\n" . wp_strip_all_tags($raw_html)
    . "\n\nBRIEF PADRÃO:\n" . $brief;

  // Payload no padrão novo do Responses API
  $payload = [
    'model' => alpha_ai_get_model(),               // ex.: gpt-4o-mini ou gpt-5-chat-latest
    'input' => [[
      'role'    => 'user',
      'content' => [
        ['type' => 'input_text', 'text' => $input_text],
      ],
    ]],
    // >>> Structured output (substitui response_format):
    'text' => [ 'format' => [ 'type' => 'json_object' ] ],
    'temperature'       => alpha_ai_get_temperature(),
    'max_output_tokens' => 1200,
  ];

  $res = wp_remote_post('https://api.openai.com/v1/responses', [
    'timeout' => 60,
    'headers' => [
      'Authorization' => 'Bearer ' . $key,
      'Content-Type'  => 'application/json',
    ],
    'body' => wp_json_encode($payload),
  ]);

  if (is_wp_error($res)) return $res;
  $code = wp_remote_retrieve_response_code($res);
  $body = wp_remote_retrieve_body($res);
  if ($code !== 200) {
    return new WP_Error('alpha_ai_http', 'OpenAI retornou '.$code.': '.substr($body, 0, 300));
  }

  $obj = json_decode($body, true);

  // As respostas do Responses API podem vir em output[0].content[*].text ou direto em output_text.
  $json_text = '';
  if (!empty($obj['output'][0]['content'])) {
    foreach ($obj['output'][0]['content'] as $chunk) {
      if (!empty($chunk['text'])) $json_text .= $chunk['text'];
      if (!empty($chunk['raw']))  $json_text .= $chunk['raw'];
    }
  } elseif (!empty($obj['output_text'])) {
    $json_text = $obj['output_text'];
  }

  $data = json_decode($json_text, true);
  if (!$data && preg_match('/\{.*\}/s', (string)$json_text, $m)) {
    $data = json_decode($m[0], true);
  }
  if (!$data || empty($data['pages']) || !is_array($data['pages'])) {
    return new WP_Error('alpha_ai_parse', 'Não consegui interpretar o JSON de páginas.');
  }

  $pages = [];
  foreach ($data['pages'] as $p) {
    $pages[] = [
      'heading'  => isset($p['heading']) ? wp_strip_all_tags($p['heading']) : '',
      'body'     => isset($p['body'])    ? wp_strip_all_tags($p['body'])    : '',
      'image'    => isset($p['image'])   ? esc_url_raw($p['image'])         : '',
      'cta_text' => isset($p['cta_text'])? sanitize_text_field($p['cta_text']): '',
      'cta_url'  => isset($p['cta_url']) ? esc_url_raw($p['cta_url'])       : '',
      'cta_type' => in_array(($p['cta_type'] ?? ''), ['button','swipe'], true) ? $p['cta_type'] : '',
      'cta_icon' => !empty($p['cta_icon']) ? esc_url_raw($p['cta_icon'])    : '',
    ];
  }
  
  // Depois de montar $pages:
    $target_id = (get_post_type($post_id) === 'alpha_story')
      ? (int)$post_id
      : alpha_story_get_or_create_story((int)$post_id);
    
    if (is_wp_error($target_id)) return $target_id;
    
    update_post_meta($target_id, '_alpha_story_pages', $pages);
    return ['ok' => true, 'count' => count($pages), 'target_id' => (int)$target_id];


  update_post_meta($post_id, '_alpha_story_pages', $pages);
  return ['ok' => true, 'count' => count($pages)];
}

