<?php
namespace App\Controllers;

class AuthController {
    public function showLoginForm() {
        view_path_require('login');
    }

    public function login() {
        session_start();

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        // 데모용 계정 (실제는 DB에서 확인해야 함)
        if ($email === 'admin@example.com' && $password === 'password') {
            $_SESSION['user'] = $email;
            header('Location: /');
        } else {
            echo "❌ 로그인 실패. 다시 시도해주세요.";
        }
    }

    public function logout() {
        session_start();
        session_destroy();
        header('Location: /login');
    }
}
