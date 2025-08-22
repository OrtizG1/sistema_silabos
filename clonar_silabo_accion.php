<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad y Permisos
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Acceso denegado.");
}

// 2. Validar que la solicitud sea por método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mis_silabos.php");
    exit;
}

// 3. Obtener y validar los IDs
$source_silabo_id = filter_input(INPUT_POST, 'source_silabo_id', FILTER_VALIDATE_INT);
$target_carga_id = filter_input(INPUT_POST, 'target_carga_id', FILTER_VALIDATE_INT);

if (!$source_silabo_id || !$target_carga_id) {
    $_SESSION['error_message'] = "Datos inválidos para la clonación.";
    header("Location: mis_silabos.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // 4. Obtener datos de la nueva carga académica de destino
    $stmt_target = $pdo->prepare("SELECT curso_id, periodo_id, docente_id FROM carga_academica WHERE id = ? AND silabo_id IS NULL");
    $stmt_target->execute([$target_carga_id]);
    $target_carga = $stmt_target->fetch(PDO::FETCH_ASSOC);

    if (!$target_carga || $target_carga['docente_id'] != $_SESSION['user_id']) {
        throw new Exception("La carga académica de destino no es válida o no le pertenece.");
    }

    // 5. Clonar el registro principal del sílabo
    $stmt_source = $pdo->prepare("SELECT * FROM silabos WHERE id = ?");
    $stmt_source->execute([$source_silabo_id]);
    $source_silabo = $stmt_source->fetch(PDO::FETCH_ASSOC);

    $stmt_insert_silabo = $pdo->prepare(
        "INSERT INTO silabos (curso_id, periodo_id, docente_id, sumilla, objetivo_general, estado) 
         VALUES (?, ?, ?, ?, ?, 'borrador')"
    );
    $stmt_insert_silabo->execute([
        $target_carga['curso_id'],
        $target_carga['periodo_id'],
        $target_carga['docente_id'],
        $source_silabo['sumilla'],
        $source_silabo['objetivo_general']
    ]);
    $new_silabo_id = $pdo->lastInsertId();

    // 6. Actualizar la carga académica con el ID del nuevo sílabo
    $stmt_update_carga = $pdo->prepare("UPDATE carga_academica SET silabo_id = ?, estado = 'borrador' WHERE id = ?");
    $stmt_update_carga->execute([$new_silabo_id, $target_carga_id]);

    // 7. Función auxiliar para clonar tablas relacionadas simples
    function clonarTablaSimple($pdo, $tabla, $source_id, $new_id) {
        $stmt_select = $pdo->prepare("SELECT * FROM $tabla WHERE silabo_id = ?");
        $stmt_select->execute([$source_id]);
        $items = $stmt_select->fetchAll(PDO::FETCH_ASSOC);

        $stmt_insert = $pdo->prepare("INSERT INTO $tabla (silabo_id, descripcion) VALUES (?, ?)");
        foreach ($items as $item) {
            $stmt_insert->execute([$new_id, $item['descripcion']]);
        }
    }

    // Clonar todas las secciones simples
    clonarTablaSimple($pdo, 'competencias_genericas', $source_silabo_id, $new_silabo_id);
    clonarTablaSimple($pdo, 'competencias_especificas', $source_silabo_id, $new_silabo_id);
    clonarTablaSimple($pdo, 'contenidos_procedimentales', $source_silabo_id, $new_silabo_id);
    clonarTablaSimple($pdo, 'contenidos_actitudinales', $source_silabo_id, $new_silabo_id);
    clonarTablaSimple($pdo, 'estrategias_metodologicas', $source_silabo_id, $new_silabo_id);
    
    // 8. Clonar Unidades y Semanas (lógica más compleja)
    $stmt_unidades = $pdo->prepare("SELECT * FROM unidades WHERE silabo_id = ? ORDER BY numero");
    $stmt_unidades->execute([$source_silabo_id]);
    $unidades = $stmt_unidades->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unidades as $unidad) {
        $stmt_insert_unidad = $pdo->prepare("INSERT INTO unidades (silabo_id, nombre, numero, descripcion, logro_esperado) VALUES (?, ?, ?, ?, ?)");
        $stmt_insert_unidad->execute([$new_silabo_id, $unidad['nombre'], $unidad['numero'], $unidad['descripcion'], $unidad['logro_esperado']]);
        $new_unidad_id = $pdo->lastInsertId();

        $stmt_semanas = $pdo->prepare("SELECT * FROM semanas WHERE unidad_id = ? ORDER BY numero");
        $stmt_semanas->execute([$unidad['id']]);
        $semanas = $stmt_semanas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($semanas as $semana) {
            $stmt_insert_semana = $pdo->prepare("INSERT INTO semanas (unidad_id, numero) VALUES (?, ?)");
            $stmt_insert_semana->execute([$new_unidad_id, $semana['numero']]);
            $new_semana_id = $pdo->lastInsertId();

            // Clonar contenidos de cada semana
            $tablas_semana = ['contenidos_conceptuales', 'aprendizajes_esperados', 'actividades_evaluacion'];
            foreach ($tablas_semana as $tabla_s) {
                 $stmt_s_select = $pdo->prepare("SELECT * FROM $tabla_s WHERE semana_id = ?");
                 $stmt_s_select->execute([$semana['id']]);
                 $items_s = $stmt_s_select->fetchAll(PDO::FETCH_ASSOC);
                 foreach($items_s as $item_s) {
                    unset($item_s['id']); // Quitar el ID viejo
                    $item_s['semana_id'] = $new_semana_id; // Poner el ID nuevo
                    $cols = implode(', ', array_keys($item_s));
                    $placeholders = implode(', ', array_fill(0, count($item_s), '?'));
                    $stmt_s_insert = $pdo->prepare("INSERT INTO $tabla_s ($cols) VALUES ($placeholders)");
                    $stmt_s_insert->execute(array_values($item_s));
                 }
            }
        }
    }

    // (Aquí se podría añadir la clonación de recursos y referencias si es necesario)

    $pdo->commit();
    $_SESSION['success_message'] = "Sílabo clonado exitosamente. Ahora puede editar el nuevo borrador.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Ocurrió un error al clonar el sílabo: " . $e->getMessage();
    error_log("Error al clonar sílabo: " . $e->getMessage());
}

header("Location: mis_silabos.php");
exit;
?>
