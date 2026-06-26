(function () {
    const root = document.querySelector('.wy-tools');
    const page = document.querySelector('.tool-detail-page');
    const storageKeys = {
        favorites: 'wy_tools_favorites',
        recent: 'wy_tools_recent',
        theme: 'wy_tools_theme'
    };

    function readJson(key, fallback) {
        try {
            return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback));
        } catch (error) {
            return fallback;
        }
    }

    function writeJson(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    }

    function toolIndex() {
        if (!root) return [];
        try {
            return JSON.parse(root.dataset.toolsIndex || '[]');
        } catch (error) {
            return [];
        }
    }

    function applyTheme() {
        if (!root) return;
        root.classList.toggle('is-dark', localStorage.getItem(storageKeys.theme) === 'dark');
    }

    function initTheme() {
        applyTheme();
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                const next = localStorage.getItem(storageKeys.theme) === 'dark' ? 'light' : 'dark';
                localStorage.setItem(storageKeys.theme, next);
                applyTheme();
            });
        });
    }

    function initSearch() {
        const input = document.querySelector('[data-tool-search]');
        const suggestions = document.querySelector('[data-search-suggestions]');
        if (!input || !suggestions) return;
        const index = toolIndex();

        input.addEventListener('input', function () {
            const query = input.value.trim().toLowerCase();
            suggestions.textContent = '';
            if (!query) {
                suggestions.classList.remove('is-open');
                return;
            }
            const matches = index.filter(function (item) {
                return [item.name, item.category, item.summary, item.keywords].join(' ').toLowerCase().includes(query);
            }).slice(0, 8);

            matches.forEach(function (item) {
                const link = document.createElement('a');
                link.href = item.url;
                link.textContent = item.name;
                const desc = document.createElement('span');
                desc.textContent = item.summary;
                link.appendChild(desc);
                suggestions.appendChild(link);
            });
            suggestions.classList.toggle('is-open', matches.length > 0);
        });
    }

    function initFavorites() {
        const favorites = readJson(storageKeys.favorites, []);
        document.querySelectorAll('[data-favorite]').forEach(function (button) {
            const slug = button.dataset.favorite;
            button.classList.toggle('is-active', favorites.includes(slug));
            button.addEventListener('click', function (event) {
                event.preventDefault();
                const next = readJson(storageKeys.favorites, []);
                const index = next.indexOf(slug);
                if (index >= 0) next.splice(index, 1);
                else next.unshift(slug);
                writeJson(storageKeys.favorites, next.slice(0, 80));
                button.classList.toggle('is-active', next.includes(slug));
            });
        });
    }

    function initRecent() {
        if (page && page.dataset.toolSlug) {
            const index = toolIndex();
            const current = index.find(function (item) { return item.slug === page.dataset.toolSlug; });
            if (current) {
                const recent = readJson(storageKeys.recent, []).filter(function (item) {
                    return item.slug !== current.slug;
                });
                recent.unshift(current);
                writeJson(storageKeys.recent, recent.slice(0, 8));
            }
        }

        const target = document.querySelector('[data-recent-tools]');
        if (!target) return;
        const recentTools = readJson(storageKeys.recent, []);
        target.textContent = '';
        recentTools.slice(0, 6).forEach(function (item) {
            const link = document.createElement('a');
            link.href = item.url;
            const name = document.createElement('span');
            name.textContent = item.name;
            const category = document.createElement('em');
            category.textContent = item.category;
            link.appendChild(name);
            link.appendChild(category);
            target.appendChild(link);
        });
    }

    initTheme();
    initSearch();
    initFavorites();
    initRecent();

    if (!page) return;

    const slug = page.dataset.tool;
    const statusEl = document.getElementById('tool-status');

    function setStatus(message, type) {
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-error', 'is-success');
        if (type) statusEl.classList.add('is-' + type);
    }

    function getValue(selector) {
        const el = document.querySelector(selector);
        return el ? el.value : '';
    }

    function setValue(selector, value) {
        const el = document.querySelector(selector);
        if (el) el.value = value;
    }

    function copyTarget(selector) {
        const el = document.querySelector(selector);
        if (!el) return;
        const text = 'value' in el ? el.value : el.textContent;
        navigator.clipboard.writeText(text || '').then(function () {
            setStatus('복사되었습니다.', 'success');
        }).catch(function () {
            setStatus('클립보드 복사에 실패했습니다.', 'error');
        });
    }

    function utf8ToBase64(text) {
        const bytes = new TextEncoder().encode(text);
        let binary = '';
        bytes.forEach(function (byte) {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary);
    }

    function base64ToUtf8(text) {
        const binary = atob(text);
        const bytes = Uint8Array.from(binary, function (char) {
            return char.charCodeAt(0);
        });
        return new TextDecoder().decode(bytes);
    }

    function randomInt(max) {
        const array = new Uint32Array(1);
        window.crypto.getRandomValues(array);
        return array[0] % max;
    }

    function makeUuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, function (c) {
            return (Number(c) ^ randomInt(256) & 15 >> Number(c) / 4).toString(16);
        });
    }

    function bytesToHex(buffer) {
        return Array.from(new Uint8Array(buffer)).map(function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    function parseHex(input) {
        let hex = input.trim().replace(/^#/, '');
        if (/^[0-9a-fA-F]{3}$/.test(hex)) {
            hex = hex.split('').map(function (char) {
                return char + char;
            }).join('');
        }
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
        return {
            hex: '#' + hex.toLowerCase(),
            r: parseInt(hex.slice(0, 2), 16),
            g: parseInt(hex.slice(2, 4), 16),
            b: parseInt(hex.slice(4, 6), 16)
        };
    }

    function rgbToHsl(r, g, b) {
        r /= 255;
        g /= 255;
        b /= 255;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        let h = 0;
        let s = 0;
        const l = (max + min) / 2;

        if (max !== min) {
            const d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h /= 6;
        }

        return { h: Math.round(h * 360), s: Math.round(s * 100), l: Math.round(l * 100) };
    }

    function convertTimestamp() {
        const raw = getValue('#timestamp-input').trim();
        if (!/^-?\d+$/.test(raw)) throw new Error('숫자 timestamp를 입력해 주세요.');
        const number = Number(raw);
        const ms = Math.abs(number) > 9999999999 ? number : number * 1000;
        const date = new Date(ms);
        if (Number.isNaN(date.getTime())) throw new Error('변환할 수 없는 timestamp입니다.');
        document.getElementById('timestamp-local').textContent = date.toLocaleString();
        document.getElementById('timestamp-utc').textContent = date.toISOString();
        setStatus('날짜로 변환되었습니다.', 'success');
    }

    function generatePassword() {
        const groups = [
            ['#pw-upper', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'],
            ['#pw-lower', 'abcdefghijklmnopqrstuvwxyz'],
            ['#pw-number', '0123456789'],
            ['#pw-symbol', '!@#$%^&*()-_=+[]{};:,.?']
        ];
        const selected = groups.filter(function (group) {
            const el = document.querySelector(group[0]);
            return el && el.checked;
        });
        if (!selected.length) throw new Error('최소 하나 이상의 문자 종류를 선택해 주세요.');
        const length = Math.min(Math.max(parseInt(getValue('#password-length'), 10) || 16, 8), 128);
        const chars = selected.map(function (group) { return group[1]; }).join('');
        let password = selected.map(function (group) {
            return group[1][randomInt(group[1].length)];
        }).join('');
        while (password.length < length) password += chars[randomInt(chars.length)];
        setValue('#password-output', password.split('').sort(function () { return randomInt(3) - 1; }).join(''));
        setStatus('비밀번호를 생성했습니다.', 'success');
    }

    async function generateHash() {
        const algorithm = getValue('#hash-algorithm');
        const buffer = await window.crypto.subtle.digest(algorithm, new TextEncoder().encode(getValue('#tool-input')));
        setValue('#tool-output', bytesToHex(buffer));
        setStatus(algorithm + ' 해시를 생성했습니다.', 'success');
    }

    function generateQr() {
        const text = getValue('#tool-input').trim();
        if (!text) throw new Error('QR 코드로 만들 값을 입력해 주세요.');
        if (!window.QRCode) throw new Error('QR 코드 라이브러리를 불러오지 못했습니다.');
        const preview = document.getElementById('qr-preview');
        preview.textContent = '';
        new window.QRCode(preview, {
            text: text,
            width: 240,
            height: 240,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M
        });
        setStatus('QR 코드가 생성되었습니다.', 'success');
    }

    function downloadQr() {
        const preview = document.getElementById('qr-preview');
        const canvas = preview ? preview.querySelector('canvas') : null;
        const image = preview ? preview.querySelector('img') : null;
        const dataUrl = canvas ? canvas.toDataURL('image/png') : (image ? image.src : '');
        if (!dataUrl) {
            setStatus('먼저 QR 코드를 생성해 주세요.', 'error');
            return;
        }
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = 'qr-code.png';
        link.click();
    }

    function analyzeCron(expression) {
        const parts = expression.trim().split(/\s+/);
        if (parts.length !== 5) throw new Error('5개 필드 형식이어야 합니다. 예: 0 9 * * 1-5');
        const labels = ['Minute', 'Hour', 'Day of month', 'Month', 'Day of week'];
        return parts.map(function (part, index) {
            let meaning = part;
            if (part === '*') meaning = 'every value';
            else if (part.includes('/')) meaning = 'step: ' + part;
            else if (part.includes('-')) meaning = 'range: ' + part;
            else if (part.includes(',')) meaning = 'list: ' + part;
            return { label: labels[index], meaning: meaning };
        });
    }

    function renderCronAnalysis(items) {
        const output = document.getElementById('cron-output');
        output.textContent = '';
        items.forEach(function (item) {
            const row = document.createElement('p');
            const label = document.createElement('strong');
            label.textContent = item.label;
            row.appendChild(label);
            row.appendChild(document.createTextNode(': ' + item.meaning));
            output.appendChild(row);
        });
    }

    function convertColor(input) {
        const parsed = parseHex(input);
        if (!parsed) throw new Error('HEX 색상 값을 입력해 주세요. 예: #005bac');
        const hsl = rgbToHsl(parsed.r, parsed.g, parsed.b);
        document.getElementById('color-preview').style.backgroundColor = parsed.hex;
        document.getElementById('color-hex').textContent = parsed.hex;
        document.getElementById('color-rgb').textContent = 'rgb(' + parsed.r + ', ' + parsed.g + ', ' + parsed.b + ')';
        document.getElementById('color-hsl').textContent = 'hsl(' + hsl.h + ', ' + hsl.s + '%, ' + hsl.l + '%)';
        setValue('#hex-input', parsed.hex);
        const picker = document.getElementById('color-picker');
        if (picker) picker.value = parsed.hex;
        setStatus('색상 값을 변환했습니다.', 'success');
    }

    function formatNumber(number) {
        if (!Number.isFinite(number)) return '-';
        return new Intl.NumberFormat('ko-KR', { maximumFractionDigits: 2 }).format(number);
    }

    function countWords() {
        const text = getValue('#tool-input');
        const trimmed = text.trim();
        const words = trimmed ? trimmed.split(/\s+/).filter(Boolean).length : 0;
        const lines = text ? text.split(/\r\n|\r|\n/).length : 0;
        const paragraphs = trimmed ? trimmed.split(/\n\s*\n/).filter(function (part) { return part.trim(); }).length : 0;
        const sentences = trimmed ? (trimmed.match(/[^.!?。！？]+[.!?。！？]*/g) || []).filter(function (part) { return part.trim(); }).length : 0;
        const bytes = new TextEncoder().encode(text).length;
        const pairs = [
            ['#wc-chars', text.length],
            ['#wc-words', words],
            ['#wc-lines', lines],
            ['#wc-sentences', sentences],
            ['#wc-paragraphs', paragraphs],
            ['#wc-bytes', bytes]
        ];
        pairs.forEach(function (pair) {
            const el = document.querySelector(pair[0]);
            if (el) el.textContent = formatNumber(pair[1]);
        });
    }

    function toTitleCase(text) {
        return text.toLowerCase().replace(/\b([a-z])/g, function (match) {
            return match.toUpperCase();
        });
    }

    function toSentenceCase(text) {
        return text.toLowerCase().replace(/(^\s*[a-zA-Z])|([.!?]\s+[a-zA-Z])/g, function (match) {
            return match.toUpperCase();
        });
    }

    function dedupeLines() {
        const trimLines = document.getElementById('dedupe-trim')?.checked;
        const caseSensitive = document.getElementById('dedupe-case')?.checked;
        const keepEmpty = document.getElementById('dedupe-empty')?.checked;
        const seen = new Set();
        const result = [];
        getValue('#tool-input').split(/\r\n|\r|\n/).forEach(function (line) {
            const value = trimLines ? line.trim() : line;
            if (!keepEmpty && value === '') return;
            const key = caseSensitive ? value : value.toLowerCase();
            if (seen.has(key)) return;
            seen.add(key);
            result.push(value);
        });
        setValue('#tool-output', result.join('\n'));
        setStatus(result.length + '개 줄을 남겼습니다.', 'success');
    }

    function generateLorem() {
        const source = [
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer vitae sem sed nibh facilisis facilisis.',
            'Praesent non lacus at erat fermentum tincidunt. Donec sed sapien vitae lorem cursus luctus.',
            'Aliquam erat volutpat. Curabitur feugiat, nibh vitae consequat gravida, lorem justo posuere erat.',
            'Sed porta arcu ac mi tincidunt, quis pulvinar lectus gravida. Nulla facilisi.',
            'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae.'
        ];
        const count = Math.min(Math.max(parseInt(getValue('#lorem-count'), 10) || 3, 1), 20);
        const paragraphs = Array.from({ length: count }, function (_, index) {
            return source.slice(index % source.length).concat(source.slice(0, index % source.length)).slice(0, 3).join(' ');
        });
        setValue('#tool-output', paragraphs.join('\n\n'));
        setStatus(count + '개 문단을 생성했습니다.', 'success');
    }

    function calculatePercent() {
        const base = Number(getValue('#percent-base'));
        const rate = Number(getValue('#percent-rate'));
        if (!Number.isFinite(base) || !Number.isFinite(rate)) throw new Error('숫자를 입력해 주세요.');
        const value = base * rate / 100;
        document.getElementById('percent-value').textContent = formatNumber(value);
        document.getElementById('percent-added').textContent = formatNumber(base + value);
        document.getElementById('percent-discounted').textContent = formatNumber(base - value);
        setStatus('퍼센트를 계산했습니다.', 'success');
    }

    function calculateVat(mode) {
        const amount = Number(getValue('#vat-amount'));
        const rate = Number(getValue('#vat-rate'));
        if (!Number.isFinite(amount) || !Number.isFinite(rate)) throw new Error('금액과 세율을 입력해 주세요.');
        let supply;
        let tax;
        let total;
        if (mode === 'total') {
            total = amount;
            supply = total / (1 + rate / 100);
            tax = total - supply;
        } else {
            supply = amount;
            tax = supply * rate / 100;
            total = supply + tax;
        }
        document.getElementById('vat-supply').textContent = formatNumber(supply);
        document.getElementById('vat-tax').textContent = formatNumber(tax);
        document.getElementById('vat-total').textContent = formatNumber(total);
        setStatus('부가세를 계산했습니다.', 'success');
    }

    function dateOnly(value) {
        if (!value) return null;
        const date = new Date(value + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function calculateDday() {
        const target = dateOnly(getValue('#dday-date'));
        if (!target) throw new Error('목표 날짜를 선택해 주세요.');
        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const diff = Math.round((target.getTime() - start.getTime()) / 86400000);
        document.getElementById('dday-result').textContent = diff === 0 ? 'D-Day' : (diff > 0 ? 'D-' + diff : 'D+' + Math.abs(diff));
        document.getElementById('dday-target').textContent = target.toLocaleDateString();
        setStatus('D-Day를 계산했습니다.', 'success');
    }

    function calculateAge() {
        const birth = dateOnly(getValue('#birth-date'));
        const base = dateOnly(getValue('#age-base-date')) || new Date();
        if (!birth) throw new Error('생년월일을 선택해 주세요.');
        if (birth > base) throw new Error('생년월일은 기준일보다 이전이어야 합니다.');
        let years = base.getFullYear() - birth.getFullYear();
        const birthdayThisYear = new Date(base.getFullYear(), birth.getMonth(), birth.getDate());
        if (base < birthdayThisYear) years -= 1;
        const months = (base.getFullYear() - birth.getFullYear()) * 12 + base.getMonth() - birth.getMonth() - (base.getDate() < birth.getDate() ? 1 : 0);
        const days = Math.floor((base.getTime() - birth.getTime()) / 86400000);
        document.getElementById('age-years').textContent = formatNumber(years);
        document.getElementById('age-months').textContent = formatNumber(Math.max(months, 0));
        document.getElementById('age-days').textContent = formatNumber(days);
        setStatus('나이를 계산했습니다.', 'success');
    }

    document.addEventListener('click', function (event) {
        const shareButton = event.target.closest('[data-share]');
        if (shareButton) {
            if (navigator.share) {
                navigator.share({ title: document.title, url: location.href }).catch(function () {});
            } else {
                navigator.clipboard.writeText(location.href).then(function () {
                    setStatus('URL이 복사되었습니다.', 'success');
                });
            }
            return;
        }

        const downloadButton = event.target.closest('[data-download]');
        if (downloadButton) {
            const selector = downloadButton.dataset.download;
            const el = document.querySelector(selector);
            const text = el && 'value' in el ? el.value : '';
            const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = (page.dataset.toolSlug || 'wy-tool') + '.txt';
            link.click();
            URL.revokeObjectURL(link.href);
            setStatus('파일을 다운로드했습니다.', 'success');
            return;
        }

        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            copyTarget(copyButton.dataset.copy);
            return;
        }

        const preset = event.target.closest('[data-cron]');
        if (preset) {
            setValue('#cron-input', preset.dataset.cron);
            setStatus('');
            return;
        }

        const button = event.target.closest('[data-action]');
        if (!button) return;

        try {
            const action = button.dataset.action;
            if (action === 'json-format') {
                setValue('#tool-output', JSON.stringify(JSON.parse(getValue('#tool-input')), null, 2));
                setStatus('유효한 JSON입니다.', 'success');
            } else if (action === 'json-minify') {
                setValue('#tool-output', JSON.stringify(JSON.parse(getValue('#tool-input'))));
                setStatus('유효한 JSON입니다.', 'success');
            } else if (action === 'base64-encode') {
                setValue('#tool-output', utf8ToBase64(getValue('#tool-input')));
                setStatus('인코딩되었습니다.', 'success');
            } else if (action === 'base64-decode') {
                setValue('#tool-output', base64ToUtf8(getValue('#tool-input').trim()));
                setStatus('디코딩되었습니다.', 'success');
            } else if (action === 'url-encode') {
                setValue('#tool-output', encodeURIComponent(getValue('#tool-input')));
                setStatus('인코딩되었습니다.', 'success');
            } else if (action === 'url-decode') {
                setValue('#tool-output', decodeURIComponent(getValue('#tool-input')));
                setStatus('디코딩되었습니다.', 'success');
            } else if (action === 'timestamp-now') {
                setValue('#timestamp-input', String(Math.floor(Date.now() / 1000)));
                convertTimestamp();
            } else if (action === 'timestamp-to-date') {
                convertTimestamp();
            } else if (action === 'date-to-timestamp') {
                const date = new Date(getValue('#date-input'));
                if (Number.isNaN(date.getTime())) throw new Error('날짜 시간을 입력해 주세요.');
                setValue('#date-timestamp-output', String(Math.floor(date.getTime() / 1000)));
                setStatus('타임스탬프로 변환되었습니다.', 'success');
            } else if (action === 'uuid-generate') {
                const count = Math.min(Math.max(parseInt(getValue('#uuid-count'), 10) || 1, 1), 50);
                setValue('#tool-output', Array.from({ length: count }, makeUuid).join('\n'));
                setStatus(count + '개 UUID를 생성했습니다.', 'success');
            } else if (action === 'password-generate') {
                generatePassword();
            } else if (action === 'hash-generate') {
                generateHash();
            } else if (action === 'qr-generate') {
                generateQr();
            } else if (action === 'qr-download') {
                downloadQr();
            } else if (action === 'cron-analyze') {
                renderCronAnalysis(analyzeCron(getValue('#cron-input')));
                setStatus('Cron 표현식을 분석했습니다.', 'success');
            } else if (action === 'color-convert') {
                convertColor(getValue('#hex-input'));
            } else if (action === 'case-upper') {
                setValue('#tool-output', getValue('#tool-input').toUpperCase());
                setStatus('대문자로 변환했습니다.', 'success');
            } else if (action === 'case-lower') {
                setValue('#tool-output', getValue('#tool-input').toLowerCase());
                setStatus('소문자로 변환했습니다.', 'success');
            } else if (action === 'case-title') {
                setValue('#tool-output', toTitleCase(getValue('#tool-input')));
                setStatus('Title Case로 변환했습니다.', 'success');
            } else if (action === 'case-sentence') {
                setValue('#tool-output', toSentenceCase(getValue('#tool-input')));
                setStatus('Sentence case로 변환했습니다.', 'success');
            } else if (action === 'dedupe-lines') {
                dedupeLines();
            } else if (action === 'lorem-generate') {
                generateLorem();
            } else if (action === 'percent-calculate') {
                calculatePercent();
            } else if (action === 'vat-from-supply') {
                calculateVat('supply');
            } else if (action === 'vat-from-total') {
                calculateVat('total');
            } else if (action === 'dday-calculate') {
                calculateDday();
            } else if (action === 'age-calculate') {
                calculateAge();
            } else if (action === 'generic-copy') {
                setValue('#tool-output', getValue('#tool-input'));
                setStatus('입력값을 결과 영역에 준비했습니다.', 'success');
            }
        } catch (error) {
            setStatus(error.message, 'error');
        }
    });

    const colorPicker = document.getElementById('color-picker');
    if (colorPicker) {
        colorPicker.addEventListener('input', function () {
            setValue('#hex-input', colorPicker.value);
            convertColor(colorPicker.value);
        });
    }

    if (slug === 'timestamp') {
        const current = document.getElementById('current-timestamp');
        const update = function () { current.textContent = String(Math.floor(Date.now() / 1000)); };
        update();
        window.setInterval(update, 1000);
    }
    if (slug === 'uuid') document.querySelector('[data-action="uuid-generate"]').click();
    if (slug === 'password-generator') document.querySelector('[data-action="password-generate"]').click();
    if (slug === 'cron-helper') document.querySelector('[data-action="cron-analyze"]').click();
    if (slug === 'color-converter') convertColor(getValue('#hex-input'));
    if (slug === 'word-counter') {
        const input = document.getElementById('tool-input');
        if (input) input.addEventListener('input', countWords);
        countWords();
    }
    if (slug === 'lorem-ipsum') generateLorem();
    if (slug === 'age-calculator') {
        const baseDate = document.getElementById('age-base-date');
        if (baseDate) baseDate.value = new Date().toISOString().slice(0, 10);
    }
})();
