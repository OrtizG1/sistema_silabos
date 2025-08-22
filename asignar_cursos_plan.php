<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad: Solo los administradores pueden acceder.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Acceso denegado.");
}

// 2. Obtener y validar el ID del plan curricular desde la URL.
$plan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$escuela_id = filter_input(INPUT_GET, 'escuela_id', FILTER_VALIDATE_INT);
$facultad_id = filter_input(INPUT_GET, 'facultad_id', FILTER_VALIDATE_INT);

if (!$plan_id || !$escuela_id || !$facultad_id) {
    $_SESSION['error_message'] = "Faltan datos para la asignación (plan, escuela o facultad).";
    header("Location: gestionar_planes.php");
    exit;
}

$errors = [];
$upload_report = null; 

// --- INICIO: LÓGICA PARA CARGA MASIVA DE CURSOS DESDE CSV ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (isset($_FILES['curso_file']) && $_FILES['curso_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['curso_file']['tmp_name'];
        $file_extension = strtolower(pathinfo($_FILES['curso_file']['name'], PATHINFO_EXTENSION));

        if ($file_extension === 'csv') {
            try {
                $pdo->beginTransaction();
                $cursos_asignados = [];
                $cursos_no_encontrados = [];
                $cursos_ya_existentes = [];
                $filas_procesadas = 0;

                if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
                    fgetcsv($handle, 1000, ";"); // Omitir la fila de encabezado

                    $stmt_check = $pdo->prepare("SELECT id FROM cursos WHERE codigo = ?");
                    $stmt_insert = $pdo->prepare("INSERT IGNORE INTO plan_cursos (plan_id, curso_id) VALUES (?, ?)");

                    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                        if (empty($data[0])) continue;

                        $filas_procesadas++;
                        $codigo_curso = trim($data[0]);
                        $nombre_curso_csv = isset($data[1]) ? trim($data[1]) : '[Sin nombre]';

                        $stmt_check->execute([$codigo_curso]);
                        $curso_id = $stmt_check->fetchColumn();

                        if ($curso_id) {
                            $stmt_insert->execute([$plan_id, $curso_id]);
                            if ($stmt_insert->rowCount() > 0) {
                                $cursos_asignados[] = "{$codigo_curso} - {$nombre_curso_csv}";
                            } else {
                                $cursos_ya_existentes[] = "{$codigo_curso} - {$nombre_curso_csv}";
                            }
                        } else {
                            $cursos_no_encontrados[] = "{$codigo_curso} - {$nombre_curso_csv}";
                        }
                    }
                    fclose($handle);
                }
                
                $pdo->commit();
                $_SESSION['upload_report'] = compact('cursos_asignados', 'cursos_no_encontrados', 'cursos_ya_existentes', 'filas_procesadas');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['upload_report'] = ['error' => 'Ocurrió un error durante el proceso: ' . $e->getMessage()];
            }

            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;

        } else { $errors[] = "Formato de archivo no válido. Por favor, suba un archivo .csv"; }
    } else { $errors[] = "Error al subir el archivo."; }
}
// --- FIN: LÓGICA PARA CARGA MASIVA ---


// --- LÓGICA PARA GUARDAR ASIGNACIONES MANUALES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manual'])) {
    $cursos_seleccionados_ids = $_POST['curso_ids'] ?? [];
    try {
        $pdo->beginTransaction();
        $stmt_delete = $pdo->prepare("DELETE FROM plan_cursos WHERE plan_id = ?");
        $stmt_delete->execute([$plan_id]);

        if (!empty($cursos_seleccionados_ids)) {
            $sql_insert = "INSERT INTO plan_cursos (plan_id, curso_id) VALUES (?, ?)";
            $stmt_insert = $pdo->prepare($sql_insert);
            foreach ($cursos_seleccionados_ids as $curso_id) {
                if (filter_var($curso_id, FILTER_VALIDATE_INT)) {
                    $stmt_insert->execute([$plan_id, $curso_id]);
                }
            }
        }
        $pdo->commit();
        $_SESSION['success_message'] = "Asignación de cursos guardada exitosamente.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Error al guardar las asignaciones en la base de datos.";
    }
}

// --- Comprobar si hay un reporte en la sesión para mostrarlo ---
if (isset($_SESSION['upload_report'])) {
    $upload_report = $_SESSION['upload_report'];
    unset($_SESSION['upload_report']);
}

// --- LÓGICA PARA CARGAR DATOS Y MOSTRAR EL FORMULARIO (GET) ---
try {
    $stmt_plan = $pdo->prepare("SELECT nombre FROM planes_curriculares WHERE id = ?");
    $stmt_plan->execute([$plan_id]);
    $plan = $stmt_plan->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $_SESSION['error_message'] = "El plan curricular no existe.";
        header("Location: gestionar_planes.php");
        exit;
    }

    // ===== INICIO DE LA CORRECCIÓN =====
    // La consulta ahora filtra los cursos por el facultad_id que viene en la URL.
    $stmt_cursos = $pdo->prepare("SELECT id, codigo, nombre FROM cursos WHERE facultad_id = ? ORDER BY nombre ASC");
    $stmt_cursos->execute([$facultad_id]);
    $todos_los_cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);
    // ===== FIN DE LA CORRECCIÓN =====

    $stmt_asignados = $pdo->prepare("SELECT curso_id FROM plan_cursos WHERE plan_id = ?");
    $stmt_asignados->execute([$plan_id]);
    $cursos_asignados_ids = $stmt_asignados->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    $errors[] = "Error al cargar los datos para la asignación.";
    $todos_los_cursos = [];
    $cursos_asignados_ids = [];
}

$page_title = "Asignar Cursos al Plan: " . htmlspecialchars($plan['nombre']);
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if ($upload_report): ?>
    <?php if (isset($upload_report['error'])): ?>
        <div class="alert alert-danger">
            <h4><i class="icon fas fa-ban"></i> Error en la Carga</h4>
            <p><?= htmlspecialchars($upload_report['error']) ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <h4><i class="icon fas fa-info"></i> Reporte de Carga Masiva</h4>
            <p>Se procesaron <strong><?= $upload_report['filas_procesadas'] ?></strong> filas.</p>
            <ul>
                <li><strong><?= count($upload_report['cursos_asignados']) ?></strong> cursos nuevos fueron asignados correctamente.</li>
                <li><strong><?= count($upload_report['cursos_ya_existentes']) ?></strong> cursos ya pertenecían al plan y fueron ignorados.</li>
                <?php if (!empty($upload_report['cursos_no_encontrados'])): ?>
                    <li>
                        <strong><?= count($upload_report['cursos_no_encontrados']) ?> cursos no se encontraron</strong> en la base de datos y fueron omitidos:
                        <ul style="font-size: 0.9em; max-height: 100px; overflow-y: auto;">
                            <?php foreach ($upload_report['cursos_no_encontrados'] as $curso_no_encontrado): ?>
                                <li><small><?= htmlspecialchars($curso_no_encontrado) ?></small></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>
<?php endif; ?>
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

<div class="card card-success">
    <div class="card-header">
        <h3 class="card-title">Carga Masiva de Cursos desde Archivo CSV</h3>
    </div>
    <form action="asignar_cursos_plan.php?id=<?= htmlspecialchars($plan_id) ?>&escuela_id=<?= htmlspecialchars($escuela_id) ?>&facultad_id=<?= htmlspecialchars($facultad_id) ?>" method="post" enctype="multipart/form-data">
        <div class="card-body">
            <div class="form-group">
                <label for="curso_file">Seleccione el archivo CSV</label>
                <div class="input-group">
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" id="curso_file" name="curso_file" accept=".csv" required>
                        <label class="custom-file-label" for="curso_file">Elegir archivo...</label>
                    </div>
                </div>
                <small class="form-text text-muted">
                    El archivo debe tener 2 columnas: <strong>código del curso</strong> en la primera y <strong>nombre del curso</strong> en la segunda. Se recomienda no incluir encabezados.
                </small>
                <div class="mt-2">
                    <a href="templates/plantilla_asignacion_cursos.csv" class="btn btn-sm btn-outline-secondary" download>
                        <i class="fas fa-download"></i> Descargar Plantilla
                    </a>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="upload_csv" class="btn btn-success">Cargar y Asignar Cursos del Archivo</button>
        </div>
    </form>
</div>
<div class="card card-primary mt-4">
    <div class="card-header">
        <h3 class="card-title">Asignación Manual de Cursos para: <?= htmlspecialchars($plan['nombre']) ?></h3>
    </div>
    <form action="asignar_cursos_plan.php?id=<?= htmlspecialchars($plan_id) ?>&escuela_id=<?= htmlspecialchars($escuela_id) ?>&facultad_id=<?= htmlspecialchars($facultad_id) ?>" method="POST">
        <div class="card-body">
            <p>Seleccione todos los cursos que pertenecen a este plan curricular. La carga masiva **agrega** cursos; esta asignación manual **reemplaza** la selección actual.</p>
            
            <div class="form-group">
                <label for="course-search">Buscar Cursos:</label>
                <input type="text" id="course-search" class="form-control" placeholder="Escriba para filtrar por código o nombre...">
            </div>
            <hr>
            <div class="row" style="max-height: 400px; overflow-y: auto;">
                <?php if (empty($todos_los_cursos)): ?>
                    <div class="col-12"><p class="text-muted">No hay cursos creados en el sistema para esta facultad.</p></div>
                <?php else: ?>
                    <?php foreach ($todos_los_cursos as $curso): ?>
                        <div class="col-md-4 course-item">
                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="curso_ids[]" value="<?= $curso['id'] ?>" id="curso_<?= $curso['id'] ?>"
                                        <?php if (in_array($curso['id'], $cursos_asignados_ids)) echo 'checked'; ?> >
                                    <label class="form-check-label" for="curso_<?= $curso['id'] ?>">
                                        <strong><?= htmlspecialchars($curso['codigo']) ?></strong> - <?= htmlspecialchars($curso['nombre']) ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="save_manual" class="btn btn-primary">Guardar Asignación Manual</button>
            <a href="gestionar_planes.php?facultad_id=<?= htmlspecialchars($facultad_id) ?>&escuela_id=<?= htmlspecialchars($escuela_id) ?>" class="btn btn-secondary">Volver a la Lista de Planes</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Script para la búsqueda en vivo en la lista manual
    const searchInput = document.getElementById('course-search');
    const courseItems = document.querySelectorAll('.course-item'); 
    searchInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase().trim();
        courseItems.forEach(function(item) {
            const label = item.querySelector('.form-check-label');
            if (label) {
                const courseText = label.textContent.toLowerCase();
                if (courseText.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            }
        });
    });

    // Script para mostrar el nombre del archivo en el input de carga
    $('.custom-file-input').on('change', function(event) {
        var inputFile = event.currentTarget;
        var fileName = $(inputFile).val().split('\\').pop();
        $(inputFile).next('.custom-file-label').html(fileName);
    });
});
</script>
