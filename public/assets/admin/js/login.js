document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(form);
        const username = formData.get('username')?.trim() ?? '';
        const password = formData.get('password')?.trim() ?? '';

        if (!username) return alert('아이디를 입력하세요.');
        if (!password) return alert('비밀번호를 입력하세요.');

        fetch('/admin/login', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.success){
                location.href = '/admin/';
            }else{
                alert(data.message);
            }
        })
        .catch(() => {
            alert('오류 발생. 관리자에게 문의하세요. 에러코드 [CT0001]');
        });
    });
});
