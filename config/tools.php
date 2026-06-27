<?php

$tool = static function (
    string $slug,
    string $name,
    string $category,
    string $summary,
    array $options = []
): array {
    $description = $options['description'] ?? $summary;
    $keywords = $options['keywords'] ?? [$name, 'WY Tools', '무료 온라인 도구'];
    $related = $options['related'] ?? [];

    return array_merge($options, [
        'slug' => $slug,
        'url' => '/tools/' . $slug,
        'name' => $name,
        'h1' => $name,
        'category' => $category,
        'summary' => $summary,
        'description' => $description,
        'meta_title' => $name . ' | WY Tools',
        'meta_description' => $description,
        'keywords' => implode(', ', $keywords),
        'status' => $options['status'] ?? 'active',
        'widget' => $options['widget'] ?? 'generic',
        'is_popular' => (bool)($options['is_popular'] ?? false),
        'is_recent' => (bool)($options['is_recent'] ?? false),
        'is_frequent' => (bool)($options['is_frequent'] ?? false),
        'how_to_use' => $options['how_to_use'] ?? [
            '입력 영역에 변환하거나 확인할 값을 입력합니다.',
            '도구가 제공하는 옵션을 선택한 뒤 결과를 확인합니다.',
            '필요한 결과는 복사하거나 공유해서 사용할 수 있습니다.',
        ],
        'examples' => $options['examples'] ?? [
            [
                'title' => $name . ' 사용 예시',
                'input' => '업무나 학습 중 변환이 필요한 데이터를 입력합니다.',
                'output' => '브라우저에서 즉시 처리된 결과를 확인합니다.',
            ],
        ],
        'faqs' => $options['faqs'] ?? [
            [
                'q' => $name . '는 무료인가요?',
                'a' => '네. WY Tools의 기본 온라인 도구는 무료로 사용할 수 있습니다.',
            ],
            [
                'q' => '입력한 데이터는 서버에 저장되나요?',
                'a' => '가능한 기능은 브라우저에서 처리하며, 입력값을 서버에 저장하지 않는 구조를 우선합니다.',
            ],
        ],
        'related' => $related,
        'premium_ready' => (bool)($options['premium_ready'] ?? false),
        'api_ready' => (bool)($options['api_ready'] ?? false),
    ], $options);
};

return [
    'json-formatter' => $tool('json-formatter', 'JSON Formatter', 'developer', 'JSON 문자열을 보기 좋게 정렬하고 압축하며 문법 오류를 빠르게 확인합니다.', [
        'widget' => 'json-formatter',
        'is_popular' => true,
        'is_frequent' => true,
        'keywords' => ['JSON Formatter', 'JSON Pretty Print', 'JSON Minify', 'JSON Validator'],
        'related' => ['json-validator', 'json-csv', 'json-xml', 'jwt-decoder'],
        'how_to_use' => ['JSON 문자열을 입력합니다.', 'Format 버튼으로 들여쓰기 된 JSON을 확인합니다.', 'Minify 버튼으로 공백을 제거한 JSON을 만들 수 있습니다.'],
        'examples' => [['title' => 'API 응답 JSON 정리', 'input' => '{"name":"WY Tools","type":"free"}', 'output' => "JSON이 줄바꿈과 들여쓰기 형태로 정리됩니다."]],
        'faqs' => [['q' => '잘못된 JSON도 자동 수정하나요?', 'a' => '문법 오류는 알려주지만 데이터 의미를 추정해 자동 수정하지는 않습니다.'], ['q' => '입력한 JSON은 저장되나요?', 'a' => '아니요. 브라우저에서만 처리합니다.']],
    ]),
    'json-validator' => $tool('json-validator', 'JSON Validator', 'developer', 'JSON 문법이 유효한지 확인하고 오류를 찾습니다.', ['widget' => 'json-validator', 'is_recent' => true, 'keywords' => ['JSON Validator', 'JSON Syntax Checker'], 'related' => ['json-formatter', 'json-csv', 'json-xml']]),
    'base64' => $tool('base64', 'Base64 Encode / Decode', 'encoding', '텍스트와 한글 문자열을 Base64로 인코딩하거나 디코딩합니다.', [
        'widget' => 'base64',
        'is_popular' => true,
        'keywords' => ['Base64 Encode', 'Base64 Decode', 'Base64 변환'],
        'related' => ['url-encode', 'image-to-base64', 'jwt-decoder'],
        'how_to_use' => ['원본 텍스트나 Base64 문자열을 입력합니다.', 'Encode 또는 Decode 버튼을 선택합니다.', '결과를 복사해 필요한 곳에 사용합니다.'],
        'faqs' => [['q' => '한글도 Base64 변환이 되나요?', 'a' => '네. UTF-8 기준으로 한글도 변환합니다.'], ['q' => 'Base64는 암호화인가요?', 'a' => '아니요. 인코딩 방식이며 보안 목적의 암호화가 아닙니다.']],
    ]),
    'jwt-decoder' => $tool('jwt-decoder', 'JWT Decoder', 'developer', 'JWT 토큰의 header와 payload를 디코딩해 내용을 확인합니다.', ['widget' => 'jwt-decoder', 'is_recent' => true, 'keywords' => ['JWT Decoder', 'JWT Decode'], 'related' => ['base64', 'json-formatter', 'sha256']]),
    'uuid' => $tool('uuid', 'UUID Generator', 'developer', 'UUID v4 값을 브라우저에서 여러 개 즉시 생성합니다.', [
        'widget' => 'uuid',
        'is_popular' => true,
        'keywords' => ['UUID Generator', 'UUID v4', 'GUID Generator'],
        'related' => ['password-generator', 'hash-generator', 'jwt-decoder'],
    ]),
    'regex-tester' => $tool('regex-tester', 'Regex Tester', 'developer', '정규식을 테스트하고 매칭 결과를 확인합니다.', ['widget' => 'regex-tester', 'is_recent' => true, 'keywords' => ['Regex Tester', 'Regular Expression Test'], 'related' => ['word-counter', 'text-diff', 'remove-duplicate-lines']]),
    'sql-formatter' => $tool('sql-formatter', 'SQL Formatter', 'developer', 'SQL 쿼리를 읽기 좋은 형태로 정리합니다.', ['widget' => 'sql-formatter', 'keywords' => ['SQL Formatter', 'SQL Pretty Print'], 'related' => ['json-formatter', 'csv-viewer']]),
    'cron-generator' => $tool('cron-generator', 'Cron Generator', 'developer', 'Cron 표현식을 만들고 5필드 스케줄 의미를 확인합니다.', [
        'widget' => 'cron-helper',
        'keywords' => ['Cron Generator', 'Cron Expression', 'Crontab'],
        'related' => ['timestamp', 'date-calculator', 'd-day-calculator'],
    ]),
    'cron-helper' => $tool('cron-helper', 'Cron Expression Helper', 'developer', '5필드 Cron 표현식의 분, 시, 일, 월, 요일 의미를 해석합니다.', [
        'widget' => 'cron-helper',
        'related' => ['cron-generator', 'timestamp', 'date-calculator'],
    ]),

    'lorem-ipsum' => $tool('lorem-ipsum', 'Lorem Ipsum Generator', 'text', '디자인 시안과 문서 작업에 사용할 더미 텍스트를 생성합니다.', ['widget' => 'lorem-ipsum', 'is_recent' => true, 'related' => ['word-counter', 'case-converter']]),
    'word-counter' => $tool('word-counter', 'Word Counter', 'text', '글자 수, 단어 수, 줄 수를 계산합니다.', ['widget' => 'word-counter', 'is_popular' => true, 'related' => ['case-converter', 'remove-duplicate-lines', 'text-diff']]),
    'case-converter' => $tool('case-converter', 'Case Converter', 'text', '영문 텍스트를 대문자, 소문자, 제목 케이스 등으로 변환합니다.', ['widget' => 'case-converter', 'related' => ['word-counter', 'lorem-ipsum']]),
    'remove-duplicate-lines' => $tool('remove-duplicate-lines', 'Remove Duplicate Lines', 'text', '중복된 줄을 제거하고 고유한 목록만 남깁니다.', ['widget' => 'remove-duplicate-lines', 'related' => ['text-diff', 'word-counter']]),
    'text-diff' => $tool('text-diff', 'Text Diff', 'text', '두 텍스트의 차이를 비교합니다.', ['widget' => 'text-diff', 'related' => ['remove-duplicate-lines', 'regex-tester']]),

    'image-compress' => $tool('image-compress', 'Image Compress', 'image', '이미지 용량을 줄여 웹 업로드와 공유에 적합하게 만듭니다.', ['widget' => 'image-processor', 'is_popular' => true, 'related' => ['image-resize', 'webp-converter']]),
    'image-resize' => $tool('image-resize', 'Image Resize', 'image', '이미지의 가로, 세로 크기를 조정합니다.', ['widget' => 'image-processor', 'related' => ['image-compress', 'image-crop']]),
    'image-crop' => $tool('image-crop', 'Image Crop', 'image', '이미지에서 필요한 영역만 잘라냅니다.', ['widget' => 'image-processor', 'related' => ['image-resize', 'webp-converter']]),
    'webp-converter' => $tool('webp-converter', 'WEBP Converter', 'image', '이미지를 WEBP 형식으로 변환해 웹 성능을 개선합니다.', ['widget' => 'image-processor', 'related' => ['image-compress', 'image-resize']]),
    'image-to-base64' => $tool('image-to-base64', 'Image to Base64', 'image', '이미지 파일을 Base64 문자열로 변환합니다.', ['widget' => 'image-base64', 'related' => ['base64', 'webp-converter']]),

    'merge-pdf' => $tool('merge-pdf', 'Merge PDF', 'pdf', '여러 PDF 파일을 하나의 문서로 합칩니다.', ['widget' => 'pdf-merge', 'is_popular' => true, 'related' => ['split-pdf', 'compress-pdf']]),
    'split-pdf' => $tool('split-pdf', 'Split PDF', 'pdf', 'PDF 문서를 페이지 단위로 분리합니다.', ['widget' => 'pdf-split', 'related' => ['merge-pdf', 'rotate-pdf']]),
    'compress-pdf' => $tool('compress-pdf', 'Compress PDF', 'pdf', 'PDF 파일 용량을 줄입니다.', ['widget' => 'pdf-compress', 'related' => ['merge-pdf', 'split-pdf']]),
    'rotate-pdf' => $tool('rotate-pdf', 'Rotate PDF', 'pdf', 'PDF 페이지 방향을 회전합니다.', ['widget' => 'pdf-rotate', 'related' => ['split-pdf', 'merge-pdf']]),

    'json-csv' => $tool('json-csv', 'JSON to CSV Converter', 'converter', 'JSON 데이터를 CSV 형식으로 변환합니다.', ['widget' => 'json-csv', 'related' => ['json-formatter', 'csv-viewer', 'json-xml']]),
    'json-xml' => $tool('json-xml', 'JSON to XML Converter', 'converter', 'JSON과 XML 데이터를 상호 변환합니다.', ['widget' => 'json-xml', 'related' => ['json-formatter', 'json-csv']]),
    'csv-viewer' => $tool('csv-viewer', 'CSV Viewer', 'converter', 'CSV 데이터를 표 형태로 확인합니다.', ['widget' => 'csv-viewer', 'related' => ['json-csv', 'sql-formatter']]),
    'timestamp' => $tool('timestamp', 'Timestamp Converter', 'date-time', 'Unix timestamp를 로컬 시간과 UTC 날짜로 변환합니다.', [
        'widget' => 'timestamp',
        'is_popular' => true,
        'keywords' => ['Unix Timestamp Converter', 'Epoch Time', 'Timestamp 변환'],
        'related' => ['cron-generator', 'date-calculator', 'd-day-calculator'],
    ]),

    'password-generator' => $tool('password-generator', 'Password Generator', 'security', '길이와 문자 옵션을 선택해 임시 비밀번호를 생성합니다.', [
        'widget' => 'password-generator',
        'is_popular' => true,
        'is_frequent' => true,
        'related' => ['uuid', 'sha256', 'bcrypt'],
    ]),
    'sha256' => $tool('sha256', 'SHA256 Generator', 'security', '텍스트를 SHA-256 해시 값으로 변환합니다.', ['widget' => 'hash-generator', 'related' => ['hash-generator', 'md5', 'bcrypt']]),
    'md5' => $tool('md5', 'MD5 Generator', 'security', '텍스트의 MD5 해시 값을 생성합니다.', ['widget' => 'md5', 'related' => ['sha256', 'hash-generator']]),
    'bcrypt' => $tool('bcrypt', 'bcrypt Hash Generator', 'security', '비밀번호 저장에 자주 쓰이는 bcrypt 해시를 생성합니다.', ['widget' => 'bcrypt-helper', 'related' => ['password-generator', 'sha256']]),
    'hash-generator' => $tool('hash-generator', 'Hash Generator', 'security', '텍스트를 SHA-1, SHA-256, SHA-384, SHA-512 해시 값으로 변환합니다.', [
        'widget' => 'hash-generator',
        'related' => ['sha256', 'md5', 'password-generator'],
    ]),

    'vat-calculator' => $tool('vat-calculator', 'VAT 계산기', 'calculator', '공급가액과 부가세, 합계 금액을 계산합니다.', ['widget' => 'vat-calculator', 'is_recent' => true, 'related' => ['percent-calculator']]),
    'percent-calculator' => $tool('percent-calculator', '퍼센트 계산기', 'calculator', '비율, 증감률, 할인율을 계산합니다.', ['widget' => 'percent-calculator', 'is_popular' => true, 'related' => ['vat-calculator']]),
    'date-calculator' => $tool('date-calculator', '날짜 계산기', 'calculator', '두 날짜 사이의 차이나 특정 날짜 이후의 날짜를 계산합니다.', ['widget' => 'date-calculator', 'related' => ['d-day-calculator', 'age-calculator', 'timestamp']]),
    'd-day-calculator' => $tool('d-day-calculator', 'D-Day 계산기', 'calculator', '목표 날짜까지 남은 일수를 계산합니다.', ['widget' => 'd-day-calculator', 'related' => ['date-calculator', 'age-calculator']]),
    'age-calculator' => $tool('age-calculator', '나이 계산기', 'calculator', '생년월일 기준 만 나이와 연령 정보를 계산합니다.', ['widget' => 'age-calculator', 'related' => ['date-calculator', 'd-day-calculator']]),

    'prompt-formatter' => $tool('prompt-formatter', 'Prompt Formatter', 'ai', 'AI 프롬프트를 구조화하고 읽기 좋게 정리합니다.', ['widget' => 'prompt-formatter', 'is_recent' => true, 'related' => ['prompt-optimizer', 'token-counter']]),
    'prompt-optimizer' => $tool('prompt-optimizer', 'Prompt Optimizer', 'ai', '목표와 조건에 맞게 AI 프롬프트를 개선합니다.', ['widget' => 'prompt-optimizer', 'related' => ['prompt-formatter', 'token-counter']]),
    'token-counter' => $tool('token-counter', 'Token Counter', 'ai', 'AI 입력 텍스트의 토큰 사용량을 추정합니다.', ['widget' => 'token-counter', 'is_popular' => true, 'related' => ['prompt-formatter', 'word-counter']]),

    'url-encode' => $tool('url-encode', 'URL Encode / Decode', 'encoding', 'URL 파라미터와 쿼리 문자열을 인코딩하거나 원래 텍스트로 디코딩합니다.', [
        'widget' => 'url-encode',
        'is_frequent' => true,
        'related' => ['base64', 'jwt-decoder'],
    ]),
    'qr-code' => $tool('qr-code', 'QR Code Generator', 'converter', 'URL과 텍스트를 QR 코드 이미지로 만들고 PNG로 내려받습니다.', [
        'widget' => 'qr-code',
        'is_popular' => true,
        'related' => ['url-encode', 'image-to-base64'],
    ]),
    'color-converter' => $tool('color-converter', 'Color Converter', 'color', 'HEX 색상 값을 RGB와 HSL로 변환하고 색상 미리보기를 제공합니다.', [
        'widget' => 'color-converter',
        'is_frequent' => true,
        'related' => ['image-to-base64', 'webp-converter'],
    ]),
];
