<?php

$router->get('/', [App\Controllers\HomeController::class, 'index']);
$router->get('/login', [App\Controllers\AuthController::class, 'showLoginForm']);
$router->post('/login', [App\Controllers\AuthController::class, 'login']);
$router->get('/logout', [App\Controllers\AuthController::class, 'logout']);

//portfolio
$router->get('/portfolio', [App\Controllers\HomeController::class, 'portfolio']);

// 견적 문의
$router->post('/contact/submit', [App\Controllers\ContactController::class, 'submit']);

// 관리자(admin) 라우팅
$router->get('/admin/', [App\Controllers\AdminController::class, 'dashboard']);
$router->get('/admin', [App\Controllers\AdminController::class, 'dashboard']);
$router->get('/admin/login', [App\Controllers\AdminController::class, 'loginForm']);
$router->post('/admin/login', [App\Controllers\AdminController::class, 'login']);
$router->get('/admin/logout', [App\Controllers\AdminController::class, 'logout']);

//관리자 업체 관리 목록
$router->get('/admin/company/list', [App\Controllers\CompanyController::class, 'list']);
//관리자 업체 입력
$router->post('/admin/company/add', [App\Controllers\CompanyController::class, 'add']);
$router->get('/admin/company/add', [App\Controllers\CompanyController::class, 'add']);
// 관리자 업체 상세 보기
$router->get('/admin/company/{id}/view', [App\Controllers\CompanyController::class, 'view']);
// 관리자 업체 정보 수정
$router->get('/admin/company/{id}/edit',  [App\Controllers\CompanyController::class, 'edit']);
$router->post('/admin/company/{id}/edit', [App\Controllers\CompanyController::class, 'edit']);
// 관리자 업체 정보 삭제
$router->get('/admin/company/{id}/delete', [App\Controllers\CompanyController::class, 'delete']);


//견적 문의 목록
$router->get('/admin/inquiry/list', [App\Controllers\InquiryController::class, 'list']);
//견적 문의 상세 보기
$router->get('/admin/inquiry/{id}/view', [App\Controllers\InquiryController::class, 'view']);
//견적 문의 삭제
$router->get('/admin/inquiry/{id}/delete', [App\Controllers\InquiryController::class, 'delete']);

//수입/지출
$router->get('/admin/money/list', [App\Controllers\MoneyController::class, 'list']);
$router->post('/admin/money/add', [App\Controllers\MoneyController::class, 'add']);
$router->post('/admin/money/delete', [App\Controllers\MoneyController::class, 'delete']);

//ebook 빌드
$router->get('/admin/ebook/list', [App\Controllers\EbookController::class, 'list']);
$router->post('/admin/ebook/upload', [App\Controllers\EbookController::class, 'upload']);
?>