<?php

defined('ABSPATH') || exit;

class DMI_Admin {

    private static $instance = null;
    private $drive;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->drive = new DMI_Google_Drive();

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_init', [$this, 'handle_disconnect']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX
        add_action('wp_ajax_dmi_import_files', [$this, 'ajax_import_files']);
        add_action('wp_ajax_dmi_list_folder', [$this, 'ajax_list_folder']);
        add_action('wp_ajax_dmi_thumbnail', [$this, 'ajax_thumbnail']);
    }

    public function add_menu(): void {
        add_menu_page(
            'Drive Importer',
            'Drive Importer',
            'upload_files',
            'dmi-import',
            [$this, 'render_import_page'],
            'dashicons-cloud-upload',
            81
        );

        add_submenu_page(
            'dmi-import',
            'Importar',
            'Importar',
            'upload_files',
            'dmi-import',
            [$this, 'render_import_page']
        );

        add_submenu_page(
            'dmi-import',
            'Configuracion',
            'Configuracion',
            'manage_options',
            'dmi-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('dmi_settings', 'dmi_client_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('dmi_settings', 'dmi_client_secret', ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'dmi-') === false) {
            return;
        }

        wp_enqueue_style('dmi-admin', DMI_PLUGIN_URL . 'assets/css/admin.css', [], DMI_VERSION);
        wp_enqueue_script('dmi-admin', DMI_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], DMI_VERSION, true);
        wp_localize_script('dmi-admin', 'dmi', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('dmi_nonce'),
            'thumb_url' => admin_url('admin-ajax.php') . '?action=dmi_thumbnail&nonce=' . wp_create_nonce('dmi_nonce') . '&file_id=',
        ]);
    }

    public function handle_oauth_callback(): void {
        if (empty($_GET['dmi_oauth_callback']) || empty($_GET['code'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $success = $this->drive->handle_oauth_callback(sanitize_text_field($_GET['code']));

        wp_redirect(admin_url('admin.php?page=dmi-settings&dmi_auth=' . ($success ? 'ok' : 'error')));
        exit;
    }

    public function handle_disconnect(): void {
        if (empty($_GET['dmi_disconnect']) || empty($_GET['_wpnonce'])) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'dmi_disconnect') || !current_user_can('manage_options')) {
            return;
        }

        $this->drive->disconnect();
        wp_redirect(admin_url('admin.php?page=dmi-settings&dmi_auth=disconnected'));
        exit;
    }

    /* ───── Settings Page ───── */

    public function render_settings_page(): void {
        $is_connected = $this->drive->is_connected();
        $is_configured = $this->drive->is_configured();
        ?>
        <div class="wrap dmi-wrap">
            <h1>Drive Media Importer - Configuracion</h1>

            <?php if (!empty($_GET['dmi_auth'])): ?>
                <?php $status = sanitize_text_field($_GET['dmi_auth']); ?>
                <div class="notice notice-<?php echo $status === 'ok' ? 'success' : ($status === 'disconnected' ? 'info' : 'error'); ?> is-dismissible">
                    <p>
                        <?php
                        switch ($status) {
                            case 'ok':           echo 'Conectado correctamente con Google Drive.'; break;
                            case 'disconnected': echo 'Desconectado de Google Drive.'; break;
                            default:             echo 'Error al conectar. Verifica tus credenciales.';
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Instrucciones -->
            <div class="dmi-card">
                <h2>Como configurar</h2>
                <ol>
                    <li>Ve a <a href="https://console.cloud.google.com/apis/dashboard" target="_blank">Google Cloud Console</a></li>
                    <li>Crea un proyecto (o selecciona uno existente)</li>
                    <li>Ve a <strong>APIs y servicios > Biblioteca</strong> y activa <strong>Google Drive API</strong></li>
                    <li>Ve a <strong>APIs y servicios > Credenciales</strong></li>
                    <li>Crea credenciales de tipo <strong>ID de cliente OAuth 2.0</strong> (tipo: Aplicacion web)</li>
                    <li>En <strong>URIs de redireccionamiento autorizados</strong> agrega:
                        <code><?php echo esc_html(admin_url('admin.php?page=dmi-settings&dmi_oauth_callback=1')); ?></code>
                    </li>
                    <li>Copia el <strong>Client ID</strong> y <strong>Client Secret</strong> aqui abajo</li>
                    <li>Si tu organizacion requiere verificacion, agrega los usuarios de prueba en la <strong>Pantalla de consentimiento OAuth</strong></li>
                </ol>
            </div>

            <!-- Formulario de credenciales -->
            <div class="dmi-card">
                <h2>Credenciales de Google API</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('dmi_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="dmi_client_id">Client ID</label></th>
                            <td>
                                <input type="text" id="dmi_client_id" name="dmi_client_id"
                                       value="<?php echo esc_attr(get_option('dmi_client_id')); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="dmi_client_secret">Client Secret</label></th>
                            <td>
                                <input type="password" id="dmi_client_secret" name="dmi_client_secret"
                                       value="<?php echo esc_attr(get_option('dmi_client_secret')); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Guardar credenciales'); ?>
                </form>
            </div>

            <!-- Conexion OAuth -->
            <div class="dmi-card">
                <h2>Conexion con Google Drive</h2>
                <?php if ($is_connected): ?>
                    <p class="dmi-status dmi-status--ok">Conectado</p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dmi-settings&dmi_disconnect=1'), 'dmi_disconnect')); ?>"
                       class="button">Desconectar</a>
                <?php elseif ($is_configured): ?>
                    <p class="dmi-status dmi-status--warn">No conectado</p>
                    <a href="<?php echo esc_url($this->drive->get_auth_url()); ?>" class="button button-primary">
                        Conectar con Google Drive
                    </a>
                <?php else: ?>
                    <p class="dmi-status dmi-status--error">Configura las credenciales primero</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ───── Import Page ───── */

    public function render_import_page(): void {
        $is_connected = $this->drive->is_connected();
        ?>
        <div class="wrap dmi-wrap">
            <h1>Drive Media Importer</h1>

            <?php if (!$is_connected): ?>
                <div class="notice notice-warning">
                    <p>Necesitas <a href="<?php echo esc_url(admin_url('admin.php?page=dmi-settings')); ?>">configurar y conectar</a> tu cuenta de Google Drive primero.</p>
                </div>
                <?php return; endif; ?>

            <!-- Importar por URLs -->
            <div class="dmi-card">
                <h2>Importar por URLs de Google Drive</h2>
                <p>Pega las URLs de Google Drive (una por linea). Soporta enlaces de archivo individuales y de carpetas.</p>
                <textarea id="dmi-urls" rows="6" class="large-text"
                          placeholder="https://drive.google.com/file/d/XXXXX/view&#10;https://drive.google.com/drive/folders/XXXXX&#10;..."></textarea>
                <p>
                    <button id="dmi-btn-import" class="button button-primary button-hero">
                        Importar imagenes
                    </button>
                </p>
            </div>

            <!-- Importar por Folder ID -->
            <div class="dmi-card">
                <h2>Explorar carpeta de Drive</h2>
                <p>Pega la URL o ID de una carpeta para ver su contenido y seleccionar que importar.</p>
                <div class="dmi-folder-input">
                    <input type="text" id="dmi-folder-url" class="regular-text"
                           placeholder="URL o ID de carpeta de Google Drive" />
                    <button id="dmi-btn-explore" class="button">Explorar carpeta</button>
                </div>
                <div id="dmi-folder-results" style="display:none;">
                    <div class="dmi-folder-actions">
                        <label><input type="checkbox" id="dmi-select-all" /> Seleccionar todas</label>
                        <button id="dmi-btn-import-selected" class="button button-primary">Importar seleccionadas</button>
                    </div>
                    <div id="dmi-folder-grid" class="dmi-grid"></div>
                </div>
            </div>

            <!-- Log -->
            <div class="dmi-card" id="dmi-log-card" style="display:none;">
                <h2>Progreso</h2>
                <div class="dmi-progress">
                    <div class="dmi-progress-bar" id="dmi-progress-bar"></div>
                </div>
                <div id="dmi-log" class="dmi-log"></div>
            </div>
        </div>
        <?php
    }

    /* ───── AJAX: Importar archivos ───── */

    public function ajax_import_files(): void {
        check_ajax_referer('dmi_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $file_ids = array_filter(array_map('sanitize_text_field', $_POST['file_ids'] ?? []));

        if (empty($file_ids)) {
            wp_send_json_error('No se proporcionaron archivos.');
        }

        $results = [];
        foreach ($file_ids as $file_id) {
            $results[] = array_merge(
                $this->drive->download_and_import($file_id),
                ['drive_id' => $file_id]
            );
        }

        wp_send_json_success($results);
    }

    /* ───── AJAX: Listar carpeta ───── */

    public function ajax_list_folder(): void {
        check_ajax_referer('dmi_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $folder_id = sanitize_text_field($_POST['folder_id'] ?? '');

        if (empty($folder_id)) {
            wp_send_json_error('ID de carpeta no proporcionado.');
        }

        $images = $this->drive->list_folder_images($folder_id);

        if (empty($images)) {
            wp_send_json_error('No se encontraron imagenes en la carpeta (o no tienes acceso).');
        }

        wp_send_json_success($images);
    }

    /* ───── AJAX: Proxy de miniaturas ───── */

    public function ajax_thumbnail(): void {
        check_ajax_referer('dmi_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_die('Forbidden', 403);
        }

        $file_id = sanitize_text_field($_GET['file_id'] ?? '');

        if (empty($file_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $file_id)) {
            wp_die('Invalid file ID', 400);
        }

        $thumb = $this->drive->get_thumbnail($file_id);

        if (!$thumb) {
            // Devolver un placeholder SVG 1x1 transparente
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-cache');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#f0f0f1"/><text x="100" y="105" text-anchor="middle" fill="#999" font-family="sans-serif" font-size="14">Sin preview</text></svg>';
            wp_die();
        }

        header('Content-Type: ' . $thumb['content_type']);
        header('Cache-Control: public, max-age=3600');
        echo $thumb['body'];
        wp_die();
    }
}
