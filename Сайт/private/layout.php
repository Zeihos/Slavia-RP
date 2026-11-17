<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function render_head(string $title, string $page='home', string $description=''): void {
    $s=settings();
    $full=$title==='Главная' ? 'SLAVIA ROLEPLAY — твоя новая история' : $title.' — SLAVIA ROLEPLAY';
    $description=$description?:'SLAVIA ROLEPLAY — игровой RolePlay-проект. Новости, информация о серверах, сообщество и начало игры.';
    $canonical='';
    $ogImage='assets/img/slavia-cover.jpg';
    if (!empty($s['domain']) && safe_url($s['domain'])) {
        $siteBase=rtrim($s['domain'],'/');
        $canonical=$siteBase.'/'.page_url($page,$page==='article' ? ['id'=>(string)($_GET['id']??'')] : []);
        $ogImage=$siteBase.'/assets/img/slavia-cover.jpg';
    }
    ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#100815">
  <meta name="description" content="<?=e($description)?>">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="ru_RU">
  <meta property="og:site_name" content="SLAVIA ROLEPLAY">
  <meta property="og:title" content="<?=e($full)?>">
  <meta property="og:description" content="<?=e($description)?>">
  <meta property="og:image" content="<?=e($ogImage)?>">
  <?php if ($canonical): ?><link rel="canonical" href="<?=e($canonical)?>"><?php endif; ?>
  <title><?=e($full)?></title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="stylesheet" href="assets/css/site.css?v=1.0.0">
  <?php if ($page==='home'): ?><link rel="preload" as="image" href="assets/img/slavia-cover.webp"><?php endif; ?>
</head>
<body>
  <a class="skip-link" href="#content">Перейти к содержимому</a>
  <div class="scroll-progress" data-scroll-progress></div>
  <header class="topbar" data-topbar>
    <div class="container nav-inner">
      <a class="brand" href="<?=page_url()?>" aria-label="SLAVIA ROLEPLAY — главная">
        <span class="brand-emblem">M</span>
        <span class="brand-label"><b>SLAVIA</b><small>ROLEPLAY</small></span>
      </a>
      <nav class="nav-links" id="main-nav" data-nav aria-label="Основное меню">
        <a href="<?=page_url()?>"<?=active_page($page,'home')?>>Главная</a>
        <a href="<?=page_url('about')?>"<?=active_page($page,'about')?>>О проекте</a>
        <a href="<?=page_url('start')?>"<?=active_page($page,'start')?>>Начать играть</a>
        <a href="<?=page_url('servers')?>"<?=active_page($page,'servers')?>>Серверы</a>
        <a href="<?=page_url('news')?>"<?=active_page($page,'news')?>>Новости</a>
        <a href="<?=page_url('shop')?>"<?=active_page($page,'shop')?>>Магазин</a>
        <a href="<?=page_url('community')?>"<?=active_page($page,'community')?>>Сообщество</a>
      </nav>
      <div class="top-actions">
        <a class="header-social" href="<?=e(safe_url($s['vk_url']??'https://vk.ru/slavia_rp'))?>" target="_blank" rel="noopener noreferrer" aria-label="SLAVIA ВКонтакте">VK <span aria-hidden="true">↗</span></a>
        <a class="header-cta" href="<?=page_url('start')?>">Играть <span>↗</span></a>
        <button class="menu-toggle" type="button" aria-controls="main-nav" aria-expanded="false" aria-label="Открыть меню" data-menu-toggle><span></span><span></span><span></span></button>
      </div>
    </div>
  </header>
<?php }
function render_footer(): void { $s=settings(); ?>
<footer class="site-footer">
 <div class="container">
  <div class="footer-main">
   <div class="footer-about">
    <a class="brand footer-brand" href="<?=page_url()?>"><span class="brand-emblem">M</span><span class="brand-label"><b>SLAVIA</b><small>ROLEPLAY</small></span></a>
    <p>Твоя новая история начинается здесь. Игровой проект с собственной атмосферой и сообществом.</p>
    <div class="footer-social"><a href="<?=e(safe_url($s['vk_url']??''))?>" target="_blank" rel="noopener noreferrer">ВКонтакте ↗</a><a href="<?=e(safe_url($s['telegram_url']??''))?>" target="_blank" rel="noopener noreferrer">Telegram ↗</a></div>
   </div>
   <div class="footer-column"><b>ПРОЕКТ</b><a href="<?=page_url('about')?>">О проекте</a><a href="<?=page_url('news')?>">Новости</a><a href="<?=page_url('servers')?>">Серверы</a><a href="<?=page_url('start')?>">Начать играть</a></div>
   <div class="footer-column"><b>ИГРОКАМ</b><a href="<?=page_url('shop')?>">Магазин</a><a href="<?=page_url('faq')?>">Вопросы и ответы</a><a href="<?=page_url('rules')?>">Правила</a><a href="<?=page_url('support')?>">Поддержка</a></div>
   <div class="footer-column"><b>ДОКУМЕНТЫ</b><a href="<?=page_url('privacy')?>">Конфиденциальность</a><a href="<?=page_url('terms')?>">Условия использования</a><a href="<?=page_url('community')?>">Сообщество</a></div>
  </div>
  <div class="footer-bottom"><span>© <?=date('Y')?> SLAVIA ROLEPLAY. Все права защищены.</span><span><?=e($s['footer_note']??'')?></span><a href="admin.php" class="admin-link">Управление</a></div>
 </div>
</footer>
<script src="assets/js/site.js?v=1.0.0" defer></script>
</body></html>
<?php }
function section_title(string $eyebrow,string $headline,string $lead='',string $action=''):void { ?>
<div class="section-heading"><div><div class="eyebrow"><i></i><?=e($eyebrow)?></div><h2 class="heading-2"><?=$headline?></h2><?php if($lead): ?><p class="section-lead"><?=e($lead)?></p><?php endif; ?></div><?php if($action): ?><div><?=$action?></div><?php endif; ?></div>
<?php }
function render_banner(string $title,string $subtitle,string $tag='SLAVIA ROLEPLAY'):void { ?>
<section class="page-banner"><div class="page-banner-background"></div><div class="container page-banner-inner"><div class="eyebrow"><i></i><?=e($tag)?></div><h1><?=e($title)?></h1><p><?=e($subtitle)?></p><div class="bread"><a href="<?=page_url()?>">Главная</a><span>/</span><span><?=e($title)?></span></div></div></section>
<?php }
function render_callout(): void { $s=settings(); ?>
<section class="bottom-cta"><div class="container"><div class="bottom-cta-inner"><div><div class="eyebrow"><i></i>СТАНЬ ЧАСТЬЮ ИСТОРИИ</div><h2>ТВОЙ ГОРОД.<br><em>ТВОИ ПРАВИЛА.</em></h2><p>Подписывайся на новости и знакомься с будущим SLAVIA ROLEPLAY.</p></div><div class="cta-buttons"><a class="button button-hot" href="<?=page_url('start')?>">Начать путь <span>↗</span></a><a class="button button-ghost" href="<?=e(safe_url($s['telegram_url']??''))?>" target="_blank" rel="noopener noreferrer">Наш Telegram <span>↗</span></a></div></div></div></section>
<?php }
function news_card(array $n):void { ?>
<article class="news-card"><a class="news-card-image" href="<?=page_url('article',['id'=>(string)($n['id']??'')])?>" aria-label="Читать: <?=e($n['title']??'')?>"><img src="assets/img/slavia-avatar.webp" alt="" loading="lazy"><span class="news-image-stamp">SLAVIA</span></a><div class="news-card-body"><div class="news-meta"><span><?=e($n['category']??'Новости')?></span><span><?=e(fmt_date((string)($n['date']??'')))?></span></div><h3><a href="<?=page_url('article',['id'=>(string)($n['id']??'')])?>"><?=e($n['title']??'')?></a></h3><p><?=e($n['excerpt']??'')?></p><a class="text-link" href="<?=page_url('article',['id'=>(string)($n['id']??'')])?>">Читать подробнее <span>↗</span></a></div></article>
<?php }
