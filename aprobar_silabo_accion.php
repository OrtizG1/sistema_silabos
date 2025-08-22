<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// 1. Verificar que el usuario sea administrador
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Acceso denegado. No tiene permisos para aprobar sílabos.";
    header('Location: revision_silabos.php');
    exit;
}

// 2. Verificar que la solicitud sea por POST y contenga el ID del sílabo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['silabo_id'])) {
    
    $silabo_id = filter_input(INPUT_POST, 'silabo_id', FILTER_VALIDATE_INT);
    $admin_id = $_SESSION['user_id'];

    if (!$silabo_id) {
        $_SESSION['error_message'] = "ID de sílabo no válido.";
        header('Location: revision_silabos.php');
        exit;
    }

    try {
        // Usar una transacción es una buena práctica para asegurar que todas las operaciones se completen
        $pdo->beginTransaction();

        // 3. Actualizar el estado del sílabo a 'aprobado'
        $stmt_update = $pdo->prepare(
            "UPDATE silabos 
             SET estado = 'aprobado', aprobado_por = :admin_id, fecha_aprobacion = NOW() 
             WHERE id = :silabo_id AND estado = 'revision'"
        );
        $stmt_update->execute([
            ':admin_id' => $admin_id,
            ':silabo_id' => $silabo_id
        ]);

        // 4. (Opcional pero recomendado) Insertar un registro en el historial
        if ($stmt_update->rowCount() > 0) { // Solo si la actualización fue exitosa
            $stmt_historial = $pdo->prepare(
                "INSERT INTO historial_silabos (silabo_id, usuario_id, accion, descripcion) 
                 VALUES (:silabo_id, :usuario_id, 'Aprobación', 'El sílabo fue aprobado.')"
            );
            $stmt_historial->execute([
                ':silabo_id' => $silabo_id,
                ':usuario_id' => $admin_id
            ]);

            $pdo->commit(); // Confirmar todos los cambios en la base de datos
            $_SESSION['success_message'] = "El sílabo ha sido aprobado exitosamente.";
        } else {
            $pdo->rollBack(); // Deshacer si no se actualizó nada
            $_SESSION['error_message'] = "No se pudo aprobar el sílabo. Puede que ya haya sido procesado.";
        }

    } catch (PDOException $e) {
        $pdo->rollBack(); // Deshacer en caso de error de base de datos
        error_log("Error al aprobar sílabo: " . $e->getMessage());
        $_SESSION['error_message'] = "Ocurrió un error en la base de datos.";
    }

} else {
    $_SESSION['error_message'] = "Solicitud no válida.";
}

// 5. Redirigir siempre de vuelta a la página de revisión
header('Location: revision_silabos.php');
exit;