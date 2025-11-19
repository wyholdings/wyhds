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
        // 🔹 Ghostscript 기본 옵션 설정 (이 프로세스에서만 유효)
        putenv('GS_OPTIONS=-dNumRenderingThreads=4 -dBufferSpace=50000000');

        // 긴 작업 안전장치
        @ini_set('max_execution_time', '0');   // 무제한
        @ini_set('memory_limit', '1024M');     // 필요시 조정
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');

        // 에러는 화면에 찍지 말고 로그로만 (ZIP 깨지는 원인 차단)
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        // 세션 락 해제(있다면)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo $this->twig->render('admin/ebook/list.html.twig', []);
            return;
        }

        try {
            if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('PDF 업로드에 실패했습니다.');
            }

            $pdfFile = $_FILES['pdf']['tmp_name'];
            $originalFileName = $_FILES['pdf']['name'];
            $ebookId = uniqid('ebook_');
            $uploadBase = __DIR__ . '/../../public/ebooks/';
            $outputDir = rtrim($uploadBase, '/').'/'.$ebookId.'/';

            if (!is_dir($uploadBase) && !mkdir($uploadBase, 0777, true)) {
                throw new \RuntimeException('업로드 폴더 생성 실패');
            }
            if (!mkdir($outputDir, 0777, true)) {
                throw new \RuntimeException('eBook 폴더 생성 실패');
            }

            // 페이지 수 파악은 ping으로(저메모리)
            $ping = new \Imagick();
            $ping->pingImage($pdfFile);
            $totalPages = $ping->getNumberImages();
            $ping->clear(); $ping->destroy();

            if ($totalPages < 1) {
                throw new \RuntimeException('PDF 페이지를 인식하지 못했습니다.');
            }

            // PDF → PNG (페이지 단위, 메모리 절약)
            // 해상도/품질은 필요에 맞게 조정
            for ($i = 0; $i < $totalPages; $i++) {
                $im = new \Imagick();
                $im->setResolution(150, 150);
                // 특정 페이지만 로드
                $im->readImage(sprintf('%s[%d]', $pdfFile, $i));

                // 투명 배경 이슈 방지(필요시)
                $im->setImageBackgroundColor('white');
                $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

                $im->setImageFormat('png');
                $im->setImageCompression(\Imagick::COMPRESSION_ZIP);
                $im->setImageCompressionQuality(90);
                $target = $outputDir . ($i + 1) . '.png';
                if (!$im->writeImage($target)) {
                    $im->clear(); $im->destroy();
                    throw new \RuntimeException('PNG 저장 실패: '.$target);
                }
                $im->clear(); $im->destroy();
            }

            // index.html 생성
            $html = $this->buildIndexHtml($totalPages); // 아래 헬퍼 함수 예시 참고
            file_put_contents($outputDir.'index.html', $html);

            // ZIP 생성
            $zipPath = rtrim($uploadBase, '/').'/'.$ebookId.'.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('ZIP 생성 실패');
            }
            // eBook 폴더 통째로 넣기
            $files = scandir($outputDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $zip->addFile($outputDir.$file, $ebookId.'/'.$file);
            }
            $zip->close();

            // DB 저장
            $ebookModel = new EbookModel();
            $ebookModel->upload([
                'file_name'   => $originalFileName,
                'folder_name' => $ebookId
            ]);

            // 모든 출력버퍼 제거(바이너리 전송 전 필수)
            while (ob_get_level() > 0) { @ob_end_clean(); }

            // 안전한 헤더
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$ebookId.'.zip"');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: '.filesize($zipPath));

            // 청크 스트리밍(대용량 안전)
            $fp = fopen($zipPath, 'rb');
            if ($fp === false) {
                throw new \RuntimeException('ZIP 읽기 실패');
            }
            $chunk = 8192;
            while (!feof($fp)) {
                $buffer = fread($fp, $chunk);
                echo $buffer;
                flush();
                if (connection_status() != CONNECTION_NORMAL) {
                    break;
                }
            }
            fclose($fp);
            exit;

        } catch (\Throwable $e) {
            // 실패 시 관리자용 에러 표시(개발 중에만)
            http_response_code(500);
            echo "처리 중 오류가 발생했습니다: ".$e->getMessage();
            return;
        }
    }

    /**
     * 페이지 수를 받아 index.html 문자열을 생성하는 헬퍼.
     * (질문에 주신 HTML/JS를 그대로 사용하되 문자열 리터럴로 분리)
     */
    function buildIndexHtml(int $totalPages, array $linkMap = []): string
    {
        $linkMapJson = json_encode(
            $linkMap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $head = <<<HTML
    <!DOCTYPE html>
    <html lang='ko'>
    <head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, user-scalable=yes'>
    <title>eBook</title>
    <!-- (질문 코드의 스타일/스크립트 동일) -->
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
                .link-area:hover{
                    background: rgba(255,0,0,.2);
                    outline: 1px dashed red;
                }
                .picker-box{
                    position:absolute; z-index:9;
                    outline:1px dashed red;
                    background:rgba(255,0,0,.2);
                    pointer-events:none;
                }
                #admin-save-panel{
                    position: fixed;
                    right: 20px;
                    bottom: 20px;
                    background: rgba(0,0,0,0.7);
                    color: #fff;
                    padding: 10px 14px;
                    border-radius: 6px;
                    font-size: 13px;
                    display: none;
                    z-index: 9999;
                }
                #admin-save-panel button{
                    margin-left: 8px;
                    padding: 4px 8px;
                    font-size: 12px;
                    cursor: pointer;
                }
            </style>
            <script>
                window.linkMap = {$linkMapJson};

                window.imageMeta = {
                    default: { naturalWidth: 763, naturalHeight: 1079 }
                };
            </script>
            
                
    <script src='https://code.jquery.com/jquery-3.6.0.min.js'></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/turn.js/3/turn.min.js'></script>
    </head>
    <body>
    <div id='flipbook-wrapper'><div id='flipbook'>
    HTML;

        $pages = '';
        for ($p=1; $p<=$totalPages; $p++) {
            if ($p === 1) {
                $pages .= "<div class='page'><img src='{$p}.png' style='max-width:100%; max-height:100%;' class='link_area_{$p}'></div>\n";
            } else {
                $pages .= "<div class='page'><img src='{$p}.png' style='max-height:100%;' class='link_area_{$p}'></div>\n";
            }
        }

        $tail = <<<HTML
    </div></div>
    <div id='controls'>
    <button onclick='$("#flipbook").turn("page", 1)'>⏮</button>
    <button onclick='$("#flipbook").turn("previous")'>◀</button>
    <button onclick='$("#flipbook").turn("next")'>▶</button>
    <button onclick='$("#flipbook").turn("page", {$totalPages})'>⏭</button>
    <div id='page-info'></div>
    <div id="admin-save-panel">
        링크 좌표 편집 중
        <button id="btn-save-links">DB 저장</button>
    </div>
    </div>

    <script>
        const EBOOK_ID = (function(){
            const parts = location.pathname.split('/').filter(Boolean);
            const ebooksIdx = parts.indexOf('ebooks');
            if (ebooksIdx === -1) return null;
            return parts[ebooksIdx + 1] || null;
        })();

        function enableAdminMode() {
            if (adminMode) return;
            adminMode = true;

            alert("관리자 모드 활성화됨!");

            document.documentElement.classList.add("show-link-boxes");

            // admin 표시 패널
            const panel = document.getElementById('admin-save-panel');
            if (panel) panel.style.display = 'block';

            if (typeof window.enablePickerMode === "function") {
                window.enablePickerMode();
            }
        }
        
        // 저장 버튼 이벤트
        document.addEventListener('DOMContentLoaded', function(){
            const btn = document.getElementById('btn-save-links');
            if (!btn) return;

            btn.addEventListener('click', function(){
                if (!EBOOK_ID) {
                    alert('ebookId를 찾을 수 없습니다.');
                    return;
                }
                if (!window.linkMap) {
                    alert('linkMap이 비어 있습니다.');
                    return;
                }

                if (!confirm('현재 linkMap을 DB에 저장할까요?')) return;

                fetch('/admin/ebook/' + EBOOK_ID + '/links', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(window.linkMap),
                })
                .then(res => res.json())
                .then(json => {
                    if (json.ok) {
                        alert('저장 완료!');
                    } else {
                        alert('저장 실패: ' + (json.error || 'unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('저장 중 오류 발생');
                });
            });
        });

        // ====== 관리자 모드 진입 키워드 ======
        let adminBuffer = "";
        let adminMode = false;

        // 키 입력 감지
        window.addEventListener("keydown", (e) => {
            // 공백/Shift 같은 건 무시
            if (e.key.length !== 1) return;

            adminBuffer += e.key.toLowerCase();

            // 입력 누적이 너무 길어지면 앞부분 컷
            if (adminBuffer.length > 10) {
                adminBuffer = adminBuffer.slice(-10);
            }

            // admin 글자가 연속으로 등장하면 관리자 모드 ON
            if (adminBuffer.includes("admin")) {
                enableAdminMode();
            }
        });

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

        // admin 모드에서 호출할 전역 함수로 노출
        window.enablePickerMode = function () {
            // 한 번만 초기화되도록 플래그
            if (window.__pickerEnabled) return;
            window.__pickerEnabled = true;

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
                    window.linkMap = window.linkMap || {};

                    window.addEventListener('mouseup', ()=>{
                        if (!dragging || !ctx) return;
                        dragging = false;

                        const rect = box.getBoundingClientRect();
                        const x0 = Math.round((rect.left - ctx.map.rect.left - ctx.map.offL) / ctx.map.scale);
                        const y0 = Math.round((rect.top  - ctx.map.rect.top  - ctx.map.offT ) / ctx.map.scale);
                        const w  = Math.round(rect.width  / ctx.map.scale);
                        const h  = Math.round(rect.height / ctx.map.scale);

                        const pageNum = parseInt((ctx.img.className.match(/link_area_(\d+)/)||[])[1] || '0', 10);

                        // 간단하게 URL만 입력 받는 버전 (추후 UI로 대체 가능)
                        const href = prompt('링크 URL (없으면 취소)', 'https://');
                        let gotoPage = null;
                        if (!href) {
                            const gotoStr = prompt('이동할 페이지 번호 (없으면 취소)', '');
                            if (gotoStr) gotoPage = parseInt(gotoStr, 10) || null;
                        }
                        const title = prompt('툴팁/설명 (옵션)', '') || '';

                        // linkMap에 반영
                        if (!window.linkMap[pageNum]) window.linkMap[pageNum] = [];
                        window.linkMap[pageNum].push({
                            href: href || null,
                            goto: gotoPage,
                            x: x0,
                            y: y0,
                            w: w,
                            h: h,
                            title: title,
                        });

                        console.log('현재 linkMap', window.linkMap);

                        box.remove(); box = null; ctx = null;
                    });
                });
            }

            // DOM 준비 후 바인딩
            $(function(){
                attachToVisible();
                $('#flipbook').on('turned', ()=>requestAnimationFrame(attachToVisible));
                window.addEventListener('resize', ()=>setTimeout(()=>requestAnimationFrame(attachToVisible), 50));
                window.addEventListener('orientationchange', ()=>setTimeout(()=>requestAnimationFrame(attachToVisible), 50));
            });
        };

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

            const PAGE_RATIO = 210 / 297; // ≈ 0.707

            function getFlipbookSize() {
                const vw = window.innerWidth;
                const vh = window.innerHeight;

                // 여유있는 가용 영역(원하는 값으로 살짝 조절 가능)
                const availW = Math.floor(vw * 0.9);
                const availH = Math.floor(vh * 0.85);

                const single = isNarrow();         // <= 768px 이면 single
                const bookRatio = single ? PAGE_RATIO : (2 * PAGE_RATIO); // flipbook 전체 가로/세로 비율

                // 가용 영역에 '비율 유지'로 최대 크기 맞추기
                // width = min(availW, availH * bookRatio)
                const width  = Math.floor(Math.min(availW, availH * bookRatio));
                const height = Math.floor(width / bookRatio);

                return {
                    width,
                    height,
                    display: single ? 'single' : 'double'
                };
            }

            const totalPages = $('#flipbook .page').length;
            const { width, height,display } = getFlipbookSize();

            $('#flipbook').turn({
                width,
                height,
                autoCenter: true,
                display,
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
                    flipbook.style.right = isNarrow() ? '' : '15%';
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
                    flipbook.style.left = isNarrow() ? '' : '14%';
                    }

                    requestAnimationFrame(applyLinks);
                }
                }
            });

            requestAnimationFrame(applyLinks);

            const reflow = () => {
            const { width, height, display } = getFlipbookSize();
            $('#flipbook').turn('size', width, height);

            const currentDisplay = $('#flipbook').turn('display');
            if (currentDisplay !== display) {
                $('#flipbook').turn('display', display);
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
    </html>
    HTML;

        return $head.$pages.$tail;
    }

    // 예: EbookController 안에 exportZip 같은 액션이 있다고 치고
    public function exportZip(string $ebookId)
    {
        $uploadBase = __DIR__ . '/../../public/ebooks/';
        $outputDir  = rtrim($uploadBase, '/').'/'.$ebookId.'/';

        // 1) 페이지 수 파악 (이미 생성된 PNG 기준)
        $files = glob($outputDir.'*.png');
        $totalPages = count($files);

        // 2) DB에서 링크 가져오기
        $ebookModel = new EbookModel();
        $rows = $ebookModel->getLinksByEbook($ebookId); // ebook_links 조회

        $linkMap = [];
        foreach ($rows as $r) {
            $page = (int)$r['page'];
            if (!isset($linkMap[$page])) $linkMap[$page] = [];

            $linkMap[$page][] = [
                'href'  => $r['target_type'] === 'url'  ? $r['target'] : null,
                'goto'  => $r['target_type'] === 'page' ? (int)$r['target'] : null,
                'x'     => (int)$r['x'],
                'y'     => (int)$r['y'],
                'w'     => (int)$r['w'],
                'h'     => (int)$r['h'],
                'title' => $r['title'] ?? '',
            ];
        }

        // 3) linkMap까지 포함된 index.html 생성/덮어쓰기
        $html = $this->buildIndexHtml($totalPages, $linkMap);
        file_put_contents($outputDir.'index.html', $html);

        // 4) ZIP 생성 (지금 upload()에서 하던 흐름 그대로)
        $zipPath = rtrim($uploadBase, '/').'/'.$ebookId.'.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZIP 생성 실패');
        }
        $files = scandir($outputDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $zip->addFile($outputDir.$file, $ebookId.'/'.$file);
        }
        $zip->close();

        // 나머지: header 내보내고 다운로드 응답하는 부분은 기존 코드 재활용
    }

    public function download(string $ebookId)
    {
        $uploadBase = __DIR__ . '/../../public/ebooks/';
        $ebookDir   = rtrim($uploadBase, '/') . '/' . $ebookId . '/';

        if (!is_dir($ebookDir)) {
            http_response_code(404);
            echo "eBook not found: {$ebookId}";
            return;
        }

        // PNG 수 계산
        $files = glob($ebookDir . '*.png');
        $totalPages = count($files);

        // 링크 불러오기
        $ebookModel = new EbookModel();
        $linkRows   = $ebookModel->getLinksByEbook($ebookId);

        $linkMap = [];
        foreach ($linkRows as $r) {
            $page = (int)$r['page'];
            if (!isset($linkMap[$page])) $linkMap[$page] = [];

            $linkMap[$page][] = [
                'href'  => $r['target_type'] === 'url'  ? $r['target'] : null,
                'goto'  => $r['target_type'] === 'page' ? (int)$r['target'] : null,
                'x'     => (int)$r['x'],
                'y'     => (int)$r['y'],
                'w'     => (int)$r['w'],
                'h'     => (int)$r['h'],
                'title' => $r['title'] ?? '',
            ];
        }

        // 최신 index.html 재생성 (linkMap 포함)
        $html = $this->buildIndexHtml($totalPages, $linkMap);
        file_put_contents($ebookDir . 'index.html', $html);

        // ZIP 생성
        $zipPath = rtrim($uploadBase, '/') . '/' . $ebookId . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo "ZIP 생성 실패";
            return;
        }

        $files = scandir($ebookDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $zip->addFile($ebookDir . $file, $ebookId . '/' . $file);
        }
        $zip->close();

        // 다운로드 헤더
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $ebookId . '.zip"');
        header('Content-Length: ' . filesize($zipPath));

        readfile($zipPath);
    }

    public function saveLinks(string $ebookId)
    {
        // JSON 받아오기
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid json']);
            return;
        }

        $ebookModel = new EbookModel();
        $ok = $ebookModel->replaceLinks($ebookId, $data);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok]);
    }


}
