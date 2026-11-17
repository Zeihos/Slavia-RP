<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function header_view(array $db,?array $u,string $pageTitle='Форум'):void {
    $title=settings($db,'forum_name','SLAVIA ROLEPLAY');
    $staff=is_staff($u);
    $alerts=flashes();
    ?><!doctype html><html lang="ru"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Официальный форум SLAVIA ROLEPLAY: новости, поддержка, общение, жалобы и предложения.">
    <title><?=h($pageTitle)?> — <?=h($title)?></title>
    <link rel="icon" href="assets/img/slavia-logo.jpg"><link rel="stylesheet" href="assets/css/style.css">
    </head><body>
    <div class="topline"></div><div class="site-wrap">
    <header class="site-header">
      <a class="brand" href="index.php" aria-label="Главная форума"><img src="assets/img/slavia-logo.jpg" alt="SLAVIA"><span><strong>SLAVIA</strong><small>ROLEPLAY / FORUM</small></span></a>
      <button type="button" class="menu-toggle" aria-label="Открыть меню" aria-expanded="false" data-menu>☰</button>
      <nav class="main-nav" id="mainnav">
        <a href="index.php" class="nav-item">Главная</a><a href="index.php?page=latest" class="nav-item">Новые темы</a><a href="index.php?page=rules" class="nav-item">Правила</a>
        <a href="index.php?page=search" class="nav-item">Поиск</a>
        <?php if($staff):?><a href="index.php?page=staff" class="nav-item">Управление</a><?php endif;?>
      </nav>
      <div class="auth-nav">
      <?php if($u):?><a class="user-pill" href="index.php?page=profile&id=<?=$u['id']?>"><span class="avatar avatar-small"><?=h(first_letter($u['username']))?></span><span class="signed-name"><?=h($u['username'])?></span></a><form method="post" action="index.php" class="inline"><?=csrf()?><input type="hidden" name="action" value="logout"><button class="btn btn-ghost btn-sm">Выход</button></form>
      <?php else:?><a class="btn btn-ghost btn-sm" href="index.php?page=login">Войти</a><a class="btn btn-primary btn-sm" href="index.php?page=register">Регистрация</a><?php endif;?>
      </div>
    </header>
    <?php foreach($alerts as $a):?><div class="alert <?=h($a['type']==='error'?'alert-error':'alert-success')?>" role="status"><?=h($a['text'])?></div><?php endforeach;?>
    <main id="main-content">
    <?php
}
function first_letter(string $s):string {
    if(function_exists('mb_substr'))return mb_substr($s,0,1,'UTF-8');
    preg_match('/^./us',$s,$m);return strtoupper($m[0]??'S');
}
function footer_view(array $db):void { ?>
    </main><footer class="site-footer"><div class="footer-main"><div><div class="footer-brand">SLAVIA <span>ROLEPLAY</span></div><p>Место, где начинается твоя история.</p></div><div class="footer-links">
    <a href="index.php?page=rules">Правила</a><a href="index.php?page=latest">Новые темы</a>
    <?php if(settings($db,'site')!==''):?><a href="<?=h(settings($db,'site'))?>" rel="noopener noreferrer">Сайт проекта</a><?php endif;?>
    <a href="<?=h(settings($db,'vk'))?>" rel="noopener noreferrer" target="_blank">ВКонтакте</a><a href="<?=h(settings($db,'telegram'))?>" rel="noopener noreferrer" target="_blank">Telegram</a></div></div><div class="foot-bottom">© <?=date('Y')?> SLAVIA ROLEPLAY <span>Независимый форум проекта</span></div></footer></div>
    <script defer src="assets/js/app.js"></script></body></html><?php
}
function page_heading(string $heading,string $sub=''):void { ?><div class="page-heading"><div class="eyebrow"><span class="pink-dot"></span> SLAVIA ROLEPLAY / COMMUNITY</div><h1><?=h($heading)?></h1><?php if($sub!==''):?><p><?=h($sub)?></p><?php endif;?></div><?php }
function crumbs(array $items):void {echo '<nav class="crumbs" aria-label="Навигация"><a href="index.php">Форум</a>';foreach($items as $a){echo '<span>/</span>';if($a[1]!==null)echo '<a href="'.h($a[1]).'">'.h($a[0]).'</a>';else echo '<strong>'.h($a[0]).'</strong>';}echo '</nav>';}
function stats(array $db):array {return [count($db['users']),count($db['topics']),count($db['posts'])];}
function community_side(array $db,?array $u):void {
    [$nusers,$ntopics,$nposts]=stats($db); ?>
    <aside class="sidebar">
      <div class="sidebar-card"><div class="sidebar-heading">СООБЩЕСТВО</div><div class="stats-grid"><div><b><?=number_format($nusers,0,'',' ')?></b><span>участников</span></div><div><b><?=number_format($ntopics,0,'',' ')?></b><span>тем</span></div><div><b><?=number_format($nposts,0,'',' ')?></b><span>сообщений</span></div></div></div>
      <div class="sidebar-card"><div class="sidebar-heading">НАШИ ПЛОЩАДКИ</div><p class="muted">Не пропускай новости и события SLAVIA.</p><a class="social-link" target="_blank" rel="noopener noreferrer" href="<?=h(settings($db,'vk'))?>"><span class="social-ico">VK</span> ВКонтакте <span>↗</span></a><a class="social-link" target="_blank" rel="noopener noreferrer" href="<?=h(settings($db,'telegram'))?>"><span class="social-ico">TG</span> Telegram <span>↗</span></a></div>
      <div class="sidebar-card rules-tip"><div class="sidebar-heading">НАЧНИ С ПРАВИЛ</div><p class="muted">Ознакомься с правилами общения перед первой публикацией.</p><a class="text-link" href="index.php?page=rules">Читать правила ↗</a></div>
    </aside><?php
}
function board_row(array $db,array $b):void {
    $t=array_values(array_filter($db['topics'],fn($t)=>$t['board_id']===$b['id']));
    usort($t,'topic_sort');$last=$t[0]??null;
    $count=0;foreach($t as $r)foreach($db['posts'] as $p)if($p['topic_id']===$r['id'])$count++;
    ?><a class="board-row" href="<?=h(board_url($b['id']))?>"><span class="board-glyph"><?=h(icon_for((string)$b['icon']))?></span><span class="board-details"><strong><?=h($b['name'])?></strong><span><?=h($b['description'])?></span></span><span class="board-amounts"><b><?=count($t)?></b><small>тем</small><b><?=$count?></b><small>сообщ.</small></span><span class="board-last"><?php if($last):?><strong><?=h($last['title'])?></strong><small><?=dt($last['updated_at'])?></small><?php else:?><small>Пока нет публикаций</small><?php endif;?></span><span class="board-arrow">›</span></a><?php
}
function icon_for(string $s):string {return ['megaphone'=>'✦','book-open'=>'▤','compass'=>'⌖','message-circle'=>'☏','lightbulb'=>'✧','calendar-days'=>'▦','server'=>'▥','landmark'=>'♜','shield'=>'◇','headset'=>'◉','flag'=>'⚑','scale'=>'⚖','shield-check'=>'◆','folder'=>'▣'][$s]??'▣';}
function topic_row(array $db,array $t, bool $showBoard=false):void {
    $posts=topic_posts($db,$t['id']);$last=end($posts)?:null;
    ?><a class="topic-row" href="<?=h(q($t['id']))?>"><div class="topic-icon"><?=!empty($t['pinned'])?'✦':'◌'?></div><div class="topic-main"><strong><?=h($t['title'])?> <?php if(!empty($t['locked'])):?><span class="pill neutral">Закрыта</span><?php endif;?><?php if(!empty($t['pinned'])):?><span class="pill">Закреплена</span><?php endif;?></strong><small><?=user_name($db,$t['user_id'])?> · <?=dt($t['created_at'])?> <?php if($showBoard):?> · <?=h($db['boards'][(string)$t['board_id']]['name']??'')?><?php endif;?></small></div><div class="topic-meta"><b><?=max(0,count($posts)-1)?></b><small>ответов</small></div><div class="topic-activity"><b><?=dt($t['updated_at'])?></b><small><?= $last?user_name($db,$last['user_id']):''?></small></div><div class="topic-chevron">›</div></a><?php
}
function paginator_records(array $items,int $p,int $per,string $path):void {echo pages(count($items),$p,$per,$path);}
function form_field(string $label,string $name,string $value='',string $type='text',int $limit=180):void {echo '<label class="form-field"><span>'.h($label).'</span><input type="'.h($type).'" name="'.h($name).'" maxlength="'.$limit.'" value="'.h($value).'" required></label>';}
function select_options(array $options,string $current):void {foreach($options as $value=>$label)echo '<option value="'.h($value).'"'.((string)$value===$current?' selected':'').'>'.h($label).'</option>';}
function post_form(array $db,string $action,string $button,string $extra=''):void {echo '<form method="post" action="index.php" class="stack-form">'.csrf().'<input type="hidden" name="action" value="'.h($action).'">'.$extra.'<button class="btn btn-primary">'.h($button).'</button></form>';}
