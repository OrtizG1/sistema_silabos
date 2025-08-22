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
$codigo = ''; $nombre = ''; $tipo = ''; $creditos = ''; 
$horas_teoricas = ''; $horas_practicas = ''; $requisitos = ''; $facultad_id = '';

// --- Lógica para obtener las facultades para el menú desplegable ---
try {
    // CAMBIO: Se obtienen las facultades en lugar de las escuelas.
    $facultades = $pdo->query("SELECT id, nombre FROM facultades ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $facultades = [];
    $errors[] = "Error fatal: No se pudieron cargar las facultades.";
}

// --- Lógica de PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger y limpiar datos del formulario
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    // CAMBIO: Se recoge facultad_id en lugar de escuela_id.
    $facultad_id = filter_input(INPUT_POST, 'facultad_id', FILTER_VALIDATE_INT);
    $tipo = trim($_POST['tipo'] ?? '');
    $creditos = filter_input(INPUT_POST, 'creditos', FILTER_VALIDATE_INT);
    $horas_teoricas = filter_input(INPUT_POST, 'horas_teoricas', FILTER_VALIDATE_INT);
    $horas_practicas = filter_input(INPUT_POST, 'horas_practicas', FILTER_VALIDATE_INT);
    $requisitos = trim($_POST['requisitos'] ?? '');

    // --- Validación de Datos ---
    if (empty($codigo)) $errors[] = "El código del curso es obligatorio.";
    if (empty($nombre)) $errors[] = "El nombre del curso es obligatorio.";
    if ($creditos === false || $creditos < 0) $errors[] = "El número de créditos no es válido.";
    if ($horas_teoricas === false || $horas_teoricas < 0) $errors[] = "El número de horas teóricas no es válido.";
    if ($horas_practicas === false || $horas_practicas < 0) $errors[] = "El número de horas prácticas no es válido.";
    // CAMBIO: Se valida facultad_id.
    if (empty($facultad_id)) $errors[] = "Debe seleccionar una facultad.";
    
    // Verificar si el código del curso ya existe
    if (empty($errors)) {
        $stmt_check = $pdo->prepare("SELECT id FROM cursos WHERE codigo = ?");
        $stmt_check->execute([$codigo]);
        if ($stmt_check->fetch()) {
            $errors[] = "El código del curso ya está en uso.";
        }
    }

    // --- Si no hay errores, proceder a la inserción ---
    if (empty($errors)) {
        try {
            // CAMBIO: La consulta SQL ahora inserta facultad_id en lugar de escuela_id.
            $sql = "INSERT INTO cursos (codigo, nombre, facultad_id, tipo, creditos, horas_teoricas, horas_practicas, requisitos) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$codigo, $nombre, $facultad_id, $tipo, $creditos, $horas_teoricas, $horas_practicas, $requisitos]);

            $_SESSION['success_message'] = "Curso creado exitosamente.";
            header("Location: gestionar_cursos.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = "Error al crear el curso en la base de datos.";
            error_log("Error al crear curso: " . $e->getMessage());
        }
    }
}

$page_title = "Crear Nuevo Curso";
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
        <h3 class="card-title">Formulario de Creación de Curso</h3>
    </div>
    <form action="crear_curso.php" method="POST">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label for="codigo">Código del Curso *</label>
                    <input type="text" class="form-control" id="codigo" name="codigo" value="<?= htmlspecialchars($codigo) ?>" required>
                </div>
                <div class="col-md-8 form-group">
                    <label for="nombre">Nombre del Curso *</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre) ?>" required>
                </div>
            </div>

            <!-- ===== CAMBIO: SELECTOR DE FACULTADES ===== -->
            <div class="form-group">
                <label for="facultad_id">Facultad *</label>
                <select class="form-control" id="facultad_id" name="facultad_id" required>
                    <option value="">-- Seleccionar una facultad --</option>
                    <?php foreach ($facultades as $facultad): ?>
                        <option value="<?= $facultad['id'] ?>" <?= ($facultad_id == $facultad['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($facultad['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- ===== FIN DEL CAMBIO ===== -->
            
            <div class="row">
                <div class="col-md-3 form-group">
                    <label for="tipo">Tipo</label>
                    <input type="text" class="form-control" id="tipo" name="tipo" placeholder="Ej: Obligatorio" value="<?= htmlspecialchars($tipo) ?>">
                </div>
                <div class="col-md-3 form-group">
                    <label for="creditos">Créditos *</label>
                    <input type="number" class="form-control" id="creditos" name="creditos" min="0" value="<?= htmlspecialchars($creditos) ?>" required>
                </div>
                <div class="col-md-3 form-group">
                    <label for="horas_teoricas">Horas Teóricas *</label>
                    <input type="number" class="form-control" id="horas_teoricas" name="horas_teoricas" min="0" value="<?= htmlspecialchars($horas_teoricas) ?>" required>
                </div>
                <div class="col-md-3 form-group">
                    <label for="horas_practicas">Horas Prácticas *</label>
                    <input type="number" class="form-control" id="horas_practicas" name="horas_practicas" min="0" value="<?= htmlspecialchars($horas_practicas) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="requisitos">Requisitos</label>
                <input type="text" class="form-control" id="requisitos" name="requisitos" placeholder="Ej: MAT001, FIS002" value="<?= htmlspecialchars($requisitos) ?>">
                <small class="form-text text-muted">Códigos de cursos como prerrequisito, separados por comas.</small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Crear Curso</button>
            <a href="gestionar_cursos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>
