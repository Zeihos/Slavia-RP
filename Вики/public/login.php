<?php
require_once __DIR__.'/../app/layout.php';must_install();if(current_user())redirect('admin.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$name=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');
 $attempts=$_SESSION['login_attempts']??0;$lockUntil=$_SESSION['login_lock_until']??0;
 if(time()<$lockUntil)$error='Слишком много попыток. Повтори вход позже.';
 else{
  $match=null;foreach(data_read('users') as $u)if(hash_equals($u['username'],$name)){$match=$u;break;}
  if($match&&password_verify($password,$match['password_hash'])){session_regenerate_id(true);$_SESSION['uid']=$match['id'];$_SESSION['auth_version']=hash('sha256',(string)$match['password_hash']);unset($_SESSION['login_attempts'],$_SESSION['login_lock_until']);redirect('admin.php');}
  $_SESSION['login_attempts']=$attempts+1;if($attempts>=5)$_SESSION['login_lock_until']=time()+300;
  $error='Неверный логин или пароль.';
 }
}
render_head('Вход в редакцию');?><div class="auth-layout"><div class="auth-card"><div class="auth-symbol">⌘</div><div class="eyebrow">SLAVIA / EDITOR</div><h1>Панель редактора</h1><p>Войди, чтобы управлять статьями и разделами базы знаний.</p><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif;?><form method="post" class="form-stack"><?=csrf_field()?><label>Логин<input name="username" autocomplete="username" required autofocus></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button class="primary-btn" type="submit">Войти →</button></form><a class="muted-tip" href="index.php">← Вернуться к базе знаний</a></div></div><?php render_footer();
