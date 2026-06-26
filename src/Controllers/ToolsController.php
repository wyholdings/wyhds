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
            'description' => 'JSON Formatter, Base64 변환, URL 인코딩, Unix Timestamp, UUID, 비밀번호, 해시, QR 코드, Cron, 색상 변환을 브라우저에서 바로 처리하는 무료 개발자 도구 모음입니다.',
            'keywords' => '개발자 도구, JSON Formatter, Base64 Decode, URL Encode, Unix Timestamp Converter, UUID Generator, Password Generator, Hash Generator, QR Code Generator, Cron Expression, Color Converter',
            'canonical_url' => 'https://wyhds.com/tools',
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
            'keywords' => $tools[$slug]['keywords'],
            'canonical_url' => 'https://wyhds.com/tools/' . $slug,
            'tool' => $tools[$slug],
            'tools' => array_values($tools),
        ]);
    }

    private function tools(): array
    {
        return [
            'json-formatter' => $this->tool('json-formatter', 'JSON Formatter', 'JSON 문자열을 보기 좋게 정렬하고 압축하며 문법 오류를 브라우저에서 즉시 확인하는 무료 JSON 포맷터입니다.', 'JSON 문자열을 입력한 뒤 Format 또는 Minify 버튼을 사용하세요.', '입력한 JSON은 서버에 저장되나요?', '아니요. 브라우저에서만 처리하며 서버에 저장하지 않습니다.', 'JSON Formatter, JSON Validator, JSON Minify, JSON Pretty Print, 개발자 도구'),
            'base64' => $this->tool('base64', 'Base64 Encode / Decode', '텍스트와 한글 문자열을 Base64로 인코딩하거나 Base64 데이터를 UTF-8 텍스트로 디코딩하는 브라우저 도구입니다.', '텍스트를 입력한 뒤 Encode 또는 Decode 버튼을 선택하세요.', 'Base64는 암호화인가요?', '아니요. Base64는 인코딩 방식이며 보안 목적의 암호화가 아닙니다.', 'Base64 Encode, Base64 Decode, Base64 변환, UTF-8 Base64, 개발자 도구'),
            'url-encode' => $this->tool('url-encode', 'URL Encode / Decode', 'URL 파라미터와 쿼리 문자열을 encodeURIComponent 기준으로 인코딩하거나 원래 텍스트로 디코딩합니다.', 'URL 또는 파라미터 값을 입력한 뒤 변환 버튼을 선택하세요.', '공백은 어떻게 변환되나요?', 'encodeURIComponent 기준으로 공백은 %20으로 변환됩니다.', 'URL Encode, URL Decode, Percent Encoding, Query String Encode, 개발자 도구'),
            'timestamp' => $this->tool('timestamp', 'Unix Timestamp Converter', 'Unix timestamp를 로컬 시간과 UTC 날짜로 변환하고 날짜 시간을 timestamp로 바꾸는 시간 변환 도구입니다.', 'timestamp 또는 날짜 시간을 입력한 뒤 변환 버튼을 사용하세요.', '초와 밀리초를 모두 지원하나요?', '13자리 값은 밀리초, 일반적인 10자리 값은 초 단위로 판단합니다.', 'Unix Timestamp Converter, Epoch Time, UTC Time, Timestamp 변환, 개발자 도구'),
            'uuid' => $this->tool('uuid', 'UUID Generator', '테스트 데이터, 식별자, API 개발에 사용할 UUID v4 값을 브라우저에서 여러 개 즉시 생성합니다.', '수량을 입력하고 Generate 버튼으로 UUID를 생성하세요.', '어떤 UUID 버전인가요?', '난수 기반 UUID v4를 생성합니다.', 'UUID Generator, UUID v4, GUID Generator, Random UUID, 개발자 도구'),
            'password-generator' => $this->tool('password-generator', 'Password Generator', '길이, 대문자, 소문자, 숫자, 특수문자 옵션을 선택해 임시 비밀번호를 브라우저에서 생성합니다.', '길이와 포함할 문자 종류를 선택한 뒤 Generate 버튼을 누르세요.', '생성된 비밀번호는 저장되나요?', '아니요. 브라우저에서 생성되고 서버에 저장하지 않습니다.', 'Password Generator, 비밀번호 생성기, Random Password, Secure Password, 개발자 도구'),
            'hash-generator' => $this->tool('hash-generator', 'Hash Generator', '텍스트를 SHA-1, SHA-256, SHA-384, SHA-512 해시 값으로 변환하는 브라우저 기반 해시 생성기입니다.', '텍스트와 알고리즘을 선택한 뒤 Generate 버튼을 사용하세요.', '해시를 원문으로 복원할 수 있나요?', '아니요. 해시는 단방향 변환입니다.', 'Hash Generator, SHA-256, SHA-512, SHA Hash, 개발자 도구'),
            'qr-code' => $this->tool('qr-code', 'QR Code Generator', 'URL과 텍스트를 QR 코드 이미지로 만들고 PNG로 내려받을 수 있는 브라우저 QR 코드 생성기입니다.', 'URL 또는 텍스트를 입력하고 Generate 버튼으로 QR 코드를 생성하세요.', 'QR 코드는 어디서 생성되나요?', 'CDN으로 불러온 qrcode 라이브러리를 사용해 브라우저에서 생성합니다.', 'QR Code Generator, QR 코드 생성기, URL QR Code, QR PNG Download, 개발자 도구'),
            'cron-helper' => $this->tool('cron-helper', 'Cron Expression Helper', '5필드 Cron 표현식의 분, 시, 일, 월, 요일 의미를 해석하고 자주 쓰는 스케줄 예시를 제공합니다.', '5필드 Cron 표현식을 입력한 뒤 Analyze 버튼을 사용하세요.', '몇 필드 Cron을 지원하나요?', '리눅스 crontab에서 흔히 쓰는 5필드 형식을 기준으로 안내합니다.', 'Cron Expression, Crontab Helper, Cron Schedule, Cron Parser, 개발자 도구'),
            'color-converter' => $this->tool('color-converter', 'Color Converter', 'HEX 색상 값을 RGB와 HSL로 변환하고 색상 미리보기를 제공하는 CSS 색상 변환 도구입니다.', '컬러 피커나 HEX 값을 입력하면 RGB와 HSL 값을 확인할 수 있습니다.', '짧은 HEX도 지원하나요?', '#0af 같은 3자리 HEX 값을 6자리로 확장해 처리합니다.', 'Color Converter, HEX to RGB, RGB to HSL, CSS Color, 개발자 도구'),
        ];
    }

    private function tool(string $slug, string $name, string $description, string $instruction, string $question, string $answer, string $keywords): array
    {
        return [
            'slug' => $slug,
            'url' => '/tools/' . $slug,
            'name' => $name,
            'h1' => $name,
            'description' => $description,
            'meta_title' => $name . ' | 개발자 도구 | 우용디앤에스',
            'meta_description' => $description,
            'keywords' => $keywords,
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
