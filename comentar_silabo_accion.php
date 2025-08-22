<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Seguridad: Solo los administradores pueden añadir comentarios de revisión.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Acceso denegado.";
    header("Location: " . APP_URL . "/dashboard.php");
    exit;
}

// 2. Verificar que la solicitud sea por método POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Método no permitido.";
    header("Location: revision_silabos.php");
    exit;
}

// 3. Obtener y validar los datos del formulario.
$silabo_id = filter_input(INPUT_POST, 'silabo_id', FILTER_VALIDATE_INT);
$comentario = trim($_POST['comentario'] ?? '');

if (!$silabo_id || empty($comentario)) {
    $_SESSION['error_message'] = "Datos inválidos. El comentario no puede estar vacío.";
    header("Location: revision_silabos.php");
    exit;
}

// 4. Proceder con la lógica de negocio.
try {
    $pdo->beginTransaction();

    // Paso 4.1: Insertar el comentario en la tabla 'comentarios_silabo'.
    // Nota: Se inserta 'General' en la columna 'seccion' por ahora.
    $stmt_insert = $pdo->prepare(
        "INSERT INTO comentarios_silabo (silabo_id, usuario_id, comentario, seccion, resuelto) 
         VALUES (?, ?, ?, 'General', 0)"
    );
    $stmt_insert->execute([$silabo_id, $_SESSION['user_id'], $comentario]);

    // Paso 4.2: Cambiar el estado del sílabo de 'revision' a 'borrador'.
    $stmt_update = $pdo->prepare("UPDATE silabos SET estado = 'borrador' WHERE id = ? AND estado = 'revision'");
    $stmt_update->execute([$silabo_id]);

    // Paso 4.3: Añadir un registro al historial.
    $stmt_historial = $pdo->prepare(
        "INSERT INTO historial_silabos (silabo_id, usuario_id, accion, descripcion) 
         VALUES (?, ?, 'Observación', ?)"
    );
    $stmt_historial->execute([$silabo_id, $_SESSION['user_id'], $comentario]);

    $pdo->commit();
    $_SESSION['success_message'] = "Observaciones enviadas correctamente. El sílabo ha sido devuelto al docente.";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al añadir observación: " . $e->getMessage());
    $_SESSION['error_message'] = "Ocurrió un error al guardar las observaciones.";
}

// 5. Redirigir de vuelta a la página de revisión.
header("Location: revision_silabos.php");
exit;
?>
