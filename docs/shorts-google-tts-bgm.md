# Google 한국어 TTS와 배경음악 설정

## Google 키 발급

1. [Google Cloud 콘솔](https://console.cloud.google.com/)에 로그인하고 프로젝트를 선택하거나 생성합니다.
2. 프로젝트에 결제 계정을 연결합니다.
3. **API 및 서비스 → 라이브러리 → Cloud Text-to-Speech API**를 사용 설정합니다.
4. **API 및 서비스 → 사용자 인증 정보 → 사용자 인증 정보 만들기 → API 키**에서 키를 발급합니다. 이 구현은 Google Cloud Text-to-Speech용 키를 사용합니다. Gemini AI Studio의 TTS API 구현과는 별개입니다.
5. 키의 API 제한을 **Cloud Text-to-Speech API**로 지정합니다. 서버 IP 제한을 설정한다면 브라우저 주소가 아닌 운영 서버의 외부 통신 IP를 사용합니다.
6. 운영 서버 `/var/www/html/wyhds/.env`에 추가합니다.

```dotenv
SHORTS_TTS_PROVIDER=google
GOOGLE_TTS_API_KEY=발급받은키
GOOGLE_TTS_VOICE=ko-KR-Chirp3-HD-Kore
```

현재 기본값은 Google / Kore입니다. 스튜디오에서 Kore·Aoede 여성 음성과 Charon·Fenrir 남성 음성을 선택하고, 말하기 속도도 조정할 수 있습니다. 보이스별 자연스러움은 직접 들어보고 선택하세요. Google에는 OpenAI용 말투 지시를 보내지 않습니다.

Chirp 3 HD는 2026-11-03 확인 기준 월 100만 자 무료 구간이 있습니다. 결제 연결이 필요하며 한도를 초과하면 과금됩니다. 다른 앱에서 사용하는 양도 같은 프로젝트의 사용량에 영향을 줍니다. 이 앱이 Google의 무료 잔여 한도를 조회해 월간 지출을 자동 차단하는 것은 아닙니다. 대본 생성에는 기존 OpenAI API 크레딧이 사용됩니다.

공식 근거: [요금](https://cloud.google.com/text-to-speech/pricing), [시작 가이드](https://docs.cloud.google.com/text-to-speech/docs/get-started), [한국어 보이스 목록](https://docs.cloud.google.com/text-to-speech/docs/list-voices-and-types), [API 키 관리](https://docs.cloud.google.com/docs/authentication/api-keys).

## 배포와 확인

변경된 서비스·컨트롤러·템플릿·스크립트와 함께 **`public/assets/bgm/shorts-ambient.m4a`**도 배포합니다. 새 Composer 패키지나 DB 변경은 없습니다.

```bash
cd /var/www/html/wyhds
sudo -u www-data php scripts/check_google_tts.php
sudo -u www-data php scripts/check_shorts.php
```

`www-data`는 실제 PHP-FPM/Apache 실행 계정으로 변경합니다. 첫 명령은 Google 보이스 목록으로 키와 음성 사용 가능 여부를 확인하며 음성을 생성하지 않습니다. 두 번째 명령은 로컬 환경을 검사합니다. 실제 생성에서도 Google 보이스 확인을 대본 생성 전에 실행합니다. Google 오류가 나더라도 OpenAI 음성으로 자동 전환하지 않습니다.

키 설정 후 스튜디오에서 **Google / Kore / 보통 1배 / 30초**로 먼저 생성해 보세요. 결과에 내레이션 원본과 브금이 포함된 오디오를 각각 들을 수 있습니다. 실제 Google 합성 품질은 키 설정 후 확인해야 합니다.

## 배경음악

스튜디오에서 다음을 선택할 수 있습니다.

- **기본 음악**: 읽을 수 있는 `SHORTS_BGM_PATH` 파일이 있으면 우선 적용하고, 없으면 제공된 32초 앰비언트를 사용합니다. 제공 음악은 외부 녹음 없이 이 프로젝트에서 합성했습니다.
- **내 음악 업로드**: MP3, WAV, M4A, OGG, FLAC 중 20MB 이하 파일을 선택합니다. PHP의 `upload_max_filesize`와 `post_max_size`도 허용 범위 안이어야 합니다. 업로드 후 서버가 실제 오디오 스트림을 검사합니다. 새 요청을 보낼 때 파일을 다시 선택해야 합니다.
- **음악 없음**: 내레이션만 사용합니다.

음악 크기는 0~30%, 기본값 12%입니다. 음악을 반복·자르기하고 시작·끝을 페이드합니다. 내레이션을 정규화한 뒤 음악과 합치므로 말소리 크기를 유지하며, 발화 중에는 음악을 추가로 낮춥니다. 사용 권한이 있는 음악을 업로드하세요.

서버 파일을 기본 음악으로 사용하려면 다음과 같이 지정합니다. 기존 경로를 사용해도 됩니다.

```dotenv
SHORTS_BGM_PATH=/var/www/html/wyhds/public/assets/bgm/my-bgm.mp3
```

## 끝 화면과 타이밍

고정된 ‘시청해주셔서 감사합니다’ 화면과 뒤의 0.8초 정지 구간을 삭제했습니다. 영상은 내레이션 끝에서 종료합니다. 기존 생성 영상은 그대로 보관하며 새 생성부터 적용됩니다. 문단별 Google WAV의 실제 길이로 장면과 자막 시간을 계산합니다. 문단 안의 단어별 강제 정렬 기능은 아닙니다.

## 검증

```bash
php tests/google_tts_regression.php
php tests/shorts_refresh_regression.php
php tests/shorts_audio_integration.php /usr/bin/ffmpeg
php tests/shorts_footage_integration.php /usr/bin/ffmpeg /usr/bin/ffprobe
```

이 테스트들은 외부 API를 호출하지 않습니다. Google 요청은 가짜 응답으로 검증하고, 오디오 테스트는 합성 신호를 실제 FFmpeg로 처리해 음성 타이밍·음악 반복·페이드·말소리 크기·자동 음악 감쇠를 측정합니다.
