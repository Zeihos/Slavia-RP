<?php
require_once __DIR__.'/markdown.php';
function render_head(string $title='',string $description='',string $canonical=''):void {
 $s=settings();$label=($title?$title.' — ':'').$s['name'].' · '.$s['wiki_title'];$description=$description?:$s['subtitle'];
 $cats=data_read('categories');$active=(string)($_GET['category']??'');$search=trim((string)($_GET['q']??''));$current=current_user();
 ?><!doctype html><html lang="ru"><head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <meta name="theme-color" content="#110e1a"><meta name="color-scheme" content="dark">
 <meta name="description" content="<?=h($description)?>"><title><?=h($label)?></title>
 <meta property="og:site_name" content="<?=h($s['name'])?>"><meta property="og:title" content="<?=h($label)?>">
 <meta property="og:description" content="<?=h($description)?>"><meta property="og:type" content="website">
 <meta property="og:image" content="<?=h(base_url())?>assets/img/slavia-cover.jpg">
 <?php if($canonical):?><link rel="canonical" href="<?=h(base_url().ltrim($canonical,'/'))?>"><?php endif; ?>
 <link rel="icon" type="image/jpeg" href="assets/img/slavia-logo.jpg">
 <link rel="stylesheet" href="assets/css/wiki.css?v=<?=h(WIKI_VERSION)?>">
 <script src="assets/js/wiki.js?v=<?=h(WIKI_VERSION)?>" defer></script></head>
 <body><div class="noise"></div>
 <div class="app-frame"><aside class="sidebar" id="sidebar">
 <a class="brand" href="index.php"><img src="assets/img/slavia-logo.jpg" alt="SLAVIA ROLEPLAY"><span><strong>SLAVIA</strong><small>ROLEPLAY / WIKI</small></span></a>
 <div class="sidebar-label">НАВИГАЦИЯ</div>
 <nav class="side-nav" aria-label="Навигация"><a href="index.php" class="side-link <?=!(isset($_GET['category'])||isset($_GET['article'])||isset($_GET['q'])||isset($_GET['tag']))?'on':''?>"><span class="nav-emoji">⌂</span> Обзор базы знаний</a>
 <a href="index.php?q=" class="side-link"><span class="nav-emoji">⌕</span> Все материалы</a></nav>
 <div class="sidebar-label group-label">РАЗДЕЛЫ</div><nav class="side-nav" aria-label="Категории">
 <?php usort($cats,fn($a,$b)=>(int)($a['sort']??0)<=>(int)($b['sort']??0)); foreach($cats as $c):?>
 <a href="<?=h(url('index.php',['category'=>$c['slug']]))?>" class="side-link <?=$active===$c['slug']?'on':''?>"><span class="nav-emoji"><?=h($c['icon']??'📄')?></span><span><?=h($c['title'])?></span></a>
 <?php endforeach;?></nav>
 <div class="sidebar-label group-label">ПРОЕКТ</div><nav class="side-nav" aria-label="Ссылки проекта">
 <?php if($link=safe_http_url($s['site_url'])):?><a class="side-link" href="<?=h($link)?>" target="_blank" rel="noopener noreferrer"><span class="nav-emoji">🌐</span> Сайт проекта ↗</a><?php endif;?>
 <?php if($link=safe_http_url($s['forum_url'])):?><a class="side-link" href="<?=h($link)?>" target="_blank" rel="noopener noreferrer"><span class="nav-emoji">💬</span> Форум ↗</a><?php endif;?>
 <?php if($link=safe_http_url($s['download_url'])):?><a class="side-link" href="<?=h($link)?>" target="_blank" rel="noopener noreferrer"><span class="nav-emoji">⬇️</span> Скачать игру ↗</a><?php endif;?>
 </nav>
 <div class="sidebar-bottom"><div class="side-help"><span class="tiny-spark">✦</span><strong>Нужна помощь?</strong><p>Задай вопрос сообществу SLAVIA ROLEPLAY.</p><a href="<?=h(safe_http_url($s['tg_url'])?:'https://t.me/slavia_rp')?>" target="_blank" rel="noopener noreferrer">Telegram ↗</a></div><span class="sidebar-version">SLAVIA KB • <?=h(WIKI_VERSION)?></span></div>
 </aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
 <div class="main-area"><header class="topbar"><div class="topbar-left"><button class="menu-btn" id="menuBtn" aria-label="Открыть меню" aria-expanded="false">☰</button><span class="topbar-kicker">SLAVIA / KNOWLEDGE BASE</span><span class="topbar-separator">/</span><span class="topbar-current"><?=h($title?:'Главная')?></span></div>
 <div class="topbar-right"><form class="quick-search" method="get" action="index.php" role="search"><span class="search-ico">⌕</span><input name="q" value="<?=h($search)?>" placeholder="Поиск по базе знаний..." aria-label="Поиск"><kbd>/</kbd></form>
 <a class="top-social" href="<?=h(safe_http_url($s['vk_url']))?>" target="_blank" rel="noopener noreferrer">VK ↗</a>
 <?php if($current):?><a class="top-admin" href="admin.php">Панель</a><?php else:?><a class="top-admin" href="login.php">Вход</a><?php endif;?></div></header>
 <main class="page-content">
 <?php if($f=get_flash()):?><div class="notice <?=h($f['type'])?>"><?=h($f['message'])?></div><?php endif;?>
 <?php
}
function render_footer():void {$s=settings();?><footer class="footer"><div><img src="assets/img/slavia-logo.jpg" alt="" class="footer-logo"><span><strong>SLAVIA ROLEPLAY</strong><small>База знаний проекта</small></span></div><p><?=h($s['footer_text'])?></p><div class="footer-links"><a href="<?=h(safe_http_url($s['vk_url']))?>" target="_blank" rel="noopener noreferrer">ВКонтакте</a><a href="<?=h(safe_http_url($s['tg_url']))?>" target="_blank" rel="noopener noreferrer">Telegram</a><a href="sitemap.php">Sitemap</a></div></footer></main></div></div></body></html><?php }
function render_card(array $a,array $categories,bool $small=false):void {
 $c=category_of($categories,(int)$a['category_id']);$icon=$c['icon']??'📄';
 ?><a class="article-card <?=$small?'compact':''?>" href="<?=h(url('index.php',['article'=>$a['slug']]))?>"><span class="article-card-icon"><?=h($icon)?></span><span class="article-card-body"><small><?=h($c['title']??'База знаний')?></small><strong><?=h($a['title'])?></strong><span class="card-description"><?=h($a['summary'])?></span><span class="card-meta">Обновлено <?=h(date_ru($a['updated_at']??''))?></span></span><span class="card-arrow">↗</span></a><?php
}
