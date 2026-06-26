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
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $ebookModel = new EbookModel();
        $ebooks = $ebookModel->getAll($perPage, $offset);
        $total = $ebookModel->countAll();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
            $ebooks = $ebookModel->getAll($perPage, $offset);
        }

        echo $this->twig->render('admin/ebook/list.html.twig', [
            'ebooks' => $ebooks,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'flash' => $flash,
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
            $storedPdfName = 'source.pdf';
            $ebookId = uniqid('ebook_');
            $uploadBase = __DIR__ . '/../../public/ebooks/';
            $outputDir = rtrim($uploadBase, '/').'/'.$ebookId.'/';

            if (!is_dir($uploadBase) && !mkdir($uploadBase, 0777, true)) {
                throw new \RuntimeException('업로드 폴더 생성 실패');
            }
            if (!mkdir($outputDir, 0777, true)) {
                throw new \RuntimeException('eBook 폴더 생성 실패');
            }

            $storedPdfPath = $outputDir . $storedPdfName;
            if (!move_uploaded_file($pdfFile, $storedPdfPath)) {
                throw new \RuntimeException('원본 PDF 저장 실패');
            }

            // 페이지 수 파악은 ping으로(저메모리)
            $ping = new \Imagick();
            $ping->pingImage($storedPdfPath);
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
                $im->readImage(sprintf('%s[%d]', $storedPdfPath, $i));

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
            $html = $this->buildIndexHtml($totalPages, [], $storedPdfName); // 아래 헬퍼 함수 예시 참고
            file_put_contents($outputDir.'index.html', $html);

            // DB 저장
            $ebookModel = new EbookModel();
            $ebookModel->upload([
                'file_name'   => $originalFileName,
                'folder_name' => $ebookId
            ]);

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $_SESSION['flash'] = [
                'message' => 'eBook 변환이 완료되었습니다.',
                'error' => false,
            ];
            header('Location: /admin/ebook/list');
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
    function buildIndexHtml(int $totalPages, array $linkMap = [], string $sourcePdfFile = 'source.pdf'): string
    {
        $linkMapJson = json_encode(
            $linkMap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $sourcePdfJson = json_encode(
            $sourcePdfFile,
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
                    position: relative;
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
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 0 16px 14px;
                }
                #controls-nav {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    flex-wrap: wrap;
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
                    font-size: 0.9rem;
                    width: 90px;
                    padding: 8px 10px;
                    text-align: center;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    box-sizing: border-box;
                }
                #page-display {
                    font-size: 0.9rem;
                    padding: 0;
                    min-width: 78px;
                    text-align: center;
                    display: inline-block;
                    font-weight: 600;
                }
                #viewer-overlay {
                    position: absolute;
                    top: 14px;
                    right: 14px;
                    z-index: 30;
                    display: flex;
                    flex-direction: column;
                    align-items: flex-end;
                    gap: 8px;
                }
                #menu-toggle {
                    width: 42px;
                    height: 42px;
                    padding: 0;
                    margin: 0;
                    font-size: 20px;
                    line-height: 1;
                    background: rgba(34,34,34,0.92);
                    color: #fff;
                    border-color: rgba(34,34,34,0.92);
                    box-shadow: 0 6px 18px rgba(0,0,0,0.18);
                }
                #viewer-panel {
                    width: min(360px, calc(100vw - 28px));
                    padding: 10px;
                    background: rgba(255,255,255,0.98);
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.16);
                    display: none;
                }
                #viewer-panel.is-open {
                    display: block;
                }
                #btn-toggle-search {
                    width: 100%;
                    margin: 0;
                    text-align: left;
                    background: #fff;
                }
                #search-panel {
                    display: none;
                    margin-top: 10px;
                }
                #search-panel.is-open {
                    display: block;
                }
                #search-form {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                }
                #search-query {
                    flex: 1;
                    min-width: 0;
                    font-size: 0.9rem;
                    padding: 8px 10px;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    box-sizing: border-box;
                }
                #btn-search {
                    margin: 0;
                    background: #222;
                    color: #fff;
                    border-color: #222;
                }
                #search-status {
                    display: block;
                    margin-top: 8px;
                    font-size: 0.85rem;
                    color: #666;
                    text-align: left;
                }
                #search-results {
                    max-height: min(52vh, 420px);
                    margin-top: 10px;
                    overflow: auto;
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
                    display: none;
                }
                .search-result-item {
                    display: block;
                    width: 100%;
                    padding: 12px 14px;
                    text-align: left;
                    border: 0;
                    border-bottom: 1px solid #eee;
                    background: #fff;
                    margin: 0;
                    border-radius: 0;
                }
                .search-result-item:last-child {
                    border-bottom: 0;
                }
                .search-result-item strong {
                    display: block;
                    margin-bottom: 4px;
                }
                .search-result-item mark {
                    background: #ffe58f;
                    padding: 0 1px;
                }
                .search-empty {
                    padding: 14px;
                    color: #666;
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
                #viewer-overlay {
                    z-index: 9999;
                    top: 10px;
                    right: 10px;
                }
                #viewer-panel {
                    width: min(340px, calc(100vw - 20px));
                }
                #search-form {
                    flex-wrap: wrap;
                }
                #search-query,
                #btn-search {
                    width: 100%;
                }
                #btn-search {
                    margin-top: 0;
                }
                #search-results {
                    max-height: 42vh;
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
                window.sourcePdfFile = {$sourcePdfJson};

                window.imageMeta = {
                    default: { naturalWidth: 763, naturalHeight: 1079 }
                };
            </script>
            
                
    <script src='https://code.jquery.com/jquery-3.6.0.min.js'></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/turn.js/3/turn.min.js'></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'></script>
    </head>
    <body>
    <div id='flipbook-wrapper'>
    <div id='viewer-overlay'>
    <button type='button' id='menu-toggle' aria-expanded='false' aria-controls='viewer-panel'>☰</button>
    <div id='viewer-panel'>
    <button type='button' id='btn-toggle-search'>검색창 열기</button>
    <div id='search-panel'>
    <div id='search-form'>
    <input id='search-query' type='search' placeholder='내용 검색' aria-label='내용 검색'>
    <button type='button' id='btn-search'>검색</button>
    </div>
    <span id='search-status'>검색 준비 중</span>
    <div id='search-results' aria-live='polite'></div>
    </div>
    </div>
    </div>
    <div id='flipbook'>
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
    <span id='page-display'></span>
    <div id='controls-nav'>
    <button type='button' id='btn-first'>⏮</button>
    <button type='button' id='btn-prev'>◀</button>
    <input id='page-info' type='number' min='1' placeholder='페이지 입력' aria-label='페이지 입력 후 Enter로 이동'>
    <button type='button' id='btn-next'>▶</button>
    <button type='button' id='btn-last'>⏭</button>
    </div>
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
                        const title = '';

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
        const searchState = {
            ready: false,
            loading: false,
            error: null,
            pages: []
        };

        function setMenuOpen(isOpen){
            const panel = document.getElementById('viewer-panel');
            const toggle = document.getElementById('menu-toggle');
            if (panel) panel.classList.toggle('is-open', isOpen);
            if (toggle) toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        function setSearchPanelOpen(isOpen){
            const panel = document.getElementById('search-panel');
            const button = document.getElementById('btn-toggle-search');
            if (panel) panel.classList.toggle('is-open', isOpen);
            if (button) button.textContent = isOpen ? '검색창 닫기' : '검색창 열기';
        }

        function escapeHtml(value){
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function normalizeText(value){
            return String(value || '')
                .replace(/[\u0000-\u001F\u007F\uFFFD]/g, ' ')
                .replace(/[◆◇■□�]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function escapeRegExp(value){
            const specials = '\\\\^$.*+?()[]{}|';
            return String(value).split('').map((char) => {
                return specials.indexOf(char) !== -1 ? '\\\\' + char : char;
            }).join('');
        }

        function updateSearchStatus(message){
            const el = document.getElementById('search-status');
            if (el) el.textContent = message;
        }

        function renderSearchResults(results, query){
            const wrap = document.getElementById('search-results');
            if (!wrap) return;
            setMenuOpen(true);
            setSearchPanelOpen(true);

            if (!results.length) {
                wrap.style.display = 'block';
                wrap.innerHTML = `<div class="search-empty">'\${escapeHtml(query)}' 검색 결과가 없습니다.</div>`;
                return;
            }

            const pattern = new RegExp(escapeRegExp(query), 'ig');
            wrap.style.display = 'block';
            wrap.innerHTML = results.map((item) => {
                const snippet = escapeHtml(item.snippet).replace(pattern, (match) => `<mark>\${match}</mark>`);
                return `
                    <button type="button" class="search-result-item" data-page="\${item.page}">
                        <strong>\${item.page}페이지</strong>
                        <span>\${snippet}</span>
                    </button>
                `;
            }).join('');

            wrap.querySelectorAll('.search-result-item').forEach((button) => {
                button.addEventListener('click', () => {
                    const targetPage = parseInt(button.dataset.page || '1', 10);
                    $('#flipbook').turn('page', targetPage);
                    setMenuOpen(false);
                });
            });
        }

        async function loadSearchIndex(totalPages){
            if (searchState.ready || searchState.loading) return;
            searchState.loading = true;
            updateSearchStatus('원본 PDF 분석 중');

            try {
                if (!window.pdfjsLib) throw new Error('pdf.js 로드 실패');

                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                const pdf = await pdfjsLib.getDocument(window.sourcePdfFile || 'source.pdf').promise;
                const pageLimit = Math.min(totalPages, pdf.numPages);
                const pages = [];

                for (let pageNo = 1; pageNo <= pageLimit; pageNo++) {
                    const page = await pdf.getPage(pageNo);
                    const textContent = await page.getTextContent();
                    const text = normalizeText(textContent.items.map((item) => item.str || '').join(' '));
                    pages.push({ page: pageNo, text });
                    updateSearchStatus(`검색 인덱스 생성 중 \${pageNo}/\${pageLimit}`);
                }

                searchState.pages = pages;
                searchState.ready = true;
                updateSearchStatus('검색 가능');
            } catch (error) {
                console.error(error);
                searchState.error = error;
                updateSearchStatus('검색 인덱스 생성 실패');
            } finally {
                searchState.loading = false;
            }
        }

        async function runSearch(){
            const input = document.getElementById('search-query');
            const query = normalizeText(input ? input.value : '');
            const resultsWrap = document.getElementById('search-results');

            if (!query) {
                if (resultsWrap) {
                    resultsWrap.style.display = 'none';
                    resultsWrap.innerHTML = '';
                }
                updateSearchStatus(searchState.ready ? '검색 가능' : '검색어를 입력하세요');
                return;
            }

            if (!searchState.ready) {
                await loadSearchIndex($('#flipbook .page').length);
            }

            if (!searchState.ready) {
                renderSearchResults([], query);
                return;
            }

            const normalizedQuery = query.toLowerCase();
            const results = searchState.pages
                .filter((item) => item.text.toLowerCase().includes(normalizedQuery))
                .slice(0, 100)
                .map((item) => {
                    const lowerText = item.text.toLowerCase();
                    const hitIndex = lowerText.indexOf(normalizedQuery);
                    const start = Math.max(0, hitIndex - 45);
                    const end = Math.min(item.text.length, hitIndex + query.length + 90);
                    return {
                        page: item.page,
                        snippet: item.text.slice(start, end).trim() || item.text.slice(0, 120)
                    };
                });

            updateSearchStatus(`검색 결과 \${results.length}건`);
            renderSearchResults(results, query);
        }

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
            const pageInput = document.getElementById('page-info');
            const pageDisplay = document.getElementById('page-display');
            const btnFirst = document.getElementById('btn-first');
            const btnPrev = document.getElementById('btn-prev');
            const btnNext = document.getElementById('btn-next');
            const btnLast = document.getElementById('btn-last');
            const btnSearch = document.getElementById('btn-search');
            const searchInput = document.getElementById('search-query');
            const menuToggle = document.getElementById('menu-toggle');
            const btnToggleSearch = document.getElementById('btn-toggle-search');

            const syncPageUi = (viewOverride) => {
                const view = Array.isArray(viewOverride) && viewOverride.length
                    ? viewOverride.filter(Boolean)
                    : (($('#flipbook').turn('view') || []).filter(Boolean));

                if (!view.length) return;

                if (pageInput) {
                    pageInput.value = view[0];
                }

                if (pageDisplay) {
                    pageDisplay.textContent = view[1] ? `\${view[0]}-\${view[1]}` : `\${view[0]}`;
                }
            };

            if (pageInput) {
                pageInput.max = totalPages;
                const goToInputPage = () => {
                    const target = parseInt(pageInput.value, 10);
                    if (Number.isFinite(target) && target >= 1 && target <= totalPages) {
                        $('#flipbook').turn('page', target);
                    } else {
                        alert(`1부터 \${totalPages} 사이의 페이지 번호를 입력하세요.`);
                    }
                };
                pageInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') goToInputPage();
                });
                pageInput.addEventListener('change', goToInputPage);
            }

            const goTo = (p) => {
                const target = Math.max(1, Math.min(totalPages, p));
                $('#flipbook').turn('page', target);
                setTimeout(() => syncPageUi(), 0);
            };
            if (btnFirst) btnFirst.addEventListener('click', () => goTo(1));
            if (btnLast) btnLast.addEventListener('click', () => goTo(totalPages));
            if (btnPrev) btnPrev.addEventListener('click', () => $('#flipbook').turn('previous'));
            if (btnNext) btnNext.addEventListener('click', () => $('#flipbook').turn('next'));
            if (btnSearch) btnSearch.addEventListener('click', runSearch);
            if (menuToggle) {
                menuToggle.addEventListener('click', () => {
                    const isOpen = document.getElementById('viewer-panel').classList.contains('is-open');
                    setMenuOpen(!isOpen);
                });
            }
            if (btnToggleSearch) {
                btnToggleSearch.addEventListener('click', () => {
                    const isOpen = document.getElementById('search-panel').classList.contains('is-open');
                    setSearchPanelOpen(!isOpen);
                    if (!isOpen && searchInput) {
                        setTimeout(() => searchInput.focus(), 10);
                    }
                });
            }
            if (searchInput) {
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        runSearch();
                    }
                });
            }

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
                    const flipbook = document.getElementById('flipbook');

                    if (page == 1 || event == 'previous') {
                    flipbook.style.right = isNarrow() ? '' : '15%';
                    flipbook.style.left = '';
                    } else {
                    flipbook.style.right = '';
                    flipbook.style.left = '';
                    }

                    syncPageUi(view);

                    if (view[0] && view[1]) {
                    flipbook.classList.remove('single-page');
                    } else if (view[0]) {
                    flipbook.classList.add('single-page');
                    flipbook.style.left = isNarrow() ? '' : '14%';
                    }

                    requestAnimationFrame(applyLinks);
                }
                }
            });

            syncPageUi();
            loadSearchIndex(totalPages);
            setSearchPanelOpen(false);
            setMenuOpen(false);
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
                const flipbookEl = document.getElementById('flipbook');
                if (flipbookEl) {
                    flipbookEl.addEventListener('wheel', function (e) {
                        if (scrollDebounce) return;
                        scrollDebounce = true;
                        setTimeout(() => (scrollDebounce = false), 400);
                        e.preventDefault();
                        if (e.deltaY > 0) $('#flipbook').turn('next');
                        else $('#flipbook').turn('previous');
                    }, { passive: false });
                }
            }
        });
    </script>
    </body>
    </html>
    HTML;

        return $head.$pages.$tail;
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
        $html = $this->buildIndexHtml($totalPages, $linkMap, 'source.pdf');
        file_put_contents($ebookDir . 'index.html', $html);

        // ZIP은 다운로드 요청 시점에만 임시 생성
        $zipPath = tempnam(sys_get_temp_dir(), 'ebook_');
        if ($zipPath === false) {
            http_response_code(500);
            echo "임시 ZIP 파일 생성 실패";
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
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
        @unlink($zipPath);
        exit;
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
