<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\InquiryModel;

class InquiryController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    //문의 목록
    public function list()
    {
        $inquiryModel = new InquiryModel();
        $inquiries = $inquiryModel->getAll();
        echo $this->twig->render('admin/inquiry/list.html.twig', [
            'inquiries' => $inquiries
        ]);
    }

    //문의 정보 보기
    public function view($id)
    {
        // 모델 인스턴스 생성
        $inquiryModel = new InquiryModel();
        $inquiry = $inquiryModel->getInquiry($id);

        // 문의 정보가 없다면 404 페이지로 리디렉션
        if (!$inquiry) {
            // 예: 오류 처리 (404 페이지로 리디렉션)
            header('Location: /404');
            exit;
        }

        // 업체 정보를 템플릿에 전달
        echo $this->twig->render('admin/inquiry/view.html.twig', ['inquiry' => $inquiry]);
    }

    // 문의 삭제 처리
    public function delete($id)
    {
        $inquiryModel = new InquiryModel();
        $inquiryModel->deleteInquiry($id);
        header("Location: /admin/inquiry/list");
        exit;
    }
}
