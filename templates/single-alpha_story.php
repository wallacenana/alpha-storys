<?php
// Início do buffer com um minificador "AMP-safe"
ob_start(function ($html) {
  // Protege blocos sensíveis antes de minificar
  $tokens = [];
  $protect = [
    '/<script type="application\/ld\+json"[^>]*>.*?<\/script>/si', // JSON-LD
    '/<style amp-custom[^>]*>.*?<\/style>/si',                     // CSS AMP
  ];
  foreach ($protect as $re) {
    $html = preg_replace_callback($re, function($m) use (&$tokens){
      $key = '%%ALPHA_PROTECT_' . count($tokens) . '%%';
      $tokens[$key] = $m[0];
      return $key;
    }, $html);
  }

  // Minifica espaços entre tags e quebras de linha, sem mexer no conteúdo protegido
  // Remove múltiplos espaços, quebras e espaços entre tags
  $html = preg_replace('/>\s+</', '><', $html);
  $html = preg_replace('/\s{2,}/', ' ', $html);
  $html = trim($html);

  // Restaura os blocos protegidos
  if ($tokens) {
    $html = strtr($html, $tokens);
  }
  return $html;
});
?>


<?php
if (!defined('ABSPATH')) exit;
global $post;

// Metas e campos
$pages      = get_post_meta($post->ID, '_alpha_story_pages', true);
$pages      = is_array($pages) ? $pages : [];

$publisher  = get_post_meta($post->ID, '_alpha_story_publisher', true) ?: (alpha_opt('publisher_name') ?: get_bloginfo('name'));

$logo_id    = (int) get_post_meta($post->ID, '_alpha_story_logo_id', true);
$logo_src   = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : (alpha_get_publisher_logo_url() ?: '');

$ga_id    = alpha_get_ga4_id();
$ga_enable= !empty($ga_id);

// Playback (sem amp-bind): valor fixo por página
$autoplay   = function_exists('get_field') ? (bool) get_field('story_autoplay', $post->ID) : true;
$seconds    = (int) (function_exists('get_field') ? (get_field('story_duration', $post->ID) ?: 7) : 7);
$seconds    = $seconds > 0 ? $seconds : 7;

// Poster obrigatório
$poster_id  = get_post_thumbnail_id($post->ID);
$poster     = $poster_id ? wp_get_attachment_image_url($poster_id, 'story_poster') : '';
if (!$poster) { foreach ($pages as $p) { if (!empty($p['image'])) { $poster = esc_url($p['image']); break; } } }
if (!$poster) { $poster = get_stylesheet_directory_uri() . '/assets/story-poster-fallback.jpg'; }
if (!$logo_src) { $logo_src = $poster; }

// Ao menos 1 página
if (count($pages) === 0) {
  $pages[] = ['heading'=>get_the_title($post), 'body'=>'', 'image'=>'', 'cta_text'=>'', 'cta_url'=>''];
}

// Estilo, fonte e acento
$style  = function_exists('get_field') ? (get_field('story_style', $post->ID) ?: 'clean') : 'clean';
$font   = function_exists('get_field') ? (get_field('story_font',  $post->ID) ?: alpha_opt('default_font','inter'))  : alpha_opt('default_font','inter');
$accent = function_exists('get_field') ? (get_field('story_accent_color',$post->ID) ?: alpha_opt('accent_color','#ffffff')) : alpha_opt('accent_color','#ffffff');

// Mapeia Google Fonts
function alpha_font_href($font) {
  switch ($font) {
    case 'inter':        return 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap';
    case 'poppins':      return 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap';
    case 'merriweather': return 'https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700;900&display=swap';
    case 'plusjakarta': return 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;900&display=swap';
    default:             return '';
  }
}
$font_href = alpha_font_href($font);

// Classe do estilo
$style_class = 'style-' . preg_replace('/[^a-z0-9\-]/i', '', $style);
// Família CSS
$font_family = $font === 'system'
  ? "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial,'Noto Sans',sans-serif"
  : ($font === 'merriweather' ? "'Merriweather',serif"
     : ($font === 'poppins' ? "'Poppins',sans-serif" : "'Inter',sans-serif"));
?>
<!doctype html>
<html amp lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html(get_the_title($post)); ?></title>
  <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
  <?php
    // ===== JSON-LD para Web Story (Article + AmpStory) =====
    $permalink     = get_permalink($post);
    $headline      = get_the_title($post);
    $description   = has_excerpt($post)
      ? wp_strip_all_tags(get_the_excerpt($post))
      : wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post)), 35, '…');
    $datePublished = get_post_time('c', true, $post);
    $dateModified  = get_post_modified_time('c', true, $post);
    
    // Imagens (poster + primeiras imagens das páginas)
    $images = [];
    if (!empty($poster_id)) {
      if ($src = wp_get_attachment_image_src($poster_id, 'full')) {
        $images[] = [
          '@type'  => 'ImageObject',
          'url'    => $src[0],
          'width'  => (int) $src[1],
          'height' => (int) $src[2],
        ];
      }
    } elseif (!empty($poster)) {
      $images[] = $poster; // fallback simples
    }
    
    if (!empty($pages) && is_array($pages)) {
      foreach ($pages as $p) {
        if (!empty($p['image'])) {
          $images[] = ['@type' => 'ImageObject', 'url' => esc_url($p['image'])];
        }
      }
    }
    // Remove duplicadas mantendo estrutura
    $images = array_values(array_unique($images, SORT_REGULAR));
    
    // Autor
    $author = [
      '@type' => 'Person',
      'name'  => get_the_author_meta('display_name', $post->post_author),
      'url'   => get_author_posts_url($post->post_author),
    ];
    
    // Publisher + logo
    $publisher_logo = null;
    if (!empty($logo_id) && ($lsrc = wp_get_attachment_image_src($logo_id, 'full'))) {
      $publisher_logo = [
        '@type'  => 'ImageObject',
        'url'    => $lsrc[0],
        'width'  => (int) $lsrc[1],
        'height' => (int) $lsrc[2],
      ];
    } elseif (!empty($logo_src)) {
      $publisher_logo = ['@type' => 'ImageObject', 'url' => $logo_src];
    }
    $publisher_data = [
      '@type' => 'Organization',
      'name'  => $publisher,
    ];
    if ($publisher_logo) $publisher_data['logo'] = $publisher_logo;
    
    // Monta o Article (+ AmpStory opcional)
    $schema = [
      '@context'          => 'https://schema.org',
      '@type'             => ['Article','AmpStory'],
      'mainEntityOfPage'  => ['@type' => 'WebPage', '@id' => $permalink],
      'headline'          => wp_strip_all_tags($headline),
      'description'       => $description,
      'image'             => $images,
      'datePublished'     => $datePublished,
      'dateModified'      => $dateModified,
      'author'            => $author,
      'publisher'         => $publisher_data,
    ];
    
    ?>
    <script type="application/ld+json">
    <?php echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>
    </script>

  <link rel="canonical" href="<?php echo esc_url(get_permalink($post)); ?>">

  <?php if ($font_href): ?>
    <link rel="stylesheet" href="<?php echo esc_url($font_href); ?>">
  <?php endif; ?>

  <style amp-boilerplate>
    body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;
    -moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;
    -ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;
    animation:-amp-start 8s steps(1,end) 0s 1 normal both}
    @-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}
    @-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}
    @-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}
    @-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}
    @keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}
  </style>
  <noscript><style amp-boilerplate>
    body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}
  </style></noscript>

  <!-- AMP scripts: apenas UMA vez cada -->
  <script async src="https://cdn.ampproject.org/v0.js"></script>
  <script async custom-element="amp-story" src="https://cdn.ampproject.org/v0/amp-story-1.0.js"></script>
  <?php if ($ga_enable): ?>
      <script async custom-element="amp-analytics" src="https://cdn.ampproject.org/v0/amp-analytics-0.1.js"></script>
      <script async custom-element="amp-story-auto-analytics" src="https://cdn.ampproject.org/v0/amp-story-auto-analytics-0.1.js"></script>
  <?php endif; ?>
  
  <style amp-custom>
    /* Fonte e estilos base */
    amp-story{ font-family: <?php echo $font_family; ?>; }
    .pad{ padding:24px }
    .h2{ font-size:26px; line-height:1.1; color:#fff; margin:0 0 10px; text-shadow:0 4px 24px rgba(0,0,0,.35);padding-left: 15px; border-left: 3px solid <?php echo esc_html($accent); ?>;}
    .p{ font-size:18px; color:#fff; margin:0; text-shadow:0 4px 24px rgba(0,0,0,.35) }
    .btn{ display:inline-block; padding:12px 20px;color:#000; border-radius:10px; text-decoration:none; font-weight:700; box-shadow:0 4px 24px rgba(0,0,0,.35) }
    .bg{ width:100%; height:100%; background:#000 center / cover no-repeat }
    .overlay{ position:absolute; top:0; right:0; bottom:0; left:0; background:linear-gradient(180deg, rgba(0,0,0,.35), rgba(0,0,0,.55)) }

    /* CLEAN - texto central com imagem de fundo */
    .style-clean .layer-content{align-content: end;justify-content: center;text-align: left;padding-bottom: 120px;}
    /* garante que a layer possa receber o pseudo-elemento */
    .style-clean .layer-content{
      position: relative;
    }
    
    /* overlay de gradiente: transparente no topo e preto no rodapé */
    .style-clean .layer-content::before{content:"";position:absolute;inset:0;pointer-events:none;background: linear-gradient(to bottom, rgba(0, 0, 0, 0) 46%, rgba(0, 0, 0, 0.5) 64%, rgba(0, 0, 0, .8) 90%);; z-index: -1;}

    /* DARK-LEFT - overlay e texto à esquerda */
    .style-dark-left .layer-content{ align-content:center; justify-content:center; text-align:left; padding:40px; }
    .style-dark-left .overlay{ background:linear-gradient(120deg, rgba(0,0,0,.65), rgba(0,0,0,.2) 60%); }
    .style-dark-left .h2, .style-dark-left .p{ text-shadow:0 4px 30px rgba(0,0,0,.8); }

    /* CARD - imagem em cartão, texto abaixo */
    .style-card .card{
      width:78%; max-width:820px; height:58%;
      border-radius:24px; overflow:hidden;
      background:#111 center / cover no-repeat;
      box-shadow:0 4px 24px rgba(0,0,0,.35);
      margin:0 auto 18px auto;
    }
    
    amp-story-grid-layer{
        border-bottom: 5px solid <?php echo esc_html($accent); ?>;
    }
    
    .style-card .layer-content{ align-content:end; justify-content:end; text-align:center; padding:24px; }

    /* SPLIT - imagem esquerda, texto direita */
    .style-split .split{ display:flex; align-items:center; height:100%; padding:24px; }
    .style-split .split .left{
      width:45%; height:80%; border-radius:20px;
      background:#111 center / cover no-repeat; box-shadow:0 4px 24px rgba(0,0,0,.35); margin-right:24px;
    }
    .style-split .split .right{ flex:1; color:#fff; }
    .style-split .right .h2{ margin-bottom:12px }
    /* Fundo desfocado tipo Web Stories plugin */
    .bg-blur { filter: blur(22px) saturate(1.1); transform: scale(1.08); }

/* opcional: você já tem .overlay; ela escurece por cima do blur */

  </style>
</head>
<body>
<amp-story
  standalone
  class="<?php echo esc_attr($style_class); ?>"
  title="<?php echo esc_attr(get_the_title($post)); ?>"
  publisher="<?php echo esc_attr($publisher); ?>"
  publisher-logo-src="<?php echo esc_url($logo_src); ?>"
  poster-portrait-src="<?php echo esc_url($poster); ?>"
>

<?php
    if ($ga_enable): ?>
        <amp-story-auto-analytics gtag-id="<?php echo esc_attr($ga_id); ?>"></amp-story-auto-analytics>
    <?php endif; 
  
    $i = 1;
    foreach ($pages as $p):
      $p = array_merge([
        'image' => '', 'heading' => '', 'body' => '',
        'cta_url' => '', 'cta_text' => '', 'cta_type' => '', 'cta_icon' => '', 'duration' => null
      ], (array) $p);

      $img = $p['image'] ? esc_url($p['image']) : '';
      $dur = $p['duration'] ? (int)$p['duration'] : (int)$seconds;

      // CTA (fallback = swipe)
      $cta_url  = !empty($p['cta_url'])  ? esc_url($p['cta_url']) : '';
      $cta_text = !empty($p['cta_text']) ? esc_html($p['cta_text']) : 'Saiba mais';
      $cta_type = !empty($p['cta_type']) ? $p['cta_type'] : ($cta_url ? 'swipe' : '');
      $cta_icon = !empty($p['cta_icon']) ? esc_url($p['cta_icon']) : '';
      $is_first = ($i === 1);
      if ($is_first && $cta_type === 'button') $cta_type = 'swipe';

      // Animações só a partir do 2º slide
      $anim = ($i > 1);
      // presets por estilo:
      $anim_card_div = $anim ? ' animate-in="fly-in-right" animate-in-delay="0s" animate-in-duration="350ms" animate-in-timing-function="ease-out"' : '';
      $anim_h2_clean = $anim ? ' animate-in="fly-in-bottom" animate-in-delay="0.08s" animate-in-duration="360ms" animate-in-timing-function="ease-out"' : '';
      $anim_p_clean  = $anim ? ' animate-in="fade-in"      animate-in-delay="0.20s" animate-in-duration="360ms" animate-in-timing-function="ease-out"' : '';

      $anim_left_split = $anim ? ' animate-in="fly-in-left"  animate-in-delay="0s"    animate-in-duration="360ms" animate-in-timing-function="ease-out"' : '';
      $anim_h2_split   = $anim ? ' animate-in="fade-in"       animate-in-delay="0.12s" animate-in-duration="360ms" animate-in-timing-function="ease-out"' : '';
      $anim_p_split    = $anim ? ' animate-in="fly-in-bottom" animate-in-delay="0.22s" animate-in-duration="360ms" animate-in-timing-function="ease-out"' : '';
  ?>
    <amp-story-page
      id="p<?php echo (int)$i; ?>"
      <?php if ($autoplay): ?>auto-advance-after="<?php echo (int)$dur; ?>s"<?php endif; ?>
    >
      <?php if ($style === 'card'): ?>
        <!-- Fundo desfocado da própria imagem + overlay -->
        <amp-story-grid-layer template="fill">
          <?php if ($img): ?>
            <amp-img layout="fill" src="<?php echo $img; ?>" alt=""></amp-img>
          <?php else: ?>
            <div class="bg" style="background:#000;"></div>
          <?php endif; ?>
          <div class="overlay"></div>
        </amp-story-grid-layer>

        <!-- Conteúdo -->
        <amp-story-grid-layer template="vertical" class="layer-content">
          <div class="card"
               <?php if ($img): ?>style="background-image:url('<?php echo $img; ?>');"<?php endif; ?>
               <?php echo $anim_card_div; ?>></div>

          <?php if (!empty($p['heading'])): ?>
            <h2 class="h2"<?php echo $anim_h2_clean; ?>><?php echo esc_html($p['heading']); ?></h2>
          <?php endif; ?>

          <?php if (!empty($p['body'])): ?>
            <p class="p"<?php echo $anim_p_clean; ?>><?php echo esc_html($p['body']); ?></p>
          <?php endif; ?>
        </amp-story-grid-layer>

      <?php elseif ($style === 'split'): ?>
        <!-- Fundo desfocado + overlay -->
        <amp-story-grid-layer template="fill">
          <?php if ($img): ?>
            <amp-img layout="fill" src="<?php echo $img; ?>" alt=""></amp-img>
          <?php else: ?>
            <div class="bg" style="background:#000;"></div>
          <?php endif; ?>
          <div class="overlay"></div>
        </amp-story-grid-layer>

        <!-- Conteúdo em colunas -->
        <amp-story-grid-layer template="vertical">
          <div class="split">
            <div class="left"
                 <?php if ($img): ?>style="background-image:url('<?php echo $img; ?>');"<?php endif; ?>
                 <?php echo $anim_left_split; ?>></div>
            <div class="right">
              <?php if (!empty($p['heading'])): ?>
                <h2 class="h2"<?php echo $anim_h2_split; ?>><?php echo esc_html($p['heading']); ?></h2>
              <?php endif; ?>
              <?php if (!empty($p['body'])): ?>
                <p class="p"<?php echo $anim_p_split; ?>><?php echo esc_html($p['body']); ?></p>
              <?php endif; ?>
            </div>
          </div>
        </amp-story-grid-layer>

      <?php else: ?>
        <!-- CLEAN / DARK-LEFT: fundo desfocado -->
        <amp-story-grid-layer template="fill">
          <?php if ($img): ?>
            <amp-img layout="fill" src="<?php echo $img; ?>" alt=""></amp-img>
          <?php else: ?>
            <div class="bg" style="background:#000;"></div>
          <?php endif; ?>
          <?php if ($style === 'dark-left'): ?><div class="overlay"></div><?php endif; ?>
        </amp-story-grid-layer>

        <amp-story-grid-layer template="vertical" class="layer-content pad">
          <?php if (!empty($p['heading'])): ?>
            <h2 class="h2"<?php echo $anim_h2_clean; ?>><?php echo esc_html($p['heading']); ?></h2>
          <?php endif; ?>
          <?php if (!empty($p['body'])): ?>
            <p class="p"<?php echo $anim_p_clean; ?>><?php echo esc_html($p['body']); ?></p>
          <?php endif; ?>
        </amp-story-grid-layer>
      <?php endif; ?>

      <?php if ($cta_url): ?>
        <?php if ($cta_type === 'button' && !$is_first): ?>
          <!-- Botão 1-tap: última layer -->
          <amp-story-cta-layer>
            <a class="btn" href="<?php echo $cta_url; ?>" target="_blank" rel="noreferrer"><?php echo $cta_text; ?></a>
          </amp-story-cta-layer>
        <?php elseif ($cta_type === 'swipe'): ?>
          <!-- Swipe up: último filho da página -->
          <amp-story-page-outlink
            layout="nodisplay"
            theme="dark"
            <?php if ($cta_icon): ?>cta-image="<?php echo $cta_icon; ?>"<?php endif; ?>
          >
            <a href="<?php echo $cta_url; ?>" target="_blank" rel="noreferrer"><?php echo $cta_text; ?></a>
          </amp-story-page-outlink>
        <?php endif; ?>
      <?php endif; ?>

    </amp-story-page>
  <?php $i++; endforeach; ?>
</amp-story>
</body>
