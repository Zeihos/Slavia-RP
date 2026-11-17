<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
require __DIR__.'/private/config.php';
header('X-Robots-Tag: noindex, nofollow');
$auth=store_read('auth',[]);
$installed=!empty($auth['password_hash']);
$isAdmin=($installed && !empty($_SESSION['admin_auth']) && (time()-(int)($_SESSION['admin_seen']??0))<3600);
if(!$isAdmin)unset($_SESSION['admin_auth']);else $_SESSION['admin_seen']=time();
$tab=(string)($_GET['tab']??'dashboard');
$tabs=['dashboard'=>'Обзор','settings'=>'Настройки','news'=>'Новости','servers'=>'Серверы','products'=>'Магазин','faq'=>'FAQ','tickets'=>'Обращения'];
if(!isset($tabs[$tab]))$tab='dashboard';
function admin_url(string $tab='dashboard'):string {return 'admin.php?tab='.rawurlencode($tab);}
function yes(string $field):bool{return isset($_POST[$field])&&$_POST[$field]==='1';}
function value(string $key,int $max=8000):string {
 $v=$_POST[$key]??'';return is_string($v)?trim(substr($v,0,$max)):'';
}
function admin_post():void {
 global $installed,$auth,$isAdmin,$tab;
 if($_SERVER['REQUEST_METHOD']!=='POST')return;
 csrf_check();
 $action=value('action',50);
 if(!$installed && $action==='setup') {
     if(!hash_equals(SLAVIA_INSTALL_KEY,value('install_key',100))) {set_flash('error','Неверный ключ установки.');redirect('admin.php');}
     $user=value('username',80);$pass=value('password',500);
     if(!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/',$user)||strlen($pass)<12){set_flash('error','Логин: 3–32 символа (латиница, цифры, точка, _ или -). Пароль: от 12 символов.');redirect('admin.php');}
     store_write('auth',['username'=>$user,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'created'=>date('c')]);
     session_regenerate_id(true);$_SESSION['admin_auth']=true;$_SESSION['admin_seen']=time();
     set_flash('success','Панель настроена. Сохраните пароль. Ключ установки хранится только на сервере и не публикуется в Git.');redirect('admin.php');
 }
 if($installed && $action==='login') {
     if(((int)($_SESSION['login_lock_until']??0))>time()) {set_flash('error','Слишком много попыток. Повторите вход позже.');redirect('admin.php');}
     $username=value('username',80);$password=value('password',500);
     if(hash_equals((string)$auth['username'],$username)&&password_verify($password,(string)$auth['password_hash'])){
         session_regenerate_id(true);$_SESSION['admin_auth']=true;$_SESSION['admin_seen']=time();unset($_SESSION['login_fails'],$_SESSION['login_lock_until']);redirect('admin.php');
     }
     $_SESSION['login_fails']=((int)($_SESSION['login_fails']??0))+1;
     if($_SESSION['login_fails']>=5)$_SESSION['login_lock_until']=time()+900;
     set_flash('error','Неверный логин или пароль.');redirect('admin.php');
 }
 if($action==='logout') {$_SESSION=[];session_regenerate_id(true);redirect('admin.php');}
 if(!$isAdmin){http_response_code(403);exit('Доступ запрещён.');}
 if($action==='settings') {
     $old=settings();$fields=['tagline'=>100,'hero_heading'=>120,'hero_description'=>650,'announcement'=>400,'footer_note'=>350,'contact_email'=>200];
     foreach($fields as $f=>$length)$old[$f]=value($f,$length);
     foreach(['vk_url','telegram_url','forum_url','download_android','download_ios','download_pc','domain'] as $f) {
         $v=value($f,500);if($v!==''&&!safe_url($v)) {set_flash('error','Для поля '.$f.' нужна HTTPS-ссылка.');redirect(admin_url('settings'));}
         $old[$f]=$v;
     }
     $status=value('launch_status',30);$old['launch_status']=in_array($status,['development','testing','online','maintenance'],true)?$status:'development';
     store_write('settings',$old);set_flash('success','Настройки обновлены.');redirect(admin_url('settings'));
 }
 if($action==='password') {
     $current=value('current_password',500);$new=value('new_password',500);
     if(!password_verify($current,(string)$auth['password_hash'])||strlen($new)<12) {set_flash('error','Текущий пароль неверный или новый короче 12 символов.');redirect(admin_url('settings'));}
     $auth['password_hash']=password_hash($new,PASSWORD_DEFAULT);store_write('auth',$auth);
     set_flash('success','Пароль изменён.');redirect(admin_url('settings'));
 }
 $stores=['news'=>['title','excerpt','body','category','date'], 'servers'=>['name','description','host'], 'products'=>['name','description','price','checkout_url'], 'faq'=>['question','answer']];
 if($action==='save' && isset($stores[$tab])) {
     $row=[];$id=value('id',50);$row['id']=$id?:slug_id();
     foreach($stores[$tab] as $field)$row[$field]=value($field, $field==='body'?12000:($field==='answer'?3500:800));
     if($tab==='news'){
         if(strlen($row['title'])<3||strlen($row['body'])<5){set_flash('error','У новости должны быть заголовок и текст.');redirect(admin_url($tab));}
         $row['date']=preg_match('/^\d{4}-\d{2}-\d{2}$/',$row['date'])?$row['date']:date('Y-m-d');
         $row['published']=yes('published');
     }
     if($tab==='servers'){
         if(strlen($row['name'])<2){set_flash('error','Укажите название сервера.');redirect(admin_url($tab));}
         $row['active']=yes('active');
     }
     if($tab==='products'){
         if(strlen($row['name'])<2 || (strlen($row['checkout_url'])&& !safe_url($row['checkout_url']))) {set_flash('error','Укажите название и корректную HTTPS-ссылку оплаты.');redirect(admin_url($tab));}
         $row['visible']=yes('visible');
     }
     if($tab==='faq' && (strlen($row['question'])<3 || strlen($row['answer'])<3)) {set_flash('error','Нужны вопрос и ответ.');redirect(admin_url($tab));}
     store_mutate($tab,static function($all)use($row){if(!is_array($all))$all=[];$found=false;foreach($all as &$item){if(($item['id']??'')===$row['id']){$item=$row;$found=true;break;}}unset($item);if(!$found)array_unshift($all,$row);return array_values($all);});
     set_flash('success','Изменения сохранены.');redirect(admin_url($tab));
 }
 if($action==='delete' && isset($stores[$tab])){
     $id=value('id',50);store_mutate($tab,static fn($all)=>array_values(array_filter($all,static fn($r)=>($r['id']??'')!==$id)));
     set_flash('success','Запись удалена.');redirect(admin_url($tab));
 }
 if(($action==='resolve'||$action==='delete_ticket')&&$tab==='tickets'){
     $id=value('id',50);store_mutate('tickets',static function($all)use($id,$action){
         if($action==='delete_ticket')return array_values(array_filter($all,static fn($t)=>($t['id']??'')!==$id));
         foreach($all as &$item)if(($item['id']??'')===$id){$item['status']='resolved';break;}unset($item);return $all;
     });set_flash('success','Обращение обновлено.');redirect(admin_url('tickets'));
 }
 set_flash('error','Неизвестное действие.');redirect(admin_url($tab));
}
admin_post();
$flash=get_flash();
function admin_input(string $field,string $label,string $val='',string $kind='text',bool $required=false):void {?>
<label class="field"><span><?=e($label)?></span><?php if($kind==='textarea'): ?><textarea name="<?=e($field)?>" rows="5"<?=$required?' required':''?>><?=e($val)?></textarea><?php else: ?><input type="<?=e($kind)?>" name="<?=e($field)?>" value="<?=e($val)?>"<?=$required?' required':''?>></input><?php endif; ?></label>
<?php }
function admin_check(string $field,string $label,bool $checked):void {?><label class="check"><input type="checkbox" name="<?=e($field)?>" value="1"<?=$checked?' checked':''?>><?=e($label)?></label><?php }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Управление — SLAVIA ROLEPLAY</title><link rel="icon" href="assets/img/favicon.png"><link rel="stylesheet" href="assets/css/admin.css"></head><body>
<?php if(!$installed||!$isAdmin): ?>
<div class="auth-layout"><div class="auth-card"><a class="auth-brand" href="index.php"><span>M</span><div><b>SLAVIA</b><small>ROLEPLAY / ADMIN</small></div></a><div class="auth-label"><?=!$installed?'ПЕРВОНАЧАЛЬНАЯ УСТАНОВКА':'ПАНЕЛЬ УПРАВЛЕНИЯ'?></div><h1><?=!$installed?'Создать администратора':'С возвращением'?></h1><p><?=!$installed?'Для установки укажите личный ключ из private/data/.install-key.php (он создаётся при первом открытии панели). Не публикуйте этот файл.':'Введите данные администратора, чтобы управлять сайтом.'?></p><?php if($flash): ?><div class="alert <?=e($flash['kind']??'')?>"><?=e($flash['message']??'')?></div><?php endif; ?><form method="post" action="admin.php"><?=csrf_field()?><input type="hidden" name="action" value="<?=$installed?'login':'setup'?>"><?php if(!$installed)admin_input('install_key','Ключ установки'); ?><?php admin_input('username','Логин','','text',true);admin_input('password','Пароль (не менее 12 символов при установке)','','password',true);?><button class="primary full" type="submit"><?=$installed?'ВОЙТИ В ПАНЕЛЬ':'СОЗДАТЬ АДМИНИСТРАТОРА'?> ↗</button></form><a class="auth-return" href="index.php">← Вернуться на сайт</a></div></div>
<?php else: ?>
<div class="admin-layout"><aside class="sidebar"><a class="admin-brand" href="index.php"><span>M</span><div><b>SLAVIA</b><small>CONTROL PANEL</small></div></a><p class="sidebar-heading">УПРАВЛЕНИЕ САЙТОМ</p><nav><?php foreach($tabs as $key=>$label): ?><a class="<?= $tab===$key?'selected':'' ?>" href="<?=admin_url($key)?>"><span><?=['dashboard'=>'▣','settings'=>'⚙','news'=>'▤','servers'=>'◉','products'=>'◇','faq'=>'?','tickets'=>'✉'][$key]?></span><?=e($label)?><?php if($key==='tickets'){$unread=count(array_filter(store_read('tickets',[]),fn($t)=>($t['status']??'new')==='new'));if($unread)echo '<b class="nav-count">'.$unread.'</b>';} ?></a><?php endforeach; ?></nav><div class="sidebar-end"><a href="index.php" target="_blank">Открыть сайт ↗</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button type="submit">Выйти</button></form></div></aside>
<div class="workspace"><header class="admin-header"><div><small>SLAVIA ROLEPLAY / АДМИНИСТРИРОВАНИЕ</small><h1><?=e($tabs[$tab])?></h1></div><span class="login-indicator">● АДМИНИСТРАТОР</span></header><div class="admin-body"><?php if($flash): ?><div class="alert <?=e($flash['kind']??'')?>"><?=e($flash['message']??'')?></div><?php endif; ?>
<?php if($tab==='dashboard'):
 $stats=['Новости'=>count(store_read('news',[])),'Серверы'=>count(store_read('servers',[])),'Товары'=>count(store_read('products',[])),'Обращения'=>count(store_read('tickets',[]))];?>
<div class="metric-grid"><?php foreach($stats as $label=>$num): ?><div class="metric"><small><?=e(strtoupper($label))?></small><strong><?=e($num)?></strong><span>записей</span></div><?php endforeach; ?></div><section class="panel"><h2>Сайт SLAVIA ROLEPLAY</h2><p>Основные публичные разделы готовы. Для запуска проекта настрой ссылки скачивания, игровые серверы, содержимое магазина и юридические документы. Текущий статус проекта: <b><?=e(status_label(settings()['launch_status']??'development'))?></b>.</p><div class="quick-links"><a href="<?=admin_url('settings')?>">Настройки →</a><a href="<?=admin_url('news')?>">Публиковать новости →</a><a href="<?=admin_url('servers')?>">Настроить серверы →</a><a href="<?=admin_url('tickets')?>">Просмотреть обращения →</a></div></section><section class="panel warning"><h2>Перед публичным открытием</h2><p>Настрой HTTPS, домен, резервное копирование папки <code>private/data</code>, официальные платёжные ссылки, политику конфиденциальности и информацию о владельце проекта. Без этих сведений платные функции не следует включать.</p></section>
<?php elseif($tab==='settings'): $conf=settings(); ?>
<section class="panel"><h2>Основные настройки</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="settings"><div class="form-grid"><?php admin_input('tagline','Короткий слоган',$conf['tagline']??'');admin_input('hero_heading','Главный заголовок',$conf['hero_heading']??''); ?></div><?php admin_input('hero_description','Описание на главной',$conf['hero_description']??'','textarea');admin_input('announcement','Статус / объявление',$conf['announcement']??'','textarea'); ?><label class="field"><span>Статус проекта</span><select name="launch_status"><?php foreach(['development'=>'В разработке','testing'=>'Тестирование','online'=>'Открыт','maintenance'=>'Технические работы'] as $k=>$v): ?><option value="<?=$k?>"<?=($conf['launch_status']??'')===$k?' selected':''?>><?=$v?></option><?php endforeach; ?></select></label><h3>Официальные ссылки</h3><div class="form-grid"><?php foreach(['vk_url'=>'ВКонтакте','telegram_url'=>'Telegram','forum_url'=>'Форум','download_android'=>'Загрузка Android','download_ios'=>'Загрузка iOS','download_pc'=>'Загрузка Windows','domain'=>'Адрес сайта (https://...)','contact_email'=>'Контактный email'] as $k=>$v)admin_input($k,$v,$conf[$k]??''); ?></div><?php admin_input('footer_note','Подпись в подвале',$conf['footer_note']??''); ?><button class="primary" type="submit">СОХРАНИТЬ НАСТРОЙКИ ↗</button></form></section><section class="panel"><h2>Смена пароля</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="password"><div class="form-grid"><?php admin_input('current_password','Текущий пароль','','password',true);admin_input('new_password','Новый пароль (от 12 символов)','','password',true); ?></div><button class="secondary" type="submit">ИЗМЕНИТЬ ПАРОЛЬ</button></form></section>
<?php elseif(in_array($tab,['news','servers','products','faq'],true)):
 $collection=store_read($tab,[]);$edit=(string)($_GET['edit']??'');$record=[];foreach($collection as $item)if(($item['id']??'')===$edit){$record=$item;break;}
 $descs=['news'=>'Редактируй новости и анонсы. Непубликованные записи скрыты на сайте.','servers'=>'Добавляй реальные адреса серверов после их открытия.','products'=>'Товары отображаются только при включённой публикации. Покупка перенаправляет по официальной HTTPS-ссылке.','faq'=>'Ответы игроков в разделе часто задаваемых вопросов.'];?>
<section class="panel"><div class="panel-heading"><div><h2><?=$record?'Редактирование записи':'Новая запись'?></h2><p><?=e($descs[$tab])?></p></div><?php if($record): ?><a class="secondary" href="<?=admin_url($tab)?>">+ Новая запись</a><?php endif; ?></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($record['id']??'')?>">
<?php if($tab==='news'): ?><?php admin_input('title','Заголовок',$record['title']??'','text',true);admin_input('excerpt','Краткое описание',$record['excerpt']??'');admin_input('body','Текст новости',$record['body']??'','textarea',true); ?><div class="form-grid"><?php admin_input('category','Категория',$record['category']??'Новости');admin_input('date','Дата',$record['date']??date('Y-m-d'),'date'); ?></div><?php admin_check('published','Опубликовать на сайте',!empty($record['published'])); ?>
<?php elseif($tab==='servers'): ?><?php admin_input('name','Название сервера',$record['name']??'','text',true);admin_input('description','Описание',$record['description']??'');admin_input('host','Адрес сервера (IP или домен)',$record['host']??'');admin_check('active','Сервер открыт для игроков',!empty($record['active'])); ?>
<?php elseif($tab==='products'): ?><?php admin_input('name','Название товара',$record['name']??'','text',true);admin_input('description','Описание',$record['description']??'','textarea');admin_input('price','Стоимость (например: 299 ₽)',$record['price']??'');admin_input('checkout_url','HTTPS-ссылка на официальную оплату',$record['checkout_url']??'');admin_check('visible','Показать товар на сайте',!empty($record['visible'])); ?>
<?php elseif($tab==='faq'): ?><?php admin_input('question','Вопрос',$record['question']??'','text',true);admin_input('answer','Ответ',$record['answer']??'','textarea',true); ?><?php endif; ?>
<button class="primary" type="submit">СОХРАНИТЬ ↗</button></form></section><section class="panel"><h2>Сохранённые записи <span class="record-count"><?=count($collection)?></span></h2><?php if(!$collection): ?><p>Записей пока нет.</p><?php endif; ?><div class="records"><?php foreach($collection as $r): ?><div class="record"><div><small>ID <?=e($r['id']??'')?></small><strong><?=e($r['title']??$r['name']??$r['question']??'Запись')?></strong><span><?=e($r['excerpt']??$r['description']??$r['answer']??'')?></span></div><div class="record-actions"><a href="<?=admin_url($tab).'&edit='.rawurlencode((string)$r['id'])?>">Изменить</a><form method="post" data-confirm="Удалить запись без возможности восстановления?"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id']??'')?>"><button class="danger" type="submit">Удалить</button></form></div></div><?php endforeach; ?></div></section>
<?php elseif($tab==='tickets'): $tickets=store_read('tickets',[]); ?><section class="panel"><h2>Обращения игроков <span class="record-count"><?=count($tickets)?></span></h2><p>Обращения записываются на сервере. Для ответа используй контакт, который указал отправитель; автоматическая отправка ответов не включена.</p><div class="records"><?php foreach($tickets as $ticket): ?><div class="ticket"><div class="ticket-head"><b>#<?=e($ticket['id']??'')?></b><span class="ticket-status <?=e($ticket['status']??'new')?>"><?=($ticket['status']??'new')==='resolved'?'ЗАКРЫТО':'НОВОЕ'?></span><time><?=e($ticket['created']??'')?></time></div><h3><?=e($ticket['subject']??'')?></h3><div class="ticket-contact"><?=e($ticket['name']??'')?> • <?=e($ticket['contact']??'')?></div><p><?=nl2br(e($ticket['message']??''))?></p><div class="record-actions"><?php if(($ticket['status']??'new')!=='resolved'): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?=e($ticket['id']??'')?>"><button class="secondary" type="submit">Отметить решённым</button></form><?php endif; ?><form method="post" data-confirm="Безвозвратно удалить это обращение?"><?=csrf_field()?><input type="hidden" name="action" value="delete_ticket"><input type="hidden" name="id" value="<?=e($ticket['id']??'')?>"><button class="danger" type="submit">Удалить</button></form></div></div><?php endforeach; ?><?php if(!$tickets): ?><p>Обращений пока нет.</p><?php endif; ?></div></section>
<?php endif; ?>
</div></div></div>
<?php endif; ?><script src="assets/js/admin.js" defer></script></body></html>
