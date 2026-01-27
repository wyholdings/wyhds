<?php

$router->get('/', [App\Controllers\HomeController::class, 'index']);
$router->get('/services', [App\Controllers\HomeController::class, 'services']);
$router->get('/login', [App\Controllers\AuthController::class, 'showLoginForm']);
$router->post('/login', [App\Controllers\AuthController::class, 'login']);
$router->get('/logout', [App\Controllers\AuthController::class, 'logout']);

//portfolio
$router->get('/portfolio', [App\Controllers\HomeController::class, 'portfolio']);

// 견적 문의
$router->post('/contact/submit', [App\Controllers\ContactController::class, 'submit']);

// 방문 로그 수집
$router->post('/visit/leave', [App\Controllers\VisitorLogController::class, 'leave']);

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

//프로젝트 관리
$router->get('/admin/project/list', [App\Controllers\ProjectController::class, 'list']);
$router->post('/admin/project/add', [App\Controllers\ProjectController::class, 'add']);
$router->get('/admin/project/add', [App\Controllers\ProjectController::class, 'add']);
$router->get('/admin/project/{id}/view', [App\Controllers\ProjectController::class, 'view']);
$router->get('/admin/project/{id}/edit', [App\Controllers\ProjectController::class, 'edit']);
$router->post('/admin/project/{id}/edit', [App\Controllers\ProjectController::class, 'edit']);
$router->get('/admin/project/{id}/delete', [App\Controllers\ProjectController::class, 'delete']);


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

$router->get('/admin/webhard', [App\Controllers\WebhardController::class, 'index']);
$router->get('/admin/webhard/', [App\Controllers\WebhardController::class, 'index']);
$router->get('/admin/webhard/download', [App\Controllers\WebhardController::class, 'download']);
$router->get('/admin/webhard/logs', [App\Controllers\WebhardController::class, 'logs']);
$router->post('/admin/webhard/folder', [App\Controllers\WebhardController::class, 'createFolder']);
$router->post('/admin/webhard/upload-folder', [App\Controllers\WebhardController::class, 'uploadFolder']);
$router->post('/admin/webhard/rename', [App\Controllers\WebhardController::class, 'rename']);
$router->post('/admin/webhard/delete', [App\Controllers\WebhardController::class, 'delete']);
$router->post('/admin/webhard/upload', [App\Controllers\WebhardController::class, 'upload']);

//ebook 빌드
$router->get('/admin/ebook/list', [App\Controllers\EbookController::class, 'list']);
$router->post('/admin/ebook/upload', [App\Controllers\EbookController::class, 'upload']);
$router->get('/admin/ebook/download/{ebookId}', [App\Controllers\EbookController::class, 'download']);
$router->post('/admin/ebook/{ebookId}/links', [App\Controllers\EbookController::class,'saveLinks']);

//접속 로그
$router->get('/admin/visitor/logs', [App\Controllers\VisitorLogController::class, 'list']);
?>
