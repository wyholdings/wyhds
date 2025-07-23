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
            $html = "<!DOCTYPE html>
            <html lang='ko'>
            <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>eBook</title>
            <style>
                body {
                margin: 0;
                background: #f4f4f4;
                overflow: hidden;
                }
                #viewer {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                transition: all 0.4s ease;
                }
                .page {
                max-width: 48%;
                width: 48%;
                height: auto;
                margin: 0 1%;
                box-shadow: 0 0 10px rgba(0,0,0,0.2);
                transition: transform 0.3s ease;
                }
                .single {
                width: 90%;
                max-width: 600px;
                }
                button {
                position: fixed;
                top: 50%;
                transform: translateY(-50%);
                font-size: 2rem;
                background: rgba(255,255,255,0.8);
                border: 1px solid #ccc;
                cursor: pointer;
                z-index: 1000;
                padding: 10px 15px;
                }
                .prev-btn { left: 10px; }
                .next-btn { right: 10px; }
                @media (max-width: 768px) {
                .page {
                    width: 90%;
                    margin: 0 5%;
                }
                }
            </style>
            </head>
            <body>
            <button class='prev-btn' onclick='go(-1)'>◀</button>
            <div id='viewer'></div>
            <button class='next-btn' onclick='go(1)'>▶</button>

            <script>
                let page = 1;
                const total = {$i}; // 전체 이미지 수

                function render() {
                const viewer = document.getElementById('viewer');
                viewer.innerHTML = '';

                if (page === 1) {
                    const img = document.createElement('img');
                    img.src = '1.png';
                    img.className = 'page single';
                    viewer.appendChild(img);
                } else {
                    const left = document.createElement('img');
                    const right = document.createElement('img');

                    left.src = page + '.png';
                    left.className = 'page';

                    if (page + 1 <= total) {
                    right.src = (page + 1) + '.png';
                    right.className = 'page';
                    viewer.appendChild(left);
                    viewer.appendChild(right);
                    } else {
                    viewer.appendChild(left);
                    }
                }
                }

                function go(n) {
                if (page === 1 && n === -1) return;
                if (page === 1 && n === 1) {
                    if (page + 1 <= total) page = 2;
                } else {
                    const newPage = page + n * 2;
                    if (newPage >= 2 && newPage <= total) {
                    page = newPage;
                    }
                }
                render();
                }

                // 마우스 휠로 페이지 넘김
                let scrollDebounce = false;
                window.addEventListener('wheel', function(e) {
                if (scrollDebounce) return;
                scrollDebounce = true;
                setTimeout(() => scrollDebounce = false, 400);

                if (e.deltaY > 0) {
                    go(1); // down scroll → 다음 페이지
                } else {
                    go(-1); // up scroll → 이전 페이지
                }
                });

                // 모바일 터치 스와이프 대응
                let touchStartX = 0;
                window.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
                });

                window.addEventListener('touchend', e => {
                const diff = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(diff) > 50) {
                    if (diff < 0) go(1); // 왼쪽으로 스와이프 → 다음
                    else go(-1);         // 오른쪽으로 스와이프 → 이전
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
