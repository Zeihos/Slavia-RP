<?php
require dirname(__DIR__).'/app/ui.php';
start_app();
if(!installed())nav('install.php');
$db=db_read();$me=user($db);
if($_SERVER['REQUEST_METHOD']==='POST') {
    $target='index.php';
    try {
        check_token();
        $action=(string)($_POST['action']??'');
        $target=safe_redirect();
        if($action==='login') {
            rate('login',12,900);
            $username=poststr('username',60);$pwd=(string)($_POST['password']??'');
            $match=null;foreach($db['users'] as $u)if(lower($u['username'])===lower($username)){$match=$u;break;}
            if(!$match || !password_verify($pwd,$match['password_hash']))throw new RuntimeException('Неверное имя пользователя или пароль.');
            if(!empty($match['banned_until']) && ($match['banned_until']===-1 || $match['banned_until']>time())) throw new RuntimeException('Аккаунт заблокирован.');
            session_regenerate_id(true);$_SESSION['uid']=$match['id'];$_SESSION['auth_version']=hash('sha256',(string)$match['password_hash']);flash('С возвращением, '.$match['username'].'!');
            $target='index.php';
        } elseif($action==='register') {
            rate('register',4,3600);
            if(settings($db,'registration')!=='1')throw new RuntimeException('Регистрация временно закрыта.');
            if(!empty($_POST['website']))throw new RuntimeException('Ошибка проверки формы.');
            $name=ensure_length(poststr('username',24),3,24,'Имя');
            if(!preg_match('/^[\pL\pN_][\pL\pN_ .-]{2,23}$/u',$name))throw new RuntimeException('Имя содержит недопустимые символы.');
            $pwd=(string)($_POST['password']??'');
            if(strlen($pwd)<10||strlen($pwd)>128)throw new RuntimeException('Длина пароля должна быть от 10 до 128 символов.');
            if($pwd!==(string)($_POST['password_again']??''))throw new RuntimeException('Пароли не совпадают.');
            $pwdHash=password_hash($pwd,PASSWORD_DEFAULT);
            $id=db_write(function (&$d) use($name,$pwdHash) {
                foreach($d['users'] as $u)if(lower($u['username'])===lower($name))throw new RuntimeException('Это имя уже занято.');
                $id=nextid($d,'users');$d['users'][(string)$id]=['id'=>$id,'username'=>$name,'password_hash'=>$pwdHash,'role'=>'user','created_at'=>time(),'banned_until'=>0];return $id;
            });
            session_regenerate_id(true);$_SESSION['uid']=$id;$_SESSION['auth_version']=hash('sha256',$pwdHash);flash('Аккаунт создан. Добро пожаловать на форум SLAVIA!');
        } elseif($action==='logout') {
            $_SESSION=[];session_regenerate_id(true);flash('Вы вышли из аккаунта.');
        } elseif($action==='change_password') {
            $who=need_user($me);rate('password',8,3600);
            $old=(string)($_POST['password_old']??'');$new=(string)($_POST['password_new']??'');
            if(!password_verify($old,$who['password_hash']))throw new RuntimeException('Текущий пароль неверный.');
            if(strlen($new)<10||strlen($new)>128)throw new RuntimeException('Новый пароль должен содержать от 10 до 128 символов.');
            if($old===$new)throw new RuntimeException('Новый пароль должен отличаться от текущего.');
            if($new!==(string)($_POST['password_again']??''))throw new RuntimeException('Новые пароли не совпадают.');
            $pwdHash=password_hash($new,PASSWORD_DEFAULT);
            db_write(function (&$d)use($who,$pwdHash){
                if(!isset($d['users'][(string)$who['id']]))throw new RuntimeException('Аккаунт не найден.');
                $d['users'][(string)$who['id']]['password_hash']=$pwdHash;
            });session_regenerate_id(true);$_SESSION['auth_version']=hash('sha256',$pwdHash);flash('Пароль изменён. Другие сеансы завершены.');$target='index.php?page=profile&id='.$who['id'];
        } elseif($action==='create_topic') {
            $who=need_user($me);rate('topic',6,600);
            $bid=(int)($_POST['board_id']??0);
            $title=ensure_length(poststr('title',MAX_TOPIC_TITLE),5,MAX_TOPIC_TITLE,'Заголовок темы');
            $body=ensure_length(poststr('body',MAX_POST_BODY),10,MAX_POST_BODY,'Сообщение');
            $id=db_write(function (&$d)use($bid,$title,$body,$who) {
                $b=find_board($d,$bid,$who);if(!can_post_board($b,$who))throw new RuntimeException('Создание тем в этом разделе ограничено.');
                $id=nextid($d,'topics');$pid=nextid($d,'posts');$now=time();
                $d['topics'][(string)$id]=['id'=>$id,'board_id'=>$bid,'user_id'=>$who['id'],'title'=>$title,'created_at'=>$now,'updated_at'=>$now,'pinned'=>false,'locked'=>false,'views'=>0];
                $d['posts'][(string)$pid]=['id'=>$pid,'topic_id'=>$id,'user_id'=>$who['id'],'body'=>$body,'created_at'=>$now,'updated_at'=>$now];
                return $id;
            });flash('Тема опубликована.');$target=q($id);
        } elseif($action==='reply') {
            $who=need_user($me);rate('reply',20,600);
            $tid=(int)($_POST['topic_id']??0);
            $body=ensure_length(poststr('body',MAX_POST_BODY),2,MAX_POST_BODY,'Ответ');
            db_write(function (&$d)use($tid,$body,$who) {
                $t=find_topic($d,$tid,$who);$b=find_board($d,$t['board_id'],$who);
                if(!can_post_board($b,$who))throw new RuntimeException('Ответы в этом разделе ограничены.');
                if($t['locked'] && !is_staff($who))throw new RuntimeException('Тема закрыта.');
                $pid=nextid($d,'posts');$now=time();
                $d['posts'][(string)$pid]=['id'=>$pid,'topic_id'=>$tid,'user_id'=>$who['id'],'body'=>$body,'created_at'=>$now,'updated_at'=>$now];
                $d['topics'][(string)$tid]['updated_at']=$now;
            });flash('Ответ добавлен.');$target=q($tid).'#replies';
        } elseif($action==='edit_post') {
            $who=need_user($me);$pid=(int)($_POST['post_id']??0);
            $body=ensure_length(poststr('body',MAX_POST_BODY),2,MAX_POST_BODY,'Сообщение');
            $tid=db_write(function (&$d)use($pid,$body,$who) {
                $p=$d['posts'][(string)$pid]??null;if(!$p)throw new RuntimeException('Сообщение не найдено.');
                find_topic($d,$p['topic_id'],$who);
                if($p['user_id']!==$who['id'] && !is_staff($who))throw new RuntimeException('Редактирование недоступно.');
                $d['posts'][(string)$pid]['body']=$body;$d['posts'][(string)$pid]['updated_at']=time();return $p['topic_id'];
            });flash('Сообщение обновлено.');$target=q($tid).'#post-'.$pid;
        } elseif($action==='report') {
            $who=need_user($me);rate('report',6,3600);
            $tid=(int)($_POST['topic_id']??0);
            $reason=ensure_length(poststr('reason',1000),10,1000,'Причина жалобы');
            db_write(function (&$d)use($tid,$reason,$who) {
                find_topic($d,$tid,$who);$id=nextid($d,'reports');
                $d['reports'][(string)$id]=['id'=>$id,'topic_id'=>$tid,'user_id'=>$who['id'],'reason'=>$reason,'created_at'=>time(),'resolved'=>false];
            });flash('Жалоба отправлена модераторам.');$target=q($tid);
        } elseif(in_array($action,['pin','lock','delete_topic','delete_post'],true)) {
            $who=need_staff($me);$tid=(int)($_POST['topic_id']??0);
            db_write(function (&$d)use($action,$tid,$who) {
                $t=find_topic($d,$tid,$who);
                if($action==='pin')$d['topics'][(string)$tid]['pinned']=!$t['pinned'];
                if($action==='lock')$d['topics'][(string)$tid]['locked']=!$t['locked'];
                if($action==='delete_topic'){
                    unset($d['topics'][(string)$tid]);
                    foreach($d['posts'] as $pid=>$p)if($p['topic_id']===$tid)unset($d['posts'][$pid]);
                    foreach($d['reports'] as $rid=>$r)if($r['topic_id']===$tid)unset($d['reports'][$rid]);
                }
                if($action==='delete_post') {
                    $pid=(int)($_POST['post_id']??0);$p=$d['posts'][(string)$pid]??null;
                    if(!$p || $p['topic_id']!==$tid)throw new RuntimeException('Сообщение не найдено.');
                    $posts=topic_posts($d,$tid);
                    if($posts[0]['id']===$pid)throw new RuntimeException('Нельзя удалить первый пост. Удалите тему целиком.');
                    unset($d['posts'][(string)$pid]);
                    $remaining=topic_posts($d,$tid);
                    $d['topics'][(string)$tid]['updated_at']=end($remaining)['created_at'];
                }
            });flash('Действие выполнено.');$target=$action==='delete_topic'?'index.php':q($tid);
        } elseif($action==='admin_settings') {
            need_admin($me);
            $names=['forum_name'=>70,'subtitle'=>150,'welcome'=>900,'vk'=>250,'telegram'=>250,'site'=>250,'rules'=>6000];
            $values=[];foreach($names as $k=>$len)$values[$k]=poststr($k,$len);
            foreach(['vk','telegram','site'] as $k)$values[$k]=clean_external_url($values[$k]);
            $values['registration']=!empty($_POST['registration'])?'1':'0';
            db_write(function (&$d)use($values){foreach($values as $k=>$v)$d['settings'][$k]=$v;});
            flash('Настройки сохранены.');$target='index.php?page=staff';
        } elseif($action==='add_category') {
            need_admin($me);$name=ensure_length(poststr('name',80),3,80,'Название категории');
            $description=poststr('description',180);
            db_write(function (&$d)use($name,$description){$id=nextid($d,'categories');$d['categories'][(string)$id]=['id'=>$id,'name'=>$name,'description'=>$description,'sort'=>$id*10];});
            flash('Категория добавлена.');$target='index.php?page=staff';
        } elseif($action==='add_board') {
            need_admin($me);$name=ensure_length(poststr('name',80),3,80,'Название раздела');
            $desc=poststr('description',200);$cat=(int)($_POST['category_id']??0);
            $access=in_array($_POST['access']??'',['public','staff'],true)?$_POST['access']:'public';
            $posting=in_array($_POST['posting']??'',['all','staff'],true)?$_POST['posting']:'all';
            db_write(function (&$d)use($name,$desc,$cat,$access,$posting){
                if(!isset($d['categories'][(string)$cat]))throw new RuntimeException('Категория не найдена.');
                $id=nextid($d,'boards');$d['boards'][(string)$id]=['id'=>$id,'category_id'=>$cat,'name'=>$name,'description'=>$desc,'icon'=>'folder','posting'=>$posting,'access'=>$access,'sort'=>$id*10];
            });flash('Раздел создан.');$target='index.php?page=staff';
        } elseif($action==='edit_board') {
            need_admin($me);$bid=(int)($_POST['board_id']??0);
            $name=ensure_length(poststr('name',80),3,80,'Название');$desc=poststr('description',200);
            $access=in_array($_POST['access']??'',['public','staff'],true)?$_POST['access']:'public';
            $posting=in_array($_POST['posting']??'',['all','staff'],true)?$_POST['posting']:'all';
            db_write(function (&$d)use($bid,$name,$desc,$access,$posting){
                if(!isset($d['boards'][(string)$bid]))throw new RuntimeException('Раздел не найден.');
                foreach(['name'=>$name,'description'=>$desc,'access'=>$access,'posting'=>$posting] as $k=>$v)$d['boards'][(string)$bid][$k]=$v;
            });flash('Раздел изменён.');$target='index.php?page=staff';
        } elseif($action==='user_role') {
            need_admin($me);$uid=(int)($_POST['user_id']??0);$role=(string)($_POST['role']??'');
            if(!in_array($role,['user','moderator','admin'],true))throw new RuntimeException('Неверная роль.');
            db_write(function (&$d)use($uid,$role,$me) {
                if(!isset($d['users'][(string)$uid]) || $uid===$me['id'])throw new RuntimeException('Изменение своей роли недоступно.');
                $d['users'][(string)$uid]['role']=$role;
            });flash('Роль изменена.');$target='index.php?page=staff';
        } elseif($action==='user_ban') {
            need_admin($me);$uid=(int)($_POST['user_id']??0);$days=(int)($_POST['days']??0);
            if(!in_array($days,[0,1,7,30,-1],true))throw new RuntimeException('Некорректный срок.');
            db_write(function (&$d)use($uid,$days,$me) {
                if(!isset($d['users'][(string)$uid]) || $uid===$me['id'] || $d['users'][(string)$uid]['role']==='admin')throw new RuntimeException('Этого пользователя нельзя заблокировать.');
                $d['users'][(string)$uid]['banned_until']=$days===-1?-1:($days===0?0:time()+$days*86400);
            });flash('Ограничение пользователя изменено.');$target='index.php?page=staff';
        } elseif($action==='resolve_report') {
            $who=need_staff($me);$rid=(int)($_POST['report_id']??0);
            db_write(function (&$d)use($rid){if(!isset($d['reports'][(string)$rid]))throw new RuntimeException('Жалоба не найдена.');$d['reports'][(string)$rid]['resolved']=true;});
            flash('Жалоба обработана.');$target='index.php?page=staff';
        } else throw new RuntimeException('Неизвестное действие.');
    } catch(Throwable $e){flash($e->getMessage(),'error');if($target==='index.php' && isset($_POST['return']))$target=safe_redirect();}
    nav($target);
}
$page=(string)($_GET['page']??'home');
$allowed=['home','board','topic','latest','rules','search','login','register','profile','new','edit','report','staff'];
if(!in_array($page,$allowed,true)){$page='notfound';http_response_code(404);}
// Any errors in GET views are transformed into a clean not-found result.
$viewError='';$currentBoard=null;$currentTopic=null;$currentPost=null;
try {
    if(in_array($page,['board','new'],true))$currentBoard=find_board($db,(int)($_GET['id']??0),$me);
    if(in_array($page,['topic','report'],true))$currentTopic=find_topic($db,(int)($_GET['id']??0),$me);
    if($page==='edit') {
        $currentPost=$db['posts'][(string)(int)($_GET['id']??0)]??null;
        if(!$currentPost)throw new RuntimeException('Сообщение не найдено.');
        $currentTopic=find_topic($db,$currentPost['topic_id'],$me);
        if(!$me||($me['id']!==$currentPost['user_id']&&!is_staff($me)))throw new RuntimeException('Недостаточно прав.');
    }
    if($page==='staff')need_staff($me);
} catch(Throwable $e){$page='notfound';$viewError=$e->getMessage();http_response_code(404);}
$title=['home'=>'Главная','board'=>'Раздел форума','topic'=>'Обсуждение','latest'=>'Новые темы','rules'=>'Правила','search'=>'Поиск','login'=>'Вход','register'=>'Регистрация','profile'=>'Профиль','new'=>'Новая тема','edit'=>'Редактирование','report'=>'Жалоба','staff'=>'Управление','notfound'=>'Не найдено'][$page];
header_view($db,$me,$title);
if($page==='home'):
    [$nu,$nt,$np]=stats($db);
    ?><section class="hero"><div class="hero-cover"></div><div class="hero-overlay"></div><div class="hero-content"><div class="hero-label"><span></span> ОФИЦИАЛЬНОЕ СООБЩЕСТВО</div><h1><span>SLAVIA</span><br>ROLEPLAY</h1><div class="hero-badge">ФОРУМ ПРОЕКТА</div><p><?=h(settings($db,'welcome'))?></p><div class="hero-actions"><?php if(!$me):?><a class="btn btn-primary" href="index.php?page=register">Присоединиться <span>↗</span></a><?php else:?><a class="btn btn-primary" href="index.php?page=latest">Новые обсуждения <span>↗</span></a><?php endif;?><a class="btn btn-glass" href="index.php?page=rules">Правила форума</a></div></div><div class="hero-stats"><span><b><?=$nu?></b> участников</span><span><b><?=$nt?></b> тем</span><span><b><?=$np?></b> сообщений</span></div></section>
    <div class="section-top"><div><div class="eyebrow">ПРОСТРАНСТВО ИГРОКОВ</div><h2>Разделы форума</h2></div><a class="subtle-link" href="index.php?page=latest">Свежие обсуждения ↗</a></div>
    <div class="layout-grid"><div class="content-col">
    <?php
    $cats=array_values($db['categories']);usort($cats,fn($a,$b)=>$a['sort']<=>$b['sort']);
    foreach($cats as $cat){
        $boards=array_values(array_filter($db['boards'],fn($b)=>$b['category_id']===$cat['id']&&visible_board($b,$me)));
        if(!$boards)continue;
        usort($boards,fn($a,$b)=>$a['sort']<=>$b['sort']);
        echo '<section class="forum-group"><div class="group-heading"><span class="group-symbol">✦</span><span><strong>'.h($cat['name']).'</strong><small>'.h($cat['description']).'</small></span><span class="group-count">'.count($boards).' разделов</span></div><div class="group-list">';
        foreach($boards as $b)board_row($db,$b);
        echo '</div></section>';
    }
    ?></div><?php community_side($db,$me);?></div>
<?php elseif($page==='board'):
    $b=$currentBoard;crumbs([[$b['name'],null]]);page_heading($b['name'],$b['description']);
    $topics=array_values(array_filter($db['topics'],fn($t)=>$t['board_id']===$b['id']));usort($topics,'topic_sort');
    $p=max(1,(int)($_GET['p']??1));?>
    <div class="toolbar"><span><?=$p>1?'Страница '.$p.' · ':''?>Тем: <?=count($topics)?></span><div><?php if($me&&can_post_board($b,$me)):?><a class="btn btn-primary" href="index.php?page=new&id=<?=$b['id']?>">+ Создать тему</a><?php elseif(!$me):?><a class="btn btn-ghost" href="index.php?page=login">Войдите для публикации</a><?php endif;?></div></div>
    <div class="topic-list"><?php foreach(page_slice($topics,$p,20) as $t)topic_row($db,$t);if(!$topics):?><div class="empty"><span>✧</span><h3>Пока нет тем</h3><p>Здесь появятся первые обсуждения SLAVIA.</p></div><?php endif;?></div>
    <?=pages(count($topics),$p,20,board_url($b['id']))?>
<?php elseif($page==='topic'):
    $t=$currentTopic;$b=$db['boards'][(string)$t['board_id']];
    $visit='viewed_'.$t['id'];if(empty($_SESSION[$visit])){db_write(function (&$d)use($t){if(isset($d['topics'][(string)$t['id']]))$d['topics'][(string)$t['id']]['views']++;});$_SESSION[$visit]=true;}
    crumbs([[$b['name'],board_url($b['id'])],[$t['title'],null]]);
    page_heading($t['title'],'Автор: '.($db['users'][(string)$t['user_id']]['username']??'Удалённый пользователь').' · '.dt($t['created_at']));
    if($t['locked']):?><div class="notice">🔒 Тема закрыта для новых сообщений.</div><?php endif;?>
    <div class="toolbar"><a class="btn btn-ghost" href="<?=h(board_url($b['id']))?>">← К разделу</a><div><?php if($me):?><a class="btn btn-ghost" href="index.php?page=report&id=<?=$t['id']?>">Пожаловаться</a><?php endif;?></div></div>
    <?php if(is_staff($me)):?><div class="mod-toolbar">
      <?php foreach([['pin',$t['pinned']?'Открепить':'Закрепить'],['lock',$t['locked']?'Открыть тему':'Закрыть тему'],['delete_topic','Удалить тему']] as [$act,$label]):?><form method="post" action="index.php" class="inline" <?=$act==='delete_topic'?'data-confirm="Удалить тему и все ответы без возможности восстановления?"':''?>><?=csrf()?><input type="hidden" name="action" value="<?=$act?>"><input type="hidden" name="topic_id" value="<?=$t['id']?>"><button class="btn btn-ghost btn-sm"><?=h($label)?></button></form><?php endforeach;?></div><?php endif;?>
    <section class="posts" id="replies">
    <?php $posts=topic_posts($db,$t['id']);$p=max(1,(int)($_GET['p']??1));foreach(page_slice($posts,$p,20) as $i=>$post):
      $author=$db['users'][(string)$post['user_id']]??['id'=>0,'username'=>'Удалённый','role'=>'user','created_at'=>time()];?>
      <article class="post" id="post-<?=$post['id']?>"><aside class="post-author"><div class="avatar avatar-large"><?=h(first_letter($author['username']))?></div><a href="index.php?page=profile&id=<?=$author['id']?>" class="post-username"><?=h($author['username'])?></a><span class="role role-<?=h($author['role'])?>"><?=h(role_label($author['role']))?></span><small>С нами с <?=date('d.m.Y',$author['created_at'])?></small></aside><div class="post-content"><div class="post-head"><span><?=dt($post['created_at'])?></span><span>#<?=$post['id']?></span></div><div class="post-body"><?=txt($post['body'])?></div><?php if($post['updated_at']!==$post['created_at']):?><div class="edited">Изменено <?=dt($post['updated_at'])?></div><?php endif;?><div class="post-actions">
      <?php if($me&&($me['id']===$post['user_id']||is_staff($me))):?><a class="btn btn-ghost btn-sm" href="index.php?page=edit&id=<?=$post['id']?>">Редактировать</a><?php endif;?>
      <?php if(is_staff($me)&&$post['id']!==$posts[0]['id']):?><form class="inline" action="index.php" method="post" data-confirm="Удалить сообщение?" ><?=csrf()?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?=$post['id']?>"><input type="hidden" name="topic_id" value="<?=$t['id']?>"><button class="btn btn-ghost btn-sm">Удалить</button></form><?php endif;?></div></div></article>
    <?php endforeach;?></section><?=pages(count($posts),$p,20,q($t['id']))?>
    <?php if($me && (!$t['locked']||is_staff($me)) && can_post_board($b,$me)):?><section class="form-card"><h2>Написать ответ</h2><p class="muted">Будьте вежливы с другими участниками.</p><form action="index.php" method="post" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="reply"><input type="hidden" name="topic_id" value="<?=$t['id']?>"><textarea name="body" maxlength="12000" rows="6" minlength="2" placeholder="Напишите сообщение..." required></textarea><button class="btn btn-primary">Отправить ответ ↗</button></form></section>
    <?php elseif(!$me):?><div class="notice">Чтобы ответить, <a href="index.php?page=login">войдите</a> или <a href="index.php?page=register">зарегистрируйтесь</a>.</div><?php endif;?>
<?php elseif($page==='new'):
    $b=$currentBoard;crumbs([[$b['name'],board_url($b['id'])],['Новая тема',null]]);page_heading('Новая тема','Раздел: '.$b['name']);?>
    <?php if(!$me||!can_post_board($b,$me)):?><div class="notice">Создание тем доступно только авторизованным пользователям с соответствующими правами.</div><?php else:?><section class="form-card slim"><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="create_topic"><input type="hidden" name="board_id" value="<?=$b['id']?>"><label class="form-field"><span>Название темы</span><input name="title" maxlength="110" minlength="5" placeholder="Коротко опишите вопрос или идею" required></label><label class="form-field"><span>Текст публикации</span><textarea name="body" rows="12" maxlength="12000" minlength="10" placeholder="Подробности сообщения..." required></textarea></label><button class="btn btn-primary">Опубликовать тему ↗</button></form></section><?php endif;?>
<?php elseif($page==='edit'):
    crumbs([[$currentTopic['title'],q($currentTopic['id'])],['Редактирование',null]]);page_heading('Изменить сообщение');?>
    <section class="form-card slim"><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="edit_post"><input type="hidden" name="post_id" value="<?=$currentPost['id']?>"><textarea name="body" rows="12" minlength="2" maxlength="12000" required><?=h($currentPost['body'])?></textarea><button class="btn btn-primary">Сохранить изменения</button></form></section>
<?php elseif($page==='report'):
    crumbs([[$currentTopic['title'],q($currentTopic['id'])],['Жалоба',null]]);page_heading('Сообщить о нарушении','Обращение увидит команда модерации.');?>
    <?php if(!$me):?><div class="notice">Сначала войдите в аккаунт.</div><?php else:?><section class="form-card slim"><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="report"><input type="hidden" name="topic_id" value="<?=$currentTopic['id']?>"><label class="form-field"><span>Опишите нарушение</span><textarea name="reason" rows="7" minlength="10" maxlength="1000" required></textarea></label><button class="btn btn-primary">Отправить жалобу</button></form></section><?php endif;?>
<?php elseif($page==='latest'):
    page_heading('Новые обсуждения','Самые свежие публикации сообщества SLAVIA.');?>
    <?php $topics=array_values(array_filter($db['topics'],fn($t)=>isset($db['boards'][(string)$t['board_id']])&&visible_board($db['boards'][(string)$t['board_id']],$me)));
    usort($topics,fn($a,$b)=>$b['updated_at']<=>$a['updated_at']);$p=max(1,(int)($_GET['p']??1));?>
    <div class="topic-list"><?php foreach(page_slice($topics,$p,25) as $t)topic_row($db,$t,true);if(!$topics):?><div class="empty"><span>✧</span><h3>Пока нет публикаций</h3><p>Новые темы появятся здесь.</p></div><?php endif;?></div><?=pages(count($topics),$p,25,'index.php?page=latest')?>
<?php elseif($page==='rules'):
    page_heading('Правила сообщества','Правила общения на форуме SLAVIA ROLEPLAY.');?>
    <article class="reading-card"><?=txt(settings($db,'rules'))?></article>
<?php elseif($page==='search'):
    page_heading('Поиск по форуму','Ищи темы и обсуждения игроков.');?>
    <form class="search-form" method="get" action="index.php"><input type="hidden" name="page" value="search"><input name="q" placeholder="Название темы или текст сообщения" maxlength="100" value="<?=h($_GET['q']??'')?>" required><button class="btn btn-primary">Найти</button></form>
    <?php $needle=trim((string)($_GET['q']??''));if($needle!==''):
      $needle=substr($needle,0,200);$hit=[];
      foreach($db['topics'] as $t){$b=$db['boards'][(string)$t['board_id']]??null;if(!$b||!visible_board($b,$me))continue;
          $inTitle=str_contains(lower($t['title']),lower($needle));$inText=false;
          foreach($db['posts'] as $post)if($post['topic_id']===$t['id']&&str_contains(lower($post['body']),lower($needle))){$inText=true;break;}
          if($inTitle||$inText)$hit[]=$t;
      }usort($hit,fn($a,$b)=>$b['updated_at']<=>$a['updated_at']);$p=max(1,(int)($_GET['p']??1));?>
      <p class="muted">Найдено тем: <?=count($hit)?></p><div class="topic-list"><?php foreach(page_slice($hit,$p,25) as $t)topic_row($db,$t,true);if(!$hit):?><div class="empty">Ничего не найдено. Попробуйте другой запрос.</div><?php endif;?></div><?=pages(count($hit),$p,25,'index.php?page=search&q='.rawurlencode($needle))?>
    <?php endif;?>
<?php elseif($page==='login'||$page==='register'):
    $isreg=$page==='register';page_heading($isreg?'Создать аккаунт':'Вход на форум',$isreg?'Вступи в сообщество SLAVIA ROLEPLAY':'Рады видеть тебя снова.');?>
    <div class="auth-card"><div class="auth-icon">M</div><h2><?=$isreg?'Присоединиться к SLAVIA':'Добро пожаловать'?></h2>
    <?php if($isreg&&settings($db,'registration')!=='1'):?><div class="notice">Регистрация временно отключена.</div><?php else:?><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="<?=$isreg?'register':'login'?>"><label class="form-field"><span>Имя пользователя</span><input name="username" minlength="3" maxlength="24" autocomplete="username" required></label><label class="form-field"><span>Пароль</span><input type="password" name="password" minlength="<?=$isreg?10:1?>" maxlength="128" autocomplete="<?=$isreg?'new-password':'current-password'?>" required></label>
    <?php if($isreg):?><label class="form-field"><span>Повторите пароль</span><input type="password" name="password_again" minlength="10" maxlength="128" autocomplete="new-password" required></label><div class="honeypot" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div><?php endif;?><button class="btn btn-primary btn-wide"><?=$isreg?'Зарегистрироваться':'Войти на форум'?> ↗</button></form><?php endif;?>
    <p class="auth-bottom"><?=$isreg?'Уже есть аккаунт?':'Ещё нет аккаунта?'?> <a href="index.php?page=<?=$isreg?'login':'register'?>"><?=$isreg?'Войти':'Зарегистрироваться'?></a></p></div>
<?php elseif($page==='profile'):
    $id=(int)($_GET['id']??0);$person=$db['users'][(string)$id]??null;
    if(!$person):?><div class="notice">Профиль не найден.</div><?php else:
    $tc=count(array_filter($db['topics'],fn($t)=>$t['user_id']===$id));$pc=count(array_filter($db['posts'],fn($p)=>$p['user_id']===$id));page_heading('Профиль игрока');?>
    <section class="profile-card"><span class="avatar avatar-xl"><?=h(first_letter($person['username']))?></span><h2><?=h($person['username'])?></h2><div class="role role-<?=h($person['role'])?>"><?=h(role_label($person['role']))?></div><p>На форуме с <?=date('d.m.Y',$person['created_at'])?></p><div class="profile-stats"><div><b><?=$tc?></b> тем</div><div><b><?=$pc?></b> сообщений</div></div></section><?php if($me && $me['id']===$person['id']):?><section class="form-card slim profile-password"><h2>Сменить пароль</h2><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="change_password"><label class="form-field"><span>Текущий пароль</span><input type="password" name="password_old" required autocomplete="current-password"></label><label class="form-field"><span>Новый пароль (от 10 символов)</span><input type="password" name="password_new" minlength="10" maxlength="128" required autocomplete="new-password"></label><label class="form-field"><span>Повторите новый пароль</span><input type="password" name="password_again" minlength="10" maxlength="128" required autocomplete="new-password"></label><button class="btn btn-primary">Обновить пароль</button></form></section><?php endif;?><?php endif;?>
<?php elseif($page==='staff'):
    page_heading('Панель управления',is_admin($me)?'Настройки форума, разделы, участники и модерация.':'Модерация жалоб и публикаций.');?>
    <div class="admin-grid"><section class="admin-panel"><h2>Очередь жалоб</h2><?php
    $reports=array_values(array_filter($db['reports'],fn($r)=>!$r['resolved']));usort($reports,fn($a,$b)=>$b['created_at']<=>$a['created_at']);
    if(!$reports)echo '<p class="muted">Открытых жалоб нет.</p>';
    foreach(array_slice($reports,0,30) as $r):?><div class="admin-record"><div><strong>Жалоба #<?=$r['id']?></strong><small><?=user_name($db,$r['user_id'])?> · <?=dt($r['created_at'])?></small><p><?=txt($r['reason'])?></p><a class="text-link" href="<?=h(q($r['topic_id']))?>">Перейти к теме ↗</a></div><form method="post" action="index.php"><?=csrf()?><input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?=$r['id']?>"><button class="btn btn-ghost btn-sm">Обработано ✓</button></form></div><?php endforeach;?></section>
    <?php if(is_admin($me)):?><section class="admin-panel"><h2>Настройки форума</h2><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="admin_settings">
    <?php foreach(['forum_name'=>'Название форума','subtitle'=>'Слоган','welcome'=>'Текст приветствия','vk'=>'Ссылка VK','telegram'=>'Ссылка Telegram','site'=>'Адрес основного сайта (если есть)'] as $key=>$label):?>
    <label class="form-field"><span><?=h($label)?></span><input name="<?=$key?>" maxlength="<?=$key==='welcome'?900:250?>" value="<?=h(settings($db,$key))?>"></label><?php endforeach;?><label class="form-field"><span>Правила</span><textarea name="rules" rows="8" maxlength="6000"><?=h(settings($db,'rules'))?></textarea></label><label class="checkline"><input type="checkbox" name="registration" value="1" <?=settings($db,'registration')==='1'?'checked':''?>> Разрешить регистрацию</label><button class="btn btn-primary">Сохранить настройки</button></form></section>
    <section class="admin-panel"><h2>Новая категория</h2><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="add_category"><label class="form-field"><span>Название</span><input name="name" required maxlength="80"></label><label class="form-field"><span>Описание</span><input name="description" maxlength="180"></label><button class="btn btn-primary">Добавить категорию</button></form></section>
    <section class="admin-panel"><h2>Новый раздел</h2><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="add_board"><label class="form-field"><span>Категория</span><select name="category_id"><?php foreach($db['categories'] as $cat):?><option value="<?=$cat['id']?>"><?=h($cat['name'])?></option><?php endforeach;?></select></label><label class="form-field"><span>Название</span><input name="name" maxlength="80" required></label><label class="form-field"><span>Описание</span><input name="description" maxlength="200"></label><label class="form-field"><span>Доступ</span><select name="access"><option value="public">Публичный</option><option value="staff">Только персонал</option></select></label><label class="form-field"><span>Публикация</span><select name="posting"><option value="all">Все пользователи</option><option value="staff">Только персонал</option></select></label><button class="btn btn-primary">Добавить раздел</button></form></section>
    <section class="admin-panel admin-full"><h2>Управление разделами</h2><div class="admin-board-list"><?php foreach($db['boards'] as $b):?><details class="admin-board"><summary><?=h($b['name'])?><span>Изменить ›</span></summary><form method="post" action="index.php" class="stack-form"><?=csrf()?><input type="hidden" name="action" value="edit_board"><input type="hidden" name="board_id" value="<?=$b['id']?>"><label class="form-field"><span>Название</span><input name="name" value="<?=h($b['name'])?>" maxlength="80"></label><label class="form-field"><span>Описание</span><input name="description" value="<?=h($b['description'])?>" maxlength="200"></label><label class="form-field"><span>Доступ</span><select name="access"><?php select_options(['public'=>'Публичный','staff'=>'Только персонал'],$b['access']);?></select></label><label class="form-field"><span>Кто может создавать темы и отвечать</span><select name="posting"><?php select_options(['all'=>'Все','staff'=>'Только персонал'],$b['posting']);?></select></label><button class="btn btn-primary btn-sm">Сохранить</button></form></details><?php endforeach;?></div></section>
    <section class="admin-panel admin-full"><h2>Участники (последние 50)</h2><?php $users=array_values($db['users']);usort($users,fn($a,$b)=>$b['id']<=>$a['id']);foreach(array_slice($users,0,50) as $member):?><div class="admin-user"><div><b><?=h($member['username'])?></b><small><?=h(role_label($member['role']))?> · <?=dt($member['created_at'])?><?=($member['banned_until']!==0?' · БЛОКИРОВКА':'')?></small></div><?php if($member['id']!==$me['id']):?><form method="post" action="index.php" class="inline"> <?=csrf()?><input type="hidden" name="action" value="user_role"><input type="hidden" name="user_id" value="<?=$member['id']?>"><select name="role"><?php select_options(['user'=>'Игрок','moderator'=>'Модератор','admin'=>'Администратор'],$member['role']);?></select><button class="btn btn-ghost btn-sm">Роль</button></form><form method="post" action="index.php" class="inline"><?=csrf()?><input type="hidden" name="action" value="user_ban"><input type="hidden" name="user_id" value="<?=$member['id']?>"><select name="days"><?php select_options(['0'=>'Снять бан','1'=>'1 день','7'=>'7 дней','30'=>'30 дней','-1'=>'Навсегда'],(string)($member['banned_until']===0?'0':'7'));?></select><button class="btn btn-ghost btn-sm">Бан</button></form><?php endif;?></div><?php endforeach;?></section>
    <?php endif;?></div>
<?php else:page_heading('Страница не найдена',$viewError?:'Проверьте адрес или вернитесь на главную.');?><a href="index.php" class="btn btn-primary">На главную</a>
<?php endif;
footer_view($db);
