(function () {
    const form = document.getElementById('automationDiagnosisForm');
    const result = document.getElementById('diagnosisResult');
    const content = document.getElementById('diagnosisResultContent');
    if (!form || !result || !content) return;

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = String(value || '');
        return element.innerHTML;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const works = Array.from(form.querySelectorAll('input[name="work"]:checked')).map(function (input) { return input.value; });
        if (!works.length) {
            alert('반복되는 업무를 한 개 이상 선택해 주세요.');
            return;
        }
        const business = document.getElementById('diagnosis-business').value;
        const sourceTool = new URLSearchParams(window.location.search).get('from') || '';
        const hours = Number(document.getElementById('diagnosis-hours').value);
        const method = document.getElementById('diagnosis-method').value;
        const note = document.getElementById('diagnosis-note').value.trim();
        const score = hours + (works.length >= 3 ? 2 : 1) + (method.indexOf('수작업') >= 0 || method.indexOf('취합') >= 0 ? 2 : 1);
        const recommendation = score >= 7 ? {
            title: '맞춤형 업무 시스템 구축',
            detail: '관리자 화면, 데이터 연동, 권한 관리, 자동 알림까지 포함한 맞춤형 구축을 권장합니다.',
            range: '권장 범위: 요구사항 분석 → 업무 흐름 설계 → 관리자·연동 기능 구축'
        } : score >= 5 ? {
            title: '반복 업무 워크플로 자동화',
            detail: 'CSV·PDF 처리와 데이터 취합, 견적·문의 관리 흐름을 하나로 연결하는 자동화가 적합합니다.',
            range: '권장 범위: 파일 처리 자동화 → 데이터 검증 → 결과·알림 관리'
        } : {
            title: '간단 자동화·표준화부터 시작',
            detail: '입력 양식과 파일 구조를 표준화하고, 반복 계산·문서 처리부터 자동화해 효과를 확인하는 방식이 적합합니다.',
            range: '권장 범위: 양식 표준화 → 반복 작업 자동화 → 사용 데이터 점검'
        };
        const savedHours = Math.max(1, Math.round(hours * (score >= 7 ? 0.6 : score >= 5 ? 0.45 : 0.25)));
        const summary = [
            '[업무 자동화 진단 결과]',
            '조직 유형: ' + business,
            sourceTool ? '유입 도구: ' + sourceTool : '',
            '반복 업무: ' + works.join(', '),
            '주간 소요 시간: ' + document.getElementById('diagnosis-hours').selectedOptions[0].text,
            '현재 방식: ' + method,
            '자동화 점수: ' + score + '점',
            '권장 방향: ' + recommendation.title,
            '예상 절감 가능 시간: 주 약 ' + savedHours + '시간',
            note ? '추가 내용: ' + note : ''
        ].filter(Boolean).join('\n');
        sessionStorage.setItem('wy_automation_diagnosis', summary);
        result.classList.add('is-ready');
        content.innerHTML = '<div class="diagnosis-score">' + score + '<small>/ 10</small></div><h3>' + escapeHtml(recommendation.title) + '</h3><p>' + escapeHtml(recommendation.detail) + '</p><p class="diagnosis-range">' + escapeHtml(recommendation.range) + '</p><div class="diagnosis-saving">예상 절감 가능 시간 <strong>주 약 ' + savedHours + '시간</strong></div><a class="diagnosis-contact" href="/contact?inquiry=business&amp;source=automation-diagnosis">진단 결과로 상담 요청하기</a><button type="button" class="diagnosis-copy" data-copy-diagnosis>결과 복사</button>';
        content.querySelector('[data-copy-diagnosis]').addEventListener('click', function () {
            navigator.clipboard.writeText(summary).then(function () { this.textContent = '복사되었습니다'; }.bind(this));
        });
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
})();
