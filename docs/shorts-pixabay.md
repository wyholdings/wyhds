# Pixabay 쇼츠 운영 설정

## API 키 발급

1. https://pixabay.com/ 에 가입하고 로그인합니다.
2. https://pixabay.com/api/docs/ 의 **Search Videos → key** 항목에서 본인 API 키를 확인합니다.
3. Linux 운영 서버 `/var/www/html/wyhds/.env`에 추가합니다.

```dotenv
PIXABAY_API_KEY=발급받은키
PIXABAY_CACHE_ROOT=/var/www/html/wyhds/var/cache/shorts/pixabay
```

Pixabay API는 무료입니다. 대본에는 OpenAI 크레딧을 사용하고 음성은 선택한 서비스의 요금이 적용됩니다. [Google 한국어 음성과 브금 설정](shorts-google-tts-bgm.md)을 참고하세요. 이 경로에서는 OpenAI 이미지 생성 요청을 보내지 않습니다.

## 배포와 권한

변경 파일을 배포하고 캐시 폴더를 생성합니다. `www-data`는 실제 PHP-FPM/Apache 실행 계정으로 바꿉니다. 기존 출력 폴더도 해당 계정이 쓸 수 있어야 합니다.

```bash
cd /var/www/html/wyhds
sudo install -d -o www-data -g www-data -m 2775 /var/www/html/wyhds/var/cache/shorts/pixabay
sudo -u www-data php scripts/check_shorts.php
```

새 Composer 패키지나 DB 마이그레이션은 필요하지 않습니다. PHP 7.4 문법을 사용합니다. 확인 스크립트는 유료 생성 요청이나 Pixabay 인증 요청을 보내지 않습니다. CLI와 웹의 PHP 설정이 다르면 웹 실행 환경도 확인해야 합니다.

## 사용

쇼츠 스튜디오에서 키워드를 입력하고 생성합니다. 영상 검색어를 비우면 대본 문단마다 짧은 영어 검색어를 만들어 Pixabay를 검색합니다. 원하는 소재가 정해져 있으면 영상 검색어에 `bedroom`, `bedding`처럼 간단한 영어를 입력할 수 있습니다.

세로 영상과 아직 사용하지 않은 영상을 우선 선택합니다. 가로 영상은 흐린 배경 위에 원래 화면을 배치합니다. 원본 소리는 제거하며, 영상이 짧으면 반복하고 길면 문단 길이에서 자릅니다. 결과 화면과 manifest에 사용한 Pixabay 영상의 제작자·원본 링크를 보관합니다. 검색 결과는 공식 API 요구사항대로 24시간 캐시합니다. 무인 대량 수집용 기능은 아닙니다.

관련 영상이 없으면 음성을 만들기 전에 중단합니다. 이 경우 대본 생성 비용은 이미 발생했을 수 있습니다. 관련된 더 넓은 영상 검색어로 **새 요청**을 만드세요. 새로고침과 동일 POST 재전송은 생성 작업을 재실행하지 않습니다.

새 썸네일은 영상에서 추출한 장면에 굵은 제목, 강조 색상, 어두운 그라데이션과 보조 문구를 배치합니다. 단어 단위 균형 배치와 폰트 실측으로 한 글자만 남는 줄바꿈과 겹침을 방지합니다. 기존 생성 기록은 다시 만들지 않으며 새로 생성한 결과에 적용됩니다.

## 오프라인 검증

```bash
php tests/shorts_regression.php
php tests/shorts_refresh_regression.php
php tests/shorts_pixabay_regression.php
php tests/pixabay_rate_regression.php
php tests/shorts_footage_integration.php /usr/bin/ffmpeg /usr/bin/ffprobe
```

썸네일만 미리 만들려면 다음을 실행합니다. GD/FreeType과 한글 폰트가 필요하며 API 비용은 없습니다.

```bash
php scripts/preview_shorts_thumbnail.php background.jpg cover.png '침대 속 진드기' '매일 쓰는 침대, 알고 계셨나요?'
```

공식 문서: https://pixabay.com/api/docs/ · 라이선스: https://pixabay.com/service/license-summary/

## 첫 요청부터 HTTP 429가 발생할 때

이전 버전은 모든 429를 요청 한도 초과로 표시했습니다. 429만으로 실제 API 사용량 제한인지, 서버 접속 제한인지 단정할 수 없습니다. 수정 버전은 API의 텍스트 오류와 HTML 응답을 구분하고 `Retry-After`, `X-RateLimit-Reset`을 읽습니다. 같은 키를 사용하는 이 배포본의 PHP 작업들이 대기 상태와 요청 수를 공유하며, 대기 중에는 캐시가 없는 검색을 다시 보내지 않습니다. 알려진 대기 상태는 유료 대본 생성 전에 확인합니다. 다른 서버나 다른 프로그램이 같은 키로 보내는 요청까지 이 로컬 제한이 통제하지는 않습니다.

운영 서버에서 PHP 웹 실행 계정으로 다음 명령을 실행합니다. 이 명령은 Pixabay 검색을 최대 한 번 보내고, 캐시나 재시도 대기 상태가 있으면 네트워크 요청을 생략합니다. OpenAI 호출·영상 다운로드는 하지 않습니다.

```bash
cd /var/www/html/wyhds
sudo -u www-data php scripts/check_pixabay.php bedroom
```

- `http_status: 200`, `kind: json_response`: 정상 API 응답입니다. `cache_hit: true`라면 저장된 결과이며 `last_response`는 이전 응답입니다.
- `kind: api_rate_limit`: API 한도 오류 문구가 확인됐습니다. `wait_seconds` 이후 다시 시도합니다.
- `kind: html_response`: API JSON 대신 HTML이 반환됐습니다. 서버 IP·호스팅 환경의 접속 제한 여부를 확인해야 합니다. 키나 요금제 문제로 단정하지 않습니다.
- `other_response`: 상태 코드만으로 원인이 확인되지 않았습니다.

진단 출력에는 키, 요청 URL, 원본 오류 본문을 넣지 않습니다. 키를 바꾸거나 제한을 우회하며 반복 요청하지 말고, 계속되는 접속 제한은 해당 진단 결과와 함께 호스팅 업체 또는 Pixabay 지원에 확인하세요.
