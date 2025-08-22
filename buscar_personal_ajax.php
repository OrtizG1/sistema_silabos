<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// Seguridad básica
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin' || empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado.']));
}

header('Content-Type: application/json');

$dni = trim($_GET['dni'] ?? '');

if (empty($dni)) {
    http_response_code(400);
    echo json_encode(['error' => 'DNI no proporcionado.']);
    exit;
}

try {
    // CAMBIO: La consulta ahora también selecciona el DNI para devolverlo.
    $stmt = $pdo->prepare("
        SELECT p.id, p.nombre, p.apellidos, p.dni 
        FROM personal p
        LEFT JOIN usuarios u ON p.id = u.personal_id
        WHERE p.dni = ? AND u.id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$dni]);
    $persona = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($persona) {
        echo json_encode(['success' => true, 'persona' => $persona]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se encontró a ninguna persona con ese DNI o ya tiene una cuenta.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Error en buscar_personal_ajax.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error en el servidor.']);
}
