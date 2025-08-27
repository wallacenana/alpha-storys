<?php

add_filter('update_plugins_assets.alphaform.com.br/storys', function ($update, $plugin_data, $plugin_file, $locales) {
    // Aqui você pode montar os dados dinamicamente ou puxar de um JSON externo
    $json = wp_remote_get('https://assets.alphaform.com.br/storys/update/storys.json');

    if (is_wp_error($json)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($json), true);

    if (!isset($body['new_version'])) {
        return false;
    }

    return [
        'id'           => $plugin_data['UpdateURI'], // obrigatório
        'slug'         => 'alpha-storys',       // opcional, mas bom pra compatibilidade
        'version'      => $body['new_version'],
        'new_version'  => $body['new_version'],
        'url'          => $body['url'],
        'package'      => $body['download_url'],
        'tested'       => $body['tested'] ?? '6.5',
        'requires'     => $body['requires'] ?? '5.0',
        'requires_php' => $body['requires_php'] ?? '7.2',
        'icons'        => $body['icons'] ?? [],
        'banners'      => $body['banners'] ?? [],
        'upgrade_notice' => $body['upgrade_notice'] ?? '',
    ];
}, 10, 4);
