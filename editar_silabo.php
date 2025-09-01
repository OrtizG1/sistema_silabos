<?php
session_start();

// 1. INCLUDES DE CONFIGURACIÓN Y CONEXIÓN
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';
// INCLUIR HELPER DE VERSIONAMIENTO
require_once BASE_PATH . '/src/utils/helpers.php';

// 2. VERIFICACIÓN DE PERMISOS
if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/login.php?error=permisos");
    exit;
}

$silabo_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$silabo_id) {
    $_SESSION['error_message'] = "ID de Syllabus no válido para editar.";
    header("Location: " . ($_SESSION['user_role'] === 'admin' ? 'gestionar_silabos.php' : 'mis_silabos.php'));
    exit;
}

// Ahora, verificamos si el usuario es el dueño O un administrador.
try {
    $stmt_owner = $pdo->prepare("SELECT docente_id FROM silabos WHERE id = ?");
    $stmt_owner->execute([$silabo_id]);
    $syllabus_owner = $stmt_owner->fetch(PDO::FETCH_ASSOC);

    if (!$syllabus_owner) {
        $_SESSION['error_message'] = "El sílabo que intenta editar no existe.";
        header("Location: " . ($_SESSION['user_role'] === 'admin' ? 'gestionar_silabos.php' : 'mis_silabos.php'));
        exit;
    }

    $is_owner = ($_SESSION['user_id'] == $syllabus_owner['docente_id']);
    $is_admin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

    if (!$is_owner && !$is_admin) {
        // Si no es ni el dueño ni un admin, se le deniega el acceso.
        $_SESSION['error_message'] = "No tiene permisos para editar este sílabo.";
        header("Location: dashboard.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al verificar los permisos del sílabo.";
    header("Location: dashboard.php");
    exit;
}

$errors = [];
$success_message_session = $_SESSION['success_message'] ?? null;
if ($success_message_session) {
    unset($_SESSION['success_message']);
}
$update_success_message = (isset($_GET['update']) && $_GET['update'] === 'success') ? "Syllabus actualizado exitosamente." : null;

$page_title = "Editar Syllabus (ID: " . htmlspecialchars($silabo_id) . ")";
$form_data = [];

// ===================================================================
// INICIO DE LA ACTUALIZACIÓN: Cargar opciones para los nuevos comboboxes
// ===================================================================
try {
    $estrategias_opciones = $pdo->query("SELECT id, descripcion FROM estrategias_maestro WHERE activo = TRUE ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);
    $recursos_opciones = $pdo->query("SELECT id, descripcion FROM recursos_maestro WHERE activo = TRUE ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors['db_load'] = "Error al cargar opciones de estrategias o recursos: " . $e->getMessage();
    $estrategias_opciones = [];
    $recursos_opciones = [];
}
// ===================================================================
// FIN DE LA ACTUALIZACIÓN
// ===================================================================


// --- LÓGICA DE ACTUALIZACIÓN (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $form_data_posted = $_POST;
    $silabo_id_posted = filter_input(INPUT_POST, 'silabo_id_hidden', FILTER_VALIDATE_INT);

    if ($silabo_id_posted !== $silabo_id) {
        $errors['general'] = "Error crítico: Intento de actualizar un ID de syllabus incorrecto.";
    } else {
        $curso_id = filter_input(INPUT_POST, 'curso_id', FILTER_VALIDATE_INT);
        $periodo_id = filter_input(INPUT_POST, 'periodo_id', FILTER_VALIDATE_INT);
        $sumilla = trim($_POST['sumilla'] ?? '');
        $objetivo_general = trim($_POST['objetivo_general'] ?? '');
        $estado_silabo_post = $_POST['estado_silabo'] ?? 'borrador';
        $docentes_texto_para_guardar = null;

        if (empty($curso_id)) $errors['curso_id'] = "Seleccione un curso";
        if (empty($periodo_id)) $errors['periodo_id'] = "Seleccione un período";
        if (empty($sumilla)) $errors['sumilla'] = "La sumilla es obligatoria";
        if (empty($objetivo_general)) $errors['objetivo_general'] = "El objetivo general es obligatorio";
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // --- BLOQUE DE VERSIONAMIENTO ---
                $datos_actuales_json = json_encode(recopilarDatosCompletosSilabo($silabo_id, $pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $stmt_version = $pdo->prepare("SELECT MAX(version_numero) FROM silabo_versiones WHERE silabo_id = :silabo_id");
                $stmt_version->execute([':silabo_id' => $silabo_id]);
                $nueva_version_numero = ($stmt_version->fetchColumn() ?: 0) + 1;
                $stmt_guardar_version = $pdo->prepare("INSERT INTO silabo_versiones (silabo_id, version_numero, datos_json, editado_por_id, fecha_guardado) VALUES (?, ?, ?, ?, NOW())");
                $stmt_guardar_version->execute([$silabo_id, $nueva_version_numero, $datos_actuales_json, $_SESSION['user_id']]);

                // 1. ACTUALIZAR TABLA 'silabos'
                $sql_update_silabo = "UPDATE silabos SET curso_id = :curso_id, periodo_id = :periodo_id, sumilla = :sumilla, objetivo_general = :objetivo_general, docentes_texto = :docentes_texto, estado = :estado, updated_at = NOW() WHERE id = :silabo_id";
                $stmt_update = $pdo->prepare($sql_update_silabo);
                $stmt_update->execute([':curso_id' => $curso_id, ':periodo_id' => $periodo_id, ':sumilla' => $sumilla, ':objetivo_general' => $objetivo_general, ':docentes_texto' => $docentes_texto_para_guardar, ':estado' => $estado_silabo_post, ':silabo_id' => $silabo_id]);

                // 2. Lógica para secciones simples (borrar y re-crear)
                function updateSimpleSection($pdo, $table, $silabo_id, $post_key, $field_name = 'descripcion') {
                    $pdo->prepare("DELETE FROM $table WHERE silabo_id = ?")->execute([$silabo_id]);
                    if (isset($_POST[$post_key]) && is_array($_POST[$post_key])) {
                        $stmt_insert = $pdo->prepare("INSERT INTO $table (silabo_id, $field_name) VALUES (?, ?)");
                        foreach ($_POST[$post_key] as $value) {
                            $sanitized_value = trim(strip_tags($value));
                            if (!empty($sanitized_value)) {
                                $stmt_insert->execute([$silabo_id, $sanitized_value]);
                            }
                        }
                    }
                }
                function deleteRelatedData($pdo, $table, $silabo_id) {
                    $stmt = $pdo->prepare("DELETE FROM $table WHERE silabo_id = ?");
                    $stmt->execute([$silabo_id]);
                }

                updateSimpleSection($pdo, 'competencias_genericas', $silabo_id, 'competencias_genericas');
                updateSimpleSection($pdo, 'competencias_especificas', $silabo_id, 'competencias_especificas');
                updateSimpleSection($pdo, 'contenidos_procedimentales', $silabo_id, 'contenidos_procedimentales');
                updateSimpleSection($pdo, 'contenidos_actitudinales', $silabo_id, 'contenidos_actitudinales');
                


                // 4. Lógica para Sistema de Evaluación: ELIMINADA.
            if (isset($_POST['estrategias']) && is_array($_POST['estrategias'])) {
                $stmt_insert_est = $pdo->prepare("INSERT INTO silabo_estrategias (silabo_id, estrategia_id) VALUES (?, ?)");
                foreach ($_POST['estrategias'] as $estrategia_id) {
                    if (filter_var($estrategia_id, FILTER_VALIDATE_INT)) {
                        $stmt_insert_est->execute([$silabo_id, $estrategia_id]);
                    }
                }
            }

            if (isset($_POST['recursos']) && is_array($_POST['recursos'])) {
                $stmt_insert_rec = $pdo->prepare("INSERT INTO silabo_recursos (silabo_id, recurso_id) VALUES (?, ?)");
                foreach ($_POST['recursos'] as $recurso_id) {
                    if (filter_var($recurso_id, FILTER_VALIDATE_INT)) {
                        $stmt_insert_rec->execute([$silabo_id, $recurso_id]);
                    }
                }
            }

                // 5. Lógica para Referencias Bibliográficas
                deleteRelatedData($pdo, 'referencias_bibliograficas', $silabo_id);
                if (isset($_POST['referencias']) && is_array($_POST['referencias'])) {
                    $stmt_ref = $pdo->prepare("INSERT INTO referencias_bibliograficas (silabo_id, tipo_bibliografia_id, autor, titulo, anio, editorial, lugar, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($_POST['referencias'] as $idx => $ref) {
                        $tid = filter_var($ref['tipo_id'] ?? null, FILTER_VALIDATE_INT);
                        $tit = trim(strip_tags($ref['titulo'] ?? ''));
                        if ($tid && !empty($tit)) {
                            $stmt_ref->execute([$silabo_id, $tid, trim(strip_tags($ref['autor']??'')), $tit, trim(strip_tags($ref['anio']??'')), trim(strip_tags($ref['editorial']??'')), trim(strip_tags($ref['lugar']??'')), filter_var($ref['url']??'', FILTER_VALIDATE_URL) ? trim($ref['url']) : null]);
                        } else { if(!empty(array_filter($ref))) $errors["ref_{$idx}"] = "Referencia " .($idx+1)." inválida."; }
                    }
                }

                // 6. Lógica compleja para Unidades y Semanas
                $stmt_old_unidades = $pdo->prepare("SELECT id FROM unidades WHERE silabo_id = ?");
                $stmt_old_unidades->execute([$silabo_id]);
                $old_unidad_ids = $stmt_old_unidades->fetchAll(PDO::FETCH_COLUMN);
                $submitted_unidad_ids = [];

                $stmt_update_unidad = $pdo->prepare("UPDATE unidades SET nombre=?, numero=?, descripcion=?, logro_esperado=? WHERE id=?");
                $stmt_insert_unidad = $pdo->prepare("INSERT INTO unidades (silabo_id, nombre, numero, descripcion, logro_esperado) VALUES (?, ?, ?, ?, ?)");
                
                if (isset($_POST['unidades']) && is_array($_POST['unidades'])) {
                    foreach ($_POST['unidades'] as $index_unidad => $unidad_data) {
                        $unidad_id = !empty($unidad_data['id']) ? $unidad_data['id'] : null;
                        $params = [trim(strip_tags($unidad_data['nombre'])), $unidad_data['numero'], trim(strip_tags($unidad_data['descripcion'])), trim(strip_tags($unidad_data['logro']))];

                        if ($unidad_id) {
                            $params[] = $unidad_id;
                            $stmt_update_unidad->execute($params);
                            $submitted_unidad_ids[] = $unidad_id;
                        } else {
                            array_unshift($params, $silabo_id);
                            $stmt_insert_unidad->execute($params);
                            $unidad_id = $pdo->lastInsertId();
                        }
                        
                        $pdo->prepare("DELETE FROM semanas WHERE unidad_id = ?")->execute([$unidad_id]);
                        if (isset($unidad_data['semanas']) && is_array($unidad_data['semanas'])) {
                            $semana_stmt_ins = $pdo->prepare("INSERT INTO semanas (unidad_id, numero) VALUES (?, ?)");
                            $cc_stmt_ins = $pdo->prepare("INSERT INTO contenidos_conceptuales (semana_id, descripcion) VALUES (?, ?)");
                            $ae_stmt_ins = $pdo->prepare("INSERT INTO aprendizajes_esperados (semana_id, descripcion) VALUES (?, ?)");
                            $act_eval_stmt_ins = $pdo->prepare("INSERT INTO actividades_evaluacion (semana_id, tipo_actividad_id, descripcion) VALUES (?, ?, ?)");
                            
                            foreach ($unidad_data['semanas'] as $semana_data) {
                                $semana_stmt_ins->execute([$unidad_id, $semana_data['numero']]);
                                $semana_id_new = $pdo->lastInsertId();
                                if (!empty($semana_data['contenido_conceptual'])) $cc_stmt_ins->execute([$semana_id_new, trim(strip_tags($semana_data['contenido_conceptual']))]);
                                if (!empty($semana_data['aprendizaje_esperado'])) $ae_stmt_ins->execute([$semana_id_new, trim(strip_tags($semana_data['aprendizaje_esperado']))]);
                                $tipo_act_id = !empty($semana_data['tipo_actividad_id']) ? $semana_data['tipo_actividad_id'] : null;
                                $act_desc = !empty($semana_data['descripcion']) ? trim(strip_tags($semana_data['descripcion'])) : null;
                                if ($tipo_act_id || $act_desc) $act_eval_stmt_ins->execute([$semana_id_new, $tipo_act_id, $act_desc]);
                            }
                        }
                    }
                }

                $unidades_a_borrar = array_diff($old_unidad_ids, $submitted_unidad_ids);
                if (!empty($unidades_a_borrar)) {
                    $placeholders = implode(',', array_fill(0, count($unidades_a_borrar), '?'));
                    $pdo->prepare("DELETE FROM unidades WHERE id IN ($placeholders)")->execute($unidades_a_borrar);
                }

                $stmt_historial_edicion = $pdo->prepare("INSERT INTO historial_silabos (silabo_id, usuario_id, accion, descripcion) VALUES (:silabo_id, :usuario_id, 'Edición', 'El sílabo fue modificado.')");
                $stmt_historial_edicion->execute([':silabo_id' => $silabo_id, ':usuario_id' => $_SESSION['user_id']]);

                if (!empty($errors)) throw new Exception("Errores de validación al actualizar detalles.");

                $pdo->commit();
                $_SESSION['success_message'] = "Syllabus actualizado exitosamente.";
                header("Location: " . APP_URL . "/editar_silabo.php?id=" . $silabo_id . "&update=success");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['db'] = "Error al actualizar: " . $e->getMessage();
            }
        }
        $form_data = $form_data_posted;
    }
} else { // --- Modo GET: Cargar datos para editar ---
    try {
        $stmt = $pdo->prepare("SELECT * FROM silabos WHERE id = :id");
        $stmt->execute([':id' => $silabo_id]);
        $form_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$form_data) {
            $_SESSION['error_message'] = "Syllabus no encontrado.";
            header("Location: " . ($_SESSION['user_role'] === 'admin' ? 'gestionar_silabos.php' : 'mis_silabos.php'));
            exit;
        }
        
        $form_data['docentes_asignados'] = [];
        $form_data['competencias_genericas'] = $pdo->query("SELECT descripcion FROM competencias_genericas WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $form_data['competencias_especificas'] = $pdo->query("SELECT descripcion FROM competencias_especificas WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt_unidades_load = $pdo->prepare("SELECT * FROM unidades WHERE silabo_id = :sid ORDER BY numero");
        $stmt_unidades_load->execute([':sid' => $silabo_id]);
        $unidades_db = $stmt_unidades_load->fetchAll(PDO::FETCH_ASSOC);
        $form_data['unidades'] = [];
        foreach ($unidades_db as $u_db) {
            $u_form = $u_db;
            $u_form['semanas'] = [];
            $stmt_sem_load = $pdo->prepare("SELECT * FROM semanas WHERE unidad_id = :uid ORDER BY numero");
            $stmt_sem_load->execute([':uid' => $u_db['id']]);
            $semanas_db = $stmt_sem_load->fetchAll(PDO::FETCH_ASSOC);
            foreach ($semanas_db as $s_db) {
                $s_form = ['numero' => $s_db['numero']];
                $s_form['contenido_conceptual'] = $pdo->query("SELECT descripcion FROM contenidos_conceptuales WHERE semana_id = {$s_db['id']}")->fetchColumn() ?: '';
                $s_form['aprendizaje_esperado'] = $pdo->query("SELECT descripcion FROM aprendizajes_esperados WHERE semana_id = {$s_db['id']}")->fetchColumn() ?: '';
                $act_eval_db = $pdo->query("SELECT tipo_actividad_id, descripcion FROM actividades_evaluacion WHERE semana_id = {$s_db['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $s_form['tipo_actividad_id'] = $act_eval_db['tipo_actividad_id'] ?? null;
                $s_form['descripcion'] = $act_eval_db['descripcion'] ?? '';
                $u_form['semanas'][] = $s_form;
            }
            $form_data['unidades'][] = $u_form;
        }
        
        $form_data['contenidos_procedimentales'] = $pdo->query("SELECT descripcion FROM contenidos_procedimentales WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $form_data['contenidos_actitudinales'] = $pdo->query("SELECT descripcion FROM contenidos_actitudinales WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

        // ===================================================================
        // INICIO DEL CAMBIO: Cargar los IDs de estrategias y recursos ya seleccionados
        // ===================================================================
        $form_data['competencias_genericas'] = $pdo->query("SELECT descripcion FROM competencias_genericas WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $form_data['competencias_especificas'] = $pdo->query("SELECT descripcion FROM competencias_especificas WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        
        // ... (Lógica para cargar unidades y semanas se mantiene igual) ...
        
        $form_data['contenidos_procedimentales'] = $pdo->query("SELECT descripcion FROM contenidos_procedimentales WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $form_data['contenidos_actitudinales'] = $pdo->query("SELECT descripcion FROM contenidos_actitudinales WHERE silabo_id = $silabo_id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        
        // Cargar los IDs de las ESTRATEGIAS asociadas a este sílabo
        $form_data['estrategias'] = $pdo->query("SELECT estrategia_id FROM silabo_estrategias WHERE silabo_id = $silabo_id")->fetchAll(PDO::FETCH_COLUMN);
        
        // Cargar los IDs de los RECURSOS asociados a este sílabo
        $form_data['recursos'] = $pdo->query("SELECT recurso_id FROM silabo_recursos WHERE silabo_id = $silabo_id")->fetchAll(PDO::FETCH_COLUMN);
        
        $form_data['referencias'] = $pdo->query("SELECT tipo_bibliografia_id as tipo_id, autor, titulo, anio, editorial, lugar, url FROM referencias_bibliograficas WHERE silabo_id = $silabo_id ORDER BY tipo_bibliografia_id, id")->fetchAll(PDO::FETCH_ASSOC);
        // ===================================================================
        // FIN DEL CAMBIO
        // ===================================================================

    } catch (PDOException $e) {
        $errors['db_load'] = "Error al cargar datos del syllabus para editar: " . $e->getMessage();
    }
}

// --- Cargar datos para los SELECTS del formulario ---
$cursos = $pdo->query("SELECT id, codigo, nombre FROM cursos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$periodos = $pdo->query("SELECT id, nombre FROM periodos_academicos WHERE estado = 'activo' ORDER BY fecha_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
$tipos_bibliografia = $pdo->query("SELECT id, nombre FROM tipos_bibliografia ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$listaTiposActividad = [];
$tiposActividadOptionsHTML = '<option value="">[Seleccionar tipo de actividad]</option>';
try {
    $stmt_tipos_act = $pdo->query("SELECT id, nombre FROM tipos_actividad_semanal WHERE activo = 1 ORDER BY nombre ASC");
    if ($stmt_tipos_act) {
        $listaTiposActividad = $stmt_tipos_act->fetchAll(PDO::FETCH_ASSOC);
        foreach ($listaTiposActividad as $tipoAct) {
            $tiposActividadOptionsHTML .= '<option value="' . htmlspecialchars($tipoAct['id']) . '">' . htmlspecialchars($tipoAct['nombre']) . '</option>';
        }
    }
} catch (PDOException $e) {
    $errors['load_tipos_actividad'] = "Error de BD al cargar tipos de actividad.";
}
$tiposActividadOptionsHTML_JS = json_encode($tiposActividadOptionsHTML);

require_once BASE_PATH . '/src/templates/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
        </div>
    </div>

    <?php if ($update_success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($update_success_message) ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>
    <?php if ($success_message_session): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success_message_session) ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Por favor, corrija los siguientes errores:</strong>
            <ul><?php foreach ($errors as $field => $error_msg): ?><li><?= htmlspecialchars($error_msg) ?></li><?php endforeach; ?></ul>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>

    <form method="post" id="syllabusFormEdit" action="<?= APP_URL ?>/editar_silabo.php?id=<?= htmlspecialchars($silabo_id) ?>">
        <input type="hidden" name="silabo_id_hidden" value="<?= htmlspecialchars($silabo_id) ?>">

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">1. Datos Generales</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="curso_id">Curso *</label>
                            <select class="form-control <?= isset($errors['curso_id']) ? 'is-invalid' : '' ?>" id="curso_id" name="curso_id" required>
                                <option value="">Seleccionar curso</option>
                                <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= (isset($form_data['curso_id']) && $form_data['curso_id'] == $curso['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curso['codigo'] . ' - ' . $curso['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['curso_id'])): ?><div class="invalid-feedback"><?= $errors['curso_id'] ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="periodo_id">Período Académico *</label>
                            <select class="form-control <?= isset($errors['periodo_id']) ? 'is-invalid' : '' ?>" id="periodo_id" name="periodo_id" required>
                                <option value="">Seleccionar período</option>
                                <?php foreach ($periodos as $periodo): ?>
                                    <option value="<?= $periodo['id'] ?>" <?= (isset($form_data['periodo_id']) && $form_data['periodo_id'] == $periodo['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($periodo['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['periodo_id'])): ?><div class="invalid-feedback"><?= $errors['periodo_id'] ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="sumilla">Sumilla *</label>
                    <textarea class="form-control" id="sumilla" name="sumilla" rows="4" readonly><?= htmlspecialchars($form_data['sumilla'] ?? '') ?></textarea>
                    <small class="form-text text-muted">Descripción breve del curso (No editable).</small>
                </div>
                <div class="form-group">
                    <label for="objetivo_general">Objetivo General / Propósito del Curso *</label>
                    <textarea class="form-control" id="objetivo_general" name="objetivo_general" rows="4" readonly><?= htmlspecialchars($form_data['objetivo_general'] ?? '') ?></textarea>
                    <small class="form-text text-muted">El propósito principal del curso (No editable).</small>
                </div>

                <div class="form-group">
                    <label for="estado_silabo">Estado del Syllabus</label>
                    <select name="estado_silabo" id="estado_silabo" class="form-control">
                        <option value="borrador" <?= (($form_data['estado'] ?? 'borrador') == 'borrador') ? 'selected' : '' ?>>Borrador</option>
                        <option value="revision" <?= (($form_data['estado'] ?? '') == 'revision') ? 'selected' : '' ?>>En Revisión</option>
                        <option value="aprobado" <?= (($form_data['estado'] ?? '') == 'aprobado') ? 'selected' : '' ?>>Aprobado</option>
                        <option value="publicado" <?= (($form_data['estado'] ?? '') == 'publicado') ? 'selected' : '' ?>>Publicado</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">2. Competencias</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="m-0">2.1 Competencias Genéricas (No editable)</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" disabled><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div id="competencias-genericas-container">
                        <?php $items = $form_data['competencias_genericas'] ?? []; if (empty($items)) $items = ['']; foreach ($items as $index => $item): ?>
                        <div class="input-group mb-2 simple-item-group">
                            <textarea class="form-control" name="competencias_genericas[]" rows="2" readonly><?= htmlspecialchars($item) ?></textarea>
                            <div class="input-group-append"><button class="btn btn-outline-danger" type="button" disabled><i class="fas fa-times"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                </div>
                <hr>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="m-0">2.2 Competencias Específicas (No editable)</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" disabled><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div id="competencias-especificas-container">
                        <?php $items = $form_data['competencias_especificas'] ?? []; if (empty($items)) $items = ['']; foreach ($items as $index => $item): ?>
                        <div class="input-group mb-2 simple-item-group">
                            <textarea class="form-control" name="competencias_especificas[]" rows="2" readonly><?= htmlspecialchars($item) ?></textarea>
                            <div class="input-group-append"><button class="btn btn-outline-danger" type="button" disabled><i class="fas fa-times"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">3. Programación de Contenidos</h6>
                <button type="button" class="btn btn-sm btn-primary" onclick="addUnidad()"><i class="fas fa-plus"></i> Agregar Unidad</button>
            </div>
            <div class="card-body">
                <div id="unidades-container">
                    <?php $unidades_current = $form_data['unidades'] ?? []; if (empty($unidades_current)) $unidades_current = [['nombre' => '', 'numero' => 1, 'descripcion' => '', 'logro_esperado' => '', 'semanas' => [['numero' => 1]]]]; foreach ($unidades_current as $index_unidad => $unidad): ?>
                    <div class="unidad-group mb-4 border p-3 bg-light rounded">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="m-0">Unidad <span class="unidad-numero-display"><?= htmlspecialchars($unidad['numero']) ?></span></h5>
                            <button type="button" class="btn btn-sm btn-danger" onclick="removeUnidad(this)"><i class="fas fa-trash"></i> Eliminar Unidad</button>
                        </div>
                        <input type="hidden" name="unidades[<?= $index_unidad ?>][id]" value="<?= htmlspecialchars($unidad['id'] ?? '') ?>">
                        <div class="form-group"><label>Nombre de la Unidad *</label><input type="text" class="form-control" name="unidades[<?= $index_unidad ?>][nombre]" value="<?= htmlspecialchars($unidad['nombre']) ?>" required></div>
                        <div class="form-group" style="display:none;"><label>Número</label><input type="number" class="form-control unidad-numero-input" name="unidades[<?= $index_unidad ?>][numero]" value="<?= htmlspecialchars($unidad['numero']) ?>" required readonly></div>
                        <div class="form-group"><label>Logro de la Unidad *</label><textarea class="form-control" name="unidades[<?= $index_unidad ?>][logro]" rows="2" required><?= htmlspecialchars($unidad['logro_esperado']) ?></textarea></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="unidades[<?= $index_unidad ?>][descripcion]" rows="2"><?= htmlspecialchars($unidad['descripcion']) ?></textarea></div>
                        <hr>
                        <h6 class="mt-3 mb-2">Semanas</h6>
                        <div class="semanas-container" data-unidad-index="<?= $index_unidad ?>">
                            <?php $semanas_current = $unidad['semanas'] ?? []; if(empty($semanas_current)) $semanas_current = [['numero'=>1]]; foreach ($semanas_current as $index_semana => $semana): ?>
                            <div class="semana-group mb-3 border p-2 rounded bg-white">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="m-0 font-weight-bold">Semana <span class="semana-numero-display"><?= htmlspecialchars($semana['numero']) ?></span></label>
                                    <input type="hidden" name="unidades[<?= $index_unidad ?>][semanas][<?= $index_semana ?>][numero]" value="<?= htmlspecialchars($semana['numero']) ?>" class="semana-numero-input">
                                    <button type="button" class="btn btn-xs btn-outline-danger" onclick="removeSemana(this)"><i class="fas fa-times"></i></button>
                                </div>
                                <div class="form-group"><label>Contenidos Conceptuales *</label><textarea class="form-control" name="unidades[<?= $index_unidad ?>][semanas][<?= $index_semana ?>][contenido_conceptual]" rows="2" required><?= htmlspecialchars($semana['contenido_conceptual'] ?? '') ?></textarea></div>
                                <div class="form-group"><label>Aprendizaje Esperado *</label><textarea class="form-control" name="unidades[<?= $index_unidad ?>][semanas][<?= $index_semana ?>][aprendizaje_esperado]" rows="2" required><?= htmlspecialchars($semana['aprendizaje_esperado'] ?? '') ?></textarea></div>
                                <div class="form-group"><label>Actividad de Evaluación</label><select class="form-control" name="unidades[<?= $index_unidad ?>][semanas][<?= $index_semana ?>][tipo_actividad_id]"><?php $current_tipo_act_id = $semana['tipo_actividad_id'] ?? null; echo str_replace('value="' . $current_tipo_act_id . '"', 'value="' . $current_tipo_act_id . '" selected', $tiposActividadOptionsHTML); ?></select></div>
                                <div class="form-group"><label>Notas / Descripción Adicional</label><textarea class="form-control" name="unidades[<?= $index_unidad ?>][semanas][<?= $index_semana ?>][descripcion]" rows="1"><?= htmlspecialchars($semana['descripcion'] ?? '') ?></textarea></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addSemana(this)"><i class="fas fa-plus"></i> Agregar Semana</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">4. Contenidos Adicionales</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="m-0">4.1 Contenidos Procedimentales</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addSimpleItem('contenidos-procedimentales-container', 'contenidos_procedimentales[]', 'textarea')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div id="contenidos-procedimentales-container">
                        <?php $items = $form_data['contenidos_procedimentales'] ?? []; if (empty($items)) $items = ['']; foreach ($items as $item): ?>
                        <div class="input-group mb-2 simple-item-group">
                            <textarea class="form-control" name="contenidos_procedimentales[]" rows="2" required><?= htmlspecialchars($item) ?></textarea>
                            <div class="input-group-append"><button class="btn btn-outline-danger" type="button" onclick="removeItem(this)"><i class="fas fa-times"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="m-0">4.2 Contenidos Actitudinales</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addSimpleItem('contenidos-actitudinales-container', 'contenidos_actitudinales[]', 'textarea')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div id="contenidos-actitudinales-container">
                        <?php $items = $form_data['contenidos_actitudinales'] ?? []; if (empty($items)) $items = ['']; foreach ($items as $item): ?>
                        <div class="input-group mb-2 simple-item-group">
                            <textarea class="form-control" name="contenidos_actitudinales[]" rows="2" required><?= htmlspecialchars($item) ?></textarea>
                            <div class="input-group-append"><button class="btn btn-outline-danger" type="button" onclick="removeItem(this)"><i class="fas fa-times"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">5. Estrategias Metodológicas y Recursos Didácticos</h6>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="estrategias">Estrategias Metodológicas</label>
                    <select class="form-control" id="estrategias" name="estrategias[]" multiple="multiple">
                        <?php 
                        $selected_estrategias = $form_data['estrategias'] ?? [];
                        foreach ($estrategias_opciones as $opcion): ?>
                            <option value="<?= $opcion['id'] ?>" <?= in_array($opcion['id'], $selected_estrategias) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opcion['descripcion']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <hr>
                <div class="form-group">
                    <label for="recursos">Recursos Didácticos</label>
                    <select class="form-control" id="recursos" name="recursos[]" multiple="multiple">
                        <?php 
                        $selected_recursos = $form_data['recursos'] ?? [];
                        foreach ($recursos_opciones as $opcion): ?>
                            <option value="<?= $opcion['id'] ?>" <?= in_array($opcion['id'], $selected_recursos) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opcion['descripcion']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">6. Sistema de Evaluación</h6>
            </div>
            <div class="card-body">
                <p>El sistema de evaluación es estándar y no es modificable. Los porcentajes son los siguientes:</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="thead-light">
                            <tr>
                                <th>Tipo de Evaluación</th>
                                <th class="text-right" style="width: 20%;">Porcentaje (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Evaluación parcial 1</td><td class="text-right">10%</td></tr>
                            <tr><td>Evaluación parcial 2</td><td class="text-right">20%</td></tr>
                            <tr><td>Evaluación parcial 3</td><td class="text-right">20%</td></tr>
                            <tr><td>Examen Final</td><td class="text-right">30%</td></tr>
                            <tr><td>Evaluaciones continuas</td><td class="text-right">20%</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th class="text-right">TOTAL:</th>
                                <th class="text-right">100%</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">7. Referencias Bibliográficas</h6>
                <button type="button" class="btn btn-sm btn-primary" onclick="addReferencia()"><i class="fas fa-plus"></i> Agregar Referencia</button>
            </div>
            <div class="card-body">
                <div id="referencias-container">
                    <?php $referencias_data = $form_data['referencias'] ?? []; if (empty($referencias_data)) $referencias_data = [['tipo_id' => '']]; foreach ($referencias_data as $index => $ref_item): ?>
                    <div class="referencia-group border p-3 mb-3 rounded bg-light">
                        <div class="d-flex justify-content-end mb-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReferencia(this)"><i class="fas fa-times"></i> Eliminar</button></div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>Tipo *</label>
                                <select class="form-control" name="referencias[<?= $index ?>][tipo_id]" required>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($tipos_bibliografia as $tipo): ?>
                                    <option value="<?= $tipo['id'] ?>" <?= (isset($ref_item['tipo_id']) && $ref_item['tipo_id'] == $tipo['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tipo['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8 form-group"><label>Autor(es)</label><input type="text" class="form-control" name="referencias[<?= $index ?>][autor]" value="<?= htmlspecialchars($ref_item['autor'] ?? '') ?>"></div>
                        </div>
                        <div class="form-group"><label>Título *</label><input type="text" class="form-control" name="referencias[<?= $index ?>][titulo]" value="<?= htmlspecialchars($ref_item['titulo'] ?? '') ?>" required></div>
                        <div class="row">
                            <div class="col-md-3 form-group"><label>Año</label><input type="text" class="form-control" name="referencias[<?= $index ?>][anio]" value="<?= htmlspecialchars($ref_item['anio'] ?? '') ?>"></div>
                            <div class="col-md-5 form-group"><label>Editorial / Revista</label><input type="text" class="form-control" name="referencias[<?= $index ?>][editorial]" value="<?= htmlspecialchars($ref_item['editorial'] ?? '') ?>"></div>
                            <div class="col-md-4 form-group"><label>Lugar / Páginas</label><input type="text" class="form-control" name="referencias[<?= $index ?>][lugar]" value="<?= htmlspecialchars($ref_item['lugar'] ?? '') ?>"></div>
                        </div>
                        <div class="form-group"><label>URL</label><input type="url" class="form-control" name="referencias[<?= $index ?>][url]" value="<?= htmlspecialchars($ref_item['url'] ?? '') ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="text-center mb-4">
            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Actualizar Syllabus</button>
            <a href="<?= APP_URL ?>/mis_silabos.php" class="btn btn-secondary btn-lg">Cancelar</a>
        </div>
    </form>
</div>

<script>
const tiposActividadOptionsJS = <?php echo json_encode($tiposActividadOptionsHTML); ?>; 

let unidadCounter = document.querySelectorAll('.unidad-group').length;
let referenciaCounter = document.querySelectorAll('.referencia-group').length;

function addSimpleItem(containerId, inputName, inputType = 'textarea') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'input-group mb-2 simple-item-group';
    let inputElement;
    if (inputType === 'textarea') {
        inputElement = document.createElement('textarea');
        inputElement.rows = 2;
        inputElement.placeholder = 'Describa aquí...';
    } else {
        inputElement = document.createElement('input');
        inputElement.type = 'text';
    }
    inputElement.className = 'form-control';
    inputElement.name = inputName;
    inputElement.required = true; 
    div.appendChild(inputElement);
    const appendDiv = document.createElement('div');
    appendDiv.className = 'input-group-append';
    const removeButton = document.createElement('button');
    removeButton.className = 'btn btn-outline-danger';
    removeButton.type = 'button';
    removeButton.innerHTML = '<i class="fas fa-times"></i>';
    removeButton.onclick = function() { removeItem(this); };
    appendDiv.appendChild(removeButton);
    div.appendChild(appendDiv);
    container.appendChild(div);
    updateRemoveButtonState(container, '.simple-item-group');
}

function removeItem(button) {
    const group = button.closest('.simple-item-group');
    const container = group.parentNode;
    if (container.querySelectorAll('.simple-item-group').length > 1) {
        group.remove();
    } else {
        group.querySelector('.form-control').value = ''; 
    }
    updateRemoveButtonState(container, '.simple-item-group');
}

function addUnidad() {
    const container = document.getElementById('unidades-container');
    const newIndex = unidadCounter++;
    const div = document.createElement('div');
    div.className = 'unidad-group mb-4 border p-3 bg-light rounded';
    div.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="m-0">Unidad <span class="unidad-numero-display">${newIndex + 1}</span></h5>
            <button type="button" class="btn btn-sm btn-danger" onclick="removeUnidad(this)"><i class="fas fa-trash"></i> Eliminar Unidad</button>
        </div>
        <input type="hidden" name="unidades[${newIndex}][id]" value="">
        <div class="form-group"><label>Nombre de la Unidad *</label><input type="text" class="form-control" name="unidades[${newIndex}][nombre]" required></div>
        <div class="form-group" style="display:none;"><label>Número</label><input type="number" class="form-control unidad-numero-input" name="unidades[${newIndex}][numero]" value="${newIndex + 1}" required readonly></div>
        <div class="form-group"><label>Logro de la Unidad *</label><textarea class="form-control" name="unidades[${newIndex}][logro]" rows="2" required></textarea></div>
        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="unidades[${newIndex}][descripcion]" rows="2"></textarea></div>
        <hr>
        <h6 class="mt-3 mb-2">Semanas</h6>
        <div class="semanas-container" data-unidad-index="${newIndex}"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addSemana(this)"><i class="fas fa-plus"></i> Agregar Semana</button>
    `;
    container.appendChild(div);
    addSemana(div.querySelector('.btn-outline-secondary'), true);
    updateUnidadNumbers();
    updateRemoveButtonState(container, '.unidad-group');
}

function removeUnidad(button) {
    const group = button.closest('.unidad-group');
    const container = group.parentNode;
    if (container.querySelectorAll('.unidad-group').length > 1) {
        group.remove();
        updateUnidadNumbers();
    }
    updateRemoveButtonState(container, '.unidad-group');
}

function updateUnidadNumbers() {
    document.querySelectorAll('.unidad-group').forEach((unidad, index) => {
        unidad.querySelector('.unidad-numero-display').textContent = index + 1;
        unidad.querySelector('.unidad-numero-input').value = index + 1;
        unidad.querySelectorAll('[name^="unidades["]').forEach(input => {
            input.name = input.name.replace(/unidades\[\d+\]/, `unidades[${index}]`);
        });
        const semanasContainer = unidad.querySelector('.semanas-container');
        if (semanasContainer) {
            semanasContainer.dataset.unidadIndex = index;
            updateSemanaNumbers(semanasContainer);
        }
    });
}

function addSemana(button, isFirst = false) {
    const semanasContainer = button.closest('.unidad-group').querySelector('.semanas-container');
    const unidadIndex = semanasContainer.dataset.unidadIndex;
    const newSemanaIndex = semanasContainer.querySelectorAll('.semana-group').length;
    const div = document.createElement('div');
    div.className = 'semana-group mb-3 border p-2 rounded bg-white';
    div.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="m-0 font-weight-bold">Semana <span class="semana-numero-display">${newSemanaIndex + 1}</span></label>
            <input type="hidden" name="unidades[${unidadIndex}][semanas][${newSemanaIndex}][numero]" value="${newSemanaIndex + 1}" class="semana-numero-input">
            <button type="button" class="btn btn-xs btn-outline-danger" onclick="removeSemana(this)" ${isFirst ? 'disabled' : ''}><i class="fas fa-times"></i></button>
        </div>
        <div class="form-group"><label>Contenidos Conceptuales *</label><textarea class="form-control" name="unidades[${unidadIndex}][semanas][${newSemanaIndex}][contenido_conceptual]" rows="2" required></textarea></div>
        <div class="form-group"><label>Aprendizaje Esperado *</label><textarea class="form-control" name="unidades[${unidadIndex}][semanas][${newSemanaIndex}][aprendizaje_esperado]" rows="2" required></textarea></div>
        <div class="form-group"><label>Actividad de Evaluación</label><select class="form-control" name="unidades[${unidadIndex}][semanas][${newSemanaIndex}][tipo_actividad_id]">${tiposActividadOptionsJS}</select></div>
        <div class="form-group"><label>Notas / Descripción Adicional</label><textarea class="form-control" name="unidades[${unidadIndex}][semanas][${newSemanaIndex}][descripcion]" rows="1"></textarea></div>
    `;
    semanasContainer.appendChild(div);
    updateRemoveButtonState(semanasContainer, '.semana-group');
}

function removeSemana(button) {
    const semanaGroup = button.closest('.semana-group');
    const semanasContainer = semanaGroup.parentNode;
    if (semanasContainer.querySelectorAll('.semana-group').length > 1) {
        semanaGroup.remove();
        updateSemanaNumbers(semanasContainer);
    }
    updateRemoveButtonState(semanasContainer, '.semana-group');
}

function updateSemanaNumbers(semanasContainer) {
    const unidadIndex = semanasContainer.dataset.unidadIndex;
    semanasContainer.querySelectorAll('.semana-group').forEach((semana, index) => {
        semana.querySelector('.semana-numero-display').textContent = index + 1;
        semana.querySelector('.semana-numero-input').value = index + 1;
        semana.querySelectorAll('[name^="unidades["]').forEach(input => {
            input.name = input.name.replace(/\[semanas\]\[\d+\]/, `[semanas][${index}]`);
        });
    });
}

const tiposBibliografiaOptions = `
    <option value="">Seleccionar...</option>
    <?php foreach ($tipos_bibliografia as $tipo): ?>
    <option value="<?= $tipo['id'] ?>"><?= htmlspecialchars($tipo['nombre']) ?></option>
    <?php endforeach; ?>
`;

function addReferencia() {
    const container = document.getElementById('referencias-container');
    const newIndex = referenciaCounter++;
    const div = document.createElement('div');
    div.className = 'referencia-group border p-3 mb-3 rounded bg-light';
    div.innerHTML = `
        <div class="d-flex justify-content-end mb-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReferencia(this)"><i class="fas fa-times"></i> Eliminar</button></div>
        <div class="row">
            <div class="col-md-4 form-group"><label>Tipo *</label><select class="form-control" name="referencias[${newIndex}][tipo_id]" required>${tiposBibliografiaOptions}</select></div>
            <div class="col-md-8 form-group"><label>Autor(es)</label><input type="text" class="form-control" name="referencias[${newIndex}][autor]"></div>
        </div>
        <div class="form-group"><label>Título *</label><input type="text" class="form-control" name="referencias[${newIndex}][titulo]" required></div>
        <div class="row">
            <div class="col-md-3 form-group"><label>Año</label><input type="text" class="form-control" name="referencias[${newIndex}][anio]"></div>
            <div class="col-md-5 form-group"><label>Editorial / Revista</label><input type="text" class="form-control" name="referencias[${newIndex}][editorial]"></div>
            <div class="col-md-4 form-group"><label>Lugar / Páginas</label><input type="text" class="form-control" name="referencias[${newIndex}][lugar]"></div>
        </div>
        <div class="form-group"><label>URL</label><input type="url" class="form-control" name="referencias[${newIndex}][url]"></div>
    `;
    container.appendChild(div);
    updateRemoveButtonState(container, '.referencia-group');
}

function removeReferencia(button) {
    const group = button.closest('.referencia-group');
    if (group.parentNode.querySelectorAll('.referencia-group').length > 1) {
        group.remove();
    } else {
        group.querySelectorAll('input, select').forEach(input => input.value = '');
    }
}

function updateRemoveButtonState(container, itemSelector) {
    if (!container) return;
    const items = container.querySelectorAll(itemSelector);
    items.forEach((item, index) => {
        const removeBtn = item.querySelector('.btn-outline-danger, .btn-danger, .btn-xs');
        if (removeBtn) {
            removeBtn.disabled = (items.length <= 1);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    ['competencias-genericas-container', 'competencias-especificas-container', 'contenidos-procedimentales-container', 'contenidos-actitudinales-container'].forEach(id => {
        const container = document.getElementById(id);
        if (container) updateRemoveButtonState(container, '.simple-item-group');
    });
    const unidadesContainer = document.getElementById('unidades-container');
    if(unidadesContainer) {
        updateRemoveButtonState(unidadesContainer, '.unidad-group');
        unidadesContainer.querySelectorAll('.semanas-container').forEach(sc => updateRemoveButtonState(sc, '.semana-group'));
    }
    const refContainer = document.getElementById('referencias-container');
    if(refContainer) updateRemoveButtonState(refContainer, '.referencia-group');

    // ===================================================================
    // INICIO DE LA ACTUALIZACIÓN: Script para activar Select2
    // ===================================================================
    $('#estrategias').select2({
        theme: 'bootstrap4',
        placeholder: 'Seleccione una o más estrategias'
    });
    $('#recursos').select2({
        theme: 'bootstrap4',
        placeholder: 'Seleccione uno o más recursos'
    });
    // ===================================================================
    // FIN DE LA ACTUALIZACIÓN
    // ===================================================================
});
</script>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>