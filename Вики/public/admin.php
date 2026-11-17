<?php
declare(strict_types=1);
require_once __DIR__.'/../app/layout.php';must_install();$user=login_required();
$section=(string)($_GET['section']??'dashboard');$id=max(0,(int)($_GET['id']??0));$allowed=['dashboard','articles','article','categories','category','settings','account','revisions'];if(!in_array($section,$allowed,true))$section='dashboard';
$errors=[];$now=gmdate('c');
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$action=(string)($_POST['action']??'');
 try {
  if($action==='article_save'){
   $aid=max(0,(int)($_POST['id']??0));$title=trim((string)($_POST['title']??''));$summary=trim((string)($_POST['summary']??''));$body=trim((string)($_POST['body']??''));$category=(int)($_POST['category_id']??0);
   $slug=slugify((string)($_POST['slug']??''));if($slug==='')$slug=slugify($title);$tagstr=trim((string)($_POST['tags']??''));$tags=array_values(array_unique(array_filter(array_map('trim',explode(',',$tagstr)))));
   $status=($_POST['status']??'draft')==='published'?'published':'draft';$featured=!empty($_POST['featured']);
   if(strlen($title)<3||strlen($title)>180)throw new RuntimeException('Название статьи: от 3 до 180 символов.');
   if(strlen($summary)>700||strlen($body)>180000)throw new RuntimeException('Текст или описание слишком длинные.');
   if($slug===''||strlen($slug)>130)throw new RuntimeException('Неверный адрес статьи.');
   if(!category_of(data_read('categories'),$category))throw new RuntimeException('Выбери существующий раздел.');
   $savedId=0;
   data_update('articles',function($items)use($aid,$title,$summary,$body,$category,$slug,$tags,$status,$featured,$now,$user,&$savedId){
     foreach($items as $a)if($a['slug']===$slug&&(int)$a['id']!==$aid)throw new RuntimeException('Такой адрес статьи уже используется.');
     foreach($items as $i=>$a)if((int)$a['id']===$aid){
       // Store a snapshot of the old article for rollback.
       $snapshot=['id'=>max(1,(int)(microtime(true)*1000000)),'article_id'=>$aid,'saved_at'=>$now,'saved_by'=>$user['username'],'article'=>$a];
       data_update('revisions',fn($revs)=>array_slice(array_merge($revs,[$snapshot]),-250));
       $items[$i]=array_replace($a,['title'=>$title,'slug'=>$slug,'summary'=>$summary,'body'=>$body,'category_id'=>$category,'tags'=>$tags,'status'=>$status,'featured'=>$featured,'updated_at'=>$now]);$savedId=$aid;return $items;
     }
     $savedId=max(array_column($items,'id')?:[0])+1;
     $items[]=['id'=>$savedId,'slug'=>$slug,'title'=>$title,'summary'=>$summary,'body'=>$body,'category_id'=>$category,'tags'=>$tags,'status'=>$status,'featured'=>$featured,'author'=>$user['username'],'created_at'=>$now,'updated_at'=>$now];return $items;
   });flash('Статья сохранена.');redirect(url('admin.php',['section'=>'article','id'=>$savedId]));
  }
  if($action==='article_delete'){
   $aid=(int)($_POST['id']??0);
   data_update('articles',fn($xs)=>array_values(array_filter($xs,fn($a)=>(int)$a['id']!==$aid)));
   flash('Статья удалена.');redirect('admin.php?section=articles');
  }
  if($action==='revision_restore'){
   $rid=(int)($_POST['revision_id']??0);$old=null;
   foreach(data_read('revisions') as $r)if((int)$r['id']===$rid)$old=$r['article'];
   if(!$old)throw new RuntimeException('Версия не найдена.');
   data_update('articles',function($xs)use($old,$now){foreach($xs as $i=>$a)if((int)$a['id']===(int)$old['id']){$old['updated_at']=$now;$xs[$i]=$old;return $xs;}throw new RuntimeException('Статья не найдена.');});
   flash('Предыдущая версия восстановлена.');redirect(url('admin.php',['section'=>'article','id'=>$old['id']]));
  }
  if($action==='category_save'){
   $cid=(int)($_POST['id']??0);$title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));$icon=trim((string)($_POST['icon']??'📘'));$slug=slugify((string)($_POST['slug']??''));if(!$slug)$slug=slugify($title);$sort=(int)($_POST['sort']??0);
   if(strlen($title)<2||strlen($title)>100||strlen($description)>400||strlen($icon)>40||strlen($slug)>120)throw new RuntimeException('Проверь длину полей категории.');
   data_update('categories',function($items)use($cid,$title,$description,$icon,$slug,$sort){
    foreach($items as $c)if($c['slug']===$slug&&(int)$c['id']!==$cid)throw new RuntimeException('Адрес категории занят.');
    foreach($items as $i=>$c)if((int)$c['id']===$cid){$items[$i]=array_replace($c,compact('title','description','icon','slug','sort'));return $items;}
    $items[]=array_merge(['id'=>max(array_column($items,'id')?:[0])+1],compact('title','description','icon','slug','sort'));return $items;
   });flash('Раздел сохранён.');redirect('admin.php?section=categories');
  }
  if($action==='category_delete'){
   $cid=(int)($_POST['id']??0);
   foreach(data_read('articles') as $a)if((int)$a['category_id']===$cid)throw new RuntimeException('Нельзя удалить раздел со статьями. Перенеси их в другой раздел.');
   data_update('categories',fn($xs)=>array_values(array_filter($xs,fn($c)=>(int)$c['id']!==$cid)));flash('Раздел удалён.');redirect('admin.php?section=categories');
  }
  if($action==='settings_save'){
   $fields=['name','wiki_title','subtitle','announcement','site_url','forum_url','vk_url','tg_url','download_url','contact_email','footer_text'];$out=[];
   foreach($fields as $f){$out[$f]=trim((string)($_POST[$f]??''));if(strlen($out[$f])>1200)throw new RuntimeException('Слишком длинное значение: '.$f);}
   foreach(['site_url','forum_url','vk_url','tg_url','download_url'] as $f)if($out[$f]!==''&&safe_http_url($out[$f])==='')throw new RuntimeException('Укажи ссылку с https:// для '.$f);
   if($out['contact_email']!==''&&!filter_var($out['contact_email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Неверный email.');
   data_save('settings',$out);flash('Настройки сохранены.');redirect('admin.php?section=settings');
  }
  if($action==='password_save'){
   $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');
   if(!password_verify($old,$user['password_hash']))throw new RuntimeException('Текущий пароль неверен.');
   if(strlen($new)<12)throw new RuntimeException('Новый пароль: минимум 12 символов.');
   data_update('users',function($xs)use($user,$new){foreach($xs as $i=>$u)if($u['id']===$user['id'])$xs[$i]['password_hash']=password_hash($new,PASSWORD_DEFAULT);return $xs;});flash('Пароль изменён.');redirect('admin.php?section=account');
  }
  throw new RuntimeException('Неизвестное действие.');
 }catch(Throwable $e){$errors[]=$e->getMessage();}
}
$articles=data_read('articles');usort($articles,fn($a,$b)=>strcmp($b['updated_at']??'',$a['updated_at']??''));$cats=data_read('categories');usort($cats,fn($a,$b)=>(int)$a['sort']<=>(int)$b['sort']);
render_head('Управление Wiki');?>
<div class="admin-head"><div><div class="eyebrow">ПАНЕЛЬ УПРАВЛЕНИЯ</div><h1>Редакция SLAVIA Wiki</h1><p>Ты вошёл как <b><?=h($user['username'])?></b>. Все изменения сохраняются сразу.</p></div><form method="post" action="logout.php"><?=csrf_field()?><button class="outline-btn" type="submit">Выйти ↗</button></form></div>
<div class="admin-tabs"><a class="<?=$section==='dashboard'?'sel':''?>" href="admin.php">Обзор</a><a class="<?=in_array($section,['articles','article','revisions'])?'sel':''?>" href="admin.php?section=articles">Статьи</a><a class="<?=in_array($section,['categories','category'])?'sel':''?>" href="admin.php?section=categories">Разделы</a><a class="<?=$section==='settings'?'sel':''?>" href="admin.php?section=settings">Настройки</a><a class="<?=$section==='account'?'sel':''?>" href="admin.php?section=account">Аккаунт</a><a href="index.php">Посмотреть Wiki ↗</a></div>
<?php foreach($errors as $error):?><div class="notice error"><?=h($error)?></div><?php endforeach;?>
<?php if($section==='dashboard'): ?>
<div class="admin-stat-grid"><div><span>Всего статей</span><strong><?=count($articles)?></strong></div><div><span>Опубликовано</span><strong><?=count(published($articles))?></strong></div><div><span>Черновиков</span><strong><?=count($articles)-count(published($articles))?></strong></div><div><span>Разделов</span><strong><?=count($cats)?></strong></div></div><div class="admin-panel"><div class="panel-title"><h2>Последние материалы</h2><a class="primary-btn" href="admin.php?section=article">+ Новая статья</a></div><?php foreach(array_slice($articles,0,8) as $a):?><div class="admin-list-row"><div><strong><?=h($a['title'])?></strong><small><?=h($a['status']==='published'?'Опубликовано':'Черновик')?> • <?=h(date_ru($a['updated_at']))?></small></div><a href="<?=h(url('admin.php',['section'=>'article','id'=>$a['id']]))?>">Редактировать →</a></div><?php endforeach;?></div>
<?php elseif($section==='articles'):?><div class="admin-panel"><div class="panel-title"><h2>Статьи <span>(<?=count($articles)?>)</span></h2><a class="primary-btn" href="admin.php?section=article">+ Новая статья</a></div><div class="admin-list"><?php foreach($articles as $a):?><div class="admin-list-row"><div><strong><?=h($a['title'])?></strong><small><?=h((category_of($cats,(int)$a['category_id'])['title']??'—'))?> • <?=h($a['status']==='published'?'Опубликовано':'Черновик')?> • <?=h(date_ru($a['updated_at']))?></small></div><a href="<?=h(url('admin.php',['section'=>'article','id'=>$a['id']]))?>">Изменить →</a></div><?php endforeach;?></div></div>
<?php elseif($section==='article'):
 $editing=null;foreach($articles as $a)if((int)$a['id']===$id){$editing=$a;break;}$a=$editing??['id'=>0,'title'=>'','slug'=>'','summary'=>'','body'=>'','tags'=>[],'category_id'=>($cats[0]['id']??0),'status'=>'draft','featured'=>false];
 ?><div class="admin-panel editor-panel"><div class="panel-title"><h2><?=$editing?'Изменить статью':'Новая статья'?></h2><a href="admin.php?section=articles">← К списку</a></div><form method="post" class="editor-form" id="editorForm"><?=csrf_field()?><input type="hidden" name="action" value="article_save"><input type="hidden" name="id" value="<?=h($a['id'])?>"><div class="field-grid"><label>Название *<input name="title" value="<?=h($a['title'])?>" maxlength="180" required></label><label>URL-адрес (латиница)<input name="slug" value="<?=h($a['slug'])?>" placeholder="avtomaticheski-po-nazvaniyu"></label></div><label>Краткое описание<textarea name="summary" rows="2" maxlength="700"><?=h($a['summary'])?></textarea></label><div class="field-grid"><label>Раздел *<select name="category_id" required><?php foreach($cats as $c):?><option value="<?=h($c['id'])?>" <?=(int)$a['category_id']===(int)$c['id']?'selected':''?>><?=h($c['title'])?></option><?php endforeach;?></select></label><label>Статус<select name="status"><option value="draft" <?=$a['status']==='draft'?'selected':''?>>Черновик</option><option value="published" <?=$a['status']==='published'?'selected':''?>>Опубликовано</option></select></label></div><label>Текст статьи (Markdown) *<textarea id="mdEditor" name="body" rows="18" class="markdown-editor" required placeholder="# Заголовок\n\nТекст статьи..."><?=h($a['body'])?></textarea></label><div class="editor-hint">Форматирование: <code># Заголовок</code>, <code>## Подзаголовок</code>, <code>**жирный**</code>, <code>- список</code>, <code>[ссылка](https://...)</code>. HTML и скрипты не исполняются.</div><div class="field-grid"><label>Теги через запятую<input name="tags" value="<?=h(implode(', ',$a['tags']??[]))?>" placeholder="гайд, новичкам"></label><label class="check-label"><input type="checkbox" name="featured" value="1" <?=!empty($a['featured'])?'checked':''?>> Показывать в рекомендованных</label></div><div class="editor-actions"><button class="primary-btn" type="submit">Сохранить статью</button><?php if($editing&&$a['status']==='published'):?><a class="outline-btn" target="_blank" rel="noopener" href="<?=h(url('index.php',['article'=>$a['slug']]))?>">Посмотреть ↗</a><?php endif;?></div></form></div>
<?php if($editing):?><div class="admin-panel destructive-panel"><div class="panel-title"><h2>История изменений</h2></div><?php $revs=array_values(array_filter(data_read('revisions'),fn($r)=>(int)$r['article_id']===$id));usort($revs,fn($a,$b)=>strcmp($b['saved_at'],$a['saved_at']));foreach(array_slice($revs,0,8) as $r):?><div class="admin-list-row"><div><strong><?=h(date_ru($r['saved_at']))?></strong><small>Сохранено пользователем <?=h($r['saved_by'])?></small></div><form method="post" onsubmit="return confirm('Восстановить этот вариант статьи?')"><?=csrf_field()?><input type="hidden" name="action" value="revision_restore"><input type="hidden" name="revision_id" value="<?=h($r['id'])?>"><button class="outline-btn" type="submit">Восстановить</button></form></div><?php endforeach;?><?php if(!$revs):?><p>История появится после первого редактирования.</p><?php endif;?><hr><form method="post" onsubmit="return confirm('Удалить статью без возможности восстановления?')"><?=csrf_field()?><input type="hidden" name="action" value="article_delete"><input type="hidden" name="id" value="<?=h($id)?>"><button class="danger-btn" type="submit">Удалить статью</button></form></div><?php endif;?>
<?php elseif($section==='categories'):?><div class="admin-panel"><div class="panel-title"><h2>Разделы базы знаний</h2><a class="primary-btn" href="admin.php?section=category">+ Новый раздел</a></div><?php foreach($cats as $c):?><div class="admin-list-row"><div><strong><?=h($c['icon'].' '.$c['title'])?></strong><small><?=h($c['description'])?> • <?=count(array_filter($articles,fn($a)=>(int)$a['category_id']===(int)$c['id']))?> статей</small></div><a href="<?=h(url('admin.php',['section'=>'category','id'=>$c['id']]))?>">Изменить →</a></div><?php endforeach;?></div>
<?php elseif($section==='category'):
 $edit=null;foreach($cats as $c)if((int)$c['id']===$id)$edit=$c;$c=$edit??['id'=>0,'title'=>'','slug'=>'','description'=>'','icon'=>'📘','sort'=>100];
 ?><div class="admin-panel editor-panel"><div class="panel-title"><h2><?=$edit?'Изменить раздел':'Новый раздел'?></h2><a href="admin.php?section=categories">← К списку</a></div><form method="post" class="editor-form"><?=csrf_field()?><input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="<?=h($c['id'])?>"><div class="field-grid"><label>Название *<input name="title" value="<?=h($c['title'])?>" required></label><label>URL-адрес<input name="slug" value="<?=h($c['slug'])?>" placeholder="adres-razdela"></label></div><label>Описание<textarea rows="3" name="description"><?=h($c['description'])?></textarea></label><div class="field-grid"><label>Иконка (эмодзи)<input name="icon" value="<?=h($c['icon'])?>"></label><label>Порядок<input type="number" name="sort" value="<?=h($c['sort'])?>"></label></div><button class="primary-btn" type="submit">Сохранить раздел</button></form><?php if($edit):?><form method="post" class="delete-category" onsubmit="return confirm('Удалить пустой раздел?')"><?=csrf_field()?><input type="hidden" name="action" value="category_delete"><input type="hidden" name="id" value="<?=h($id)?>"><button class="danger-btn" type="submit">Удалить раздел</button></form><?php endif;?></div>
<?php elseif($section==='settings'):$s=settings();?><div class="admin-panel editor-panel"><div class="panel-title"><h2>Настройки проекта</h2></div><form method="post" class="editor-form"><?=csrf_field()?><input type="hidden" name="action" value="settings_save"><?php foreach(['name'=>'Название проекта','wiki_title'=>'Заголовок Wiki','subtitle'=>'Описание базы знаний','announcement'=>'Объявление на главной','site_url'=>'Адрес Wiki (необязательно)','forum_url'=>'Адрес форума','vk_url'=>'ВКонтакте','tg_url'=>'Telegram','download_url'=>'Ссылка на клиент игры','contact_email'=>'Контактный email','footer_text'=>'Текст внизу сайта'] as $key=>$label):?><label><?=h($label)?><?php if(in_array($key,['subtitle','announcement','footer_text'])):?><textarea name="<?=h($key)?>" rows="3"><?=h($s[$key]??'')?></textarea><?php else:?><input name="<?=h($key)?>" value="<?=h($s[$key]??'')?>" <?=str_ends_with($key,'_url')?'placeholder="https://..."':''?>><?php endif;?></label><?php endforeach;?><button class="primary-btn" type="submit">Сохранить настройки</button></form></div>
<?php elseif($section==='account'):?><div class="admin-panel editor-panel"><div class="panel-title"><h2>Безопасность аккаунта</h2></div><form method="post" class="editor-form"><?=csrf_field()?><input type="hidden" name="action" value="password_save"><label>Текущий пароль<input type="password" name="old_password" required autocomplete="current-password"></label><label>Новый пароль (от 12 символов)<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label><button class="primary-btn" type="submit">Изменить пароль</button></form></div>
<?php endif;render_footer();
