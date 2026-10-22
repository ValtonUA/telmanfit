<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TelmanFit — запрошення</title>
<style>body{font:18px system-ui;max-width:700px;margin:50px auto;padding:20px}textarea{width:100%;min-height:100px;font:inherit}</style>
<h1>Запрошення TelmanFit</h1>
@if(isset($url))<p>Скопіюйте URL зараз і передайте особисто клієнту. Повторного показу немає.</p><textarea id="invitation-url" readonly>{{ $url }}</textarea>
@elseif(isset($revoked))<p>Запрошення відкликано.</p>
@else<p role="alert">Дію відхилено. Перевірте доступ, пароль і новий код Authenticator.</p>@endif
<a href="/admin/invitations">До списку запрошень</a></html>
