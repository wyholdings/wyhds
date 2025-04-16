<?php

namespace App\Controllers;

use Twig\Environment;

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
        echo $this->twig->render('admin/login.html.twig');
    }

    // 로그인 처리
    public function login(): void
    {
        // 실제 로그인 로직은 여기서 처리
        // 예시: 세션 처리 및 사용자 인증
        session_start();

        // 로그인 성공 시 관리자 대시보드로 리디렉션
        $_SESSION['user'] = 'admin'; // 임시 사용자 데이터
        header('Location: /admin/dashboard');
        exit;
    }

    // 로그아웃 처리
    public function logout(): void
    {
        session_start();
        session_unset();  // 세션 데이터 제거
        session_destroy();  // 세션 종료

        // 로그인 페이지로 리디렉션
        header('Location: /admin/login');
        exit;
    }

    // 사용자 리스트
    public function userList(): void
    {
        echo $this->twig->render('admin/users.html.twig', [
            'page_title' => '사용자 목록',
        ]);
    }
}
