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
                .picker-box{
                    position:absolute; z-index:9;
                    outline:1px dashed red;
                    background:rgba(255,0,0,.2);
                    pointer-events:none;
                }
            </style>
            <script>
                window.linkMap = {
                1: [
                    { href: 'https://karp.or.kr', x: 380, y: 1534, w: 492, h: 169, title: '대한방사선방어학회 홈페이지' },
                ],
                2: [
                    { href: 'https://sponsor-a.com', x: 100,  y: 0, w: 520, h: 220, title: '스폰서 A' },
                    { goto: 10, x: 0, y: 0, w: 520, h: 220, title: '10페이지로 이동' },
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
                function getPageByNumber(page){
                    const img = document.querySelector(`#flipbook img.link_area_\${page}`);
                    if (!img) return {};
                    const pageEl   = img.closest('.page');
                    const canvasEl = img.closest('.page-canvas') || pageEl;
                    return { pageEl, canvasEl, img };
                }

                function getContainMap(canvasEl, img){
                    const rect = canvasEl.getBoundingClientRect();
                    const cw = rect.width, ch = rect.height;
                    const iw = img.naturalWidth, ih = img.naturalHeight;
                    const scale = Math.min(cw/iw, ch/ih);
                    const renderW = iw*scale, renderH = ih*scale;
                    const offL = (cw - renderW)/2, offT = (ch - renderH)/2;
                    return { rect, cw, ch, iw, ih, scale, offL, offT };
                }

                function clientToImagePx(map, clientX, clientY){
                    const x = (clientX - map.rect.left - map.offL) / map.scale;
                    const y = (clientY - map.rect.top  - map.offT ) / map.scale;
                    return {
                        x: Math.max(0, Math.min(map.iw, x)),
                        y: Math.max(0, Math.min(map.ih, y)),
                        inside: x>=0 && y>=0 && x<=map.iw && y<=map.ih
                    };
                }

                (function enablePicker(){
                    let dragging = false, start = null, box = null, ctx = null;

                    function attachToVisible(){
                        const view = ($('#flipbook').turn('view')||[]).filter(Boolean);
                        view.forEach(p=>{
                        const { canvasEl, img } = getPageByNumber(p);
                        if (!canvasEl || !img) return;

                        if (canvasEl.__pickerBound) return;
                        canvasEl.__pickerBound = true;

                        canvasEl.addEventListener('mousedown', (e)=>{
                            if (e.button !== 0) return;
                            const map = getContainMap(canvasEl, img);
                            const pt = clientToImagePx(map, e.clientX, e.clientY);
                            if (!pt.inside) return;

                            dragging = true;
                            ctx = { canvasEl, img, map };
                            start = pt;

                            box = document.createElement('div');
                            box.className = 'picker-box';
                            canvasEl.appendChild(box);
                            e.preventDefault();
                        });

                        canvasEl.addEventListener('mousemove', (e)=>{
                            if (!dragging || !ctx) return;
                            const pt = clientToImagePx(ctx.map, e.clientX, e.clientY);
                            const x0 = Math.min(start.x, pt.x), y0 = Math.min(start.y, pt.y);
                            const w  = Math.abs(start.x - pt.x), h  = Math.abs(start.y - pt.y);

                            const left = ctx.map.offL + x0 * ctx.map.scale;
                            const top  = ctx.map.offT + y0 * ctx.map.scale;
                            const ww   = Math.max(1, w * ctx.map.scale);
                            const hh   = Math.max(1, h * ctx.map.scale);

                            Object.assign(box.style, {
                                left:  left + 'px', top: top + 'px',
                                width: ww + 'px',  height: hh + 'px'
                            });
                        });

                        window.addEventListener('mouseup', ()=>{
                            if (!dragging || !ctx) return;
                            dragging = false;

                            const rect = box.getBoundingClientRect();
                            const x0 = Math.round((rect.left - ctx.map.rect.left - ctx.map.offL) / ctx.map.scale);
                            const y0 = Math.round((rect.top  - ctx.map.rect.top  - ctx.map.offT ) / ctx.map.scale);
                            const w  = Math.round(rect.width  / ctx.map.scale);
                            const h  = Math.round(rect.height / ctx.map.scale);

                            const pageNum = (ctx.img.className.match(/link_area_(\d+)/)||[])[1] || '?';
                            const snippet = `{ href: 'https://example.com', x: \${x0}, y: \${y0}, w: \${w}, h: \${h}, title: '' }`;

                            console.log(`Page \${pageNum} →`, snippet);
                            navigator.clipboard?.writeText(snippet).catch(()=>{});

                            box.remove(); box = null; ctx = null;
                        });
                        });
                    }

                    $(function(){
                        attachToVisible();
                        $('#flipbook').on('turned', ()=>requestAnimationFrame(attachToVisible));
                        window.addEventListener('resize', ()=>setTimeout(()=>requestAnimationFrame(attachToVisible), 50));
                        window.addEventListener('orientationchange', ()=>setTimeout(()=>requestAnimationFrame(attachToVisible), 50));
                    });
                })();
            </script>


            <script>
                function getPageByNumber(page){
                    const img = document.querySelector(`#flipbook img.link_area_\${page}`);
                    if (img) {
                        const pageEl   = img.closest('.page');
                        const canvasEl = img.closest('.page-canvas') || pageEl;
                        return { pageEl, canvasEl, img };
                    }
                    const pageEl = $('#flipbook .page').get(page - 1) || null;
                    const canvasEl = pageEl ? (pageEl.querySelector('.page-canvas') || pageEl) : null;
                    const fallbackImg = canvasEl ? canvasEl.querySelector('img') : null;
                    return { pageEl, canvasEl, img: fallbackImg };
                }

                function getVisiblePages(){
                    const v = $('#flipbook').turn('view') || [];
                    return v.filter(Boolean);
                }

                function applyLinks(){
                    if (!window.linkMap) return;

                    const pagesEls = $('#flipbook .page');
                    const visible  = getVisiblePages();

                    visible.forEach(p => {
                        const { canvasEl } = getPageByNumber(p);
                        if (!canvasEl) return;
                        canvasEl.querySelectorAll('.link-area').forEach(a => a.remove());
                    });

                    visible.forEach(page => {
                        const areas = linkMap[page];
                        if (!areas || !areas.length) return;

                        const { pageEl, canvasEl, img } = getPageByNumber(page);
                        if (!img) return;

                        const tryCalc = () => {
                        const cw = canvasEl.clientWidth;
                        const ch = canvasEl.clientHeight;

                        const meta = (window.imageMeta && (imageMeta[page] || imageMeta.default)) || {};
                        const iw = img.naturalWidth  || meta.naturalWidth  || 0;
                        const ih = img.naturalHeight || meta.naturalHeight || 0;

                        if (!cw || !ch || !iw || !ih) return requestAnimationFrame(tryCalc);

                        const scale = Math.min(cw / iw, ch / ih);
                        const renderW = iw * scale, renderH = ih * scale;
                        const offsetLeft = (cw - renderW) / 2;
                        const offsetTop  = (ch - renderH) / 2;

                        areas.forEach(({ href, goto, x, y, w, h, title }) => {
                            if ([x,y,w,h].some(v => v === undefined)) return;

                            const a = document.createElement('a');
                            a.className = 'link-area';
                            a.setAttribute('aria-label', title || '');

                            Object.assign(a.style, {
                                position: 'absolute',
                                zIndex: 5,
                                cursor: 'pointer',
                                left:  (offsetLeft + x * scale) + 'px',
                                top:   (offsetTop  + y * scale) + 'px',
                                width: (w * scale) + 'px',
                                height:(h * scale) + 'px'
                            });

                            if (typeof goto === 'number') {
                                a.href = 'javascript:void(0)';
                                a.role = 'button';
                                a.tabIndex = 0;

                                const jump = () => $('#flipbook').turn('page', goto);

                                a.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); jump(); });
                                a.addEventListener('keydown', (e) => {
                                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jump(); }
                                });
                            } else if (href) {
                                a.href = href;
                                a.target = '_blank';
                                a.rel = 'noopener';
                            } else {
                                return;
                            }

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
                    $('#flipbook .page').each(function(){
                        if (!this.querySelector('.page-canvas')) {
                            const canvas = document.createElement('div');
                            canvas.className = 'page-canvas';
                            while (this.firstChild) canvas.appendChild(this.firstChild);
                            this.appendChild(canvas);
                        }
                    });

                    const isNarrow = () => window.innerWidth <= 768;
                    function getFlipbookSize(){
                        const vw = window.innerWidth;
                        const vh = window.innerHeight;
                        return {
                        width:  isNarrow() ? vw : Math.round(vw * 0.5),
                        height: Math.round(vh * 0.8)
                        };
                    }

                    const totalPages = $('#flipbook .page').length;
                    const { width, height } = getFlipbookSize();

                    $('#flipbook').turn({
                        width,
                        height,
                        autoCenter: true,
                        display: isNarrow() ? 'single' : 'double',
                        gradients: true,
                        elevation: 50,
                        pages: totalPages,
                        when: {
                        turning: function(){ 
                            $('#flipbook .page-canvas .link-area').remove();
                        },
                        turned: function (event, page, view) {
                            const info = document.getElementById('page-info');
                            const flipbook = document.getElementById('flipbook');

                            if (page == 1 || event == 'previous') {
                            flipbook.style.right = isNarrow() ? '' : '12%';
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
                            flipbook.style.left = isNarrow() ? '' : '12%';
                            }

                            requestAnimationFrame(applyLinks);
                        }
                        }
                    });

                    requestAnimationFrame(applyLinks);

                    const reflow = () => {
                        const { width, height } = getFlipbookSize();
                        $('#flipbook').turn('size', width, height);

                        const desiredDisplay = isNarrow() ? 'single' : 'double';
                        const currentDisplay = $('#flipbook').turn('display');
                        if (currentDisplay !== desiredDisplay) {
                            $('#flipbook').turn('display', desiredDisplay);
                        }

                        setTimeout(() => requestAnimationFrame(applyLinks), 50);
                    };
                    window.addEventListener('resize', reflow);
                    window.addEventListener('orientationchange', reflow);

                    if (!isNarrow()) {
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
