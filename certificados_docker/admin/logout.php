<?php
// admin/logout.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

if (isset($_SESSION['user_id'])) {
    registrarAuditoria('LOGOUT', 'usuarios', $_SESSION['user_id']);
}

session_destroy();
header('Location: login.php');
exit;
?>
