<?php
if (!defined('ABSPATH')) exit;

// Metabox “IA / Geração”
add_action('add_meta_boxes', function(){
  add_meta_box(
    'alpha_ai_box',
    'Alpha Stories — IA',
    'alpha_ai_autogen_cb',
    ['post'], // ajuste o post type se precisar
    'side',
    'high'
  );
});

function alpha_ai_autogen_cb($post) {
  // nonce específico do AJAX “gerar agora”
  $ajax_nonce = wp_create_nonce('alpha_ai_generate_now');
  ?>

  <p>
    <button type="button" class="button button-primary" id="alpha_ai_generate_now">Gerar story agora</button>
    <span id="alpha_ai_generate_now_status" style="margin-left:8px;"></span>
  </p>

  <script>
  (function(){
    const btn = document.getElementById('alpha_ai_generate_now');
    const st  = document.getElementById('alpha_ai_generate_now_status');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      st.textContent = 'Gerando…';
      btn.disabled   = true;
      try {
        const res = await fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: new URLSearchParams({
            action:    'alpha_ai_generate_now',
            source_id: '<?php echo (int) $post->ID; ?>',            // <-- post normal
            nonce:     '<?php echo esc_js( $ajax_nonce ); ?>',
            preview:   '0'
          })
        });

        const raw  = await res.text();
        let json;
        try { json = JSON.parse(raw); }
        catch(e) { throw new Error('Resposta não-JSON: '+ raw.slice(0,200)); }

        if (!json.success) {
          const msg = (json.data && (json.data.message || JSON.stringify(json.data))) || 'Falha';
          throw new Error(msg);
        }

        st.innerHTML = 'OK ('+(json.data.count||0)+' páginas) — ' +
          (json.data.edit_url ? '<a href="'+json.data.edit_url+'">editar</a> · ' : '') +
          (json.data.view_url ? '<a href="'+json.data.view_url+'" target="_blank" rel="noreferrer">ver</a>' : '');
      } catch (e) {
        st.textContent = 'Erro: ' + e.message;
        console.error(e);
      } finally {
        btn.disabled = false;
      }
    });
  })();
  </script>
  <?php
}

add_action('wp_ajax_alpha_ai_generate_now', 'alpha_ajax_ai_generate_now');

function alpha_ajax_ai_generate_now() {
  // valida nonce do AJAX
  check_ajax_referer('alpha_ai_generate_now', 'nonce');

  // pode vir como source_id (preferido) ou post_id (retrocompat)
  $source_id = isset($_POST['source_id']) ? (int) $_POST['source_id'] : (int)($_POST['post_id'] ?? 0);
  $preview   = !empty($_POST['preview']);

  if (!$source_id || !get_post($source_id)) {
    wp_send_json_error(['message' => 'Post de origem inválido.'], 400);
  }

  // só aceito gerar a partir de post
  if (get_post_type($source_id) !== 'post') {
    wp_send_json_error(['message' => 'Geração deve ser feita a partir de um post.'], 400);
  }

  // permissão: vai gravar story irmã => precisa editar o post de origem
  if (!current_user_can('edit_post', $source_id)) {
    wp_send_json_error(['message' => 'Permissão negada.'], 403);
  }

  if (!function_exists('alpha_ai_get_api_key') || !alpha_ai_get_api_key()) {
    wp_send_json_error(['message' => 'Configure a OpenAI API Key nas Configurações.'], 400);
  }

  // Gera (a função cria/atualiza a irmã alpha_story e retorna target_id)
  $res = alpha_ai_generate_for_post($source_id);
  if (is_wp_error($res)) {
    wp_send_json_error(['message' => $res->get_error_message()], 500);
  }

  $target_id = (int)($res['target_id'] ?? 0);
  if (!$target_id) {
    // fallback: tenta descobrir a irmã
    if (function_exists('alpha_story_get_or_create_story')) {
      $tmp = alpha_story_get_or_create_story($source_id);
      if (!is_wp_error($tmp)) $target_id = (int)$tmp;
    }
  }

  wp_send_json_success([
    'preview'  => (bool)$preview,
    'count'    => (int)($res['count'] ?? 0),
    'storyId'  => $target_id,
    'edit_url' => $target_id ? get_edit_post_link($target_id, 'raw') : '',
    'view_url' => $target_id ? get_permalink($target_id) : '',
    'message'  => 'Story gerada/atualizada com sucesso.',
  ]);
}
