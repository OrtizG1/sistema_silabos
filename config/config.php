<?php
// Configuración de la Base de Datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'sistema_syllabus');
define('DB_USER', 'admin');
define('DB_PASS', 'admin');
define('DB_CHARSET', 'utf8mb4');

// --- URL BASE DINÁMICA (VERSIÓN SIMPLIFICADA) ---
// Este bloque de código detecta automáticamente el host (IP pública o privada)
// y le añade la ruta conocida de tu proyecto.

// 1. Detecta el protocolo (http o https) y el host (la IP que usas en el navegador)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// 2. Define la constante APP_URL combinando el host dinámico con la ruta fija de tu proyecto
define('APP_URL', $protocol . '://' . $host . '/syllabus_system/public');

// --- FIN DE LA SOLUCIÓN ---


// La ruta base del proyecto (desde la carpeta 'config' sube un nivel a 'syllabus_system')
// Esto está correcto, no necesita cambios.
define('BASE_PATH', __DIR__ . '/..'); 

// --- AÑADIDO ---
// IDs de Roles (Asegúrate que coincidan con tu tabla `roles`)
define('ROL_ADMIN_ID', 1); // Asumiendo que 1 es admin
define('ROL_DOCENTE_ID', 2); // El rol docente tiene ID 2 según tu SQL

// --- NUEVO: Configuración para envío de correos (SMTP) ---
define('SMTP_HOST', 'smtp.gmail.com');       // Servidor SMTP (ej. para Gmail)
define('SMTP_USERNAME', 'obetortiz14@gmail.com'); // Tu dirección de correo
define('SMTP_PASSWORD', 'prde ktyc oltl pqxu'); // Tu contraseña
define('SMTP_PORT', 587);                     // Puerto SMTP (587 para TLS es común)
define('SMTP_SECURE', 'tls');                     // Tipo de seguridad (tls o ssl)
define('SMTP_FROM_EMAIL', 'oortiz@ucss.edu.pe'); // El correo que verán los destinatarios
define('SMTP_FROM_NAME', 'Sistema de Sílabos UCSS');
// --- FIN AÑADIDO ---

?>