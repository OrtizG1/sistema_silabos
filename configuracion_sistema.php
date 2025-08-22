<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad: Solo los administradores pueden acceder.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Acceso denegado.");
}

$errors = [];
$config = [];

// --- LÓGICA PARA CARGAR LA CONFIGURACIÓN ACTUAL ---
try {
    $stmt_load = $pdo->query("SELECT parametro, valor FROM configuraciones");
    $config_raw = $stmt_load->fetchAll(PDO::FETCH_KEY_PAIR); // Crea un array [parametro => valor]
    $config = $config_raw;
} catch (PDOException $e) {
    $errors[] = "Error fatal al cargar la configuración del sistema.";
    error_log("Error al cargar configuraciones: " . $e->getMessage());
}

// --- LÓGICA PARA GUARDAR LA CONFIGURACIÓN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Aquí recibiremos todos los campos del formulario.
    // Por ejemplo: $_POST['nombre_institucion'], $_POST['email_notificaciones'], etc.

    // Usaremos una transacción para asegurar que todas las actualizaciones se completen o ninguna lo haga.
    try {
        $pdo->beginTransaction();
        
        $sql = "UPDATE configuraciones SET valor = :valor WHERE parametro = :parametro";
        $stmt_update = $pdo->prepare($sql);

        // Iteramos sobre cada valor recibido del POST para actualizarlo
        foreach ($_POST as $parametro => $valor) {
            // Asegurarnos de no intentar guardar el botón de submit u otros campos no deseados
            if (array_key_exists($parametro, $config)) {
                $stmt_update->execute([':valor' => trim($valor), ':parametro' => $parametro]);
            }
        }

        $pdo->commit();
        $_SESSION['success_message'] = "Configuración guardada exitosamente.";
        header("Location: configuracion_sistema.php");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Error al guardar la configuración en la base de datos.";
        error_log("Error al guardar configuración: " . $e->getMessage());
    }
}

$page_title = "Configuración del Sistema";
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['success_message']) ?><button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card card-primary">
    <div class="card-header">
        <h3 class="card-title">Parámetros Generales del Sistema</h3>
    </div>
    <form action="configuracion_sistema.php" method="POST">
        <div class="card-body">
            <div class="form-group">
                <label for="nombre_institucion">Nombre de la Institución</label>
                <input type="text" class="form-control" id="nombre_institucion" name="nombre_institucion" 
                       value="<?= htmlspecialchars($config['nombre_institucion'] ?? '') ?>">
                <small class="form-text text-muted">Aparecerá en los documentos y cabeceras.</small>
            </div>

            <div class="form-group">
                <label for="email_notificaciones">Email para Notificaciones</label>
                <input type="email" class="form-control" id="email_notificaciones" name="email_notificaciones" 
                       value="<?= htmlspecialchars($config['email_notificaciones'] ?? '') ?>">
                <small class="form-text text-muted">La dirección de correo desde donde el sistema enviará emails.</small>
            </div>

            <div class="form-group">
                <label for="logo_url">URL del Logo</label>
                <input type="text" class="form-control" id="logo_url" name="logo_url" 
                       value="<?= htmlspecialchars($config['logo_url'] ?? '') ?>">
                <small class="form-text text-muted">Ruta relativa a la carpeta `public` (ej: `dist/img/mi_logo.png`). Por ahora es un campo de texto, más adelante podemos implementar la subida de archivos.</small>
            </div>
            
            <div class="form-group">
                <label for="formato_pdf">Plantilla para PDF</label>
                <input type="text" class="form-control" id="formato_pdf" name="formato_pdf" 
                       value="<?= htmlspecialchars($config['formato_pdf'] ?? '') ?>">
                <small class="form-text text-muted">Define el nombre de la plantilla a usar para generar los PDFs de los sílabos (funcionalidad futura).</small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>