<?php

defined('ABSPATH') || exit;

class DMI_Google_Drive {

    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $tokens;

    const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    const DRIVE_API    = 'https://www.googleapis.com/drive/v3';
    const SCOPES       = 'https://www.googleapis.com/auth/drive.readonly';

    public function __construct() {
        $this->client_id     = get_option('dmi_client_id', '');
        $this->client_secret = get_option('dmi_client_secret', '');
        $this->redirect_uri  = admin_url('admin.php?page=dmi-settings&dmi_oauth_callback=1');
        $this->tokens        = get_option('dmi_tokens', '');

        if (is_string($this->tokens) && !empty($this->tokens)) {
            $this->tokens = json_decode($this->tokens, true);
        }
    }

    public function is_configured(): bool {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    public function is_connected(): bool {
        return $this->is_configured() && !empty($this->tokens['refresh_token']);
    }

    public function get_auth_url(): string {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
    }

    public function handle_oauth_callback(string $code): bool {
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            return false;
        }

        $body['expires_at'] = time() + ($body['expires_in'] ?? 3600);
        $this->tokens = $body;
        update_option('dmi_tokens', wp_json_encode($body));

        return true;
    }

    public function disconnect(): void {
        $this->tokens = '';
        update_option('dmi_tokens', '');
    }

    private function get_access_token(): ?string {
        if (empty($this->tokens) || !is_array($this->tokens)) {
            return null;
        }

        if (time() >= ($this->tokens['expires_at'] ?? 0) - 60) {
            return $this->refresh_token();
        }

        return $this->tokens['access_token'] ?? null;
    }

    private function refresh_token(): ?string {
        if (empty($this->tokens['refresh_token'])) {
            return null;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $this->tokens['refresh_token'],
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            return null;
        }

        $this->tokens['access_token'] = $body['access_token'];
        $this->tokens['expires_at']   = time() + ($body['expires_in'] ?? 3600);
        update_option('dmi_tokens', wp_json_encode($this->tokens));

        return $body['access_token'];
    }

    /**
     * Extrae el ID de archivo de una URL de Google Drive.
     */
    public static function extract_file_id(string $url): ?string {
        $url = trim($url);

        // /d/FILE_ID/
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        // ?id=FILE_ID
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        // ID directo (sin URL)
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * Extrae el ID de carpeta de una URL de Google Drive.
     */
    public static function extract_folder_id(string $url): ?string {
        $url = trim($url);

        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Obtiene metadatos de un archivo.
     */
    public function get_file_meta(string $file_id): ?array {
        $token = $this->get_access_token();
        if (!$token) {
            return null;
        }

        $response = wp_remote_get(self::DRIVE_API . "/files/{$file_id}?" . http_build_query([
            'fields'             => 'id,name,mimeType,size,imageMediaMetadata',
            'supportsAllDrives'  => 'true',
        ]), [
            'headers' => ['Authorization' => "Bearer {$token}"],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return !empty($body['id']) ? $body : null;
    }

    /**
     * Lista archivos de imagen dentro de una carpeta.
     */
    public function list_folder_images(string $folder_id): array {
        $token = $this->get_access_token();
        if (!$token) {
            return [];
        }

        $images    = [];
        $page_token = null;
        $mime_types = "mimeType='image/jpeg' or mimeType='image/png' or mimeType='image/webp' or mimeType='image/gif' or mimeType='image/svg+xml'";

        do {
            $params = [
                'q'                  => "'{$folder_id}' in parents and ({$mime_types}) and trashed=false",
                'fields'             => 'nextPageToken,files(id,name,mimeType,size)',
                'pageSize'           => 100,
                'supportsAllDrives'  => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];

            if ($page_token) {
                $params['pageToken'] = $page_token;
            }

            $response = wp_remote_get(self::DRIVE_API . '/files?' . http_build_query($params), [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                break;
            }

            $body       = json_decode(wp_remote_retrieve_body($response), true);
            $images     = array_merge($images, $body['files'] ?? []);
            $page_token = $body['nextPageToken'] ?? null;

        } while ($page_token);

        return $images;
    }

    /**
     * Descarga un archivo y lo importa a la biblioteca de medios de WP.
     */
    public function download_and_import(string $file_id, int $post_id = 0): array {
        $token = $this->get_access_token();
        if (!$token) {
            return ['success' => false, 'error' => 'No autenticado con Google Drive.'];
        }

        $meta = $this->get_file_meta($file_id);
        if (!$meta) {
            return ['success' => false, 'error' => "No se pudo obtener información del archivo {$file_id}."];
        }

        $allowed_mimes = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/gif'     => 'gif',
            'image/svg+xml' => 'svg',
        ];

        $mime = $meta['mimeType'] ?? '';
        if (!isset($allowed_mimes[$mime])) {
            return ['success' => false, 'error' => "Tipo de archivo no soportado: {$mime}"];
        }

        // Descargar el archivo
        $download_url = self::DRIVE_API . "/files/{$file_id}?alt=media";
        $response = wp_remote_get($download_url, [
            'headers'  => ['Authorization' => "Bearer {$token}"],
            'timeout'  => 120,
            'stream'   => true,
            'filename' => wp_tempnam($meta['name']),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $tmp_file = $response['filename'];
        if (!file_exists($tmp_file)) {
            return ['success' => false, 'error' => 'Error al descargar el archivo temporal.'];
        }

        // Asegurar extensión correcta en el nombre
        $filename = sanitize_file_name($meta['name']);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $filename .= '.' . $allowed_mimes[$mime];
        }

        $file_array = [
            'name'     => $filename,
            'type'     => $mime,
            'tmp_name' => $tmp_file,
            'error'    => 0,
            'size'     => filesize($tmp_file),
        ];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Limpiar archivo temporal si aún existe
        if (file_exists($tmp_file)) {
            @unlink($tmp_file);
        }

        if (is_wp_error($attachment_id)) {
            return ['success' => false, 'error' => $attachment_id->get_error_message()];
        }

        return [
            'success'       => true,
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url($attachment_id),
            'filename'      => $filename,
        ];
    }
}
