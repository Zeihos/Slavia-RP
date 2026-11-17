<?php
require_once __DIR__.'/../app/layout.php';
if(installed())redirect('index.php');
$err='';$ready=is_writable(WIKI_STORAGE);$key=WIKI_INSTALL_KEY;
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$submitted=(string)($_POST['install_key']??'');
 if(!$ready)$err='Папка storage недоступна для записи.';
 elseif(!hash_equals($key,$submitted))$err='Неверный ключ установки.';
 elseif(!preg_match('/^[a-zA-Z0-9_]{3,32}$/',$username))$err='Логин: 3–32 латинские буквы, цифры или _. ';
 elseif(strlen($password)<12)$err='Пароль должен содержать минимум 12 символов.';
 else{
  require_once __DIR__.'/../app/seed.php';
  $fh=fopen(WIKI_STORAGE.'/.install_mutex','c');
  if(!$fh||!flock($fh,LOCK_EX))$err='Не удалось получить блокировку установки.';
  else {
    if(installed()){$err='Установка уже выполнена.';}
    else{
      data_save('categories',seed_categories());data_save('articles',seed_articles());data_save('settings',[]);
      data_save('users', [['id'=>1,'username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'created_at'=>gmdate('c')]]);
      data_save('revisions',[]);file_put_contents(WIKI_STORAGE.'/installed.lock',gmdate('c'));@chmod(WIKI_STORAGE.'/installed.lock',0640);
      session_regenerate_id(true);$_SESSION['uid']=1;flash('База знаний установлена. Добро пожаловать в панель управления!');flock($fh,LOCK_UN);fclose($fh);redirect('admin.php');
    }
    flock($fh,LOCK_UN);fclose($fh);
  }
 }
}
render_head('Установка');?><div class="auth-layout"><div class="auth-card install-card"><div class="auth-symbol">✦</div><div class="eyebrow">ПЕРВЫЙ ЗАПУСК</div><h1>Установка SLAVIA Wiki</h1><p>Создай администратора. Начальные категории и статьи появятся автоматически.</p>
<?php if($err):?><div class="notice error"><?=h($err)?></div><?php endif;?><div class="setup-check"><span><?=$ready?'✓':'!'?></span> Папка хранения: <?=$ready?'доступна':'недоступна'?></div>
<form method="post" class="form-stack"><?=csrf_field()?><label>Ключ установки<input type="password" name="install_key" required autocomplete="off" placeholder="Из storage/.install-key.php"></label><label>Логин администратора<input name="username" minlength="3" maxlength="32" required autocomplete="username" placeholder="admin"></label><label>Надёжный пароль<input type="password" name="password" minlength="12" required autocomplete="new-password" placeholder="От 12 символов"></label><button class="primary-btn" type="submit">Установить Wiki →</button></form><p class="muted-tip">Ключ автоматически создан в непубличном файле <code>storage/.install-key.php</code>. После установки мастер автоматически закрывается.</p></div></div><?php render_footer();
