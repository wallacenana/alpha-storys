<?php
if (!defined('ABSPATH')) exit;

function alpha_admin_bulk_ai_screen(){
  if (!current_user_can('manage_options')) return;

  // parâmetros
  $did    = false;
  $rows   = [];
  $errors = [];

  // defaults UI
  $status   = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'draft';
  $cats_sel = isset($_POST['cats']) ? array_map('intval', (array)$_POST['cats']) : [];
  $date_from= isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
  $date_to  = isset($_POST['date_to'])   ? sanitize_text_field($_POST['date_to'])   : '';
  $per_page = isset($_POST['per_page'])  ? max(1, (int)$_POST['per_page']) : 10;
  $offset   = isset($_POST['offset'])    ? max(0, (int)$_POST['offset'])   : 0;
  $only_new = !empty($_POST['only_new']);
  $overwrite= !empty($_POST['overwrite']);
  $generator= in_array(($_POST['generator'] ?? 'ai'), ['ai','parser'], true) ? $_POST['generator'] : 'ai';
  $brief    = trim((string)($_POST['brief'] ?? ''));

  if (!empty($_POST['alpha_bulk_nonce']) && wp_verify_nonce($_POST['alpha_bulk_nonce'], 'alpha_bulk_ai')) {

    // monta WP_Query nos POSTS existentes
    $args = [
      'post_type'      => 'post',
      'post_status'    => ($status === 'any') ? ['publish','draft','pending','future','private'] : $status,
      'posts_per_page' => $per_page,
      'offset'         => $offset,
      'orderby'        => 'date',
      'order'          => 'DESC',
    ];

    if ($cats_sel){
      $args['tax_query'] = [[
        'taxonomy' => 'category',
        'field'    => 'term_id',
        'terms'    => $cats_sel,
      ]];
    }
    if ($date_from || $date_to){
      $dq = [];
      if ($date_from) $dq['after']  = $date_from;
      if ($date_to)   $dq['before'] = $date_to;
      $dq['inclusive'] = true;
      $args['date_query'] = [$dq];
    }
    if ($only_new){
      // pega posts que NÃO possuem relação _alpha_story_id
      $args['meta_query'] = [[
        'key'     => '_alpha_story_id',
        'compare' => 'NOT EXISTS',
      ]];
    }

    $q = new WP_Query($args);
    if ($q->have_posts()){
      while ($q->have_posts()){ $q->the_post();
        $pid = get_the_ID();

        // pega/cria a story espelhada deste post
        $sid = alpha_get_or_create_story_for_post($pid);
        if (!$sid){ $errors[] = "Falha ao criar story para post #$pid"; continue; }

        // decide de onde vêm as páginas
        if ($generator === 'ai'){
          $res = alpha_ai_generate_for_post($sid, $brief);
          if (is_wp_error($res)){
            $rows[] = ['post_id'=>$pid,'story_id'=>$sid,'ok'=>false,'msg'=>$res->get_error_message()];
          } else {
            $rows[] = ['post_id'=>$pid,'story_id'=>$sid,'ok'=>true,'count'=>$res['count'],'source'=>'AI'];
          }
        } else {
          // PARSER (H2 e <hr>)
          $html  = get_post_field('post_content', $pid);
          $pages = alpha_build_story_pages_from_content($html);
          if (!$pages){ 
            $rows[] = ['post_id'=>$pid,'story_id'=>$sid,'ok'=>false,'msg'=>'Parser não encontrou seções.'];
          } else {
            if ($overwrite || !get_post_meta($sid, '_alpha_story_pages', true)){
              update_post_meta($sid, '_alpha_story_pages', $pages);
            }
            $rows[] = ['post_id'=>$pid,'story_id'=>$sid,'ok'=>true,'count'=>count($pages),'source'=>'parser'];
          }
        }

        // poster/thumbnail: se a story não tiver, usa do post
        if (!has_post_thumbnail($sid) && has_post_thumbnail($pid)){
          set_post_thumbnail($sid, get_post_thumbnail_id($pid));
        }
      }
      wp_reset_postdata();
    }
    $did = true;
  }

  // UI
  ?>
  <div class="wrap">
    <h1>Gerar Stories em Massa a partir de Posts</h1>
    <form method="post">
      <?php wp_nonce_field('alpha_bulk_ai','alpha_bulk_nonce'); ?>

      <table class="form-table">
        <tr>
          <th scope="row">Status</th>
          <td>
            <select name="status">
              <option value="publish" <?php selected($status,'publish'); ?>>Publicados</option>
              <option value="draft"   <?php selected($status,'draft'); ?>>Rascunhos</option>
              <option value="any"     <?php selected($status,'any'); ?>>Qualquer</option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row">Categorias</th>
          <td>
            <?php
              wp_dropdown_categories([
                'taxonomy'         => 'category',
                'name'             => 'cats[]',
                'hide_empty'       => false,
                'hierarchical'     => true,
                'show_option_all'  => '(todas)',
                'selected'         => $cats_sel ? $cats_sel[0] : 0,
              ]);
            ?>
            <p class="description">Se precisar de múltiplas categorias, rode mais de uma vez (UI simples).</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Intervalo de datas</th>
          <td>
            De <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            até <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>">
          </td>
        </tr>
        <tr>
          <th scope="row">Lote</th>
          <td>
            <label>Itens por execução: <input type="number" name="per_page" value="<?php echo (int)$per_page; ?>" min="1" max="100"></label>
            &nbsp; &nbsp;
            <label>Offset: <input type="number" name="offset" value="<?php echo (int)$offset; ?>" min="0"></label>
            <p class="description">Use offset para processar em “páginas” e evitar timeout.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Filtro</th>
          <td>
            <label><input type="checkbox" name="only_new" <?php checked($only_new); ?>> Apenas posts sem story vinculada</label><br>
            <label><input type="checkbox" name="overwrite" <?php checked($overwrite); ?>> Sobrescrever páginas existentes</label>
          </td>
        </tr>
        <tr>
          <th scope="row">Gerador</th>
          <td>
            <label><input type="radio" name="generator" value="ai" <?php checked($generator,'ai'); ?>> IA (OpenAI)</label><br>
            <label><input type="radio" name="generator" value="parser" <?php checked($generator,'parser'); ?>> Parser (H2 e &lt;hr&gt;)</label>
          </td>
        </tr>
        <tr>
          <th scope="row">Brief (IA, opcional)</th>
          <td><textarea name="brief" class="large-text" rows="3" placeholder="tom, público, CTA…"><?php echo esc_textarea($brief); ?></textarea></td>
        </tr>
      </table>

      <?php submit_button('Processar lote'); ?>
    </form>

    <?php if ($did): ?>
      <h2>Resultado</h2>
      <table class="widefat striped">
        <thead><tr><th>Post</th><th>Story</th><th>Status</th><th>Detalhes</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <a href="<?php echo esc_url(get_edit_post_link($r['post_id'])); ?>">
                  #<?php echo (int)$r['post_id']; ?> — <?php echo esc_html(get_the_title($r['post_id'])); ?>
                </a>
              </td>
              <td>
                <a href="<?php echo esc_url(get_edit_post_link($r['story_id'])); ?>">
                  #<?php echo (int)$r['story_id']; ?>
                </a>
              </td>
              <td><?php echo !empty($r['ok']) ? 'OK' : 'Erro'; ?></td>
              <td>
                <?php
                  if (!empty($r['ok'])) {
                    echo 'Páginas: '.(int)$r['count'];
                    if (!empty($r['source'])) echo ' · Fonte: '.esc_html($r['source']);
                  } else {
                    echo esc_html($r['msg'] ?? 'Falha');
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p>
        <!-- atalho pra continuar o próximo lote -->
        <form method="post" style="display:inline;">
          <?php wp_nonce_field('alpha_bulk_ai','alpha_bulk_nonce'); ?>
          <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
          <?php foreach ($cats_sel as $c): ?>
            <input type="hidden" name="cats[]" value="<?php echo (int)$c; ?>">
          <?php endforeach; ?>
          <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
          <input type="hidden" name="date_to"   value="<?php echo esc_attr($date_to); ?>">
          <input type="hidden" name="per_page"  value="<?php echo (int)$per_page; ?>">
          <input type="hidden" name="offset"    value="<?php echo (int)($offset + $per_page); ?>">
          <input type="hidden" name="only_new"  value="<?php echo $only_new ? 1 : 0; ?>">
          <input type="hidden" name="overwrite" value="<?php echo $overwrite ? 1 : 0; ?>">
          <input type="hidden" name="generator" value="<?php echo esc_attr($generator); ?>">
          <input type="hidden" name="brief"     value="<?php echo esc_attr($brief); ?>">
          <?php submit_button('Continuar próximo lote', 'secondary', '', false); ?>
        </form>
      </p>
    <?php endif; ?>
  </div>
  <?php
}

