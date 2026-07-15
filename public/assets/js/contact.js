document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contactForm');
    if (!form) return;
    const messageBox = document.getElementById('formMessage');
    const submitButton = form.querySelector('button[type="submit"]');
    const phoneInput = form.querySelector('input[name="phone"]');
    const inquiryType = form.dataset.inquiryType || '';
    const source = new URLSearchParams(window.location.search).get('source') || '';
    const diagnosisSummary = source === 'automation-diagnosis' ? sessionStorage.getItem('wy_automation_diagnosis') : '';
    const websiteScopeSummary = source === 'website-scope-estimator' ? sessionStorage.getItem('wy_website_scope_inquiry') : '';
    const quoteAmountSummary = source === 'quote-amount-designer' ? sessionStorage.getItem('wy_quote_amount_inquiry') : '';

    if (inquiryType === 'business' && diagnosisSummary) {
        const messageInput = form.querySelector('textarea[name="message"]');
        if (messageInput) {
            messageInput.value = diagnosisSummary + '\n\n상세 요구사항과 구축 방향을 상담하고 싶습니다.';
            sessionStorage.removeItem('wy_automation_diagnosis');
        }
    }

    if (inquiryType === 'business' && websiteScopeSummary) {
        const messageInput = form.querySelector('textarea[name="message"]');
        if (messageInput) {
            messageInput.value = websiteScopeSummary + '\n\n위 범위를 기준으로 상세 견적과 구축 방향을 상담하고 싶습니다.';
            sessionStorage.removeItem('wy_website_scope_inquiry');
        }
    }

    if (inquiryType === 'business' && quoteAmountSummary) {
        const messageInput = form.querySelector('textarea[name="message"]');
        if (messageInput) {
            messageInput.value = quoteAmountSummary + '\n\n위 견적 기준으로 상세 범위와 구축 방향을 상담하고 싶습니다.';
            sessionStorage.removeItem('wy_quote_amount_inquiry');
        }
    }

    function formatPhoneNumber(value) {
        const digits = value.replace(/\D/g, '').slice(0, 11);

        if (digits.length <= 3) {
            return digits;
        }

        if (digits.length <= 7) {
            return digits.slice(0, 3) + '-' + digits.slice(3);
        }

        return digits.slice(0, 3) + '-' + digits.slice(3, 7) + '-' + digits.slice(7);
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            phoneInput.value = formatPhoneNumber(phoneInput.value);
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(form);
        const email = formData.get('email')?.trim() ?? '';
        const phone = formData.get('phone')?.trim() ?? '';
        const company = formData.get('company')?.trim() ?? '';
        const name = formData.get('name')?.trim() ?? '';
        const money = formData.get('money')?.trim() ?? '';
        const dueDate = formData.get('due_date')?.trim() ?? '';
        const message = formData.get('message')?.trim() ?? '';
        const token = formData.get('contact_token')?.trim() ?? '';

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const phoneRegex = /^01[016789]-?\d{3,4}-?\d{4}$/;

        if (!company) return alert('기관명/학회명/회사명을 입력하세요.');
        if (!name) return alert('성명을 입력하세요.');
        if (!phone) return alert('연락처를 입력하세요.');
        if (!phoneRegex.test(phone)) return alert('연락처 형식이 잘못되었습니다.');
        if (!email) return alert('이메일을 입력하세요.');
        if (!emailRegex.test(email)) return alert('이메일 형식이 올바르지 않습니다.');
        if (inquiryType !== 'pro' && !money) return alert('예산을 선택하세요.');
        if (inquiryType !== 'pro' && !dueDate) return alert('희망 완료일자를 입력하세요.');
        if (!message) return alert('내용을 입력하세요.');
        if (!token) return alert('새로고침 후 다시 시도해 주세요.');

        if (submitButton) {
            submitButton.disabled = true;
        }
        if (messageBox) {
            messageBox.textContent = '문의 내용을 전송하고 있습니다.';
        }

        fetch('/contact/submit', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (messageBox) {
                messageBox.textContent = data.message;
            }
            alert(data.message);
            if (data.success) form.reset();
        })
        .catch(() => {
            if (messageBox) {
                messageBox.textContent = '오류 발생. 관리자에게 문의하세요. 에러코드 [CT0001]';
            }
            alert('오류 발생. 관리자에게 문의하세요. 에러코드 [CT0001]');
        })
        .finally(() => {
            if (submitButton) {
                submitButton.disabled = false;
            }
        });
    });
});
