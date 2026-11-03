# 썸네일 미리보기

- 최종 이미지: `bedroom-cover.png`
- 글자를 제거한 배경: `bedroom-background.png`
- 첨부된 침실 썸네일을 내장 image_gen 도구로 편집해 기존 글자만 제거했습니다. 아래 프롬프트를 사용했습니다. 실제 쇼츠 파이프라인에는 이미지 생성 도구를 사용하지 않습니다.
- 최종 제목과 디자인은 `ShortsThumbnailService`의 GD 코드로 렌더링했습니다. 코드 변경 시 같은 배경으로 재현할 수 있습니다.

```bash
php scripts/preview_shorts_thumbnail.php output/shorts-thumbnail/bedroom-background.png output/shorts-thumbnail/bedroom-cover.png '침대 속 진드기' '매일 쓰는 침대, 알고 계셨나요?'
```

## 배경 편집 최종 프롬프트

Use case: precise-object-edit. Input image 1 is the edit target: the user's portrait thumbnail showing a bedroom, night sky and moon with the Korean title 침대 속 진드기 across the upper middle. Remove ALL existing Korean lettering and its black shadow from this image, reconstructing the sky and clouds seamlessly behind it. Preserve the exact bedroom, bed, moon, sky, night lighting, original composition and portrait 9:16 dimensions. This is a clean background plate for deterministic thumbnail typography that will be added by software later. Do not add any text, shapes, labels, logos, borders, or new objects. Change only the area occupied by the old letters.
