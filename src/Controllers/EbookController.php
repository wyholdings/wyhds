<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\EbookModel;

class EbookController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    //문의 목록
    public function list()
    {   
        $ebookModel = new EbookModel();
        $ebooks = $ebookModel->getAll();

        echo $this->twig->render('admin/ebook/list.html.twig', [
            'ebooks' => $ebooks
        ]);
    }

    public function upload()
    {
        error_reporting(E_ALL);
        ini_set("display_errors", 1);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // 업로드된 파일 처리
            $pdfFile = $_FILES['pdf']['tmp_name'];
            $originalFileName = $_FILES['pdf']['name'];
            $ebookId = uniqid('ebook_');
            $uploadBase = __DIR__ . '/../../public/ebooks/';
            $outputDir = $uploadBase . $ebookId . '/';

            if (!is_dir($uploadBase)) {
                mkdir($uploadBase, 0777, true);
            }
            mkdir($outputDir, 0777, true);

            // PDF → PNG 변환
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($pdfFile);

            foreach ($imagick as $i => $page) {
                $page->setImageFormat('png');
                $page->writeImage($outputDir . ($i + 1) . '.png');
            }
            $imagick->clear();
            $imagick->destroy();

            // index.html 생성
            // 예: $outputDir = '/ebook_path'; PNG가 이미 존재한다는 전제

            $files = glob($outputDir . '/*.png');
            $totalPages = count($files);

            // index.html 생성
            $html = "<!DOCTYPE html>
            <html lang='ko'>
            <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0, user-scalable=no'>
            <title>eBook</title>
            <style>
                * { box-sizing: border-box; }
                body {
                margin: 0;
                background: #f9f9f9;
                overflow: hidden;
                font-family: sans-serif;
                }
                #viewer {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 85vh;
                transition: all 0.4s ease;
                }
                .page {
                width: 45%;
                max-width: 800px;
                height: auto;
                max-height: 90vh;
                margin: 0 1%;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                transition: transform 0.3s ease;
                }
                .single {
                width: 80%;
                max-width: 800px;
                }
                #controls {
                position: fixed;
                bottom: 10px;
                width: 100%;
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
                z-index: 1000;
                }
                button {
                font-size: 1rem;
                padding: 10px 15px;
                background: #fff;
                border: 1px solid #ccc;
                cursor: pointer;
                border-radius: 5px;
                }
                #page-info {
                margin-top: 5px;
                font-size: 0.9rem;
                color: #333;
                }
                @media (max-width: 768px) {
                .page {
                    width: 90%;
                }
                }
            </style>
            </head>
            <body>
            <div id='viewer'></div>

            <div id='controls'>
                <button onclick='goToFirst()'>⏮ 처음</button>
                <button onclick='go(-1)'>◀ 이전</button>
                <div id='page-info'></div>
                <button onclick='go(1)'>다음 ▶</button>
                <button onclick='goToLast()'>끝 ⏭</button>
            </div>

            <script>
                let page = 1;
                const total = {$totalPages};

                function render() {
                const viewer = document.getElementById('viewer');
                const info = document.getElementById('page-info');
                viewer.innerHTML = '';

                if (page === 1) {
                    const img = document.createElement('img');
                    img.src = '1.png';
                    img.className = 'page single';
                    viewer.appendChild(img);
                    info.innerText = '표지';
                } else {
                    const left = document.createElement('img');
                    left.src = page + '.png';
                    left.className = 'page';
                    viewer.appendChild(left);

                    const rightPage = page + 1;
                    if (rightPage <= total) {
                    const right = document.createElement('img');
                    right.src = rightPage + '.png';
                    right.className = 'page';
                    viewer.appendChild(right);
                    info.innerText = `\${page}-\${rightPage}`;
                    } else {
                    info.innerText = `\${page}`;
                    }
                }
                }

                function go(n) {
                if (page === 1 && n === -1) return;

                if (page === 1 && n === 1) {
                    if (total >= 2) page = 2;
                } else {
                    const next = page + n * 2;

                    if (next > total) return;
                    if (next < 1) page = 1;
                    else page = next;
                }
                render();
                }

                function goToFirst() {
                page = 1;
                render();
                }

                function goToLast() {
                if (total % 2 === 0) {
                    page = total - 1; // 예: 10 → 9-10 페이지
                } else {
                    page = total;     // 예: 9 → 9 페이지만 표시
                }
                render();
                }

                // 마우스 휠
                let scrollDebounce = false;
                window.addEventListener('wheel', function(e) {
                if (scrollDebounce) return;
                scrollDebounce = true;
                setTimeout(() => scrollDebounce = false, 400);

                if (e.deltaY > 0) go(1);
                else go(-1);
                });

                // 터치 스와이프
                let touchStartX = 0;
                window.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
                });
                window.addEventListener('touchend', e => {
                const dx = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(dx) > 50) {
                    if (dx < 0) go(1);
                    else go(-1);
                }
                });

                window.onload = render;
            </script>
            </body>
            </html>";

            file_put_contents($outputDir . 'index.html', $html);

            // ZIP 압축
            $zipPath = $uploadBase . $ebookId . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
                $files = scandir($outputDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $zip->addFile($outputDir . $file, $ebookId . '/' . $file);
                    }
                }
                $zip->close();
            }

            // DB에 저장
            $data = [
                'file_name' => $originalFileName,
                'folder_name' => $ebookId
            ];

            $ebookModel = new EbookModel();
            $ebooks = $ebookModel->upload($data);
            
            // ZIP 파일 다운로드
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"{$ebookId}.zip\"");
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);

            // (선택) 임시 파일 정리
            // unlink($zipPath);
            // array_map('unlink', glob("$outputDir/*.*")); rmdir($outputDir);
            exit;
        }

        echo $this->twig->render('admin/ebook/list.html.twig', [

        ]);
    }

}
