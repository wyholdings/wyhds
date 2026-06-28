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
    'personal-info-masker' => $tool('personal-info-masker', '개인정보 마스킹 도구', 'text', '전화번호, 이메일, 주민등록번호 패턴을 찾아 일부를 마스킹합니다.', [
        'widget' => 'personal-info-masker',
        'is_recent' => true,
        'keywords' => ['개인정보 마스킹', '전화번호 마스킹', '이메일 마스킹', '주민번호 마스킹'],
        'related' => ['word-counter', 'regex-tester', 'text-diff'],
    ]),
    'random-picker' => $tool('random-picker', '랜덤 추첨기', 'text', '이름이나 항목 목록에서 무작위 당첨자를 추첨합니다.', [
        'widget' => 'random-picker',
        'is_popular' => true,
        'is_recent' => true,
        'keywords' => ['랜덤 추첨기', '이름 추첨기', '당첨자 뽑기'],
        'related' => ['list-shuffler', 'remove-duplicate-lines'],
    ]),
    'list-shuffler' => $tool('list-shuffler', '목록 셔플 도구', 'text', '줄 단위 목록을 무작위로 섞거나 번호를 붙여 정리합니다.', [
        'widget' => 'list-shuffler',
        'is_frequent' => true,
        'keywords' => ['목록 섞기', '랜덤 순서', '리스트 셔플'],
        'related' => ['random-picker', 'remove-duplicate-lines'],
    ]),
    'checklist-generator' => $tool('checklist-generator', '체크리스트 생성기', 'text', '줄 단위 메모를 Markdown 체크리스트로 변환합니다.', [
        'widget' => 'checklist-generator',
        'keywords' => ['체크리스트 생성기', 'Markdown 체크리스트', '할일 목록 변환'],
        'related' => ['note-cleaner', 'case-converter'],
    ]),
    'csv-sort-filter' => $tool('csv-sort-filter', 'CSV 정렬/필터 도구', 'text', 'CSV 데이터를 컬럼 기준으로 필터링하고 정렬합니다.', [
        'widget' => 'csv-sort-filter',
        'is_recent' => true,
        'keywords' => ['CSV 정렬', 'CSV 필터', 'CSV 데이터 정리'],
        'related' => ['csv-viewer', 'json-csv'],
    ]),
    'excel-table-converter' => $tool('excel-table-converter', '엑셀 표 변환기', 'text', '엑셀에서 복사한 탭 구분 표를 Markdown, HTML, CSV로 변환합니다.', [
        'widget' => 'excel-table-converter',
        'keywords' => ['엑셀 표 변환', 'Markdown 표 변환', 'HTML 테이블 변환'],
        'related' => ['csv-viewer', 'csv-sort-filter'],
    ]),
    'note-cleaner' => $tool('note-cleaner', '메모 정리기', 'text', '흩어진 메모의 공백, 빈 줄, 중복 줄을 정리합니다.', [
        'widget' => 'note-cleaner',
        'is_frequent' => true,
        'keywords' => ['메모 정리', '텍스트 정리', '공백 제거'],
        'related' => ['checklist-generator', 'remove-duplicate-lines'],
    ]),

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
    'withholding-3-3-calculator' => $tool('withholding-3-3-calculator', '3.3% 계산기', 'calculator', '프리랜서·사업소득 3.3% 원천징수액과 실수령액을 계산합니다.', [
        'widget' => 'withholding-3-3',
        'is_popular' => true,
        'is_recent' => true,
        'is_frequent' => true,
        'keywords' => ['3.3% 계산기', '프리랜서 세금 계산기', '원천징수 계산기', '실수령액 계산기'],
        'related' => ['vat-calculator', 'percent-calculator', 'salary-calculator'],
        'how_to_use' => ['계약금액 또는 실수령액을 입력합니다.', '계산 방향을 선택합니다.', '원천징수세액과 지급액을 확인합니다.'],
        'faqs' => [['q' => '3.3%는 무엇인가요?', 'a' => '일반적으로 사업소득 원천징수 3%와 지방소득세 0.3%를 합친 비율입니다.'], ['q' => '정확한 세무 신고 금액인가요?', 'a' => '간편 계산용이며 실제 신고·정산은 계약 유형과 세무 기준에 따라 달라질 수 있습니다.']],
    ]),
    'salary-calculator' => $tool('salary-calculator', '시급·월급 환산기', 'calculator', '시급, 근무시간, 근무일 기준으로 월급과 연봉을 빠르게 환산합니다.', [
        'widget' => 'salary-calculator',
        'is_recent' => true,
        'keywords' => ['시급 계산기', '월급 계산기', '연봉 계산기', '급여 환산기'],
        'related' => ['withholding-3-3-calculator', 'percent-calculator'],
    ]),
    'annual-salary-net-calculator' => $tool('annual-salary-net-calculator', '연봉 실수령액 계산기', 'calculator', '연봉 기준 월 예상 실수령액과 공제액을 계산합니다.', [
        'widget' => 'annual-salary-net',
        'is_popular' => true,
        'is_recent' => true,
        'keywords' => ['연봉 실수령액 계산기', '월급 실수령액', '4대보험 계산기'],
        'related' => ['salary-calculator', 'withholding-3-3-calculator', 'weekly-holiday-pay-calculator'],
    ]),
    'weekly-holiday-pay-calculator' => $tool('weekly-holiday-pay-calculator', '주휴수당 계산기', 'calculator', '시급과 주 근무시간으로 주휴수당과 주급을 계산합니다.', [
        'widget' => 'weekly-holiday-pay',
        'is_frequent' => true,
        'keywords' => ['주휴수당 계산기', '알바 주휴수당', '시급 주급 계산기'],
        'related' => ['salary-calculator', 'annual-salary-net-calculator'],
    ]),
    'severance-pay-calculator' => $tool('severance-pay-calculator', '퇴직금 계산기', 'calculator', '평균임금과 재직기간으로 예상 퇴직금을 계산합니다.', [
        'widget' => 'severance-pay',
        'keywords' => ['퇴직금 계산기', '퇴직급여 계산기', '평균임금 계산'],
        'related' => ['annual-salary-net-calculator', 'salary-calculator'],
    ]),
    'margin-calculator' => $tool('margin-calculator', '마진율 계산기', 'calculator', '원가, 판매가, 수수료를 기준으로 마진과 마진율을 계산합니다.', [
        'widget' => 'margin-calculator',
        'is_popular' => true,
        'keywords' => ['마진율 계산기', '원가 계산기', '판매가 계산기', '쇼핑몰 마진 계산'],
        'related' => ['percent-calculator', 'vat-calculator'],
    ]),
    'pyeong-calculator' => $tool('pyeong-calculator', '평수 계산기', 'calculator', '제곱미터와 평을 서로 변환하고 면적을 계산합니다.', [
        'widget' => 'pyeong-calculator',
        'is_frequent' => true,
        'keywords' => ['평수 계산기', '제곱미터 평 변환', '아파트 평수 계산'],
        'related' => ['unit-converter', 'date-calculator'],
    ]),
    'loan-calculator' => $tool('loan-calculator', '대출 이자 계산기', 'calculator', '원금, 금리, 기간으로 월 상환액과 총 이자를 계산합니다.', [
        'widget' => 'loan-calculator',
        'is_popular' => true,
        'keywords' => ['대출 이자 계산기', '월 상환액 계산기', '원리금균등 계산기'],
        'related' => ['compound-interest-calculator', 'percent-calculator'],
    ]),
    'compound-interest-calculator' => $tool('compound-interest-calculator', '복리 계산기', 'calculator', '초기 금액, 추가 납입, 수익률 기준으로 미래 가치를 계산합니다.', [
        'widget' => 'compound-interest',
        'keywords' => ['복리 계산기', '투자 수익률 계산기', '미래가치 계산기'],
        'related' => ['loan-calculator', 'percent-calculator'],
    ]),
    'split-bill-calculator' => $tool('split-bill-calculator', '더치페이 계산기', 'calculator', '총액, 인원, 팁 또는 추가 비용을 기준으로 1인 부담액을 계산합니다.', [
        'widget' => 'split-bill',
        'is_frequent' => true,
        'keywords' => ['더치페이 계산기', 'N분의1 계산기', '회식비 계산기'],
        'related' => ['percent-calculator', 'vat-calculator'],
    ]),
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
    'utm-url-builder' => $tool('utm-url-builder', 'UTM URL 생성기', 'network', '캠페인 추적에 사용할 UTM 파라미터 URL을 생성합니다.', [
        'widget' => 'utm-builder',
        'is_recent' => true,
        'keywords' => ['UTM URL 생성기', '캠페인 URL 빌더', '마케팅 URL 생성'],
        'related' => ['url-encode', 'qr-code'],
    ]),
    'qr-code' => $tool('qr-code', 'QR Code Generator', 'converter', 'URL과 텍스트를 QR 코드 이미지로 만들고 PNG로 내려받습니다.', [
        'widget' => 'qr-code',
        'is_popular' => true,
        'related' => ['url-encode', 'image-to-base64'],
    ]),
    'unit-converter' => $tool('unit-converter', 'Unit Converter', 'converter', '길이, 무게, 면적 단위를 빠르게 변환합니다.', [
        'widget' => 'unit-converter',
        'is_recent' => true,
        'keywords' => ['단위 변환기', '길이 변환', '무게 변환', '평 제곱미터 변환'],
        'related' => ['percent-calculator', 'date-calculator'],
    ]),
    'color-converter' => $tool('color-converter', 'Color Converter', 'color', 'HEX 색상 값을 RGB와 HSL로 변환하고 색상 미리보기를 제공합니다.', [
        'widget' => 'color-converter',
        'is_frequent' => true,
        'related' => ['image-to-base64', 'webp-converter'],
    ]),
];
