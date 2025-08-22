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
$nombre = '';
$fecha_inicio = '';
$fecha_fin = '';
$estado = 'inactivo'; // Por defecto, los nuevos períodos se crean como inactivos

// --- LÓGICA DE PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger y limpiar datos del formulario
    $nombre = trim($_POST['nombre'] ?? '');
    $fecha_inicio = trim($_POST['fecha_inicio'] ?? '');
    $fecha_fin = trim($_POST['fecha_fin'] ?? '');
    $estado = trim($_POST['estado'] ?? '');

    // --- Validación de Datos ---
    if (empty($nombre)) $errors[] = "El nombre del período es obligatorio.";
    if (empty($fecha_inicio)) $errors[] = "La fecha de inicio es obligatoria.";
    if (empty($fecha_fin)) $errors[] = "La fecha de fin es obligatoria.";
    if (empty($estado)) $errors[] = "Debe seleccionar un estado.";

    // Validación de fechas
    if (!empty($fecha_inicio) && !empty($fecha_fin)) {
        if (strtotime($fecha_fin) <= strtotime($fecha_inicio)) {
            $errors[] = "La fecha de fin debe ser posterior a la fecha de inicio.";
        }
    }

    // Verificar si el nombre del período ya existe
    if (empty($errors)) {
        $stmt_check = $pdo->prepare("SELECT id FROM periodos_academicos WHERE nombre = ?");
        $stmt_check->execute([$nombre]);
        if ($stmt_check->fetch()) {
            $errors[] = "Ya existe un período académico con ese nombre.";
        }
    }

    // --- Si no hay errores, proceder a la inserción ---
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO periodos_academicos (nombre, fecha_inicio, fecha_fin, estado) 
                    VALUES (?, ?, ?, ?)";
            
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$nombre, $fecha_inicio, $fecha_fin, $estado]);

            $_SESSION['success_message'] = "Período académico creado exitosamente.";
            header("Location: gestionar_periodos.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = "Error al crear el período en la base de datos.";
            error_log("Error al crear período académico: " . $e->getMessage());
        }
    }
}

$page_title = "Crear Nuevo Período Académico";
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Por favor, corrija los siguientes errores:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card card-primary">
    <div class="card-header">
        <h3 class="card-title">Formulario de Creación de Período</h3>
    </div>
    <form action="crear_periodo.php" method="POST">
        <div class="card-body">
            <div class="form-group">
                <label for="nombre">Nombre del Período *</label>
                <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: 2025-I" value="<?= htmlspecialchars($nombre) ?>" required>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="fecha_inicio">Fecha de Inicio *</label>
                    <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" required>
                </div>
                <div class="col-md-6 form-group">
                    <label for="fecha_fin">Fecha de Fin *</label>
                    <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="estado">Estado *</label>
                <select class="form-control" id="estado" name="estado" required>
                    <option value="inactivo" <?= ($estado == 'inactivo') ? 'selected' : '' ?>>Inactivo</option>
                    <option value="activo" <?= ($estado == 'activo') ? 'selected' : '' ?>>Activo</option>
                    <option value="cerrado" <?= ($estado == 'cerrado') ? 'selected' : '' ?>>Cerrado</option>
                </select>
                <small class="form-text text-muted">
                    'Inactivo' para futuros períodos, 'Activo' para el actual, 'Cerrado' para períodos pasados.
                </small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Crear Período</button>
            <a href="gestionar_periodos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>