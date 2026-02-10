# Drive Media Importer

Plugin de WordPress para importar imagenes desde Google Drive (Workspace) directamente a la biblioteca de medios.

Pensado para organizaciones que trabajan con Google Workspace y necesitan trasladar imagenes de Drive a WordPress sin descargarlas manualmente al ordenador.

## Funcionalidades

- **OAuth2 con Google Workspace** - Accede a archivos compartidos internamente en tu organizacion (no necesitan ser publicos).
- **Importar por URLs** - Pega una o varias URLs de Google Drive (archivos individuales o carpetas), una por linea.
- **Explorar carpetas** - Introduce la URL de una carpeta, visualiza las imagenes en un grid y selecciona cuales importar.
- **Importacion masiva** - Descarga secuencial con barra de progreso y log en tiempo real.
- **Formatos soportados** - JPG, PNG, WebP, GIF, SVG.
- **Shared Drives** - Compatible con unidades compartidas de la organizacion.

## Estructura del plugin

```
drive-media-importer/
├── drive-media-importer.php          # Archivo principal del plugin
├── includes/
│   ├── class-dmi-admin.php           # Paginas de administracion y AJAX
│   └── class-dmi-google-drive.php    # Integracion con Google Drive API y OAuth2
└── assets/
    ├── css/admin.css                 # Estilos del panel de administracion
    └── js/admin.js                   # Logica de importacion en el frontend
```

## Requisitos

- WordPress 5.0+
- PHP 7.4+
- Cuenta de Google Cloud con acceso a la consola
- Google Drive API habilitada

## Instalacion

1. Copia la carpeta `drive-media-importer` en `wp-content/plugins/`.
2. Activa el plugin desde **Plugins** en el panel de WordPress.

## Configuracion

### 1. Crear credenciales en Google Cloud

1. Ve a [Google Cloud Console](https://console.cloud.google.com/apis/dashboard).
2. Crea un proyecto nuevo o selecciona uno existente.
3. Ve a **APIs y servicios > Biblioteca** y activa **Google Drive API**.
4. Ve a **APIs y servicios > Credenciales**.
5. Crea credenciales de tipo **ID de cliente OAuth 2.0** (tipo: Aplicacion web).
6. En **URIs de redireccionamiento autorizados** agrega la URI que te indica el plugin en su pagina de configuracion. Tiene este formato:
   ```
   https://tu-dominio.com/wp-admin/admin.php?page=dmi-settings&dmi_oauth_callback=1
   ```
7. Copia el **Client ID** y **Client Secret** generados.

### 2. Configurar el plugin

1. En WordPress, ve a **Drive Importer > Configuracion**.
2. Introduce el **Client ID** y **Client Secret**.
3. Pulsa **Guardar credenciales**.
4. Pulsa **Conectar con Google Drive**.
5. Autoriza con tu cuenta de Google de la organizacion.

### 3. Pantalla de consentimiento OAuth (organizaciones)

Si tu organizacion requiere verificacion de apps:

1. En Google Cloud Console, ve a **APIs y servicios > Pantalla de consentimiento OAuth**.
2. Configura como **Interno** (solo usuarios de tu organizacion) o **Externo** si necesitas acceso mas amplio.
3. Si es externo y esta en modo de prueba, anade los correos de los usuarios que usaran el plugin en **Usuarios de prueba**.

## Uso

### Importar por URLs

1. Ve a **Drive Importer > Importar**.
2. Pega las URLs de Google Drive en el area de texto (una por linea).
3. Pulsa **Importar imagenes**.
4. El plugin descargara cada imagen y la registrara en la biblioteca de medios.

Formatos de URL soportados:
```
https://drive.google.com/file/d/XXXXX/view
https://drive.google.com/open?id=XXXXX
https://drive.google.com/drive/folders/XXXXX
```

### Explorar carpeta

1. Pega la URL o ID de una carpeta de Google Drive.
2. Pulsa **Explorar carpeta**.
3. Se mostrara un grid con las imagenes encontradas.
4. Selecciona las que quieras importar (o usa "Seleccionar todas").
5. Pulsa **Importar seleccionadas**.

## Permisos

- Los usuarios con capacidad `upload_files` pueden importar imagenes.
- Solo los usuarios con capacidad `manage_options` (administradores) pueden configurar las credenciales y conectar/desconectar la cuenta de Google.
