<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad: Solo los administradores pueden acceder.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Acceso denegado.");
}

// ===== INICIO DE CAMBIO: Obtener IDs para el enlace de retorno =====
$escuela_id = filter_input(INPUT_GET, 'escuela_id', FILTER_VALIDATE_INT);
$facultad_id = filter_input(INPUT_GET, 'facultad_id', FILTER_VALIDATE_INT);

if (!$escuela_id || !$facultad_id) {
    $_SESSION['error_message'] = "No se ha especificado una escuela o facultad para crear el plan.";
    header("Location: gestionar_planes.php");
    exit;
}
// ===== FIN DE CAMBIO =====

$errors = [];
$nombre = '';
$descripcion = '';
$estado = 'activo';

// --- LÓGICA DE PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger y limpiar datos del formulario
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    // CAMBIO: Recoger el escuela_id del formulario
    $escuela_id_post = filter_input(INPUT_POST, 'escuela_id', FILTER_VALIDATE_INT);

    // --- Validación de Datos ---
    if (empty($nombre)) {
        $errors[] = "El nombre del plan es obligatorio.";
    }
    if (!in_array($estado, ['activo', 'inactivo'])) {
        $errors[] = "El estado seleccionado no es válido.";
    }
    if (empty($escuela_id_post)) {
        $errors[] = "El ID de la escuela es requerido.";
    }

    // Verificar si el nombre del plan ya existe
    if (empty($errors)) {
        $stmt_check = $pdo->prepare("SELECT id FROM planes_curriculares WHERE nombre = ? AND escuela_id = ?");
        $stmt_check->execute([$nombre, $escuela_id_post]);
        if ($stmt_check->fetch()) {
            $errors[] = "Ya existe un plan curricular con ese nombre para esta escuela.";
        }
    }

    // --- Si no hay errores, proceder a la inserción ---
    if (empty($errors)) {
        try {
            // CAMBIO: La consulta ahora incluye el escuela_id
            $sql = "INSERT INTO planes_curriculares (nombre, descripcion, estado, escuela_id) VALUES (?, ?, ?, ?)";
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$nombre, $descripcion, $estado, $escuela_id_post]);

            $_SESSION['success_message'] = "Plan curricular creado exitosamente.";
            // CAMBIO: Redirigir de vuelta a la vista filtrada
            header("Location: gestionar_planes.php?facultad_id=" . $facultad_id . "&escuela_id=" . $escuela_id_post);
            exit;

        } catch (PDOException $e) {
            $errors[] = "Error al crear el plan en la base de datos.";
            error_log("Error al crear plan curricular: " . $e->getMessage());
        }
    }
}

$page_title = "Crear Nuevo Plan Curricular";
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
        <h3 class="card-title">Formulario de Creación de Plan Curricular</h3>
    </div>
    <!-- CAMBIO: Se añaden los IDs al action del formulario -->
    <form action="crear_plan.php?escuela_id=<?= htmlspecialchars($escuela_id) ?>&facultad_id=<?= htmlspecialchars($facultad_id) ?>" method="POST">
        <!-- CAMBIO: Campo oculto para enviar el escuela_id -->
        <input type="hidden" name="escuela_id" value="<?= htmlspecialchars($escuela_id) ?>">
        
        <div class="card-body">
            <div class="form-group">
                <label for="nombre">Nombre del Plan *</label>
                <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: Plan de Estudios 2023" value="<?= htmlspecialchars($nombre) ?>" required>
            </div>

            <div class="form-group">
                <label for="descripcion">Descripción (Opcional)</label>
                <textarea class="form-control" id="descripcion" name="descripcion" rows="3" placeholder="Añada una breve descripción del plan si es necesario..."><?= htmlspecialchars($descripcion) ?></textarea>
            </div>

            <div class="form-group">
                <label for="estado">Estado *</label>
                <select class="form-control" id="estado" name="estado" required>
                    <option value="activo" <?= ($estado == 'activo') ? 'selected' : '' ?>>Activo</option>
                    <option value="inactivo" <?= ($estado == 'inactivo') ? 'selected' : '' ?>>Inactivo</option>
                </select>
                <small class="form-text text-muted">Solo los planes 'Activos' podrán ser seleccionados para nuevas asignaciones.</small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Crear Plan</button>
            <!-- CAMBIO: El botón Cancelar ahora regresa a la vista filtrada -->
            <a href="gestionar_planes.php?facultad_id=<?= htmlspecialchars($facultad_id) ?>&escuela_id=<?= htmlspecialchars($escuela_id) ?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>
