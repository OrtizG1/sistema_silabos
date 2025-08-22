<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad y obtención de IDs
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Acceso denegado.");
}

$periodo_id = filter_input(INPUT_GET, 'periodo_id', FILTER_VALIDATE_INT);
// ===== INICIO DE CAMBIO: Obtener el ID de la facultad =====
$facultad_id = filter_input(INPUT_GET, 'facultad_id', FILTER_VALIDATE_INT);

if (!$periodo_id || !$facultad_id) {
    $_SESSION['error_message'] = "Debe seleccionar un período y una facultad primero.";
    header("Location: gestion_carga.php");
    exit;
}
// ===== FIN DE CAMBIO =====

$errors = [];

// 2. Lógica para guardar las asignaciones (POST) - Sin cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docente_id = filter_input(INPUT_POST, 'docente_id', FILTER_VALIDATE_INT);
    $curso_ids = $_POST['curso_ids'] ?? [];

    if (empty($docente_id)) $errors[] = "Debe seleccionar un docente.";
    if (empty($curso_ids)) $errors[] = "Debe seleccionar al menos un curso.";

    if (empty($errors)) {
        try {
            $sql = "INSERT IGNORE INTO carga_academica (docente_id, curso_id, periodo_id) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            
            $asignaciones_nuevas = 0;
            foreach ($curso_ids as $curso_id) {
                if (filter_var($curso_id, FILTER_VALIDATE_INT)) {
                    $stmt->execute([$docente_id, $curso_id, $periodo_id]);
                    if ($stmt->rowCount() > 0) {
                        $asignaciones_nuevas++;
                    }
                }
            }
            $_SESSION['success_message'] = "$asignaciones_nuevas curso(s) asignado(s) exitosamente. Se ignoraron las asignaciones duplicadas.";
            // CAMBIO: Se añade el facultad_id a la redirección para mantener el filtro
            header("Location: gestion_carga.php?periodo_id=" . $periodo_id . "&facultad_id=" . $facultad_id);
            exit;
        } catch (PDOException $e) {
            $errors[] = "Error al guardar la carga académica.";
            error_log("Error al asignar carga: " . $e->getMessage());
        }
    }
}

// 3. Lógica para cargar los datos para el formulario (GET)
try {
    $periodo_stmt = $pdo->prepare("SELECT nombre FROM periodos_academicos WHERE id = ?");
    $periodo_stmt->execute([$periodo_id]);
    $nombre_periodo = $periodo_stmt->fetchColumn();

    if (!$nombre_periodo) {
        throw new Exception("El período seleccionado no existe.");
    }
    
    $docentes = $pdo->query("
        SELECT u.id, CONCAT(p.apellidos, ', ', p.nombre) as nombre_completo 
        FROM usuarios u
        JOIN personal p ON u.personal_id = p.id
        WHERE u.rol_id IN (1, 2) AND u.activo = 1 
        ORDER BY p.apellidos, p.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ===== INICIO DE CAMBIO: Filtrar planes por facultad =====
    // Se unen las tablas para obtener solo los planes de las escuelas que pertenecen a la facultad seleccionada.
    $stmt_planes = $pdo->prepare("
        SELECT p.id, p.nombre 
        FROM planes_curriculares p
        JOIN escuelas e ON p.escuela_id = e.id
        WHERE e.facultad_id = ? AND p.estado = 'activo' 
        ORDER BY p.nombre ASC
    ");
    $stmt_planes->execute([$facultad_id]);
    $planes = $stmt_planes->fetchAll(PDO::FETCH_ASSOC);
    // ===== FIN DE CAMBIO =====

} catch (Exception $e) {
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: gestion_carga.php");
    exit;
}

$page_title = "Asignar Carga para el Período: " . htmlspecialchars($nombre_periodo);
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card card-primary">
    <div class="card-header">
        <h3 class="card-title"><?= $page_title ?></h3>
    </div>
    <!-- CAMBIO: Se añade el facultad_id al action del formulario -->
    <form action="asignar_carga.php?periodo_id=<?= htmlspecialchars($periodo_id) ?>&facultad_id=<?= htmlspecialchars($facultad_id) ?>" method="POST">
        <div class="card-body">
            <div class="form-group">
                <label for="docente_id">Seleccione el Docente *</label>
                <select name="docente_id" id="docente_id" class="form-control" required>
                    <option value="">-- Elija un docente o administrador --</option>
                    <?php foreach($docentes as $docente): ?>
                        <option value="<?= $docente['id'] ?>"><?= htmlspecialchars($docente['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <hr>

            <div class="form-group">
                <label for="plan_id_selector">Seleccione el Plan Académico *</label>
                <select id="plan_id_selector" class="form-control" required>
                    <option value="">-- Elija un plan para filtrar los cursos --</option>
                    <?php foreach($planes as $plan): ?>
                        <option value="<?= $plan['id'] ?>"><?= htmlspecialchars($plan['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Seleccione los Cursos a Asignar *</label>
                <input type="text" id="course-search" class="form-control mb-3" placeholder="Escriba para buscar cursos..." style="display: none;">
                <div id="courses-container" class="row" style="max-height: 400px; overflow-y: auto; border: 1px solid #ced4da; padding: 10px; border-radius: 4px;">
                    <p class="text-muted col-12">Seleccione un plan para ver los cursos disponibles.</p>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Asignar Cursos</button>
            <a href="gestion_carga.php?periodo_id=<?= htmlspecialchars($periodo_id) ?>&facultad_id=<?= htmlspecialchars($facultad_id) ?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const planSelector = document.getElementById('plan_id_selector');
    const coursesContainer = document.getElementById('courses-container');
    const searchInput = document.getElementById('course-search');

    function initializeCourseSearch() {
        const courseItems = coursesContainer.querySelectorAll('.course-item');
        if (courseItems.length > 0) {
            searchInput.style.display = 'block';
        } else {
            searchInput.style.display = 'none';
        }
        searchInput.removeEventListener('input', handleSearch);
        searchInput.addEventListener('input', handleSearch);
    }

    function handleSearch(e) {
        const searchTerm = e.target.value.toLowerCase();
        const courseItems = coursesContainer.querySelectorAll('.course-item');
        courseItems.forEach(function(item) {
            const courseText = item.querySelector('.form-check-label').textContent.toLowerCase();
            if (courseText.includes(searchTerm)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    planSelector.addEventListener('change', function() {
        const planId = this.value;
        coursesContainer.innerHTML = '<p class="text-muted col-12">Cargando cursos...</p>';
        searchInput.style.display = 'none';

        if (!planId) {
            coursesContainer.innerHTML = '<p class="text-muted col-12">Seleccione un plan para ver los cursos disponibles.</p>';
            return;
        }

        fetch(`get_cursos_por_plan.php?plan_id=${planId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la red o en el servidor.');
                }
                return response.json();
            })
            .then(data => {
                coursesContainer.innerHTML = '';
                if (data.length === 0) {
                    coursesContainer.innerHTML = '<p class="text-muted col-12">No hay cursos asociados a este plan.</p>';
                } else {
                    data.forEach(curso => {
                        const courseItem = document.createElement('div');
                        courseItem.className = 'col-md-6 course-item';
                        courseItem.innerHTML = `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="curso_ids[]" value="${curso.id}" id="curso_${curso.id}">
                                <label class="form-check-label" for="curso_${curso.id}">
                                    <strong>${curso.codigo}</strong> - ${curso.nombre}
                                </label>
                            </div>
                        `;
                        coursesContainer.appendChild(courseItem);
                    });
                }
                initializeCourseSearch();
            })
            .catch(error => {
                coursesContainer.innerHTML = '<p class="text-danger col-12">No se pudieron cargar los cursos.</p>';
                console.error('Error en fetch:', error);
            });
    });
});
</script>
