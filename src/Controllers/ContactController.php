<?php

namespace App\Controllers;

use App\Models\Contact;

class ContactController
{
    public function submit()
    {
        header('Content-Type: application/json');

        $company = $_POST['company'] ?? '';
        $name    = $_POST['name'] ?? '';
        $email   = $_POST['email'] ?? '';
        $phone   = $_POST['phone'] ?? '';
        $budget  = $_POST['money'] ?? 0;
        $message = $_POST['message'] ?? '';

        if ($company && $name && filter_var($email, FILTER_VALIDATE_EMAIL) && $phone && $budget && $message) {
            $contact = new Contact();
            $contact->save($company, $name, $email, $phone, $budget, $message);
            echo json_encode(['success' => true, 'message' => '문의가 접수되었습니다. 빠른 시일안에 연락 드리겠습니다.']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => '입력값을 확인해주세요.']);
            exit;
        }
    }
}



?>