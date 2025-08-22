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
// Variables para repoblar el formulario en caso de error
$dni = ''; $nombre = ''; $apellidos = ''; $email = ''; 
$telefono = ''; $tipo = '';

// --- Lógica de PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger y limpiar datos del formulario
    $dni = trim($_POST['dni'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');

    // --- Validación de Datos ---
    if (empty($nombre)) $errors[] = "El nombre es obligatorio.";
    if (empty($apellidos)) $errors[] = "Los apellidos son obligatorios.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "El correo electrónico no es válido.";
    if (empty($tipo)) $errors[] = "Debe seleccionar un tipo de personal.";
    
    // Verificar si el DNI o el email ya existen
    if (empty($errors)) {
        if (!empty($dni)) {
            $stmt_check_dni = $pdo->prepare("SELECT id FROM personal WHERE dni = ?");
            $stmt_check_dni->execute([$dni]);
            if ($stmt_check_dni->fetch()) {
                $errors[] = "El DNI ya está registrado.";
            }
        }
        
        $stmt_check_email = $pdo->prepare("SELECT id FROM personal WHERE email = ?");
        $stmt_check_email->execute([$email]);
        if ($stmt_check_email->fetch()) {
            $errors[] = "El correo electrónico ya está en uso.";
        }
    }

    // --- Si no hay errores, proceder a la inserción ---
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO personal (dni, nombre, apellidos, email, telefono, tipo) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$dni, $nombre, $apellidos, $email, $telefono, $tipo]);

            $_SESSION['success_message'] = "Registro de personal creado exitosamente.";
            header("Location: gestionar_personal.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = "Error al crear el registro en la base de datos.";
            error_log("Error al crear personal: " . $e->getMessage());
        }
    }
}

$page_title = "Registrar Nuevo Personal";
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Por favor, corrija los siguientes errores:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card card-primary">
    <div class="card-header">
        <h3 class="card-title">Formulario de Registro de Personal</h3>
    </div>
    <form action="crear_personal.php" method="POST">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="nombre">Nombre(s) *</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre) ?>" required>
                </div>
                <div class="col-md-6 form-group">
                    <label for="apellidos">Apellidos *</label>
                    <input type="text" class="form-control" id="apellidos" name="apellidos" value="<?= htmlspecialchars($apellidos) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 form-group">
                    <label for="dni">DNI</label>
                    <input type="text" class="form-control" id="dni" name="dni" value="<?= htmlspecialchars($dni) ?>">
                </div>
                <div class="col-md-8 form-group">
                    <label for="email">Correo Electrónico *</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" class="form-control" id="telefono" name="telefono" value="<?= htmlspecialchars($telefono) ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label for="tipo">Tipo de Personal *</label>
                    <select class="form-control" id="tipo" name="tipo" required>
                        <option value="">-- Seleccionar tipo --</option>
                        <option value="docente" <?= ($tipo == 'docente') ? 'selected' : '' ?>>Docente</option>
                        <option value="administrativo" <?= ($tipo == 'administrativo') ? 'selected' : '' ?>>Administrativo</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Registrar Personal</button>
            <a href="gestionar_personal.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/header.php';
?>
