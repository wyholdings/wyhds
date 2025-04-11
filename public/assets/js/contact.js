document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contactForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(form);
        const email = formData.get('email')?.trim() ?? '';
        const phone = formData.get('phone')?.trim() ?? '';
        const company = formData.get('company')?.trim() ?? '';
        const name = formData.get('name')?.trim() ?? '';
        const money = formData.get('money')?.trim() ?? '';
        const message = formData.get('message')?.trim() ?? '';

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const phoneRegex = /^01[016789]-?\d{3,4}-?\d{4}$/;

        if (!company) return alert('상호를 입력하세요.');
        if (!name) return alert('성함을 입력하세요.');
        if (!email) return alert('이메일을 입력하세요.');
        if (!emailRegex.test(email)) return alert('이메일 형식이 올바르지 않습니다.');
        if (!phone) return alert('전화번호를 입력하세요.');
        if (!phoneRegex.test(phone)) return alert('전화번호 형식이 잘못되었습니다.');
        if (!money) return alert('예산을 입력하세요.');
        if (!message) return alert('내용을 입력하세요.');

        fetch('/contact/submit', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            const box = document.getElementById('formMessage');
            box.innerHTML = data.success
                ? alert(data.message)
                : alert(data.message);
            if (data.success) form.reset();
        })
        .catch(() => {
            document.getElementById('formMessage').innerHTML =
            alert('오류 발생. 관리자에게 문의하세요. 에러코드 [CT0001]');
        });
    });
});
