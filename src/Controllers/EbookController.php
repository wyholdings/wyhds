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

            $html = "<!DOCTYPE html>
            <html lang='ko' class='show-link-boxes'>
            <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0, user-scalable=yes'>
            <title>eBook</title>
            <style>
                body {
                margin: 0;
                background: #f4f4f4;
                overflow: hidden;
                font-family: sans-serif;
                display: flex;
                flex-direction: column;
                align-items: center;
                }

                #flipbook-wrapper {
                display: flex;
                justify-content: center;   /* 중앙 정렬 */
                align-items: center;
                width: 100vw;
                height: 90vh;
                background: #f4f4f4;
                }
                #flipbook {
                width: 90%;
                height: 90%;
                }
                
                #flipbook .page {
                background: white;
                box-shadow: 0 0 15px rgba(0,0,0,0.3);
                overflow: hidden;
                display: flex;
                justify-content: center;
                align-items: center;
                position: relative;
                }

                #flipbook .page img {
                height: 100%;
                object-fit: contain;
                }
                #controls {
                text-align: center;
                margin-top: 10px;
                }
                button {
                font-size: 1rem;
                padding: 8px 14px;
                margin: 0 5px;
                border: 1px solid #ccc;
                background: #fff;
                cursor: pointer;
                border-radius: 5px;
                }
                #page-info {
                margin-top: 10px;
                font-size: 0.9rem;
                }

                @media (max-width: 768px) {
                #flipbook {
                    width: 100vw !important;
                    height: 80vh !important;
                }

                #flipbook .page {
                    width: 100vw !important;
                    height: 80vh !important;
                }

                #flipbook .page img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                }
                }

                #flipbook .page-canvas{
                position: relative;
                width: 100%;
                height: 100%;
                display: flex;            /* 이미지 중앙정렬 유지 */
                align-items: center;
                justify-content: center;
                }
                #flipbook .page-canvas img{
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                }
                .link-area{
                position: absolute;
                display: block;
                z-index: 5;
                }
                /* 디버그용 (완료 후 제거) */
                html.show-link-boxes .link-area{
                background: rgba(255,0,0,.2);
                outline: 1px dashed red;
                }
            </style>
            <script>
                window.linkMap = {
                1: [
                    { href: 'https://conference.org', x: 0, y: 0, w: 420, h: 160, title: '학회 홈페이지' },
                ],
                2: [
                    { href: 'https://sponsor-a.com', x: 100,  y: 0, w: 520, h: 220, title: '스폰서 A' },
                    { href: 'https://sponsor-b.com', x: 0, y: 0, w: 520, h: 220, title: '스폰서 B' },
                ],
                };

                window.imageMeta = {
                default: { naturalWidth: 640, naturalHeight: 1017 },
                // 5: { naturalWidth: 2200, naturalHeight: 3300 }, // 페이지별로 다른 경우 예시
                };
            </script>
            <script src='https://code.jquery.com/jquery-3.6.0.min.js'></script>
            <script src='https://cdnjs.cloudflare.com/ajax/libs/turn.js/3/turn.min.js'></script>
            </head>
            <body>
            <div id='flipbook-wrapper'>
            <div id='flipbook'>";

            for ($p = 1; $p <= $totalPages; $p++) {
                if($p == 1){
                    $html .= "<div class='page'><img src='{$p}.png' style='max-width:100%; max-height:100%;' class='link_area_{$p}'></div>";
                }else{
                    $html .= "<div class='page'><img src='{$p}.png' style='max-height:100%;' class='link_area_{$p}'></div>";
                }
            }

            $html .= "</div></div>

            <div id='controls'>
                <button onclick='$(\"#flipbook\").turn(\"page\", 1)'>⏮</button>
                <button onclick='$(\"#flipbook\").turn(\"previous\")'>◀</button>
                <button onclick='$(\"#flipbook\").turn(\"next\")'>▶</button>
                <button onclick='$(\"#flipbook\").turn(\"page\", {$totalPages})'>⏭</button>
                <div id='page-info'></div>
            </div>

            <script>
            $('#flipbook .page').each(function(){
                if (!this.querySelector('.page-canvas')) {
                    const canvas = document.createElement('div');
                    canvas.className = 'page-canvas';
                    while (this.firstChild) canvas.appendChild(this.firstChild); // 자식들 이동
                    this.appendChild(canvas);
                }
            });

            function getVisiblePages(){
                const v = $('#flipbook').turn('view') || [];
                return v.filter(Boolean);
            }

            function applyLinks(){
                if (!window.linkMap) return;

                const \$pages = $('#flipbook .page');

                getVisiblePages().forEach((page)=>{
                    const areas = linkMap[page];
                    if (!areas || !areas.length) return;

                    const pageEl = \$pages.eq(page - 1)[0];
                    if (!pageEl) return;

                    const canvasEl = pageEl.querySelector('.page-canvas') || pageEl;
                    const img = canvasEl.querySelector('img');
                    if (!img) return;

                    const tryCalc = () => {
                    const cw = canvasEl.clientWidth;
                    const ch = canvasEl.clientHeight;

                    const meta = (window.imageMeta && (imageMeta[page] || imageMeta.default)) || {};
                    const iw = img.naturalWidth  || meta.naturalWidth  || 0;
                    const ih = img.naturalHeight || meta.naturalHeight || 0;

                    if (!cw || !ch || !iw || !ih) {
                        return requestAnimationFrame(tryCalc);
                    }

                    const scale = Math.min(cw / iw, ch / ih);
                    const renderW = iw * scale;
                    const renderH = ih * scale;
                    const offsetLeft = (cw - renderW) / 2;
                    const offsetTop  = (ch - renderH) / 2;

                    canvasEl.querySelectorAll('.link-area').forEach(a => a.remove());

                    areas.forEach(({ href, x, y, w, h, title }) => {
                        if ([href,x,y,w,h].some(v => v === undefined)) return;

                            const a = document.createElement('a');
                            a.className = 'link-area';
                            a.href = href;
                            a.target = '_blank';
                            a.rel = 'noopener';
                            if (title) a.setAttribute('aria-label', title);

                            const left   = offsetLeft + x * scale;
                            const top    = offsetTop  + y * scale;
                            const width  = w * scale;
                            const height = h * scale;

                            Object.assign(a.style, {
                            left: left + 'px',
                            top:  top  + 'px',
                            width:  width  + 'px',
                            height: height + 'px'
                            });

                            canvasEl.appendChild(a);
                        });
                    };

                    if (!img.complete || !img.naturalWidth) {
                        img.addEventListener('load', tryCalc, { once: true });
                    } else {
                        tryCalc();
                    }
                });
            }

            $(function () {
                const isMobile = window.innerWidth <= 768;

                function getFlipbookSize(){
                    const vw = window.innerWidth;
                    const vh = window.innerHeight;
                    return {
                        width:  isMobile ? vw : Math.round(vw * 0.5),
                        height: Math.round(vh * 0.8)
                    };
                }

                $('#flipbook .page').each(function(){
                    if (!this.querySelector('.page-canvas')) {
                        const canvas = document.createElement('div');
                        canvas.className = 'page-canvas';
                        while (this.firstChild) canvas.appendChild(this.firstChild);
                        this.appendChild(canvas);
                    }
                });

                const totalPages = $('#flipbook .page').length;
                const { width, height } = getFlipbookSize();

                $('#flipbook').turn({
                    width,
                    height,
                    autoCenter: true,
                    display: isMobile ? 'single' : 'double',
                    gradients: true,
                    elevation: 50,
                    pages: totalPages,
                    when: {
                        turning: function(){ /* 애니메이션 중에는 DOM 손대지 않음 */ },
                        turned: function (event, page, view) {
                            const info = document.getElementById('page-info');
                            const flipbook = document.getElementById('flipbook');

                            if (page == 1 || event == 'previous') {
                            flipbook.style.right = isMobile ? '' : '12%';
                            flipbook.style.left = '';
                            info.innerText = '';
                            } else {
                            flipbook.style.right = '';
                            flipbook.style.left = '';
                            }

                            if (view[0] && view[1]) {
                            info.innerText = `\${view[0]}-\${view[1]}`;
                            flipbook.classList.remove('single-page');
                            } else if (view[0]) {
                            info.innerText = `\${view[0]}`;
                            flipbook.classList.add('single-page');
                            flipbook.style.left = isMobile ? '' : '12%';
                            }

                            requestAnimationFrame(applyLinks);
                        }
                    }
                });

                requestAnimationFrame(applyLinks);

                const reflow = () => {
                    const { width, height } = getFlipbookSize();
                    $('#flipbook').turn('size', width, height);
                    setTimeout(() => requestAnimationFrame(applyLinks), 50);
                };
                window.addEventListener('resize', reflow);
                window.addEventListener('orientationchange', reflow);

                if (!isMobile) {
                    let scrollDebounce = false;
                    window.addEventListener('wheel', function (e) {
                    if (scrollDebounce) return;
                    scrollDebounce = true;
                    setTimeout(() => (scrollDebounce = false), 400);
                    if (e.deltaY > 0) $('#flipbook').turn('next');
                    else $('#flipbook').turn('previous');
                    });
                }
            });
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
