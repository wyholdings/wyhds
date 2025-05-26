<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\AdminModel;

class AdminController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    // 대시보드
    public function dashboard(): void
    {
        echo $this->twig->render('admin/dashboard.html.twig', [
            'page_title' => '관리자 대시보드',
        ]);
    }

    // 로그인 폼
    public function loginForm(): void
    {
        echo $this->twig->render('admin/auth/login.html.twig');
    }

    //로그인
    public function login() {
        $username = str_replace(' ', '', $_POST['username'] ?? '');
        $password = str_replace(' ', '', $_POST['password'] ?? '');

        $adminModel = new AdminModel();
        $admin = $adminModel->getByUsername($username);
        
        //로그인 성공 시
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            echo json_encode(['success' => true, 'message' => '로그인 성공']);
            exit;
        }

        //로그인 실패
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 틀렸습니다.']);
        exit;
    }

    //로그아웃
    public function logout() {
        session_destroy();
        header('Location: /admin/login');
        exit;
    }
}
