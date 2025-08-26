<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
  add_menu_page(
    'Alpha Stories', 'Alpha Stories',
    'manage_options', 'alpha-stories',
    'alpha_admin_dashboard_screen',
    'dashicons-slides', 25
  );

  // Adicionar novo (CPT)
  add_submenu_page('alpha-stories','Adicionar novo','Adicionar novo','edit_posts','post-new.php?post_type=alpha_story', null);

  // IA em Massa
  add_submenu_page('alpha-stories','IA em Massa','IA em Massa','manage_options','alpha-stories-bulk','alpha_admin_bulk_ai_screen');

  // Configurações
  add_submenu_page('alpha-stories','Configurações','Configurações','manage_options','alpha-stories-settings','alpha_admin_settings_screen');
});


/** Registrar settings */
add_action('admin_init', function(){
  register_setting('alpha_stories_group', 'alpha_stories_options', [
    'type' => 'array',
    'sanitize_callback' => function($in){
      $out = is_array($in) ? $in : [];
      // Publisher
      $out['publisher_name']   = sanitize_text_field($out['publisher_name'] ?? '');
      $out['publisher_logo_id']= (int) ($out['publisher_logo_id'] ?? 0);
      // Estilo/Playback
      $allowed_styles = ['clean','dark-left','card','split'];
      $out['default_style']    = in_array(($out['default_style'] ?? ''), $allowed_styles, true) ? $out['default_style'] : 'clean';
      $allowed_fonts  = ['system','inter','poppins','merriweather'];
      $out['default_font']     = in_array(($out['default_font'] ?? ''), $allowed_fonts, true) ? $out['default_font'] : 'inter';
      $out['accent_color']     = preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', ($out['accent_color'] ?? '')) ? $out['accent_color'] : '#ffffff';
      $out['autoplay']         = !empty($out['autoplay']) ? 1 : 0;
      $out['duration']         = in_array(($out['duration'] ?? '7'), ['5','7','10','12'], true) ? $out['duration'] : '7';
      // Analytics
      $mode = $out['ga_mode'] ?? 'auto';
      $out['ga_mode']          = in_array($mode, ['auto','manual','off'], true) ? $mode : 'auto';
      $id = trim((string) ($out['ga_manual_id'] ?? ''));
      $out['ga_manual_id']     = preg_match('/^G-[A-Z0-9\-]{4,}$/i', $id) ? $id : '';
      $out['ai_api_key']      = sanitize_text_field($out['ai_api_key'] ?? '');
        $out['ai_model']        = sanitize_text_field($out['ai_model'] ?? 'gpt-4o-mini');
        $out['ai_temperature']  = is_numeric($out['ai_temperature'] ?? null) ? (float)$out['ai_temperature'] : 0.4;
        $out['ai_brief_default']= wp_kses_post($out['ai_brief_default'] ?? '');
      return $out;
    }
  ]);
});

/** Enqueue uploader só na tela de settings do plugin */
add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'toplevel_page_alpha-stories' && $hook !== 'alpha-stories_page_alpha-stories-settings') return;
  wp_enqueue_media();
  wp_enqueue_script('alpha-stories-admin', ALPHA_STORIES_URL.'assets/js/admin.js', ['jquery'], '1.0.1', true);
});

/** Dashboard (simples) */
function alpha_admin_dashboard_screen(){
  if (!current_user_can('manage_options')) return;
  $ga_auto = alpha_get_ga4_id();
  ?>
  <div class="wrap">
    <h1>Alpha Stories · Dashboard</h1>
    <p>Aqui você pode ver um resumo rápido e links úteis.</p>
    <ul>
      <li><strong>Stories publicadas:</strong> <?php echo (int) wp_count_posts('alpha_story')->publish; ?></li>
      <li><strong>Estilo padrão:</strong> <?php echo esc_html(alpha_opt('default_style','clean')); ?></li>
      <li><strong>GA4 (auto):</strong> <?php echo $ga_auto ? esc_html($ga_auto) : '<em>não detectado</em>'; ?></li>
    </ul>
    <p><a href="<?php echo esc_url( admin_url('admin.php?page=alpha-stories-settings') ); ?>" class="button button-primary">Abrir Configurações</a></p>
  </div>
  <?php
}

/** Settings screen */
function alpha_admin_settings_screen(){
  if (!current_user_can('manage_options')) return;
  $o = alpha_stories_options();
  $logo_url = alpha_get_publisher_logo_url();
  ?>
  <div class="wrap">
    <h1>Alpha Stories · Configurações</h1>
    <form method="post" action="options.php">
      <?php settings_fields('alpha_stories_group'); ?>
      <?php $o = alpha_stories_options(); ?>

      <h2 class="title">Publisher</h2>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="publisher_name">Nome do publisher</label></th>
          <td><input name="alpha_stories_options[publisher_name]" id="publisher_name" type="text" class="regular-text" value="<?php echo esc_attr($o['publisher_name'] ?? get_bloginfo('name')); ?>"></td>
        </tr>
        <tr>
          <th scope="row">Logo (96x96)</th>
          <td>
            <div style="margin-bottom:8px;">
              <img id="alpha_logo_preview" src="<?php echo esc_url($logo_url ?: ''); ?>" style="max-width:96px;height:auto;<?php echo $logo_url?'':'display:none'; ?>">
            </div>
            <input type="hidden" id="publisher_logo_id" name="alpha_stories_options[publisher_logo_id]" value="<?php echo (int) ($o['publisher_logo_id'] ?? 0); ?>">
            <button type="button" class="button" id="alpha_logo_btn">Selecionar imagem</button>
            <button type="button" class="button" id="alpha_logo_clear" style="margin-left:8px;">Remover</button>
          </td>
        </tr>
      </table>

      <h2 class="title">Estilo & Playback (padrão)</h2>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="default_style">Preset de estilo</label></th>
          <td>
            <select name="alpha_stories_options[default_style]" id="default_style">
              <?php
              $choices = ['clean'=>'Clean','dark-left'=>'Dark Left','card'=>'Card','split'=>'Split'];
              foreach ($choices as $val=>$lab){
                printf('<option value="%s"%s>%s</option>', esc_attr($val), selected(($o['default_style']??'clean'),$val,false), esc_html($lab));
              }
              ?>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="default_font">Fonte</label></th>
          <td>
            <select name="alpha_stories_options[default_font]" id="default_font">
              <?php
              $fonts = ['system'=>'System UI','inter'=>'Inter','poppins'=>'Poppins','merriweather'=>'Merriweather', 'plusjakarta' => 'Plus Jakarta Sans',];
              foreach ($fonts as $val=>$lab){
                printf('<option value="%s"%s>%s</option>', esc_attr($val), selected(($o['default_font']??'plusjakarta'),$val,false), esc_html($lab));
              }
              ?>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="accent_color">Cor de destaque</label></th>
          <td><input name="alpha_stories_options[accent_color]" id="accent_color" type="text" class="regular-text" value="<?php echo esc_attr($o['accent_color'] ?? '#ffffff'); ?>" placeholder="#ffffff"></td>
        </tr>
        <tr>
          <th scope="row">Autoplay</th>
          <td>
            <label><input type="checkbox" name="alpha_stories_options[autoplay]" value="1" <?php checked(!empty($o['autoplay'])); ?>> Ativar</label>
            &nbsp;&nbsp;
            <label for="duration">Tempo por página (s)</label>
            <select name="alpha_stories_options[duration]" id="duration">
              <?php foreach (['5','7','10','12'] as $d) printf('<option value="%s"%s>%ss</option>', $d, selected(($o['duration']??'7'),$d,false), $d); ?>
            </select>
          </td>
        </tr>
      </table>

      <h2 class="title">Analytics</h2>
      <table class="form-table">
        <tr>
          <th scope="row">Modo</th>
          <td>
            <label><input type="radio" name="alpha_stories_options[ga_mode]" value="auto"   <?php checked(($o['ga_mode']??'auto'),'auto'); ?>> Auto (tentar Site Kit)</label><br>
            <label><input type="radio" name="alpha_stories_options[ga_mode]" value="manual" <?php checked(($o['ga_mode']??'auto'),'manual'); ?>> Manual</label><br>
            <label><input type="radio" name="alpha_stories_options[ga_mode]" value="off"    <?php checked(($o['ga_mode']??'auto'),'off'); ?>> Desativado</label>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="ga_manual_id">GA4 Measurement ID (Manual)</label></th>
          <td>
            <input name="alpha_stories_options[ga_manual_id]" id="ga_manual_id" type="text" class="regular-text" placeholder="G-XXXXXXXXXX" value="<?php echo esc_attr($o['ga_manual_id'] ?? ''); ?>">
            <p class="description">Usado apenas se “Manual” estiver selecionado.</p>
          </td>
        </tr>
      </table>

    <h2 class="title">ChatGPT / IA</h2>
    <table class="form-table">
      <tr>
        <th scope="row"><label for="ai_api_key">OpenAI API Key</label></th>
        <td>
          <input name="alpha_stories_options[ai_api_key]" id="ai_api_key" type="password" class="regular-text" value="<?php echo esc_attr($o['ai_api_key'] ?? ''); ?>" placeholder="sk-...">
          <p class="description">Você também pode definir a constante <code>ALPHA_OPENAI_KEY</code> no wp-config.php.</p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="ai_model">Modelo</label></th>
        <td>
          <input name="alpha_stories_options[ai_model]" id="ai_model" type="text" class="regular-text" value="<?php echo esc_attr($o['ai_model'] ?? 'gpt-4o-mini'); ?>">
          <p class="description">Ex.: gpt-4o-mini (rápido e econômico) — pode trocar depois.</p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="ai_temperature">Temperatura</label></th>
        <td>
          <input name="alpha_stories_options[ai_temperature]" id="ai_temperature" type="number" min="0" max="1" step="0.1" value="<?php echo esc_attr($o['ai_temperature'] ?? '0.4'); ?>">
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="ai_brief_default">Brief padrão</label></th>
        <td>
          <textarea name="alpha_stories_options[ai_brief_default]" id="ai_brief_default" class="large-text" rows="4" placeholder="tom, público, CTA padrão, nº ideal de slides etc."><?php echo esc_textarea($o['ai_brief_default'] ?? ''); ?></textarea>
        </td>
      </tr>
    </table>


      <?php submit_button(); ?>
    </form>
  </div>
  <?php
}
