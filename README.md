# Simple Video Learning

Rhymix 기반의 수업·주차별 영상 학습 모듈입니다. 강의 업로드, 교수·조교 역할 관리, 수강신청, 회원별 영상 시청 진도 및 완료 판정을 제공합니다.

- **모듈 ID:** `simple_video_learning`
- **설치 경로:** `modules/simple_video_learning/`
- **Rhymix:** 2.1.3 이상
- **PHP:** 7.4 이상
- **버전:** 0.5.10 
- **라이선스:** GPL-2.0-or-later

## 설치 및 시작

1. 모듈 파일을 Rhymix 설치 루트의 `modules/simple_video_learning/`에 배치합니다.
2. Rhymix 관리자에서 모듈 설치/업데이트를 실행합니다.
3. **사이트 메뉴 편집**에서 **Simple Video Learning** 메뉴 및 MID를 만듭니다.
4. **Simple Video Learning → 수업 관리 → 새 수업**에서 수업 코드(예: `AI101`), 수업명, 주차 수, 수강 방식을 등록합니다.
5. **API · 기본 설정**에서 토큰과 이러닝 메뉴 MID를 확인합니다. 학습자 화면은 해당 MID로 접근합니다.


## 주요 기능

- 수업별 자동 주차 생성: `1`부터 `num_sections`까지
- 교수/조교 수업 운영, 수강 승인·취소·관리
- 영상 직접 업로드 및 Native JSON / Moodle 호환 API 강의 등록
- 로그인 회원별 시청 구간·진도·완료 상태 추적
- 전역 API 토큰 및 수업 범위로 제한되는 수업별 API 토큰
- `external_id` 기반 강의 갱신(idempotent upsert)

## API: 수업과 강의 등록

**중요:** 현재 Native JSON API는 *기존 수업에 강의를 등록하거나 갱신*합니다. 수업 자체를 생성하는 Native API는 없으므로 **수업은 먼저 관리자 화면에서 생성해야 합니다.**

### 인증 및 엔드포인트

```http
POST /?module=simple_video_learning&act=procSimple_video_learningApiUpsertLecture
Authorization: Bearer YOUR_TOKEN
Content-Type: application/json
```

서버 주소 앞에는 실제 HTTPS 도메인을 붙입니다. 전역 토큰은 관리자 **API · 기본 설정**에서 최초 발급 또는 재생성 직후 한 번만 표시하며 서버에는 해시만 저장합니다. 기존 토큰은 모듈 업데이트 시 해시로 이관하고 인증 값은 유지합니다. 수업별 토큰은 수업 운영 메뉴에서 발급하며 다른 수업에 사용할 수 없습니다.

### Native JSON 강의 등록 예시 — 3주차

```bash
curl -X POST 'https://YOUR-DOMAIN/?module=simple_video_learning&act=procSimple_video_learningApiUpsertLecture' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "external_id": "cloud-lecture-03",
    "course_code": "AI101",
    "sectionnum": 3,
    "sort_order": 1,
    "title": "클라우드 개요",
    "content": "3주차 강의입니다.",
    "video_url": "https://cdn.example.com/cloud-week3.mp4",
    "duration": 900,
    "completion_threshold": 80
  }'
```

| 필드 | 설명 |
|---|---|
| `external_id` | 외부 강의 고유 ID. 같은 값으로 다시 호출하면 기존 강의 갱신 |
| `course_code` 또는 `course_srl` | 기존 수업 식별자(전역 토큰 사용 시 필요) |
| **`sectionnum`** | **주차 번호. 예: `3` → 3주차. 미지정 시 1주차** |
| `sort_order` | 같은 주차 안의 순서 |
| `title` | 강의 제목 |
| `video_url` | HTTP(S) 영상 주소. Native API는 영상 파일 바이너리를 직접 업로드하는 API가 아닙니다 |
| `duration` | 영상 길이(초) |
| `completion_threshold` | 완료 판정 비율(%) |

필수 필드: `external_id`, `title`, `video_url`, 수업 식별자(단, 수업 전용 토큰이면 수업 식별자 생략 가능). `sectionnum`은 1~99의 정수이며, 현재 주차 수보다 크면 수업의 주차 수가 해당 값까지 확장됩니다.

**외부 API의 주차 파라미터 표준은 `sectionnum`입니다.** 기존 클라이언트를 위해 입력 시 `week_no`도 지원하지만 신규 연동에는 `sectionnum`을 사용하세요. 내부 DB 컬럼 이름 `week_no`는 별개이며 유지됩니다.

### 성공 응답 예시

```json
{
  "success": true,
  "external_id": "cloud-lecture-03",
  "platform_id": 12345,
  "document_srl": 12345,
  "url": "https://YOUR-DOMAIN/...",
  "created": true
}
```

등록된 강의 조회: `GET /?module=simple_video_learning&act=dispSimple_video_learningApiLecture&external_id=cloud-lecture-03`에 동일한 Bearer 토큰을 보냅니다. 조회 응답의 주차 필드는 `sectionnum`입니다.

## Moodle 호환 API
일부 무들과 호환되는 API 입니다, 크게는 정보 출력, 업로드가 있습니다.

```text
Moodle 기본 URL: https://YOUR-DOMAIN/modules/simple_video_learning/compat
REST: https://YOUR-DOMAIN/modules/simple_video_learning/compat/webservice/rest/server.php
파일 업로드: https://YOUR-DOMAIN/modules/simple_video_learning/compat/webservice/upload.php
```

- `core_enrol_get_users_courses` — 수업 조회
- `core_course_get_contents` — 수업 주차 및 활동 조회
- `mod_simplevideotracker_create_activity` / `mod_videotracker_create_activity` — 강의 생성
- `mod_simplevideotracker_set_video` / `mod_videotracker_set_video` — 영상 연결

**Moodle 호환 클라이언트는 `courseid`, `sectionnum`을 전달합니다.** 예를 들어 `sectionnum=3`이면 3주차입니다. 호환 API는 레거시 파라미터 `week_no`, `week`, `section`도 수용합니다. 주차를 지정하지 않으면 기본값은 1입니다. 수업 주차 수보다 큰 값은 주차 수를 자동 확장합니다.

외부 연동 인증에는 모듈의 API 토큰을 사용합니다. 실제 Moodle 서버는 필요하지 않습니다.

Moodle 임시 업로드는 웹 루트 밖의 시스템 임시 디렉터리에 저장합니다. 업데이트 전에 생성한 기존 임시 업로드는 다시 업로드해야 합니다. 이전 `files/cache/simple_video_learning_compat/`의 임시 파일은 운영자가 제거하세요.

새로 업로드하는 영상의 플레이어 URL은 라이믹스 파일 다운로드 경로를 사용하며 모듈의 수강 권한 검사를 적용합니다. 기존·신규 영상 모두 원본 파일의 정적 URL로 접근하면 이 검사를 거치지 않습니다. 수강 제한이 필요한 운영 환경에서는 Apache/Nginx의 직접 파일 접근을 차단해야 합니다. 외부 `video_url`은 해당 저장소/CDN의 접근 정책을 별도로 설정하세요.

## 관리자 화면

- **API · 기본 설정:** 전역 토큰, 대상 MID, 영상 업로드 제한, 기본 수료 기준
- **수업 관리:** 수업 목록, 새 수업 생성, 상세 관리, 강의 배정
- **수강생 · 진도:** 수업별 수강생과 강의별 시청·완료 기록
- **API 가이드:** 강의 등록 요청 예제 및 오류 설명

수강 방식은 오픈 수업, 자동 승인, 승인 필요, 닫힘(수동 등록)을 지원합니다.
