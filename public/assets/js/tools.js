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

    function normalizeCategory(category) {
        return String(category || '').replace(/-/g, ' ').replace(/\b\w/g, function (char) {
            return char.toUpperCase();
        });
    }

    function findTool(slug) {
        return toolIndex().find(function (item) {
            return item.slug === slug;
        });
    }

    function renderToolList(target, items, emptyMessage) {
        target.textContent = '';
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'compact-empty';
            empty.textContent = emptyMessage;
            target.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            const link = document.createElement('a');
            link.href = item.url;
            const name = document.createElement('span');
            name.textContent = item.name;
            const category = document.createElement('em');
            category.textContent = normalizeCategory(item.category);
            link.appendChild(name);
            link.appendChild(category);
            target.appendChild(link);
        });
    }

    function renderFavorites() {
        const favorites = readJson(storageKeys.favorites, []);
        document.querySelectorAll('[data-favorite-tools]').forEach(function (target) {
            const items = favorites.map(findTool).filter(Boolean).slice(0, 6);
            renderToolList(target, items, '즐겨찾기한 도구가 없습니다.');
        });
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

        function closeSuggestions() {
            suggestions.classList.remove('is-open');
        }

        input.addEventListener('input', function () {
            const query = input.value.trim().toLowerCase();
            suggestions.textContent = '';
            if (!query) {
                closeSuggestions();
                return;
            }
            const matches = index.filter(function (item) {
                return [item.name, item.category, item.summary, item.keywords].join(' ').toLowerCase().includes(query);
            }).slice(0, 8);

            if (!matches.length) {
                const empty = document.createElement('div');
                empty.className = 'search-empty';
                empty.textContent = '검색 결과가 없습니다.';
                suggestions.appendChild(empty);
                suggestions.classList.add('is-open');
                return;
            }

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

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeSuggestions();
            if (event.key === 'Enter') {
                const first = suggestions.querySelector('a');
                if (first) {
                    event.preventDefault();
                    first.click();
                }
            }
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.wy-search')) closeSuggestions();
        });
    }

    function initFavorites() {
        const favorites = readJson(storageKeys.favorites, []);
        document.querySelectorAll('[data-favorite]').forEach(function (button) {
            const slug = button.dataset.favorite;
            const setButtonState = function () {
                const isActive = readJson(storageKeys.favorites, []).includes(slug);
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                if (!button.classList.contains('favorite-btn')) {
                    button.textContent = isActive ? 'Favorited' : 'Favorite';
                }
            };
            setButtonState();
            button.addEventListener('click', function (event) {
                event.preventDefault();
                const next = readJson(storageKeys.favorites, []);
                const index = next.indexOf(slug);
                if (index >= 0) next.splice(index, 1);
                else next.unshift(slug);
                writeJson(storageKeys.favorites, next.slice(0, 80));
                document.querySelectorAll('[data-favorite="' + slug + '"]').forEach(function (sameButton) {
                    const isActive = next.includes(slug);
                    sameButton.classList.toggle('is-active', isActive);
                    sameButton.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    if (!sameButton.classList.contains('favorite-btn')) {
                        sameButton.textContent = isActive ? 'Favorited' : 'Favorite';
                    }
                });
                renderFavorites();
            });
        });
        renderFavorites();
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

        const recentTools = readJson(storageKeys.recent, []);
        document.querySelectorAll('[data-recent-tools]').forEach(function (target) {
            renderToolList(target, recentTools.slice(0, 6), '최근 사용한 도구가 없습니다.');
        });
    }

    function initRelatedClickTracking() {
        document.querySelectorAll('[data-related-click]').forEach(function (link) {
            link.addEventListener('click', function () {
                const source = link.dataset.sourceTool || '';
                const target = link.dataset.targetTool || '';
                if (!source || !target) return;

                const payload = new URLSearchParams();
                payload.set('source', source);
                payload.set('target', target);
                payload.set('context', link.dataset.clickContext || 'related');
                payload.set('path', window.location.pathname);

                if (navigator.sendBeacon) {
                    navigator.sendBeacon('/tools/related-click', payload);
                    return;
                }

                fetch('/tools/related-click', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: payload.toString(),
                    keepalive: true
                }).catch(function () {});
            });
        });
    }

    initTheme();
    initSearch();
    initFavorites();
    initRecent();
    initRelatedClickTracking();

    if (!page) return;

    const slug = page.dataset.tool;
    const statusEl = document.getElementById('tool-status');
    let processedImage = null;
    let processedPdf = null;

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

    function base64UrlToUtf8(text) {
        const normalized = text.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(text.length / 4) * 4, '=');
        return base64ToUtf8(normalized);
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

    function md5(text) {
        function rotateLeft(value, shift) { return (value << shift) | (value >>> (32 - shift)); }
        function add(x, y) { return (x + y) & 0xffffffff; }
        function cmn(q, a, b, x, s, t) { return add(rotateLeft(add(add(a, q), add(x, t)), s), b); }
        function ff(a, b, c, d, x, s, t) { return cmn((b & c) | ((~b) & d), a, b, x, s, t); }
        function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & (~d)), a, b, x, s, t); }
        function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
        function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | (~d)), a, b, x, s, t); }
        function toWords(input) {
            const bytes = new TextEncoder().encode(input);
            const words = [];
            for (let i = 0; i < bytes.length; i += 1) words[i >> 2] |= bytes[i] << ((i % 4) * 8);
            words[bytes.length >> 2] |= 0x80 << ((bytes.length % 4) * 8);
            words[(((bytes.length + 8) >> 6) + 1) * 16 - 2] = bytes.length * 8;
            return words;
        }
        function hex(value) {
            let output = '';
            for (let i = 0; i < 4; i += 1) output += ((value >> (i * 8)) & 255).toString(16).padStart(2, '0');
            return output;
        }
        const x = toWords(text);
        let a = 1732584193;
        let b = -271733879;
        let c = -1732584194;
        let d = 271733878;
        for (let i = 0; i < x.length; i += 16) {
            const olda = a, oldb = b, oldc = c, oldd = d;
            a = ff(a, b, c, d, x[i], 7, -680876936); d = ff(d, a, b, c, x[i + 1], 12, -389564586); c = ff(c, d, a, b, x[i + 2], 17, 606105819); b = ff(b, c, d, a, x[i + 3], 22, -1044525330);
            a = ff(a, b, c, d, x[i + 4], 7, -176418897); d = ff(d, a, b, c, x[i + 5], 12, 1200080426); c = ff(c, d, a, b, x[i + 6], 17, -1473231341); b = ff(b, c, d, a, x[i + 7], 22, -45705983);
            a = ff(a, b, c, d, x[i + 8], 7, 1770035416); d = ff(d, a, b, c, x[i + 9], 12, -1958414417); c = ff(c, d, a, b, x[i + 10], 17, -42063); b = ff(b, c, d, a, x[i + 11], 22, -1990404162);
            a = ff(a, b, c, d, x[i + 12], 7, 1804603682); d = ff(d, a, b, c, x[i + 13], 12, -40341101); c = ff(c, d, a, b, x[i + 14], 17, -1502002290); b = ff(b, c, d, a, x[i + 15], 22, 1236535329);
            a = gg(a, b, c, d, x[i + 1], 5, -165796510); d = gg(d, a, b, c, x[i + 6], 9, -1069501632); c = gg(c, d, a, b, x[i + 11], 14, 643717713); b = gg(b, c, d, a, x[i], 20, -373897302);
            a = gg(a, b, c, d, x[i + 5], 5, -701558691); d = gg(d, a, b, c, x[i + 10], 9, 38016083); c = gg(c, d, a, b, x[i + 15], 14, -660478335); b = gg(b, c, d, a, x[i + 4], 20, -405537848);
            a = gg(a, b, c, d, x[i + 9], 5, 568446438); d = gg(d, a, b, c, x[i + 14], 9, -1019803690); c = gg(c, d, a, b, x[i + 3], 14, -187363961); b = gg(b, c, d, a, x[i + 8], 20, 1163531501);
            a = gg(a, b, c, d, x[i + 13], 5, -1444681467); d = gg(d, a, b, c, x[i + 2], 9, -51403784); c = gg(c, d, a, b, x[i + 7], 14, 1735328473); b = gg(b, c, d, a, x[i + 12], 20, -1926607734);
            a = hh(a, b, c, d, x[i + 5], 4, -378558); d = hh(d, a, b, c, x[i + 8], 11, -2022574463); c = hh(c, d, a, b, x[i + 11], 16, 1839030562); b = hh(b, c, d, a, x[i + 14], 23, -35309556);
            a = hh(a, b, c, d, x[i + 1], 4, -1530992060); d = hh(d, a, b, c, x[i + 4], 11, 1272893353); c = hh(c, d, a, b, x[i + 7], 16, -155497632); b = hh(b, c, d, a, x[i + 10], 23, -1094730640);
            a = hh(a, b, c, d, x[i + 13], 4, 681279174); d = hh(d, a, b, c, x[i], 11, -358537222); c = hh(c, d, a, b, x[i + 3], 16, -722521979); b = hh(b, c, d, a, x[i + 6], 23, 76029189);
            a = hh(a, b, c, d, x[i + 9], 4, -640364487); d = hh(d, a, b, c, x[i + 12], 11, -421815835); c = hh(c, d, a, b, x[i + 15], 16, 530742520); b = hh(b, c, d, a, x[i + 2], 23, -995338651);
            a = ii(a, b, c, d, x[i], 6, -198630844); d = ii(d, a, b, c, x[i + 7], 10, 1126891415); c = ii(c, d, a, b, x[i + 14], 15, -1416354905); b = ii(b, c, d, a, x[i + 5], 21, -57434055);
            a = ii(a, b, c, d, x[i + 12], 6, 1700485571); d = ii(d, a, b, c, x[i + 3], 10, -1894986606); c = ii(c, d, a, b, x[i + 10], 15, -1051523); b = ii(b, c, d, a, x[i + 1], 21, -2054922799);
            a = ii(a, b, c, d, x[i + 8], 6, 1873313359); d = ii(d, a, b, c, x[i + 15], 10, -30611744); c = ii(c, d, a, b, x[i + 6], 15, -1560198380); b = ii(b, c, d, a, x[i + 13], 21, 1309151649);
            a = ii(a, b, c, d, x[i + 4], 6, -145523070); d = ii(d, a, b, c, x[i + 11], 10, -1120210379); c = ii(c, d, a, b, x[i + 2], 15, 718787259); b = ii(b, c, d, a, x[i + 9], 21, -343485551);
            a = add(a, olda); b = add(b, oldb); c = add(c, oldc); d = add(d, oldd);
        }
        return hex(a) + hex(b) + hex(c) + hex(d);
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

    function validateJson() {
        const parsed = JSON.parse(getValue('#tool-input'));
        const type = Array.isArray(parsed) ? 'array' : typeof parsed;
        const status = document.getElementById('json-valid-status');
        const typeEl = document.getElementById('json-valid-type');
        if (status) status.textContent = 'Valid JSON';
        if (typeEl) typeEl.textContent = type;
        setValue('#tool-output', JSON.stringify(parsed, null, 2));
        setStatus('유효한 JSON입니다.', 'success');
    }

    function decodeJwt() {
        const token = getValue('#jwt-input').trim();
        const parts = token.split('.');
        if (parts.length < 2) throw new Error('JWT는 header.payload.signature 형식이어야 합니다.');
        const header = JSON.parse(base64UrlToUtf8(parts[0]));
        const payload = JSON.parse(base64UrlToUtf8(parts[1]));
        setValue('#jwt-header', JSON.stringify(header, null, 2));
        setValue('#jwt-payload', JSON.stringify(payload, null, 2));
        setStatus(parts[2] ? 'JWT를 디코딩했습니다. 서명 검증은 수행하지 않습니다.' : 'Header와 payload를 디코딩했습니다.', 'success');
    }

    function testRegex() {
        const pattern = getValue('#regex-pattern');
        const flags = getValue('#regex-flags');
        const text = getValue('#tool-input');
        const regex = new RegExp(pattern, flags);
        const matches = [];
        if (regex.global) {
            let match;
            while ((match = regex.exec(text)) !== null) {
                matches.push(match[0]);
                if (match[0] === '') regex.lastIndex += 1;
            }
        } else {
            const match = regex.exec(text);
            if (match) matches.push(match[0]);
        }
        document.getElementById('regex-count').textContent = formatNumber(matches.length);
        document.getElementById('regex-status').textContent = matches.length ? 'Matched' : 'No match';
        setValue('#tool-output', matches.join('\n'));
        setStatus(matches.length + '개 매치를 찾았습니다.', matches.length ? 'success' : '');
    }

    function formatSqlText(sql) {
        const keywords = [
            'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT',
            'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'FULL JOIN', 'JOIN',
            'VALUES', 'SET', 'AND', 'OR'
        ];
        let result = sql.replace(/\s+/g, ' ').trim();
        keywords.forEach(function (keyword) {
            const escaped = keyword.replace(/\s+/g, '\\s+');
            const regex = new RegExp('\\b' + escaped + '\\b', 'gi');
            result = result.replace(regex, '\n' + keyword);
        });
        result = result.replace(/,\s*/g, ',\n    ');
        result = result.replace(/^\n/, '');
        return result.split('\n').map(function (line) {
            const trimmed = line.trim();
            if (/^(AND|OR)\b/i.test(trimmed)) return '  ' + trimmed;
            return trimmed;
        }).join('\n');
    }

    function parseCsv(text) {
        const rows = [];
        let row = [];
        let cell = '';
        let quoted = false;
        for (let i = 0; i < text.length; i += 1) {
            const char = text[i];
            const next = text[i + 1];
            if (char === '"' && quoted && next === '"') {
                cell += '"';
                i += 1;
            } else if (char === '"') {
                quoted = !quoted;
            } else if (char === ',' && !quoted) {
                row.push(cell);
                cell = '';
            } else if ((char === '\n' || char === '\r') && !quoted) {
                if (char === '\r' && next === '\n') i += 1;
                row.push(cell);
                rows.push(row);
                row = [];
                cell = '';
            } else {
                cell += char;
            }
        }
        row.push(cell);
        if (row.length > 1 || row[0] !== '') rows.push(row);
        return rows;
    }

    function csvEscape(value) {
        const text = value == null ? '' : String(value);
        return /[",\n\r]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    function jsonToCsv() {
        const parsed = JSON.parse(getValue('#tool-input'));
        const rows = Array.isArray(parsed) ? parsed : [parsed];
        if (!rows.length || typeof rows[0] !== 'object' || rows[0] === null || Array.isArray(rows[0])) {
            throw new Error('객체 또는 객체 배열 JSON을 입력해 주세요.');
        }
        const headers = Array.from(new Set(rows.flatMap(function (row) { return Object.keys(row); })));
        const lines = [headers.map(csvEscape).join(',')];
        rows.forEach(function (row) {
            lines.push(headers.map(function (header) {
                const value = typeof row[header] === 'object' && row[header] !== null ? JSON.stringify(row[header]) : row[header];
                return csvEscape(value);
            }).join(','));
        });
        setValue('#tool-output', lines.join('\n'));
        setStatus(rows.length + '개 행을 CSV로 변환했습니다.', 'success');
    }

    function csvToJson() {
        const rows = parseCsv(getValue('#tool-input'));
        if (rows.length < 1) throw new Error('CSV 데이터를 입력해 주세요.');
        const headers = rows[0].map(function (header) { return header.trim(); });
        const data = rows.slice(1).filter(function (row) {
            return row.some(function (cell) { return cell !== ''; });
        }).map(function (row) {
            return headers.reduce(function (obj, header, index) {
                obj[header || ('column_' + (index + 1))] = row[index] ?? '';
                return obj;
            }, {});
        });
        setValue('#tool-output', JSON.stringify(data, null, 2));
        setStatus(data.length + '개 행을 JSON으로 변환했습니다.', 'success');
        return { headers: headers, rows: rows.slice(1) };
    }

    function renderCsvTable() {
        const parsed = csvToJson();
        const preview = document.getElementById('csv-preview');
        if (!preview) return;
        preview.textContent = '';
        const table = document.createElement('table');
        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        parsed.headers.forEach(function (header) {
            const th = document.createElement('th');
            th.textContent = header;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        parsed.rows.slice(0, 100).forEach(function (row) {
            const tr = document.createElement('tr');
            parsed.headers.forEach(function (_, index) {
                const td = document.createElement('td');
                td.textContent = row[index] ?? '';
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        preview.appendChild(table);
    }

    function escapeXml(value) {
        return String(value).replace(/[<>&'"]/g, function (char) {
            return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' })[char];
        });
    }

    function objectToXml(value, nodeName) {
        if (Array.isArray(value)) {
            return value.map(function (item) { return objectToXml(item, nodeName || 'item'); }).join('');
        }
        if (typeof value === 'object' && value !== null) {
            const name = nodeName || 'root';
            const children = Object.keys(value).map(function (key) {
                return objectToXml(value[key], key.replace(/[^a-zA-Z0-9_-]/g, '_') || 'item');
            }).join('');
            return '<' + name + '>' + children + '</' + name + '>';
        }
        const name = nodeName || 'value';
        return '<' + name + '>' + escapeXml(value == null ? '' : value) + '</' + name + '>';
    }

    function jsonToXml() {
        const parsed = JSON.parse(getValue('#tool-input'));
        setValue('#tool-output', '<?xml version="1.0" encoding="UTF-8"?>\n' + objectToXml(parsed, 'root'));
        setStatus('JSON을 XML로 변환했습니다.', 'success');
    }

    function xmlNodeToObject(node) {
        if (!node.children.length) return node.textContent || '';
        const result = {};
        Array.from(node.children).forEach(function (child) {
            const value = xmlNodeToObject(child);
            if (Object.prototype.hasOwnProperty.call(result, child.nodeName)) {
                if (!Array.isArray(result[child.nodeName])) result[child.nodeName] = [result[child.nodeName]];
                result[child.nodeName].push(value);
            } else {
                result[child.nodeName] = value;
            }
        });
        return result;
    }

    function xmlToJson() {
        const parser = new DOMParser();
        const doc = parser.parseFromString(getValue('#tool-input'), 'application/xml');
        if (doc.querySelector('parsererror')) throw new Error('유효한 XML을 입력해 주세요.');
        const rootNode = doc.documentElement;
        setValue('#tool-output', JSON.stringify({ [rootNode.nodeName]: xmlNodeToObject(rootNode) }, null, 2));
        setStatus('XML을 JSON으로 변환했습니다.', 'success');
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

    function calculateWithholding(mode) {
        const amount = Number(getValue('#withholding-amount'));
        const rate = Number(getValue('#withholding-rate'));
        if (!Number.isFinite(amount) || amount < 0 || !Number.isFinite(rate) || rate < 0 || rate >= 100) {
            throw new Error('금액과 원천징수율을 올바르게 입력해 주세요.');
        }
        let gross;
        let tax;
        let net;
        if (mode === 'net') {
            net = amount;
            gross = net / (1 - rate / 100);
            tax = gross - net;
        } else {
            gross = amount;
            tax = gross * rate / 100;
            net = gross - tax;
        }
        document.getElementById('withholding-gross').textContent = formatNumber(Math.round(gross));
        document.getElementById('withholding-tax').textContent = formatNumber(Math.round(tax));
        document.getElementById('withholding-net').textContent = formatNumber(Math.round(net));
        setStatus('3.3% 원천징수를 계산했습니다.', 'success');
    }

    function calculateSalary() {
        const hourly = Number(getValue('#salary-hourly'));
        const hours = Number(getValue('#salary-hours'));
        const days = Number(getValue('#salary-days'));
        if (![hourly, hours, days].every(function (value) { return Number.isFinite(value) && value >= 0; })) {
            throw new Error('시급, 근무시간, 근무일을 입력해 주세요.');
        }
        const daily = hourly * hours;
        const monthly = daily * days;
        document.getElementById('salary-daily').textContent = formatNumber(Math.round(daily));
        document.getElementById('salary-monthly').textContent = formatNumber(Math.round(monthly));
        document.getElementById('salary-yearly').textContent = formatNumber(Math.round(monthly * 12));
        setStatus('급여를 환산했습니다.', 'success');
    }

    function calculateLoan() {
        const principal = Number(getValue('#loan-principal'));
        const annualRate = Number(getValue('#loan-rate'));
        const months = parseInt(getValue('#loan-months'), 10);
        if (!Number.isFinite(principal) || principal <= 0 || !Number.isFinite(annualRate) || annualRate < 0 || !Number.isFinite(months) || months <= 0) {
            throw new Error('원금, 금리, 기간을 올바르게 입력해 주세요.');
        }
        const monthlyRate = annualRate / 100 / 12;
        const payment = monthlyRate === 0 ? principal / months : principal * monthlyRate * Math.pow(1 + monthlyRate, months) / (Math.pow(1 + monthlyRate, months) - 1);
        const total = payment * months;
        document.getElementById('loan-payment').textContent = formatNumber(Math.round(payment));
        document.getElementById('loan-interest').textContent = formatNumber(Math.round(total - principal));
        document.getElementById('loan-total').textContent = formatNumber(Math.round(total));
        setStatus('원리금균등 기준으로 계산했습니다.', 'success');
    }

    function calculateCompound() {
        const principal = Number(getValue('#compound-principal'));
        const monthly = Number(getValue('#compound-monthly'));
        const annualRate = Number(getValue('#compound-rate'));
        const years = Number(getValue('#compound-years'));
        if (![principal, monthly, annualRate, years].every(function (value) { return Number.isFinite(value); }) || principal < 0 || monthly < 0 || years < 0) {
            throw new Error('초기 금액, 월 납입액, 수익률, 기간을 입력해 주세요.');
        }
        const months = Math.round(years * 12);
        const monthlyRate = annualRate / 100 / 12;
        let balance = principal;
        for (let i = 0; i < months; i += 1) {
            balance = balance * (1 + monthlyRate) + monthly;
        }
        const paid = principal + monthly * months;
        document.getElementById('compound-future').textContent = formatNumber(Math.round(balance));
        document.getElementById('compound-paid').textContent = formatNumber(Math.round(paid));
        document.getElementById('compound-gain').textContent = formatNumber(Math.round(balance - paid));
        setStatus('복리 미래가치를 계산했습니다.', 'success');
    }

    function calculateSplitBill() {
        const total = Number(getValue('#split-total'));
        const people = parseInt(getValue('#split-people'), 10);
        const extraRate = Number(getValue('#split-extra-rate'));
        if (!Number.isFinite(total) || total < 0 || !Number.isFinite(people) || people <= 0 || !Number.isFinite(extraRate)) {
            throw new Error('총액, 인원, 추가 비율을 입력해 주세요.');
        }
        const grandTotal = Math.round(total * (1 + extraRate / 100));
        const perPerson = Math.floor(grandTotal / people);
        const remainder = grandTotal - perPerson * people;
        document.getElementById('split-grand-total').textContent = formatNumber(grandTotal);
        document.getElementById('split-per-person').textContent = formatNumber(perPerson);
        document.getElementById('split-remainder').textContent = formatNumber(remainder);
        setStatus('1인 부담액을 계산했습니다.', 'success');
    }

    function calculateAnnualNetSalary() {
        const annual = Number(getValue('#annual-salary'));
        const monthlyTax = Number(getValue('#annual-tax')) || 0;
        const pensionRate = Number(getValue('#annual-pension-rate'));
        const healthRate = Number(getValue('#annual-health-rate'));
        const careRate = Number(getValue('#annual-care-rate'));
        const employmentRate = Number(getValue('#annual-employment-rate'));
        if (![annual, pensionRate, healthRate, careRate, employmentRate].every(function (value) { return Number.isFinite(value) && value >= 0; }) || annual <= 0) {
            throw new Error('연봉과 공제율을 올바르게 입력해 주세요.');
        }
        const gross = annual / 12;
        const pension = gross * pensionRate / 100;
        const health = gross * healthRate / 100;
        const care = health * careRate / 100;
        const employment = gross * employmentRate / 100;
        const deductions = pension + health + care + employment + monthlyTax;
        document.getElementById('annual-monthly-gross').textContent = formatNumber(Math.round(gross));
        document.getElementById('annual-deductions').textContent = formatNumber(Math.round(deductions));
        document.getElementById('annual-net').textContent = formatNumber(Math.round(gross - deductions));
        setStatus('월 예상 실수령액을 계산했습니다.', 'success');
    }

    function calculateWeeklyHolidayPay() {
        const hourly = Number(getValue('#weekly-hourly'));
        const weeklyHours = Number(getValue('#weekly-hours'));
        if (!Number.isFinite(hourly) || hourly < 0 || !Number.isFinite(weeklyHours) || weeklyHours < 0) {
            throw new Error('시급과 주 근무시간을 입력해 주세요.');
        }
        const eligibleHours = Math.min(weeklyHours, 40);
        const holidayHours = weeklyHours >= 15 ? eligibleHours / 5 : 0;
        const holidayPay = hourly * holidayHours;
        const weeklyPay = hourly * weeklyHours + holidayPay;
        document.getElementById('weekly-holiday-hours').textContent = formatNumber(holidayHours);
        document.getElementById('weekly-holiday-pay').textContent = formatNumber(Math.round(holidayPay));
        document.getElementById('weekly-total-pay').textContent = formatNumber(Math.round(weeklyPay));
        setStatus('주휴수당을 계산했습니다.', 'success');
    }

    function calculateSeverancePay() {
        const average = Number(getValue('#severance-average'));
        const days = Number(getValue('#severance-days'));
        if (!Number.isFinite(average) || average < 0 || !Number.isFinite(days) || days < 0) {
            throw new Error('평균임금과 재직일수를 입력해 주세요.');
        }
        const years = days / 365;
        const monthWage = average * 30;
        const severance = monthWage * years;
        document.getElementById('severance-years').textContent = formatNumber(years);
        document.getElementById('severance-month-wage').textContent = formatNumber(Math.round(monthWage));
        document.getElementById('severance-result').textContent = formatNumber(Math.round(severance));
        setStatus('예상 퇴직금을 계산했습니다.', 'success');
    }

    function calculateMargin() {
        const cost = Number(getValue('#margin-cost'));
        const price = Number(getValue('#margin-price'));
        const feeRate = Number(getValue('#margin-fee')) || 0;
        if (!Number.isFinite(cost) || !Number.isFinite(price) || cost < 0 || price <= 0 || !Number.isFinite(feeRate)) {
            throw new Error('원가, 판매가, 수수료율을 입력해 주세요.');
        }
        const fee = price * feeRate / 100;
        const profit = price - cost - fee;
        document.getElementById('margin-profit').textContent = formatNumber(Math.round(profit));
        document.getElementById('margin-rate').textContent = formatNumber(profit / price * 100) + '%';
        document.getElementById('markup-rate').textContent = cost > 0 ? formatNumber(profit / cost * 100) + '%' : '-';
        setStatus('마진을 계산했습니다.', 'success');
    }

    function calculatePyeong(mode) {
        const value = Number(getValue('#pyeong-value'));
        if (!Number.isFinite(value) || value < 0) throw new Error('면적 값을 입력해 주세요.');
        const sqm = mode === 'pyeong' ? value * 3.305785 : value;
        const pyeong = mode === 'pyeong' ? value : value / 3.305785;
        document.getElementById('pyeong-sqm').textContent = formatNumber(sqm) + ' m²';
        document.getElementById('pyeong-result').textContent = formatNumber(pyeong) + ' 평';
        setStatus('면적을 변환했습니다.', 'success');
    }

    function maskPersonalInfo() {
        let text = getValue('#tool-input');
        if (document.getElementById('mask-email')?.checked) {
            text = text.replace(/([A-Za-z0-9._%+-]{2})[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/g, '$1***$2');
        }
        if (document.getElementById('mask-phone')?.checked) {
            text = text.replace(/\b(01[016789])[-.\s]?(\d{3,4})[-.\s]?(\d{4})\b/g, '$1-****-$3');
            text = text.replace(/\b(0\d{1,2})[-.\s]?(\d{3,4})[-.\s]?(\d{4})\b/g, '$1-****-$3');
        }
        if (document.getElementById('mask-rrn')?.checked) {
            text = text.replace(/\b(\d{6})[-\s]?([1-4])\d{6}\b/g, '$1-$2******');
        }
        setValue('#tool-output', text);
        setStatus('개인정보 패턴을 마스킹했습니다.', 'success');
    }

    function linesFromInput(selector) {
        return getValue(selector).split(/\r\n|\r|\n/).map(function (line) {
            return line.trim();
        }).filter(Boolean);
    }

    function shuffleArray(items) {
        const result = items.slice();
        for (let i = result.length - 1; i > 0; i -= 1) {
            const j = randomInt(i + 1);
            const temp = result[i];
            result[i] = result[j];
            result[j] = temp;
        }
        return result;
    }

    function randomPick() {
        const items = linesFromInput('#tool-input');
        const count = Math.max(parseInt(getValue('#picker-count'), 10) || 1, 1);
        const unique = document.getElementById('picker-unique')?.checked;
        if (!items.length) throw new Error('추첨할 항목을 입력해 주세요.');
        const source = unique ? shuffleArray(Array.from(new Set(items))) : items;
        const winners = [];
        for (let i = 0; i < count; i += 1) {
            winners.push(unique ? source[i % source.length] : source[randomInt(source.length)]);
        }
        setValue('#tool-output', winners.map(function (item, index) {
            return (index + 1) + '. ' + item;
        }).join('\n'));
        setStatus(winners.length + '개 항목을 추첨했습니다.', 'success');
    }

    function shuffleList() {
        let items = getValue('#tool-input').split(/\r\n|\r|\n/);
        if (document.getElementById('shuffle-trim')?.checked) {
            items = items.map(function (line) { return line.trim(); }).filter(Boolean);
        } else {
            items = items.filter(function (line) { return line !== ''; });
        }
        if (!items.length) throw new Error('섞을 목록을 입력해 주세요.');
        const numbered = document.getElementById('shuffle-numbered')?.checked;
        const shuffled = shuffleArray(items).map(function (item, index) {
            return numbered ? (index + 1) + '. ' + item : item;
        });
        setValue('#tool-output', shuffled.join('\n'));
        setStatus('목록을 무작위로 섞었습니다.', 'success');
    }

    function generateChecklist() {
        const items = linesFromInput('#tool-input');
        const numbered = document.getElementById('checklist-numbered')?.checked;
        const checked = document.getElementById('checklist-checked')?.checked ? 'x' : ' ';
        if (!items.length) throw new Error('체크리스트로 만들 항목을 입력해 주세요.');
        setValue('#tool-output', items.map(function (item, index) {
            return numbered ? (index + 1) + '. [' + checked + '] ' + item : '- [' + checked + '] ' + item;
        }).join('\n'));
        setStatus('체크리스트를 생성했습니다.', 'success');
    }

    function csvSortFilter() {
        const rows = parseCsv(getValue('#tool-input'));
        if (rows.length < 2) throw new Error('헤더와 데이터가 포함된 CSV를 입력해 주세요.');
        const headers = rows[0].map(function (header) { return header.trim(); });
        let data = rows.slice(1).filter(function (row) {
            return row.some(function (cell) { return cell !== ''; });
        });
        const filterColumn = getValue('#csv-filter-column').trim();
        const filterText = getValue('#csv-filter-text').trim().toLowerCase();
        if (filterColumn && filterText) {
            const filterIndex = headers.indexOf(filterColumn);
            if (filterIndex < 0) throw new Error('필터 컬럼을 찾을 수 없습니다.');
            data = data.filter(function (row) {
                return String(row[filterIndex] || '').toLowerCase().includes(filterText);
            });
        }
        const sortColumn = getValue('#csv-sort-column').trim();
        if (sortColumn) {
            const sortIndex = headers.indexOf(sortColumn);
            if (sortIndex < 0) throw new Error('정렬 컬럼을 찾을 수 없습니다.');
            const desc = document.getElementById('csv-sort-desc')?.checked ? -1 : 1;
            data.sort(function (a, b) {
                const av = a[sortIndex] || '';
                const bv = b[sortIndex] || '';
                const an = Number(av);
                const bn = Number(bv);
                if (Number.isFinite(an) && Number.isFinite(bn)) return (an - bn) * desc;
                return av.localeCompare(bv, 'ko') * desc;
            });
        }
        setValue('#tool-output', [headers].concat(data).map(function (row) {
            return row.map(csvEscape).join(',');
        }).join('\n'));
        setStatus(data.length + '개 행을 정리했습니다.', 'success');
    }

    function parseTableText() {
        const rows = getValue('#tool-input').trim().split(/\r\n|\r|\n/).map(function (line) {
            return line.split('\t');
        }).filter(function (row) {
            return row.some(function (cell) { return cell.trim() !== ''; });
        });
        if (!rows.length) throw new Error('표 데이터를 붙여넣어 주세요.');
        return rows;
    }

    function tableToMarkdown() {
        const rows = parseTableText();
        const width = Math.max.apply(null, rows.map(function (row) { return row.length; }));
        const normalized = rows.map(function (row) {
            return Array.from({ length: width }, function (_, index) { return (row[index] || '').trim(); });
        });
        const separator = Array.from({ length: width }, function () { return '---'; });
        const output = [normalized[0], separator].concat(normalized.slice(1)).map(function (row) {
            return '| ' + row.map(function (cell) { return cell.replace(/\|/g, '\\|'); }).join(' | ') + ' |';
        }).join('\n');
        setValue('#tool-output', output);
        setStatus('Markdown 표로 변환했습니다.', 'success');
    }

    function tableToHtml() {
        const rows = parseTableText();
        const escapeHtml = function (text) {
            return String(text).replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            });
        };
        const output = ['<table>', '  <thead>', '    <tr>' + rows[0].map(function (cell) { return '<th>' + escapeHtml(cell.trim()) + '</th>'; }).join('') + '</tr>', '  </thead>', '  <tbody>']
            .concat(rows.slice(1).map(function (row) {
                return '    <tr>' + row.map(function (cell) { return '<td>' + escapeHtml(cell.trim()) + '</td>'; }).join('') + '</tr>';
            }))
            .concat(['  </tbody>', '</table>'])
            .join('\n');
        setValue('#tool-output', output);
        setStatus('HTML 표로 변환했습니다.', 'success');
    }

    function tableToCsv() {
        const rows = parseTableText();
        setValue('#tool-output', rows.map(function (row) {
            return row.map(function (cell) { return csvEscape(cell.trim()); }).join(',');
        }).join('\n'));
        setStatus('CSV로 변환했습니다.', 'success');
    }

    function cleanNote() {
        let lines = getValue('#tool-input').split(/\r\n|\r|\n/);
        if (document.getElementById('note-trim')?.checked) {
            lines = lines.map(function (line) { return line.trim().replace(/\s+/g, ' '); });
        }
        if (document.getElementById('note-collapse')?.checked) {
            const collapsed = [];
            lines.forEach(function (line) {
                if (line === '' && collapsed[collapsed.length - 1] === '') return;
                collapsed.push(line);
            });
            lines = collapsed;
        }
        if (document.getElementById('note-dedupe')?.checked) {
            const seen = new Set();
            lines = lines.filter(function (line) {
                const key = line.toLowerCase();
                if (line !== '' && seen.has(key)) return false;
                if (line !== '') seen.add(key);
                return true;
            });
        }
        setValue('#tool-output', lines.join('\n').trim());
        setStatus('메모를 정리했습니다.', 'success');
    }

    function compareLists() {
        const trim = document.getElementById('compare-trim')?.checked;
        const caseSensitive = document.getElementById('compare-case')?.checked;
        const normalize = function (line) {
            const value = trim ? line.trim() : line;
            return caseSensitive ? value : value.toLowerCase();
        };
        const listA = getValue('#list-a').split(/\r\n|\r|\n/).map(function (line) { return trim ? line.trim() : line; }).filter(Boolean);
        const listB = getValue('#list-b').split(/\r\n|\r|\n/).map(function (line) { return trim ? line.trim() : line; }).filter(Boolean);
        const mapA = new Map(listA.map(function (item) { return [normalize(item), item]; }));
        const mapB = new Map(listB.map(function (item) { return [normalize(item), item]; }));
        const common = [];
        const onlyA = [];
        const onlyB = [];
        mapA.forEach(function (value, key) {
            if (mapB.has(key)) common.push(value);
            else onlyA.push(value);
        });
        mapB.forEach(function (value, key) {
            if (!mapA.has(key)) onlyB.push(value);
        });
        document.getElementById('compare-common-count').textContent = formatNumber(common.length);
        document.getElementById('compare-a-count').textContent = formatNumber(onlyA.length);
        document.getElementById('compare-b-count').textContent = formatNumber(onlyB.length);
        setValue('#tool-output', [
            '[Common]',
            common.join('\n') || '-',
            '',
            '[Only A]',
            onlyA.join('\n') || '-',
            '',
            '[Only B]',
            onlyB.join('\n') || '-'
        ].join('\n'));
        setStatus('두 목록을 비교했습니다.', 'success');
    }

    function addLineNumbers() {
        const lines = getValue('#tool-input').split(/\r\n|\r|\n/);
        const width = String(lines.length).length;
        setValue('#tool-output', lines.map(function (line, index) {
            return String(index + 1).padStart(width, '0') + '. ' + line;
        }).join('\n'));
        setStatus('줄 번호를 추가했습니다.', 'success');
    }

    function removeLineNumbers() {
        setValue('#tool-output', getValue('#tool-input').split(/\r\n|\r|\n/).map(function (line) {
            return line.replace(/^\s*\d+[\).\-\:]\s*/, '');
        }).join('\n'));
        setStatus('줄 번호를 제거했습니다.', 'success');
    }

    function sortTextLines() {
        let lines = linesFromInput('#tool-input');
        if (document.getElementById('sort-unique')?.checked) {
            lines = Array.from(new Set(lines));
        }
        const desc = document.getElementById('sort-desc')?.checked ? -1 : 1;
        const numeric = document.getElementById('sort-numeric')?.checked;
        lines.sort(function (a, b) {
            if (numeric) return ((Number(a) || 0) - (Number(b) || 0)) * desc;
            return a.localeCompare(b, 'ko') * desc;
        });
        setValue('#tool-output', lines.join('\n'));
        setStatus('텍스트를 정렬했습니다.', 'success');
    }

    function transposeTable() {
        const rows = parseTableText();
        const width = Math.max.apply(null, rows.map(function (row) { return row.length; }));
        const height = rows.length;
        const output = [];
        for (let col = 0; col < width; col += 1) {
            const next = [];
            for (let row = 0; row < height; row += 1) {
                next.push((rows[row][col] || '').trim());
            }
            output.push(next.join('\t'));
        }
        setValue('#tool-output', output.join('\n'));
        setStatus('표의 행과 열을 전환했습니다.', 'success');
    }

    function calculateShippingMargin() {
        const price = Number(getValue('#ship-price'));
        const cost = Number(getValue('#ship-cost'));
        const shipping = Number(getValue('#ship-cost-extra')) || 0;
        const feeRate = Number(getValue('#ship-fee-rate')) || 0;
        const adCost = Number(getValue('#ship-ad-cost')) || 0;
        if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(cost) || cost < 0) {
            throw new Error('판매가와 원가를 올바르게 입력해 주세요.');
        }
        const fee = price * feeRate / 100;
        const profit = price - cost - shipping - fee - adCost;
        const breakEven = (cost + shipping + adCost) / Math.max(1 - feeRate / 100, 0.0001);
        document.getElementById('ship-profit').textContent = formatNumber(Math.round(profit));
        document.getElementById('ship-margin-rate').textContent = formatNumber(profit / price * 100) + '%';
        document.getElementById('ship-break-even').textContent = formatNumber(Math.ceil(breakEven));
        setStatus('배송비 포함 마진을 계산했습니다.', 'success');
    }

    function escapeAttribute(text) {
        return String(text).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function generateMetaTags() {
        const title = getValue('#meta-title').trim();
        const description = getValue('#meta-description').trim();
        const url = getValue('#meta-url').trim();
        const image = getValue('#meta-image').trim();
        if (!title || !description) throw new Error('제목과 설명을 입력해 주세요.');
        const tags = [
            '<title>' + escapeAttribute(title) + '</title>',
            '<meta name="description" content="' + escapeAttribute(description) + '">',
            url ? '<link rel="canonical" href="' + escapeAttribute(url) + '">' : '',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="' + escapeAttribute(title) + '">',
            '<meta property="og:description" content="' + escapeAttribute(description) + '">',
            url ? '<meta property="og:url" content="' + escapeAttribute(url) + '">' : '',
            image ? '<meta property="og:image" content="' + escapeAttribute(image) + '">' : '',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="' + escapeAttribute(title) + '">',
            '<meta name="twitter:description" content="' + escapeAttribute(description) + '">',
            image ? '<meta name="twitter:image" content="' + escapeAttribute(image) + '">' : ''
        ].filter(Boolean);
        setValue('#tool-output', tags.join('\n'));
        setStatus('메타태그를 생성했습니다.', 'success');
    }

    function generateSlug() {
        let text = getValue('#tool-input').trim();
        if (document.getElementById('slug-lower')?.checked) text = text.toLowerCase();
        const keepKorean = document.getElementById('slug-keep-korean')?.checked;
        const pattern = keepKorean ? /[^a-z0-9가-힣]+/gi : /[^a-z0-9]+/gi;
        const slugText = text.normalize('NFKC').replace(pattern, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-');
        setValue('#tool-output', slugText);
        setStatus('URL 슬러그를 생성했습니다.', 'success');
    }

    function buildUtmUrl() {
        const rawUrl = getValue('#utm-url').trim();
        if (!rawUrl) throw new Error('랜딩 URL을 입력해 주세요.');
        const url = new URL(rawUrl, window.location.origin);
        const params = {
            utm_source: getValue('#utm-source').trim(),
            utm_medium: getValue('#utm-medium').trim(),
            utm_campaign: getValue('#utm-campaign').trim(),
            utm_term: getValue('#utm-term').trim(),
            utm_content: getValue('#utm-content').trim(),
            utm_language: getValue('#utm-language').trim()
        };
        Object.keys(params).forEach(function (key) {
            if (params[key]) url.searchParams.set(key, params[key]);
        });
        setValue('#tool-output', url.href);
        setStatus('UTM URL을 생성했습니다.', 'success');
    }

    const unitGroups = {
        length: {
            mm: ['Millimeter', 0.001],
            cm: ['Centimeter', 0.01],
            m: ['Meter', 1],
            km: ['Kilometer', 1000],
            inch: ['Inch', 0.0254],
            ft: ['Feet', 0.3048]
        },
        weight: {
            g: ['Gram', 1],
            kg: ['Kilogram', 1000],
            ton: ['Metric ton', 1000000],
            oz: ['Ounce', 28.349523125],
            lb: ['Pound', 453.59237]
        },
        area: {
            sqm: ['Square meter', 1],
            pyeong: ['Pyeong', 3.305785],
            sqft: ['Square feet', 0.09290304],
            hectare: ['Hectare', 10000],
            acre: ['Acre', 4046.8564224]
        }
    };

    function populateUnitOptions() {
        const type = getValue('#unit-type') || 'length';
        const from = document.getElementById('unit-from');
        const to = document.getElementById('unit-to');
        if (!from || !to || !unitGroups[type]) return;
        const previousFrom = from.value;
        const previousTo = to.value;
        [from, to].forEach(function (select) {
            select.textContent = '';
            Object.keys(unitGroups[type]).forEach(function (key) {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = unitGroups[type][key][0] + ' (' + key + ')';
                select.appendChild(option);
            });
        });
        from.value = unitGroups[type][previousFrom] ? previousFrom : Object.keys(unitGroups[type])[0];
        to.value = unitGroups[type][previousTo] ? previousTo : Object.keys(unitGroups[type])[1];
    }

    function convertUnit() {
        const type = getValue('#unit-type') || 'length';
        const value = Number(getValue('#unit-value'));
        const from = getValue('#unit-from');
        const to = getValue('#unit-to');
        if (!Number.isFinite(value) || !unitGroups[type] || !unitGroups[type][from] || !unitGroups[type][to]) {
            throw new Error('변환할 값과 단위를 선택해 주세요.');
        }
        const result = value * unitGroups[type][from][1] / unitGroups[type][to][1];
        document.getElementById('unit-result').textContent = formatNumber(result) + ' ' + to;
        document.getElementById('unit-formula').textContent = '1 ' + from + ' = ' + formatNumber(unitGroups[type][from][1] / unitGroups[type][to][1]) + ' ' + to;
        setStatus('단위를 변환했습니다.', 'success');
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

    function formatPrompt() {
        const draft = getValue('#tool-input').trim();
        const role = getValue('#prompt-role').trim() || 'You are a helpful assistant.';
        if (!draft) throw new Error('프롬프트 내용을 입력해 주세요.');
        const output = [
            '# Role',
            role,
            '',
            '# Task',
            draft,
            '',
            '# Context',
            '- Add relevant background information here.',
            '',
            '# Requirements',
            '- Be specific and practical.',
            '- Ask clarifying questions only when required.',
            '',
            '# Output Format',
            '- Use clear headings and concise bullet points when helpful.'
        ].join('\n');
        setValue('#tool-output', output);
        setStatus('프롬프트를 구조화했습니다.', 'success');
    }

    function optimizePrompt() {
        const prompt = getValue('#tool-input').trim();
        if (!prompt) throw new Error('개선할 프롬프트를 입력해 주세요.');
        const sections = [];
        if (document.getElementById('opt-role')?.checked) {
            sections.push(['# Role', 'You are an expert assistant for this task.']);
        }
        sections.push(['# Goal', prompt]);
        if (document.getElementById('opt-context')?.checked) {
            sections.push(['# Context', '- Audience:\n- Current situation:\n- Important background:']);
        }
        if (document.getElementById('opt-constraints')?.checked) {
            sections.push(['# Constraints', '- Avoid unsupported assumptions.\n- Be accurate and actionable.\n- Keep the response easy to scan.']);
        }
        if (document.getElementById('opt-output')?.checked) {
            sections.push(['# Output Format', '- Summary\n- Step-by-step answer\n- Risks or notes\n- Next action']);
        }
        setValue('#tool-output', sections.map(function (section) {
            return section[0] + '\n' + section[1];
        }).join('\n\n'));
        setStatus('프롬프트 개선안을 만들었습니다.', 'success');
    }

    function estimateTokens(text) {
        if (!text) return 0;
        const cjk = (text.match(/[\u3131-\uD79D\u3040-\u30ff\u3400-\u9fff]/g) || []).length;
        const latinWords = (text.replace(/[\u3131-\uD79D\u3040-\u30ff\u3400-\u9fff]/g, ' ').match(/[A-Za-z0-9_]+|[^\sA-Za-z0-9_]/g) || []).length;
        return Math.max(1, Math.ceil(cjk * 1.1 + latinWords * 1.3));
    }

    function countTokens() {
        const text = getValue('#tool-input');
        const words = text.trim() ? text.trim().split(/\s+/).filter(Boolean).length : 0;
        document.getElementById('token-estimate').textContent = formatNumber(estimateTokens(text));
        document.getElementById('token-chars').textContent = formatNumber(text.length);
        document.getElementById('token-words').textContent = formatNumber(words);
        setStatus('토큰 수를 추정했습니다.', 'success');
    }

    function calculateTextDiff() {
        const left = getValue('#diff-left').split(/\r\n|\r|\n/);
        const right = getValue('#diff-right').split(/\r\n|\r|\n/);
        const dp = Array.from({ length: left.length + 1 }, function () {
            return Array(right.length + 1).fill(0);
        });
        for (let i = left.length - 1; i >= 0; i -= 1) {
            for (let j = right.length - 1; j >= 0; j -= 1) {
                dp[i][j] = left[i] === right[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }

        const preview = document.getElementById('diff-preview');
        if (preview) preview.textContent = '';
        const rows = [];
        let added = 0;
        let removed = 0;
        let same = 0;
        let i = 0;
        let j = 0;
        while (i < left.length || j < right.length) {
            let type;
            let value;
            if (i < left.length && j < right.length && left[i] === right[j]) {
                type = 'same'; value = '  ' + left[i]; i += 1; j += 1; same += 1;
            } else if (j < right.length && (i === left.length || dp[i][j + 1] >= dp[i + 1][j])) {
                type = 'added'; value = '+ ' + right[j]; j += 1; added += 1;
            } else {
                type = 'removed'; value = '- ' + left[i]; i += 1; removed += 1;
            }
            rows.push(value);
            if (preview) {
                const line = document.createElement('div');
                line.className = 'is-' + type;
                line.textContent = value;
                preview.appendChild(line);
            }
        }
        document.getElementById('diff-added').textContent = formatNumber(added);
        document.getElementById('diff-removed').textContent = formatNumber(removed);
        document.getElementById('diff-same').textContent = formatNumber(same);
        setValue('#tool-output', rows.join('\n'));
        setStatus('텍스트 차이를 비교했습니다.', 'success');
    }

    function calculateDateDiff() {
        const start = dateOnly(getValue('#date-start'));
        const end = dateOnly(getValue('#date-end'));
        if (!start || !end) throw new Error('시작일과 종료일을 선택해 주세요.');
        const days = Math.round((end.getTime() - start.getTime()) / 86400000);
        document.getElementById('date-days').textContent = formatNumber(days);
        document.getElementById('date-weeks').textContent = formatNumber(days / 7);
        document.getElementById('date-months').textContent = formatNumber(days / 30.4375);
        setStatus('날짜 차이를 계산했습니다.', 'success');
    }

    function calculateDateOffset(direction) {
        const base = dateOnly(getValue('#date-base'));
        const offset = parseInt(getValue('#date-offset'), 10);
        if (!base || !Number.isFinite(offset)) throw new Error('기준일과 일수를 입력해 주세요.');
        const result = new Date(base);
        result.setDate(result.getDate() + (direction === 'subtract' ? -offset : offset));
        document.getElementById('date-result').textContent = result.toLocaleDateString();
        document.getElementById('date-weekday').textContent = result.toLocaleDateString('ko-KR', { weekday: 'long' });
        setStatus('날짜를 계산했습니다.', 'success');
    }

    function selectedImageFile() {
        const input = document.getElementById('image-file');
        const file = input && input.files ? input.files[0] : null;
        if (!file) throw new Error('이미지 파일을 선택해 주세요.');
        if (!file.type.startsWith('image/')) throw new Error('이미지 파일만 사용할 수 있습니다.');
        return file;
    }

    function readFileAsDataUrl(file, callback) {
        const reader = new FileReader();
        reader.onload = function () { callback(String(reader.result || '')); };
        reader.onerror = function () { setStatus('파일을 읽지 못했습니다.', 'error'); };
        reader.readAsDataURL(file);
    }

    function loadImage(dataUrl, callback) {
        const image = new Image();
        image.onload = function () { callback(image); };
        image.onerror = function () { setStatus('이미지를 불러오지 못했습니다.', 'error'); };
        image.src = dataUrl;
    }

    function renderImagePreview(node) {
        const preview = document.getElementById('image-preview');
        if (!preview) return;
        preview.textContent = '';
        preview.appendChild(node);
    }

    function convertImageToBase64() {
        const file = selectedImageFile();
        readFileAsDataUrl(file, function (dataUrl) {
            const image = document.createElement('img');
            image.src = dataUrl;
            image.alt = file.name;
            renderImagePreview(image);
            setValue('#tool-output', dataUrl);
            setStatus('Base64 Data URL로 변환했습니다.', 'success');
        });
    }

    function processImage() {
        const file = selectedImageFile();
        const quality = Math.min(Math.max(Number(getValue('#image-quality')) || 0.82, 0.1), 1);
        readFileAsDataUrl(file, function (dataUrl) {
            loadImage(dataUrl, function (image) {
                const requestedWidth = parseInt(getValue('#image-width'), 10);
                const requestedHeight = parseInt(getValue('#image-height'), 10);
                let sx = 0;
                let sy = 0;
                let sw = image.naturalWidth;
                let sh = image.naturalHeight;
                if (page.dataset.toolSlug === 'image-crop') {
                    sx = Math.min(Math.max(parseInt(getValue('#crop-x'), 10) || 0, 0), image.naturalWidth - 1);
                    sy = Math.min(Math.max(parseInt(getValue('#crop-y'), 10) || 0, 0), image.naturalHeight - 1);
                    sw = Math.min(Math.max(parseInt(getValue('#crop-width'), 10) || image.naturalWidth, 1), image.naturalWidth - sx);
                    sh = Math.min(Math.max(parseInt(getValue('#crop-height'), 10) || image.naturalHeight, 1), image.naturalHeight - sy);
                }
                let width = Number.isFinite(requestedWidth) && requestedWidth > 0 ? requestedWidth : sw;
                let height = Number.isFinite(requestedHeight) && requestedHeight > 0 ? requestedHeight : Math.round(width * sh / sw);
                if (!(Number.isFinite(requestedWidth) && requestedWidth > 0) && Number.isFinite(requestedHeight) && requestedHeight > 0) {
                    height = requestedHeight;
                    width = Math.round(height * sw / sh);
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(image, sx, sy, sw, sh, 0, 0, width, height);
                const mime = page.dataset.toolSlug === 'webp-converter' ? 'image/webp' : 'image/jpeg';
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        setStatus('이미지 변환에 실패했습니다.', 'error');
                        return;
                    }
                    processedImage = {
                        blob: blob,
                        filename: (page.dataset.toolSlug || 'image') + (mime === 'image/webp' ? '.webp' : '.jpg')
                    };
                    renderImagePreview(canvas);
                    document.getElementById('image-original').textContent = formatNumber(file.size / 1024) + ' KB';
                    document.getElementById('image-output-size').textContent = formatNumber(blob.size / 1024) + ' KB';
                    document.getElementById('image-output-format').textContent = mime.replace('image/', '').toUpperCase();
                    setStatus('이미지를 처리했습니다.', 'success');
                }, mime, quality);
            });
        });
    }

    function downloadProcessedImage() {
        if (!processedImage) throw new Error('먼저 이미지를 처리해 주세요.');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(processedImage.blob);
        link.download = processedImage.filename;
        link.click();
        URL.revokeObjectURL(link.href);
        setStatus('이미지를 다운로드했습니다.', 'success');
    }

    function generateBcryptSnippet() {
        const password = getValue('#tool-input');
        const cost = Math.min(Math.max(parseInt(getValue('#bcrypt-cost'), 10) || 12, 10), 14);
        if (!password) throw new Error('비밀번호를 입력해 주세요.');
        setValue('#tool-output', [
            "<?php",
            "$password = " + JSON.stringify(password) + ";",
            "$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => " + cost + "]);",
            "var_dump($hash);",
            "var_dump(password_verify($password, $hash));"
        ].join('\n'));
        setStatus('PHP bcrypt 생성 코드를 만들었습니다.', 'success');
    }

    function selectedPdfFiles() {
        const input = document.getElementById('pdf-files');
        const files = input && input.files ? Array.from(input.files) : [];
        if (!files.length) throw new Error('PDF 파일을 선택해 주세요.');
        files.forEach(function (file) {
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                throw new Error('PDF 파일만 사용할 수 있습니다.');
            }
        });
        return files;
    }

    function fileToArrayBuffer(file) {
        return file.arrayBuffer ? file.arrayBuffer() : new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = reject;
            reader.readAsArrayBuffer(file);
        });
    }

    function parsePageSelection(input, total) {
        const selected = new Set();
        const value = input.trim() || '1-' + total;
        value.split(',').forEach(function (part) {
            const range = part.trim().split('-').map(function (item) { return parseInt(item, 10); });
            if (range.length === 2 && Number.isFinite(range[0]) && Number.isFinite(range[1])) {
                const start = Math.max(1, Math.min(range[0], range[1]));
                const end = Math.min(total, Math.max(range[0], range[1]));
                for (let pageNo = start; pageNo <= end; pageNo += 1) selected.add(pageNo - 1);
            } else if (Number.isFinite(range[0]) && range[0] >= 1 && range[0] <= total) {
                selected.add(range[0] - 1);
            }
        });
        if (!selected.size) throw new Error('분리할 페이지를 입력해 주세요. 예: 1,3-5');
        return Array.from(selected).sort(function (a, b) { return a - b; });
    }

    function setPdfStats(files, pages, bytes) {
        document.getElementById('pdf-file-count').textContent = formatNumber(files);
        document.getElementById('pdf-page-count').textContent = formatNumber(pages);
        document.getElementById('pdf-output-size').textContent = bytes ? formatNumber(bytes / 1024) + ' KB' : '-';
    }

    async function processPdf(mode) {
        if (!window.PDFLib) throw new Error('PDF 처리 라이브러리를 불러오지 못했습니다.');
        const files = selectedPdfFiles();
        const output = await PDFLib.PDFDocument.create();
        let pageCount = 0;

        if (mode === 'merge') {
            for (const file of files) {
                const source = await PDFLib.PDFDocument.load(await fileToArrayBuffer(file));
                const pages = await output.copyPages(source, source.getPageIndices());
                pages.forEach(function (pdfPage) {
                    output.addPage(pdfPage);
                    pageCount += 1;
                });
            }
        } else {
            const source = await PDFLib.PDFDocument.load(await fileToArrayBuffer(files[0]));
            const indices = mode === 'split' ? parsePageSelection(getValue('#pdf-pages'), source.getPageCount()) : source.getPageIndices();
            const pages = await output.copyPages(source, indices);
            pages.forEach(function (pdfPage) {
                if (mode === 'rotate') {
                    const degrees = parseInt(getValue('#pdf-rotation'), 10) || 90;
                    pdfPage.setRotation(PDFLib.degrees(degrees));
                }
                output.addPage(pdfPage);
                pageCount += 1;
            });
        }

        const bytes = await output.save({ useObjectStreams: true });
        processedPdf = {
            blob: new Blob([bytes], { type: 'application/pdf' }),
            filename: (page.dataset.toolSlug || 'document') + '.pdf'
        };
        setPdfStats(files.length, pageCount, bytes.length);
        setStatus('PDF를 처리했습니다.', 'success');
    }

    function downloadProcessedPdf() {
        if (!processedPdf) throw new Error('먼저 PDF를 처리해 주세요.');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(processedPdf.blob);
        link.download = processedPdf.filename;
        link.click();
        URL.revokeObjectURL(link.href);
        setStatus('PDF를 다운로드했습니다.', 'success');
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
            } else if (action === 'json-validate') {
                validateJson();
            } else if (action === 'jwt-decode') {
                decodeJwt();
            } else if (action === 'regex-test') {
                testRegex();
            } else if (action === 'sql-format') {
                setValue('#tool-output', formatSqlText(getValue('#tool-input')));
                setStatus('SQL을 정리했습니다.', 'success');
            } else if (action === 'sql-minify') {
                setValue('#tool-output', getValue('#tool-input').replace(/\s+/g, ' ').trim());
                setStatus('SQL 공백을 압축했습니다.', 'success');
            } else if (action === 'json-to-csv') {
                jsonToCsv();
            } else if (action === 'csv-to-json') {
                csvToJson();
            } else if (action === 'json-to-xml') {
                jsonToXml();
            } else if (action === 'xml-to-json') {
                xmlToJson();
            } else if (action === 'csv-view') {
                renderCsvTable();
            } else if (action === 'md5-generate') {
                setValue('#tool-output', md5(getValue('#tool-input')));
                setStatus('MD5 해시를 생성했습니다.', 'success');
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
            } else if (action === 'text-diff') {
                calculateTextDiff();
            } else if (action === 'percent-calculate') {
                calculatePercent();
            } else if (action === 'vat-from-supply') {
                calculateVat('supply');
            } else if (action === 'vat-from-total') {
                calculateVat('total');
            } else if (action === 'withholding-from-gross') {
                calculateWithholding('gross');
            } else if (action === 'withholding-from-net') {
                calculateWithholding('net');
            } else if (action === 'salary-calculate') {
                calculateSalary();
            } else if (action === 'loan-calculate') {
                calculateLoan();
            } else if (action === 'compound-calculate') {
                calculateCompound();
            } else if (action === 'split-calculate') {
                calculateSplitBill();
            } else if (action === 'annual-net-calculate') {
                calculateAnnualNetSalary();
            } else if (action === 'weekly-holiday-calculate') {
                calculateWeeklyHolidayPay();
            } else if (action === 'severance-calculate') {
                calculateSeverancePay();
            } else if (action === 'margin-calculate') {
                calculateMargin();
            } else if (action === 'sqm-to-pyeong') {
                calculatePyeong('sqm');
            } else if (action === 'pyeong-to-sqm') {
                calculatePyeong('pyeong');
            } else if (action === 'date-diff') {
                calculateDateDiff();
            } else if (action === 'date-add') {
                calculateDateOffset('add');
            } else if (action === 'date-subtract') {
                calculateDateOffset('subtract');
            } else if (action === 'dday-calculate') {
                calculateDday();
            } else if (action === 'age-calculate') {
                calculateAge();
            } else if (action === 'image-to-base64') {
                convertImageToBase64();
            } else if (action === 'image-process') {
                processImage();
            } else if (action === 'image-download') {
                downloadProcessedImage();
            } else if (action === 'bcrypt-template') {
                generateBcryptSnippet();
            } else if (action === 'pdf-merge') {
                processPdf('merge').catch(function (error) { setStatus(error.message, 'error'); });
            } else if (action === 'pdf-split') {
                processPdf('split').catch(function (error) { setStatus(error.message, 'error'); });
            } else if (action === 'pdf-rotate') {
                processPdf('rotate').catch(function (error) { setStatus(error.message, 'error'); });
            } else if (action === 'pdf-compress') {
                processPdf('compress').catch(function (error) { setStatus(error.message, 'error'); });
            } else if (action === 'pdf-download') {
                downloadProcessedPdf();
            } else if (action === 'unit-convert') {
                convertUnit();
            } else if (action === 'unit-swap') {
                const from = document.getElementById('unit-from');
                const to = document.getElementById('unit-to');
                const previous = from.value;
                from.value = to.value;
                to.value = previous;
                convertUnit();
            } else if (action === 'mask-personal-info') {
                maskPersonalInfo();
            } else if (action === 'random-pick') {
                randomPick();
            } else if (action === 'shuffle-list') {
                shuffleList();
            } else if (action === 'checklist-generate') {
                generateChecklist();
            } else if (action === 'csv-sort-filter') {
                csvSortFilter();
            } else if (action === 'table-to-markdown') {
                tableToMarkdown();
            } else if (action === 'table-to-html') {
                tableToHtml();
            } else if (action === 'table-to-csv') {
                tableToCsv();
            } else if (action === 'note-clean') {
                cleanNote();
            } else if (action === 'compare-lists') {
                compareLists();
            } else if (action === 'line-number-add') {
                addLineNumbers();
            } else if (action === 'line-number-remove') {
                removeLineNumbers();
            } else if (action === 'text-sort') {
                sortTextLines();
            } else if (action === 'table-transpose') {
                transposeTable();
            } else if (action === 'utm-build') {
                buildUtmUrl();
            } else if (action === 'shipping-margin-calculate') {
                calculateShippingMargin();
            } else if (action === 'meta-generate') {
                generateMetaTags();
            } else if (action === 'slug-generate') {
                generateSlug();
            } else if (action === 'prompt-format') {
                formatPrompt();
            } else if (action === 'prompt-optimize') {
                optimizePrompt();
            } else if (action === 'token-count') {
                countTokens();
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
    if (slug === 'unit-converter') {
        populateUnitOptions();
        setValue('#unit-value', '1');
        const unitType = document.getElementById('unit-type');
        if (unitType) {
            unitType.addEventListener('change', function () {
                populateUnitOptions();
                convertUnit();
            });
        }
        convertUnit();
    }
    if (slug === 'annual-salary-net') {
        setValue('#annual-salary', '50000000');
        calculateAnnualNetSalary();
    }
    if (slug === 'weekly-holiday-pay') {
        setValue('#weekly-hourly', '10030');
        setValue('#weekly-hours', '40');
        calculateWeeklyHolidayPay();
    }
    if (slug === 'severance-pay') {
        setValue('#severance-average', '120000');
        setValue('#severance-days', '1095');
        calculateSeverancePay();
    }
    if (slug === 'margin-calculator') {
        setValue('#margin-cost', '7000');
        setValue('#margin-price', '10000');
        calculateMargin();
    }
    if (slug === 'pyeong-calculator') {
        setValue('#pyeong-value', '84');
        calculatePyeong('sqm');
    }
    if (slug === 'shipping-margin') {
        setValue('#ship-price', '30000');
        setValue('#ship-cost', '15000');
        calculateShippingMargin();
    }
    if (slug === 'word-counter') {
        const input = document.getElementById('tool-input');
        if (input) input.addEventListener('input', countWords);
        countWords();
    }
    if (slug === 'lorem-ipsum') generateLorem();
    if (slug === 'date-calculator') {
        const today = new Date().toISOString().slice(0, 10);
        const dateStart = document.getElementById('date-start');
        const dateEnd = document.getElementById('date-end');
        const dateBase = document.getElementById('date-base');
        if (dateStart) dateStart.value = today;
        if (dateEnd) dateEnd.value = today;
        if (dateBase) dateBase.value = today;
    }
    if (slug === 'age-calculator') {
        const baseDate = document.getElementById('age-base-date');
        if (baseDate) baseDate.value = new Date().toISOString().slice(0, 10);
    }
    if (['image-compress', 'image-resize', 'image-crop', 'webp-converter'].includes(page.dataset.toolSlug)) {
        const fileInput = document.getElementById('image-file');
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                const file = fileInput.files && fileInput.files[0];
                if (!file) return;
                readFileAsDataUrl(file, function (dataUrl) {
                    loadImage(dataUrl, function (image) {
                        document.getElementById('image-original').textContent = image.naturalWidth + ' x ' + image.naturalHeight + ' / ' + formatNumber(file.size / 1024) + ' KB';
                        const width = document.getElementById('image-width');
                        const height = document.getElementById('image-height');
                        if (width && !width.value) width.value = image.naturalWidth;
                        if (height && !height.value) height.value = image.naturalHeight;
                        const cropWidth = document.getElementById('crop-width');
                        const cropHeight = document.getElementById('crop-height');
                        if (cropWidth && !cropWidth.value) cropWidth.value = image.naturalWidth;
                        if (cropHeight && !cropHeight.value) cropHeight.value = image.naturalHeight;
                        renderImagePreview(image);
                    });
                });
            });
        }
    }
    if (slug === 'token-counter') {
        const input = document.getElementById('tool-input');
        if (input) input.addEventListener('input', countTokens);
        countTokens();
    }
})();
