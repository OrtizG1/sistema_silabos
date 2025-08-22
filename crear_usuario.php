<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/src/includes/db_connection.php';

// Incluir PHPMailer
require_once BASE_PATH . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Seguridad: Solo los administradores pueden crear usuarios.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Acceso denegado.");
}

$errors = [];
$personal_id = '';
$rol_id = '';
$activo = 1; 

// --- Lógica de PROCESAMIENTO DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // (La lógica de procesamiento del POST no necesita cambios)
    $personal_id = filter_input(INPUT_POST, 'personal_id', FILTER_VALIDATE_INT);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $rol_id = filter_input(INPUT_POST, 'rol_id', FILTER_VALIDATE_INT);
    $activo = filter_input(INPUT_POST, 'activo', FILTER_VALIDATE_INT);

    if (empty($personal_id)) $errors[] = "Debe seleccionar a una persona.";
    if (empty($password)) $errors[] = "La contraseña es obligatoria.";
    if (strlen($password) < 8) $errors[] = "La contraseña debe tener al menos 8 caracteres.";
    if ($password !== $password_confirm) $errors[] = "Las contraseñas no coinciden.";
    if (empty($rol_id)) $errors[] = "Debe seleccionar un rol para el usuario.";

    if (empty($errors)) {
        $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE personal_id = ?");
        $stmt_check->execute([$personal_id]);
        if ($stmt_check->fetch()) {
            $errors[] = "Esta persona ya tiene una cuenta de usuario.";
        }
    }

    if (empty($errors)) {
        try {
            $stmt_personal = $pdo->prepare("SELECT nombre, apellidos, email FROM personal WHERE id = ?");
            $stmt_personal->execute([$personal_id]);
            $persona = $stmt_personal->fetch(PDO::FETCH_ASSOC);
            if (!$persona) { throw new Exception("No se encontró el registro de personal seleccionado."); }

            $password_hashed = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuarios (personal_id, email, password, rol_id, activo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$personal_id, $persona['email'], $password_hashed, $rol_id, $activo]);

            $email_sent_successfully = false;
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = SMTP_SECURE;
                $mail->Port = SMTP_PORT;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($persona['email'], $persona['nombre'] . ' ' . $persona['apellidos']);
                $mail->isHTML(true);
                $mail->Subject = 'Bienvenido al Sistema de Sílabos';
                $mail->Body = "<h1>¡Bienvenido/a, " . htmlspecialchars($persona['nombre']) . "!</h1><p>Se ha creado una cuenta para ti en el Sistema de Gestión de Sílabos. A continuación encontrarás tus credenciales de acceso:</p><ul><li><strong>Usuario:</strong> " . htmlspecialchars($persona['email']) . "</li><li><strong>Contraseña:</strong> " . htmlspecialchars($password) . "</li></ul><p>Puedes iniciar sesión en el siguiente enlace: <a href='http://190.43.118.85:8080/syllabus_system/'>http://190.43.118.85:8080/syllabus_system/</a> </p><p>Saludos Cordiales.</p>";
                $mail->send();
                $email_sent_successfully = true;
            } catch (Exception $e) {
                error_log("Error de PHPMailer al crear usuario: " . $mail->ErrorInfo);
            }

            $_SESSION['success_message'] = $email_sent_successfully ? "Cuenta de usuario creada exitosamente. Se ha enviado un correo de bienvenida." : "Cuenta creada, pero no se pudo enviar el correo de notificación.";
            header("Location: gestionar_usuarios.php");
            exit;
        } catch (Exception $e) {
            $errors[] = "Error al crear la cuenta de usuario: " . $e->getMessage();
        }
    }
}

// --- Lógica para obtener datos para el formulario ---
try {
    // CAMBIO: Ahora también obtenemos el DNI para usarlo en el formulario.
    $personal_sin_cuenta = $pdo->query("
        SELECT p.id, p.nombre, p.apellidos, p.email, p.dni 
        FROM personal p
        LEFT JOIN usuarios u ON p.id = u.personal_id
        WHERE u.id IS NULL
        ORDER BY p.apellidos, p.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $personal_sin_cuenta = [];
    $roles = [];
    $errors[] = "No se pudieron cargar los datos necesarios para el formulario.";
}

$page_title = "Crear Nueva Cuenta de Usuario";
require_once BASE_PATH . '/src/templates/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Por favor, corrija los siguientes errores:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card card-primary">
    <div class="card-header"><h3 class="card-title">Formulario de Creación de Cuenta</h3></div>
    <form action="crear_usuario.php" method="POST">
        <div class="card-body">
            
            <div class="form-group">
                <label for="dni_search">Buscar Persona por DNI</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="dni_search" placeholder="Ingrese el DNI y presione Buscar">
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" id="btn_buscar_dni">Buscar</button>
                    </div>
                </div>
                <div id="search_result" class="mt-2"></div>
            </div>
            <hr>

            <div class="form-group">
                <label for="personal_id">O, Seleccionar Persona de la Lista *</label>
                <select class="form-control" id="personal_id" name="personal_id" required>
                    <option value="">-- Seleccionar de la lista de personal registrado --</option>
                    <?php foreach ($personal_sin_cuenta as $persona): ?>
                        <!-- CAMBIO: Añadimos el atributo data-dni a cada opción -->
                        <option value="<?= $persona['id'] ?>" data-dni="<?= htmlspecialchars($persona['dni'] ?? '') ?>" <?= ($personal_id == $persona['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($persona['apellidos'] . ', ' . $persona['nombre'] . ' (' . $persona['email'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Solo se muestran las personas que aún no tienen una cuenta de usuario.</small>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="password">Contraseña *</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    <small class="form-text text-muted">Mínimo 8 caracteres. Se autocompletará con el DNI al seleccionar una persona.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label for="password_confirm">Confirmar Contraseña *</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="rol_id">Asignar Rol *</label>
                    <select class="form-control" id="rol_id" name="rol_id" required>
                        <option value="">-- Seleccionar un rol --</option>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= $rol['id'] ?>" <?= ($rol_id == $rol['id']) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($rol['nombre'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 form-group">
                    <label for="activo">Estado de la Cuenta</label>
                    <select class="form-control" id="activo" name="activo">
                        <option value="1" <?= ($activo == 1) ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= ($activo == 0) ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Crear Cuenta</button>
            <a href="gestionar_usuarios.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php
require_once BASE_PATH . '/src/templates/footer.php';
?>

<!-- ===== INICIO: SCRIPT ACTUALIZADO ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnBuscar = document.getElementById('btn_buscar_dni');
    const dniInput = document.getElementById('dni_search');
    const personalSelect = document.getElementById('personal_id');
    const resultDiv = document.getElementById('search_result');
    const passwordInput = document.getElementById('password');
    const passwordConfirmInput = document.getElementById('password_confirm');

    function setPassword(dni) {
        if (dni) {
            passwordInput.value = dni;
            passwordConfirmInput.value = dni;
        } else {
            passwordInput.value = '';
            passwordConfirmInput.value = '';
        }
    }

    btnBuscar.addEventListener('click', function() {
        const dni = dniInput.value.trim();
        if (!dni) {
            resultDiv.innerHTML = '<div class="alert alert-warning">Por favor, ingrese un DNI.</div>';
            return;
        }
        resultDiv.innerHTML = 'Buscando...';
        fetch(`buscar_personal_ajax.php?dni=${dni}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const persona = data.persona;
                const optionExists = personalSelect.querySelector(`option[value="${persona.id}"]`);
                if (optionExists) {
                    personalSelect.value = persona.id;
                    resultDiv.innerHTML = `<div class="alert alert-success">Persona encontrada y seleccionada: <strong>${persona.apellidos}, ${persona.nombre}</strong></div>`;
                    setPassword(persona.dni); // Autocompletar contraseña
                } else {
                    resultDiv.innerHTML = `<div class="alert alert-danger">Se encontró a la persona, pero ya tiene una cuenta de usuario.</div>`;
                    setPassword('');
                }
            } else {
                resultDiv.innerHTML = `<div class="alert alert-danger">${data.message || 'DNI no encontrado.'}</div>`;
                setPassword('');
            }
        })
        .catch(error => {
            console.error('Error en la búsqueda:', error);
            resultDiv.innerHTML = '<div class="alert alert-danger">Ocurrió un error al realizar la búsqueda.</div>';
        });
    });

    personalSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const dni = selectedOption.dataset.dni || '';
        setPassword(dni); // Autocompletar contraseña al cambiar la selección
    });
});
</script>
<!-- ===== FIN: SCRIPT ACTUALIZADO ===== -->
