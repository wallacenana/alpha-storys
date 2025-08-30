<?php
if (!defined('ABSPATH')) exit;

/**
 * Cliente de licença — Alpha Stories
 * Fala com o servidor: POST https://pluginsalpha.com/?rest_route=/alpha/v1/license
 * Ações: activate|verify|deactivate
 */

if (!defined('ALPHA_LICENSE_SERVER')) {
  // Use a URL "plain" para funcionar mesmo sem permalinks bonitinhos
  define('ALPHA_LICENSE_SERVER', 'https://pluginsalpha.com/?rest_route=/alpha/v1/license');
}
if (!defined('ALPHA_LICENSE_OPTION')) define('ALPHA_LICENSE_OPTION', 'alpha_stories_license');

/* ------------------ Utils e opções ------------------ */
function alpha_client_license_opts(): array {
  $o = get_option(ALPHA_LICENSE_OPTION, []);
  return is_array($o) ? $o : [];
}
function alpha_client_save_license_opts(array $o): void {
  update_option(ALPHA_LICENSE_OPTION, $o, false);
}
/** Normaliza o site como scheme://host/ (igual ao servidor) */
function alpha_client_norm_site(?string $url = null): string {
  $url = $url ?: home_url('/');
  $u = wp_parse_url($url);
  if (!$u || empty($u['host'])) return '';
  $scheme = $u['scheme'] ?? 'https';
  return trailingslashit($scheme . '://' . strtolower($u['host']));
}
function alpha_client_is_license_valid(): bool {
  $o = alpha_client_license_opts();
  if (($o['status'] ?? '') !== 'valid') return false;
  $exp = $o['expires'] ?? '';
  if ($exp) {
    $ts = strtotime($exp . ' 23:59:59');
    if ($ts && $ts < current_time('timestamp')) return false;
  }
  return true;
}

/* ------------------ Chamada ao servidor ------------------ */
function alpha_client_call_server(string $action, ?string $license = null, ?string $site = null): array {
  $opts    = alpha_client_license_opts();
  $license = $license ?? ($opts['key'] ?? '');
  $tx      = $opts['tx']   ?? '';
  $email   = $opts['email']?? '';
  $site    = $site    ?? alpha_client_norm_site();

  $body = [
    'action'  => $action,           // activate|verify|deactivate
    'license' => $license,          // pode estar vazio (server usará tx+email)
    'tx'      => $tx,
    'email'   => $email,
    'site'    => $site,
  ];
  $args = [
    'timeout'     => 20,
    'redirection' => 3,
    'headers'     => [
      'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
      'User-Agent'   => 'AlphaStories/' . (defined('ALPHA_STORIES_VERSION') ? ALPHA_STORIES_VERSION : 'dev') . '; ' . $site,
    ],
    'body'        => $body,
  ];

  $res = wp_remote_post(ALPHA_LICENSE_SERVER, $args);
  if (is_wp_error($res)) {
    return ['ok' => false, 'error' => $res->get_error_message()];
  }
  $code = wp_remote_retrieve_response_code($res);
  $raw  = wp_remote_retrieve_body($res);
  $json = json_decode($raw, true);
  if ($code !== 200 || !is_array($json)) {
    return ['ok' => false, 'error' => 'HTTP '.$code.' / Resposta inválida', 'raw' => $raw];
  }

  return [
    'ok'      => true,
    'status'  => (string)($json['status']  ?? ''),
    'expires' => (string)($json['expires'] ?? ''),
    'raw'     => $json,
  ];
}

/* ------------------ Admin Page: Licença ------------------ */
add_action('admin_menu', function(){
  // Submenu sob o menu principal do plugin (slug 'alpha-stories')
  add_submenu_page(
    'alpha-storys',
    'Licença',
    'Licença',
    'manage_options',
    'alpha-storys-license',
    'alpha_client_license_page_render',
    20
  );
});

function alpha_client_license_page_render() {
  if (!current_user_can('manage_options')) return;

  $msg = '';
  $type = 'updated';
  $opts = alpha_client_license_opts();

  // Processa ações do formulário
  if (!empty($_POST['alpha_license_action']) && check_admin_referer('alpha_license_action_nonce', 'alpha_license_nonce')) {
    $action = sanitize_text_field($_POST['alpha_license_action']);
    $key    = isset($_POST['alpha_license_key']) ? trim((string)$_POST['alpha_license_key']) : ($opts['key'] ?? '');
    $tx     = isset($_POST['alpha_license_tx'])  ? trim((string)$_POST['alpha_license_tx'])  : ($opts['tx'] ?? '');
    $email  = isset($_POST['alpha_license_email']) ? trim((string)$_POST['alpha_license_email']) : ($opts['email'] ?? '');
    if ($action === 'save_key') {
      $opts['key']   = $key;
      $opts['tx']    = $tx;
      $opts['email'] = $email;
      alpha_client_save_license_opts($opts);
      $msg = 'Dados salvos.';
    } elseif (in_array($action, ['activate','verify','deactivate'], true)) {
      $opts['key']   = $key;   // persistir caso tenham mudado
      $opts['tx']    = $tx;
      $opts['email'] = $email;
      alpha_client_save_license_opts($opts);
      $res = alpha_client_call_server($action, $key);
      if (!$key) {
        $msg = 'Informe a chave de licença antes.';
        $type = 'error';
      } else {
        $res = alpha_client_call_server($action, $key);
        if (!empty($res['ok'])) {
          // Atualiza opções locais
          $opts['key']       = $key;
          $opts['status']    = (string)($res['status'] ?? '');
          $opts['expires']   = (string)($res['expires'] ?? '');
          $opts['last_check']= current_time('mysql');
          alpha_client_save_license_opts($opts);
          $msg  = 'Servidor respondeu: '.($opts['status'] ?: 'sem status');
          $type = ($opts['status'] === 'valid') ? 'updated' : 'error';
        } else {
          $msg = 'Falha: ' . ($res['error'] ?? 'desconhecida');
          $type = 'error';
        }
      }
    }
  }

  // UI
  $site     = alpha_client_norm_site();
  $key      = esc_attr($opts['key'] ?? '');
  $status   = esc_html($opts['status'] ?? '—');
  $expires  = esc_html($opts['expires'] ?? '—');
  $last     = esc_html($opts['last_check'] ?? '—');
  $badge    = alpha_client_is_license_valid() ? '<span style="color:#0a0;font-weight:600">VÁLIDA</span>' : '<span style="color:#a00;font-weight:600">INVÁLIDA</span>';
  $key   = esc_attr($opts['key']   ?? '');
  $tx    = esc_attr($opts['tx']    ?? '');
  $email = esc_attr($opts['email'] ?? '');

  echo '<div class="wrap"><h1>Licença — Alpha storys</h1>';

  if ($msg) {
    echo '<div class="'.esc_attr($type).' notice"><p>'.wp_kses_post($msg).'</p></div>';
  }

  echo '<form method="post" style="max-width:760px">';
  wp_nonce_field('alpha_license_action_nonce','alpha_license_nonce');

  echo '<table class="form-table" role="presentation">

    <tr>
      <th scope="row">Status local</th>
      <td>'.$badge.' &nbsp; <code>'. $status .'</code></td>
    </tr>
    <tr>
      <th scope="row"><label for="alpha_license_tx">ID da compra (transação)</label></th>
      <td>
        <input name="alpha_license_tx" id="alpha_license_tx" type="text" class="regular-text" value="'.$tx .'" placeholder="HP16161616116" />
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="alpha_license_email">E-mail do comprador</label></th>
      <td>
        <input name="alpha_license_email" id="alpha_license_email" type="email" class="regular-text" value="'. $email .'" placeholder="email@exemplo.com" />
      </td>
    </tr>
    <tr>
      <th scope="row">Última verificação</th>
      <td>'. $last .'</td>
    </tr>
    <tr>
      <th scope="row">Site vinculado</th>
      <td><code>'. esc_html($site) .'</code></td>
    </tr>

  </table>';

  echo '<p>
    <button class="button button-primary" name="alpha_license_action" value="activate">Ativar</button>
    <button class="button button-secondary" name="alpha_license_action" value="deactivate" onclick="return confirm(\'Desvincular esta instalação?\')">Desativar</button>
  </p>';

  echo '</form></div>';
}

/* ------------------ Verificação automática ------------------ */
add_action('alpha_client_license_cron_verify', function(){
  $opts = alpha_client_license_opts();
  if (empty($opts['key'])) return;
  $res = alpha_client_call_server('verify', $opts['key']);
  if (!empty($res['ok'])) {
    $opts['status']     = (string)($res['status'] ?? '');
    $opts['expires']    = (string)($res['expires'] ?? '');
    $opts['last_check'] = current_time('mysql');
    alpha_client_save_license_opts($opts);
  }
});

// agenda verificação (2x por dia)
register_activation_hook(ALPHA_STORYS_FILE, function(){
  if (!wp_next_scheduled('alpha_client_license_cron_verify')) {
    wp_schedule_event(time()+1800, 'twicedaily', 'alpha_client_license_cron_verify');
  }
});
register_deactivation_hook(ALPHA_STORYS_FILE, function(){
  $ts = wp_next_scheduled('alpha_client_license_cron_verify');
  if ($ts) wp_unschedule_event($ts, 'alpha_client_license_cron_verify');
});

/* ------------------ Helper para proteger features ------------------ */
/**
 * Use antes de ações premium. Ex. no AJAX de "Gerar story agora":
 *
 * if (function_exists('alpha_client_require_valid_license')) {
 *   $err = alpha_client_require_valid_license();
 *   if (is_wp_error($err)) wp_send_json_error(['message' => $err->get_error_message()], 403);
 * }
 */
function alpha_client_require_valid_license() {
  if (alpha_client_is_license_valid()) return true;

  // tenta uma verificação silenciosa antes de bloquear
  $o = alpha_client_license_opts();
  if (!empty($o['key'])) {
    $res = alpha_client_call_server('verify', $o['key']);
    if (!empty($res['ok'])) {
      $o['status']     = (string)($res['status'] ?? '');
      $o['expires']    = (string)($res['expires'] ?? '');
      $o['last_check'] = current_time('mysql');
      alpha_client_save_license_opts($o);
      if (alpha_client_is_license_valid()) return true;
    }
  }
  return new WP_Error('alpha_license_invalid', 'Licença inválida ou expirada. Ative sua licença em Alpha Storys → Licença.');
}
