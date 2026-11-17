<?php
require dirname(__DIR__).'/app/core.php';
start_app();
if(installed()){nav('index.php');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try {
    check_token();
    $key=trim((string)($_POST['key']??''));
    $correct=require dirname(__DIR__).'/app/install_key.php';
    if(!hash_equals($correct,$key)) throw new RuntimeException('Неверный ключ установки.');
    $username=poststr('username',24);ensure_length($username,3,24,'Логин');
    if(!preg_match('/^[\pL\pN_][\pL\pN_ .-]{2,23}$/u',$username)) throw new RuntimeException('Недопустимые символы в имени.');
    $pwd=(string)($_POST['password']??'');
    if(strlen($pwd)<12||strlen($pwd)>128) throw new RuntimeException('Пароль должен содержать от 12 до 128 символов.');
    if(!is_writable(STORE))throw new RuntimeException('Папка storage недоступна для записи.');
    $lock=fopen(STORE.'/install.lock','c+');if(!$lock)throw new RuntimeException('Нет доступа к storage.');
    flock($lock,LOCK_EX);
    if(installed())throw new RuntimeException('Установка уже выполнена.');
    save_db(seed_data($username,$pwd));
    flock($lock,LOCK_UN);fclose($lock);
    $_SESSION['uid']=1;session_regenerate_id(true);
    flash('Форум установлен. Вы вошли как главный администратор.');nav('index.php?page=staff');
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Установка • SLAVIA ROLEPLAY</title><link rel="stylesheet" href="assets/css/style.css"></head><body><div class="install-page"><div class="install-card"><img class="install-logo" src="assets/img/slavia-logo.jpg" alt="SLAVIA"><div class="eyebrow">ПЕРВЫЙ ЗАПУСК</div><h1>Установка форума</h1><p class="muted">Создай главного администратора. Ключ установки находится в файле <code>app/install_key.php</code> (сам файл нельзя размещать в открытом доступе).</p><?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?><form method="post" class="stack-form"><?=csrf()?><label class="form-field"><span>Ключ установки</span><input name="key" required autocomplete="off"></label><label class="form-field"><span>Имя администратора</span><input name="username" minlength="3" maxlength="24" required autocomplete="username"></label><label class="form-field"><span>Пароль (не менее 12 символов)</span><input type="password" name="password" minlength="12" maxlength="128" required autocomplete="new-password"></label><button class="btn btn-primary btn-wide">Установить форум</button></form><small class="muted">После установки страница автоматически блокируется.</small></div></div></body></html>
