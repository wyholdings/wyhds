<?php

namespace App\Controllers;

use Twig\Environment;

class ToolsController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): void
    {
        echo $this->twig->render('tools/index.html.twig', [
            'title' => '개발자 도구 모음 | 우용디앤에스',
            'description' => 'JSON Formatter, Base64, URL Encode, Unix Timestamp, UUID, Password, Hash, QR Code, Cron, Color Converter를 브라우저에서 바로 사용하는 개발자 도구 모음입니다.',
            'tools' => array_values($this->tools()),
        ]);
    }

    public function show(string $slug): void
    {
        $tools = $this->tools();

        if (!isset($tools[$slug])) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            return;
        }

        echo $this->twig->render('tools/show.html.twig', [
            'title' => $tools[$slug]['meta_title'],
            'description' => $tools[$slug]['meta_description'],
            'tool' => $tools[$slug],
            'tools' => array_values($tools),
        ]);
    }

    private function tools(): array
    {
        return [
            'json-formatter' => $this->tool('json-formatter', 'JSON Formatter', 'JSON 데이터를 보기 좋게 정렬하고 유효성 오류를 확인합니다.', 'JSON 문자열을 입력한 뒤 Format 또는 Minify 버튼을 사용하세요.', '입력한 JSON은 서버에 저장되나요?', '아니요. 브라우저에서만 처리하며 서버에 저장하지 않습니다.'),
            'base64' => $this->tool('base64', 'Base64 Encode / Decode', '텍스트를 Base64로 인코딩하거나 Base64 문자열을 디코딩합니다.', '텍스트를 입력한 뒤 Encode 또는 Decode 버튼을 선택하세요.', 'Base64는 암호화인가요?', '아니요. Base64는 인코딩 방식이며 보안 목적의 암호화가 아닙니다.'),
            'url-encode' => $this->tool('url-encode', 'URL Encode / Decode', 'URL 파라미터에 사용할 문자열을 인코딩하거나 디코딩합니다.', 'URL 또는 파라미터 값을 입력한 뒤 변환 버튼을 선택하세요.', '공백은 어떻게 변환되나요?', 'encodeURIComponent 기준으로 공백은 %20으로 변환됩니다.'),
            'timestamp' => $this->tool('timestamp', 'Unix Timestamp Converter', 'Unix timestamp와 사람이 읽는 날짜 시간을 상호 변환합니다.', 'timestamp 또는 날짜 시간을 입력한 뒤 변환 버튼을 사용하세요.', '초와 밀리초를 모두 지원하나요?', '13자리 값은 밀리초, 일반적인 10자리 값은 초 단위로 판단합니다.'),
            'uuid' => $this->tool('uuid', 'UUID Generator', '브라우저에서 UUID v4 값을 즉시 생성합니다.', '수량을 입력하고 Generate 버튼으로 UUID를 생성하세요.', '어떤 UUID 버전인가요?', '난수 기반 UUID v4를 생성합니다.'),
            'password-generator' => $this->tool('password-generator', 'Password Generator', '길이와 문자 옵션을 선택해 임시 비밀번호를 생성합니다.', '길이와 포함할 문자 종류를 선택한 뒤 Generate 버튼을 누르세요.', '생성된 비밀번호는 저장되나요?', '아니요. 브라우저에서 생성되고 서버에 저장하지 않습니다.'),
            'hash-generator' => $this->tool('hash-generator', 'Hash Generator', '텍스트의 SHA-1, SHA-256, SHA-384, SHA-512 해시를 생성합니다.', '텍스트와 알고리즘을 선택한 뒤 Generate 버튼을 사용하세요.', '해시를 원문으로 복원할 수 있나요?', '아니요. 해시는 단방향 변환입니다.'),
            'qr-code' => $this->tool('qr-code', 'QR Code Generator', 'URL이나 텍스트를 QR 코드 이미지로 생성합니다.', 'URL 또는 텍스트를 입력하고 Generate 버튼으로 QR 코드를 생성하세요.', 'QR 코드는 어디서 생성되나요?', 'CDN으로 불러온 qrcode 라이브러리를 사용해 브라우저에서 생성합니다.'),
            'cron-helper' => $this->tool('cron-helper', 'Cron Expression Helper', 'Cron 표현식의 의미를 확인하고 자주 쓰는 예시를 선택합니다.', '5필드 Cron 표현식을 입력한 뒤 Analyze 버튼을 사용하세요.', '몇 필드 Cron을 지원하나요?', '리눅스 crontab에서 흔히 쓰는 5필드 형식을 기준으로 안내합니다.'),
            'color-converter' => $this->tool('color-converter', 'Color Converter', 'HEX, RGB, HSL 색상 값을 상호 변환하고 미리봅니다.', '컬러 피커나 HEX 값을 입력하면 RGB와 HSL 값을 확인할 수 있습니다.', '짧은 HEX도 지원하나요?', '#0af 같은 3자리 HEX 값을 6자리로 확장해 처리합니다.'),
        ];
    }

    private function tool(string $slug, string $name, string $description, string $instruction, string $question, string $answer): array
    {
        return [
            'slug' => $slug,
            'url' => '/tools/' . $slug,
            'name' => $name,
            'h1' => $name,
            'description' => $description,
            'meta_title' => $name . ' | 개발자 도구 | 우용디앤에스',
            'meta_description' => $description,
            'instructions' => [
                $instruction,
                '결과는 화면에만 표시되며 서버에 저장하지 않습니다.',
                '필요한 결과는 Copy 버튼으로 클립보드에 복사할 수 있습니다.',
            ],
            'faqs' => [
                ['q' => $question, 'a' => $answer],
                ['q' => '모바일에서도 사용할 수 있나요?', 'a' => '네. 반응형 레이아웃으로 모바일 브라우저에서도 사용할 수 있습니다.'],
            ],
        ];
    }
}

?>
