<?php

class AuthMiddleware {
    public static function checkAdminAuth() {
        if (empty($_SESSION['admin_logged_in'])) {
            header('Location: /admin/login');
            exit;
        }
    }
}

?>