<?php
if (!defined('ABSPATH')) exit;

// Metabox “IA / Geração”
add_action('add_meta_boxes', function(){
  add_meta_box(
    'alpha_ai_box',
    'Alpha Storys — IA',
    'alpha_ai_autogen_cb',
    ['post'], // ajuste o post type se precisar
    'side',
    'high'
  );
});

function alpha_ai_autogen_cb($post) {
  // nonce específico do AJAX “gerar agora”
  $ajax_nonce = wp_create_nonce('alpha_ai_generate_now');

  // status da licença no servidor (sem criar endpoint novo)
  $license_valid = function_exists('alpha_client_is_license_valid') ? (bool) alpha_client_is_license_valid() : true;
  // URL da tela de licença (equivalente a site() + /wp-admin/admin.php?page=alpha-storys-license)
  $license_url   = admin_url('admin.php?page=alpha-storys-license');
  ?>

  <p>
    <button type="button" class="button button-primary" id="alpha_ai_generate_now">Gerar story agora</button>
    <span id="alpha_ai_generate_now_status" style="margin-left:8px;"></span>
  </p>

  <!-- SweetAlert2 (carrega se não existir) -->
  <script>
  (function () {
    if (!window.Swal && !document.getElementById('swal2-cdn')) {
      var s = document.createElement('script');
      s.id = 'swal2-cdn';
      s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
      s.defer = true;
      document.head.appendChild(s);
    }
  })();
  </script>

  <script>
  (function(){
    const btn = document.getElementById('alpha_ai_generate_now');
    const st  = document.getElementById('alpha_ai_generate_now_status'); // fallback visual (não usamos muito)
    if (!btn) return;

    const LICENSE_VALID = <?php echo $license_valid ? 'true' : 'false'; ?>;
    const LICENSE_URL   = '<?php echo esc_js($license_url); ?>';

    async function ensureSwal(){
      for (let i=0;i<30;i++){
        if (window.Swal) return true;
        await new Promise(r=>setTimeout(r,100));
      }
      return false;
    }

    btn.addEventListener('click', async () => {
      btn.disabled = true;
      st.textContent = ''; // status por SweetAlert

      try {
        // 1) Licença
        if (!LICENSE_VALID) {
          if (await ensureSwal()) {
            Swal.fire({
              icon: 'info',
              title: 'Licença necessária',
              html: 'Para gerar o Web Story, ative sua licença em <strong>Alpha Storys → Licença</strong>.<br><br>'
                    + '<a class="button button-primary" href="'+LICENSE_URL+'">Abrir configurações de licença</a>',
              confirmButtonText: 'OK'
            });
          } else {
            alert('Para gerar o Web Story, ative sua licença em Alpha Storys → Licença.');
          }
          return;
        }

        // 2) Loading “Gerando…”
        if (await ensureSwal()) {
          Swal.fire({
            title: 'Gerando…',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => { Swal.showLoading(); }
          });
        } else {
          st.textContent = 'Gerando…';
        }

        // 3) Chamada que você já tinha
        const res = await fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: new URLSearchParams({
            action:    'alpha_ai_generate_now',
            source_id: '<?php echo (int) $post->ID; ?>',
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

        // 4) Sucesso — SweetAlert
        const count = json.data.count || 0;
        const edit  = json.data.edit_url ? '<a href="'+json.data.edit_url+'" target="_blank" rel="noreferrer">editar</a>' : '';
        const view  = json.data.view_url ? '<a href="'+json.data.view_url+'" target="_blank" rel="noreferrer">ver</a>' : '';
        const sep   = (edit && view) ? ' · ' : '';

        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: 'Story gerado com sucesso',
            html: '(<strong>'+count+'</strong> páginas) — ' + edit + sep + view,
            confirmButtonText: 'OK'
          });
        } else {
          st.innerHTML = 'OK ('+count+' páginas) — ' + edit + sep + view;
        }

      } catch (e) {
        if (window.Swal) {
          Swal.fire({ icon: 'error', title: 'Ops…', text: e.message || 'Erro inesperado', confirmButtonText: 'OK' });
        } else {
          st.textContent = 'Erro: ' + (e.message || 'Erro inesperado');
        }
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

  // permissão: vai gravar storys irmã => precisa editar o post de origem
  if (!current_user_can('edit_post', $source_id)) {
    wp_send_json_error(['message' => 'Permissão negada.'], 403);
  }

  if (!function_exists('alpha_ai_get_api_key') || !alpha_ai_get_api_key()) {
    wp_send_json_error(['message' => 'Configure a OpenAI API Key nas Configurações.'], 400);
  }

  // Gera (a função cria/atualiza a irmã alpha_storys e retorna target_id)
  $res = alpha_ai_generate_for_post($source_id);
  if (is_wp_error($res)) {
    wp_send_json_error(['message' => $res->get_error_message()], 500);
  }

  $target_id = (int)($res['target_id'] ?? 0);
  if (!$target_id) {
    // fallback: tenta descobrir a irmã
    if (function_exists('alpha_storys_get_or_create_storys')) {
      $tmp = alpha_storys_get_or_create_storys($source_id);
      if (!is_wp_error($tmp)) $target_id = (int)$tmp;
    }
  }

  wp_send_json_success([
    'preview'  => (bool)$preview,
    'count'    => (int)($res['count'] ?? 0),
    'storysId'  => $target_id,
    'edit_url' => $target_id ? get_edit_post_link($target_id, 'raw') : '',
    'view_url' => $target_id ? get_permalink($target_id) : '',
    'message'  => 'Story gerada/atualizada com sucesso.',
  ]);
}
