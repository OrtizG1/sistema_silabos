<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad: Solo administradores y docentes pueden crear sílabos libres.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'docente'])) {
    http_response_code(403);
    die("Acceso denegado.");
}

$errors = [];
// Variables para repoblar el formulario en caso de error
$nombre_curso = ''; $periodo_id = ''; $docente_id = ''; $sumilla = '';

// --- Lógica para obtener datos para los menús desplegables ---
try {
    // Obtener períodos académicos activos
    $periodos = $pdo->query("SELECT id, nombre FROM periodos_academicos WHERE estado = 'activo' ORDER BY fecha_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener docentes y administradores activos para asignarles el sílabo
    $docentes = $pdo->query("
        SELECT u.id, CONCAT(p.apellidos, ', ', p.nombre) as nombre_completo 
        FROM usuarios u
        JOIN personal p ON u.personal_id = p.id
        WHERE u.rol_id IN (1, 2) AND u.activo = 1 
        ORDER BY p.apellidos, p.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    $periodos = [];
    $docentes = [];
    $errors[] = "Error fatal: No se pudieron cargar los datos necesarios para el formulario.";
}

// --- Lógica de PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger y limpiar datos del formulario
    $nombre_curso = trim($_POST['nombre_curso'] ?? '');
    $periodo_id = filter_input(INPUT_POST, 'periodo_id', FILTER_VALIDATE_INT);
    $sumilla = trim($_POST['sumilla'] ?? '');
    
    // Si es admin, puede asignar a cualquier docente. Si es docente, se autoasigna.
    if ($_SESSION['user_role'] === 'admin') {
        $docente_id = filter_input(INPUT_POST, 'docente_id', FILTER_VALIDATE_INT);
    } else {
        $docente_id = $_SESSION['user_id'];
    }

    // --- Validación de Datos ---
    if (empty($nombre_curso)) $errors[] = "El nombre del curso/taller es obligatorio.";
    if (empty($periodo_id)) $errors[] = "Debe seleccionar un período académico.";
    if (empty($docente_id)) $errors[] = "Debe seleccionar un docente responsable.";
    if (empty($sumilla)) $errors[] = "La sumilla es obligatoria.";

    // --- Si no hay errores, proceder a la inserción ---
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO silabos_libres (nombre_curso, periodo_id, docente_id, sumilla, estado) 
                    VALUES (?, ?, ?, ?, 'borrador')";
            
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$nombre_curso, $periodo_id, $docente_id, $sumilla]);

            $nuevo_silabo_id = $pdo->lastInsertId();

            $_SESSION['success_message'] = "Borrador del sílabo libre creado exitosamente. Ahora puede editarlo en detalle.";
            // Redirigir a una futura página de edición
            header("Location: editar_silabo_libre.php?id=" . $nuevo_silabo_id);
            exit;

        } catch (PDOException $e) {
            $errors[] = "Error al crear el sílabo en la base de datos.";
            error_log("Error al crear sílabo libre: " . $e->getMessage());
        }
    }
}

$page_title = "Crear Nuevo Sílabo Libre";
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
        <h3 class="card-title">Formulario de Creación de Sílabo Libre</h3>
    </div>
    <form action="crear_silabo_libre.php" method="POST">
        <div class="card-body">
            <div class="form-group">
                <label for="nombre_curso">Nombre del Curso / Taller / Capacitación *</label>
                <input type="text" class="form-control" id="nombre_curso" name="nombre_curso" value="<?= htmlspecialchars($nombre_curso) ?>" required>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="periodo_id">Período Académico *</label>
                    <select class="form-control" id="periodo_id" name="periodo_id" required>
                        <option value="">-- Seleccionar un período --</option>
                        <?php foreach ($periodos as $periodo): ?>
                            <option value="<?= $periodo['id'] ?>" <?= ($periodo_id == $periodo['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($periodo['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6 form-group">
                    <label for="docente_id">Docente Responsable *</label>
                    <select class="form-control" id="docente_id" name="docente_id" <?= $_SESSION['user_role'] !== 'admin' ? 'disabled' : '' ?> required>
                        <?php if ($_SESSION['user_role'] === 'admin'): ?>
                            <option value="">-- Seleccionar un docente --</option>
                            <?php foreach ($docentes as $docente): ?>
                                <option value="<?= $docente['id'] ?>" <?= ($docente_id == $docente['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($docente['nombre_completo']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="<?= $_SESSION['user_id'] ?>" selected><?= htmlspecialchars($_SESSION['user_name']) ?></option>
                        <?php endif; ?>
                    </select>
                    <?php if ($_SESSION['user_role'] !== 'admin'): ?>
                        <small class="form-text text-muted">Se le asignará a usted automáticamente.</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="sumilla">Sumilla *</label>
                <textarea class="form-control" id="sumilla" name="sumilla" rows="4" required><?= htmlspecialchars($sumilla) ?></textarea>
                <small class="form-text text-muted">Describa brevemente el contenido y propósito del curso.</small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Crear y Continuar a Edición</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>
