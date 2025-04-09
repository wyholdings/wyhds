<?php

/**
 * 지정한 뷰 파일의 전체 경로를 반환합니다.
 *
 * @param string $view 뷰 파일 이름 (확장자 없이)
 * @return string 전체 파일 경로
 */
function view_path(string $view): string
{
    return __DIR__ . '/../views/' . $view . '.php';
}

/**
 * 지정한 뷰 파일을 포함(require)하며, 데이터 배열을 추출해 전달합니다.
 *
 * @param string $view 뷰 파일 이름 (확장자 없이)
 * @param array $data 뷰에서 사용할 데이터 (연관 배열)
 * @throws Exception 뷰 파일이 존재하지 않을 경우 예외 발생
 * @return void
 */
function view_path_require(string $view, array $data = []): void
{
    $path = view_path($view);

    if (!file_exists($path)) {
        throw new Exception("View file not found: {$path}");
    }

    extract($data); // $data 배열을 변수로 변환
    require $path;
}

?>