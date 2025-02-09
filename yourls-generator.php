<?php
/**
 * Plugin Name: ShortURL Generator
 * Description: Fügt eine Textbox im Admin-Editorbereich ein, um Shortlinks zu generieren. Du brauchst eine Yourls-Installation und ein Sicherheitstoken.
 * Version: 1.1
 * Author: BÜRO BATTENBERG
 */

// Verhindert direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Add Plugin Settings Link to Plugins List Table
function shorturl_add_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=shorturl_settings">' . __( 'Settings' ) . '</a>';
    array_push( $links, $settings_link );
    return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'shorturl_add_settings_link' );

// Registriere die Settings-Seite im Admin-Menü
function shorturl_add_settings_page() {
    add_options_page(
        'ShortURL Einstellungen',
        'ShortURL Generator',
        'manage_options',
        'shorturl_settings',
        'shorturl_settings_page'
    );
}
add_action('admin_menu', 'shorturl_add_settings_page');

// Registriere die Einstellungen
function shorturl_register_settings() {
    register_setting('shorturl_settings_group', 'shorturl_api_url');
    register_setting('shorturl_settings_group', 'shorturl_api_token');
}
add_action('admin_init', 'shorturl_register_settings');

// Callback-Funktion für die Settings-Seite
function shorturl_settings_page() {
    ?>
    <div class="wrap">
        <h1>ShortURL Einstellungen</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('shorturl_settings_group');
            do_settings_sections('shorturl_settings_group');
            ?>
            <table class="form-table">
                <tr>
                  <th>Info</th>
                  <td>
                    <p>
                      Der ShortUrl Generator funktioniert mit einer Yourls Installation.
                      <br>
                      Du benötigst dafür die URL und das Sicherheitstoken.
                    </p>
                    <p>
                      So installierst Du Yourls: <a target="_blank" href="https://yourls.org/docs">https://yourls.org/docs</a>
                    </p>
                    <p>
                      Es ist zudem notwendig das User-Plugin <a href="https://github.com/mountbatt/yourls-check-existing-url" target="_blank">yourls-check-existing-url</a> in Deiner Yoast Installation zu installieren und aktivieren.
                    </p>
                  </td>
                </tr>
                <tr>
                    <th><label for="shorturl_api_url">YOURLS API-URL:</label></th>
                    <td><input type="text" id="shorturl_api_url" name="shorturl_api_url" value="<?php echo esc_attr(get_option('shorturl_api_url', '')); ?>" placeholder="z.B.: https://short.mysite.com" class="regular-text no-deepl"></td>
                </tr>
                <tr>
                    <th><label for="shorturl_api_token">Sicherheitstoken:</label></th>
                    <td><input type="password" id="shorturl_api_token" name="shorturl_api_token" value="<?php echo esc_attr(get_option('shorturl_api_token', '')); ?>" class="regular-text">
                    <br>
                    <small>Das Token findest Du auf der "Tools"-Seite im Yourls Admin</small>
                  </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Prüft, ob eine ShortURL existiert
function shorturl_check_existing($post_url) {
    $api_url = get_option('shorturl_api_url', '').'/yourls-api.php';
    $api_token = get_option('shorturl_api_token', '');
    
    if (!$api_url || !$api_token) {
        return false;
    }

    $request_url = "{$api_url}?signature={$api_token}&action=check_url_exists&url=" . urlencode($post_url) . "&format=json";
    $response = wp_remote_get($request_url);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    return $data ?? false;
}

// AJAX-Handler für ShortURL-Anfrage
function shorturl_generate() {
    if (!isset($_POST['post_url']) || !isset($_POST['keyword'])) {
        wp_send_json_error('Ungültige Anfrage.');
    }

    $post_url = esc_url_raw($_POST['post_url']);
    $keyword = sanitize_text_field($_POST['keyword']);
    
    $api_url = get_option('shorturl_api_url', '').'/yourls-api.php';
    $api_token = get_option('shorturl_api_token', '');

    if (!$api_url || !$api_token) {
        wp_send_json_error('API-URL oder Token nicht konfiguriert.');
    }

    $request_url = "{$api_url}?signature={$api_token}&action=shorturl&url=" . urlencode($post_url) . "&keyword=" . urlencode($keyword) . "&format=json";
    $response = wp_remote_get($request_url);
    
    if (is_wp_error($response)) {
        wp_send_json_error('Fehler beim Erstellen der ShortURL.');
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['shorturl'])) {
        wp_send_json_success(['shorturl' => $data['shorturl'], 'message' => $data['message'] ?? '']);
    } else {
        wp_send_json_error('Fehlerhafte Antwort von der API.');
    }
}
add_action('wp_ajax_shorturl_generate', 'shorturl_generate');

// Funktion zum Hinzufügen der Meta-Box im Admin-Editorbereich
function shorturl_meta_box() {
    $post_types = get_post_types(['public' => true], 'names');
    foreach ($post_types as $post_type) {
        add_meta_box(
            'shorturl_meta_box_id',
            'ShortURL Generator',
            'shorturl_meta_box_callback',
            $post_type,
            'side',
            'high'
        );
    }
}
add_action('add_meta_boxes', 'shorturl_meta_box');


// Callback-Funktion zur Anzeige der Meta-Box
function shorturl_meta_box_callback($post) {
    $post_url = get_permalink($post->ID);
    $post_status = get_post_status($post->ID);
    $existing_shorturl = shorturl_check_existing($post_url);
    ?>
    <div id="shorturl-widget">
        <?php if ($post_status !== 'publish') : ?>
            <p style="color: red; font-weight: 400;">⚠️ Dieser Beitrag ist noch nicht veröffentlicht. ShortURL wird erst nach Veröffentlichung verfügbar.</p>
        <?php endif; ?>
        <?php if ($post_status == 'publish') : ?>
          <?php if (!$existing_shorturl['shorturl']) : ?>
            <label for="shorturl-keyword">Keyword eingeben:</label>
            <input type="text" style="width: 100%; margin-top:3px;" id="shorturl-keyword" placeholder="z. B. meinlink">
            <br><br>
            <button id="generate-shorturl" class="button">ShortURL generieren</button>
          <?php endif; ?>
          <p id="shorturl-result">
              <?php if ($existing_shorturl['shorturl']) : ?>
                  <strong>Bestehende ShortURL:</strong> <br><a href="<?php echo esc_url($existing_shorturl['shorturl']); ?>" target="_blank"><?php echo esc_url($existing_shorturl['shorturl']); ?></a>&nbsp;
                  
                  <hr><?php echo $existing_shorturl['clicks']; ?> Aufruf(e)<br>
                  <a target="_blank" href="<?php echo esc_url($existing_shorturl['shorturl']); ?>+">Statistik in YOURLS ansehen</a>
              <?php endif; ?>
          </p>
          <p id="shorturl-message" style="word-wrap: break-word;"></p>
        <?php endif; ?>
    </div>
    <script>
        document.getElementById("generate-shorturl").addEventListener("click", function(event) {
            event.preventDefault(); // Verhindert das Neuladen der Seite
            let keyword = document.getElementById("shorturl-keyword").value;
            let postUrl = "<?php echo esc_url($post_url); ?>";
            
            fetch(ajaxurl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams({
                    action: "shorturl_generate",
                    post_url: postUrl,
                    keyword: keyword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById("shorturl-result").innerHTML = `<strong>Generierte ShortURL:</strong> <a href="${data.data.shorturl}" target="_blank">${data.data.shorturl}</a>`;
                    document.getElementById("shorturl-message").innerText = data.data.message ? `${data.data.message}` : '';
                } else {
                    document.getElementById("shorturl-result").innerText = "Fehler: " + data.data;
                    document.getElementById("shorturl-message").innerText = '';
                }
            })
            .catch(error => {
                document.getElementById("shorturl-result").innerText = "Fehler beim Erstellen der ShortURL.";
                document.getElementById("shorturl-message").innerText = '';
            });
        });
    </script>
    <?php
}
