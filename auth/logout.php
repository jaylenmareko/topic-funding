<?php
// auth/logout.php - Clean logout without messages
session_start();
session_destroy();
header('Location: /');
exit;
?>
