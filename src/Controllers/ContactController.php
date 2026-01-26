<?php

namespace App\Controllers;

use App\Models\Contact;
use App\Controllers\MessageController;

class ContactController
{
    public function submit()
    {
        // 허니팟(봇 차단)
        if (!empty($_POST['contact_method'])) {
            http_response_code(400);
            die("Closed.");
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Wrong request.']);
            exit;
        }

        header('Content-Type: application/json; charset=UTF-8');

        // 요청 무결성 체크(필요 없으면 삭제해도 됨)
        if (empty($_SERVER['HTTP_USER_AGENT']) || !isset($_SERVER['HTTP_REFERER'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $token = $_POST['contact_token'] ?? '';
        $sessionToken = $_SESSION['contact_form_token'] ?? '';
        if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $issuedAt = $_SESSION['contact_form_issued_at'] ?? 0;
        $elapsed = time() - (int)$issuedAt;
        if ($elapsed < 3 || $elapsed > 21600) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $clientIp = $this->getClientIp();
        if ($clientIp && $this->isRateLimited($clientIp)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => '요청이 너무 많습니다. 잠시 후 다시 시도해주세요.']);
            exit;
        }

        $company = trim((string)($_POST['company'] ?? ''));
        $name    = trim((string)($_POST['name'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $phone   = trim((string)($_POST['phone'] ?? ''));
        $budget  = (int)($_POST['money'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));

        if (
            $this->stringLength($company) > 120 ||
            $this->stringLength($name) > 80 ||
            $this->stringLength($email) > 120 ||
            $this->stringLength($phone) > 30 ||
            $this->stringLength($message) > 2000
        ) {
            echo json_encode(['success' => false, 'message' => '입력값을 확인해주세요.']);
            exit;
        }

        $urlCount = preg_match_all('/https?:\\/\\//i', $message, $matches);
        if ($urlCount > 1) {
            echo json_encode(['success' => false, 'message' => '입력값을 확인해주세요.']);
            exit;
        }

        if ($company && $name && filter_var($email, FILTER_VALIDATE_EMAIL) && $phone && $budget > 0 && $message) {
            $contact = new Contact();
            $contact->save($company, $name, $email, $phone, $budget, $message);
            $receiverNumber = '010-4928-4236'; // 문의 접수 받을 번호
            if ($receiverNumber) {
                $subject = '새 문의 접수';
                $msg = "회사: {$company}\n이름: {$name}";
                $messageController = new MessageController();
                $messageController->send($msg, $subject, $receiverNumber);
            }
            echo json_encode(['success' => true, 'message' => '문의가 접수되었습니다. 빠른 시일안에 연락 드리겠습니다.']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => '입력값을 확인해주세요.']);
            exit;
        }
    }

    private function getClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    private function isRateLimited(string $ip): bool
    {
        $limit = 3;
        $windowSeconds = 600;
        $now = time();
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contact_rate_' . sha1($ip);

        $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['count'], $decoded['reset_at'])) {
                $data = $decoded;
            }
        }

        if ((int)$data['reset_at'] <= $now) {
            $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        $data['count'] = (int)$data['count'] + 1;
        $blocked = $data['count'] > $limit;

        @file_put_contents($path, json_encode($data), LOCK_EX);

        return $blocked;
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}



?>
