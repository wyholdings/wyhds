<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>로그인</title>
</head>
<body>
    <h2>로그인</h2>
    <form method="POST" action="/login">
        <label>이메일: <input type="email" name="email" required></label><br>
        <label>비밀번호: <input type="password" name="password" required></label><br>
        <button type="submit">로그인</button>
    </form>
</body>
</html>
