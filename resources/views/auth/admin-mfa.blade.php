<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TelmanFit — захищений вхід</title>
<style>body{font:18px system-ui;background:#10131b;color:#eee;max-width:640px;margin:6vh auto;padding:24px}input,button{display:block;padding:12px;margin:14px 0;max-width:95%;font:inherit}button{background:#fbbf24;border:0;border-radius:8px}a{color:#fbbf24}code{overflow-wrap:anywhere}li{margin:12px 0}label{display:block}</style>
<h1>TelmanFit</h1><p>Захищений вхід адміністратора</p>
@if(isset($error))<p role="alert">{{ $error }}</p>@endif
@if($step === 'login')
<form method="post" action="/admin/auth/primary">@csrf
<label>Email<input name="email" type="email" autocomplete="username" required maxlength="255"></label>
<label>Пароль<input name="password" type="password" autocomplete="current-password" required maxlength="1024"></label><button>Увійти</button></form>
@elseif($step === 'enroll')
<h2>Підключення Authenticator</h2><p>Для адміністратора потрібна двофакторна автентифікація. До її завершення дані недоступні.</p>
<form method="post" action="/admin/auth/enroll">@csrf<button>Створити ключ Authenticator</button></form>
@elseif($step === 'confirm')
<h2>Підтвердьте Authenticator</h2><p>Додайте ключ вручну: TOTP, 6 цифр, 30 секунд, SHA-1. Збережіть його тільки у своєму Authenticator.</p>
<p data-sensitive><code id="totp-secret">{{ $secret }}</code></p>
<form method="post" action="/admin/auth/confirm">@csrf<label>Код Authenticator<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required></label><button>Підтвердити</button></form>
@elseif($step === 'recovery')
<h2>Збережіть резервні коди</h2><p>Ці коди показані один раз. Кожен можна використати лише один раз. Збережіть їх у менеджері паролів.</p>
<ul data-sensitive>@foreach($codes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul>
<p>Фактор активовано, попередні сесії відкликано. Виконайте повний вхід.</p><a href="/admin/login">Повторний вхід</a>
@elseif($step === 'verify')
<h2>Другий фактор</h2><form method="post" action="/admin/auth/verify">@csrf
<label>Код Authenticator або резервний код<input name="code" autocomplete="one-time-code" required maxlength="100"></label>
<label><input type="checkbox" name="recovery" value="1">Використати резервний код</label><button>Підтвердити вхід</button></form>
@endif
@if($step !== 'login')<form method="post" action="/admin/auth/logout">@csrf<button>Скасувати вхід</button></form>@endif
</html>
