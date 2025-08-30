<?php

add_filter('update_plugins_pluginsalpha.com', function ($update, $plugin_data, $plugin_file, $locales) {
    // Aqui voc锚 pode montar os dados dinamicamente ou puxar de um JSON externo
    $json = wp_remote_get('https://pluginsalpha.com/wp-content/uploads/storys/update/storys.json');

    if (is_wp_error($json)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($json), true);

    if (!isset($body['new_version'])) {
        return false;
    }

    return [
        'id'           => $plugin_data['UpdateURI'],
        'slug'         => 'alpha-storys',
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
