(function () {
    const page = document.querySelector('.tool-detail-page');
    if (!page) {
        return;
    }

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

    document.addEventListener('click', function (event) {
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
})();
