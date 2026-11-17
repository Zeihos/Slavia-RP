<?php
declare(strict_types=1);require_once __DIR__.'/../app/layout.php';must_install();
$categories=data_read('categories');usort($categories,fn($a,$b)=>(int)($a['sort']??0)<=>(int)($b['sort']??0));
$all=published(data_read('articles'));usort($all,fn($a,$b)=>strcmp($b['updated_at']??'',$a['updated_at']??''));
$byCat=function(int $id)use($all):array{return array_values(array_filter($all,fn($a)=>(int)$a['category_id']===$id));};
$s=settings();$articleSlug=(string)($_GET['article']??'');$categorySlug=(string)($_GET['category']??'');
$query=trim((string)($_GET['q']??''));$tag=trim((string)($_GET['tag']??''));
if($articleSlug!==''){
 $article=by_slug($all,$articleSlug);if(!$article){http_response_code(404);render_head('Статья не найдена');?><div class="error-panel"><span>404</span><h1>Статья не найдена</h1><p>Возможно, материал был перемещён или снят с публикации.</p><a class="primary-btn" href="index.php">На главную →</a></div><?php render_footer();exit;}
 $cat=category_of($categories,(int)$article['category_id']);$toc=article_toc($article['body']);$related=array_values(array_filter($byCat((int)$article['category_id']),fn($x)=>$x['id']!==$article['id']));
 render_head($article['title'],$article['summary'],'index.php?article='.rawurlencode($article['slug']));?>
 <nav class="breadcrumbs"><a href="index.php">Главная</a><span>›</span><a href="<?=h(url('index.php',['category'=>$cat['slug']??'']))?>"><?=h($cat['title']??'Статьи')?></a><span>›</span><span><?=h($article['title'])?></span></nav>
 <div class="article-layout"><article class="article-main"><div class="article-top"><span class="section-chip"><?=h($cat['icon']??'📘')?> <?=h($cat['title']??'Материалы')?></span><span class="article-date">Обновлено <?=h(date_ru($article['updated_at']??''))?></span></div><h1 class="article-title"><?=h($article['title'])?></h1><p class="article-lead"><?=h($article['summary'])?></p>
 <div class="article-divider"></div><div class="article-content"><?=markdown_html($article['body'])?></div>
 <?php if(!empty($article['tags'])):?><div class="tags"><span>Теги:</span><?php foreach($article['tags'] as $t):?><a href="<?=h(url('index.php',['tag'=>$t]))?>">#<?=h($t)?></a><?php endforeach;?></div><?php endif;?>
 <div class="article-after"><span>Была полезна статья? Поделитесь ссылкой.</span><button class="copy-link" data-copy-url="<?=h(base_url().url('index.php',['article'=>$article['slug']]))?>">Скопировать ссылку</button></div>
 <?php if($related):?><section class="related"><div class="section-title-row"><h2>Похожие материалы</h2><a href="<?=h(url('index.php',['category'=>$cat['slug']??'']))?>">Все статьи →</a></div><div class="cards-grid"><?php foreach(array_slice($related,0,3) as $a)render_card($a,$categories,true);?></div></section><?php endif;?></article>
 <aside class="article-aside"><div class="toc-block"><h3>НА ЭТОЙ СТРАНИЦЕ</h3><?php if($toc):foreach($toc as $item):?><a class="toc-level-<?=h($item['level'])?>" href="#<?=h($item['slug'])?>"><?=h($item['title'])?></a><?php endforeach;else:?><p>Краткая статья</p><?php endif;?></div><div class="aside-box"><strong>Есть неточность?</strong><p>Сообщи администрации через официальное сообщество.</p><a href="<?=h(safe_http_url($s['vk_url']))?>" target="_blank" rel="noopener noreferrer">Написать →</a></div></aside></div>
 <?php render_footer();exit;
}
if($categorySlug!==''){
 $cat=by_slug($categories,$categorySlug);if(!$cat){http_response_code(404);render_head('Категория не найдена');?><div class="error-panel"><h1>Раздел не найден</h1><a href="index.php">Вернуться на главную</a></div><?php render_footer();exit;}
 $items=$byCat((int)$cat['id']);render_head($cat['title'],$cat['description'],'index.php?category='.rawurlencode($cat['slug']));?>
 <nav class="breadcrumbs"><a href="index.php">Главная</a><span>›</span><?=h($cat['title'])?></nav>
 <div class="category-hero"><div class="category-icon"><?=h($cat['icon'])?></div><div><div class="eyebrow">РАЗДЕЛ БАЗЫ ЗНАНИЙ</div><h1><?=h($cat['title'])?></h1><p><?=h($cat['description'])?></p><span class="category-count"><?=count($items)?> <?=count($items)===1?'статья':'статей'?></span></div></div>
 <div class="section-title-row"><h2>Материалы раздела</h2></div><div class="cards-grid"><?php foreach($items as $a)render_card($a,$categories);?></div>
 <?php if(!$items):?><div class="empty-state">Здесь пока нет опубликованных статей.</div><?php endif;?>
 <?php render_footer();exit;
}
if(array_key_exists('q',$_GET)||$tag!==''){
 $needle=mb_lower_fallback($query);$matching=[];
 foreach($all as $a){$hay=mb_lower_fallback(($a['title']??'').' '.($a['summary']??'').' '.($a['body']??'').' '.implode(' ',$a['tags']??[]));
 if($tag!==''){if(in_array($tag,$a['tags']??[],true))$matching[]=$a;}elseif($needle===''||str_contains($hay,$needle))$matching[]=$a;}
 $heading=$tag!==''?'Материалы по тегу #'.$tag:($query!==''?'Результаты поиска':'Все статьи');render_head($heading);?>
 <nav class="breadcrumbs"><a href="index.php">Главная</a><span>›</span><?=h($heading)?></nav>
 <section class="results-head"><div class="eyebrow">SLAVIA KNOWLEDGE BASE</div><h1><?=h($heading)?></h1><p>Найдено материалов: <?=count($matching)?></p></section>
 <form class="search-large" action="index.php" method="get"><span>⌕</span><input type="search" name="q" placeholder="Название, термин или вопрос..." value="<?=h($query)?>"><button type="submit">Найти →</button></form>
 <div class="cards-grid"><?php foreach($matching as $a)render_card($a,$categories);?></div>
 <?php if(!$matching):?><div class="empty-state"><strong>Ничего не найдено</strong><p>Попробуй другие слова или открой каталог разделов.</p><a href="index.php">Каталог →</a></div><?php endif;?>
 <?php render_footer();exit;
}
$featured=array_values(array_filter($all,fn($a)=>!empty($a['featured'])));
render_head('', $s['subtitle'],'index.php'); ?>
<div class="hero"><div class="hero-image"></div><div class="hero-glow"></div><div class="hero-content"><div class="hero-chip"><span class="pulse-dot"></span> ОФИЦИАЛЬНАЯ БАЗА ЗНАНИЙ ПРОЕКТА</div><h1>Твой путеводитель<br>по миру <em>SLAVIA</em></h1><p><?=h($s['subtitle'])?>. Руководства, ответы на вопросы и полезные материалы в одном месте.</p><form class="hero-search" method="get" action="index.php"><span>⌕</span><input name="q" placeholder="Что хочешь узнать?" aria-label="Поиск в базе знаний" required><button type="submit">Найти <span>↗</span></button></form><div class="hero-stats"><span><strong><?=count($all)?></strong> материалов</span><span class="stat-divider"></span><span><strong><?=count($categories)?></strong> разделов</span><span class="stat-divider"></span><span><strong>24/7</strong> доступ</span></div></div></div>
<?php if(!empty($s['announcement'])):?><div class="announcement"><div class="announce-icon">✧</div><p><?=h($s['announcement'])?></p><a href="<?=h(safe_http_url($s['tg_url']))?>" rel="noopener noreferrer" target="_blank">Новости →</a></div><?php endif;?>
<section class="sections-block"><div class="section-heading"><div><div class="eyebrow">НАЧНИ ОТСЮДА</div><h2>Исследуй базу знаний <span class="heading-spark">✦</span></h2><p>Выбери интересующую тему и найди нужную информацию.</p></div><a href="index.php?q=" class="subtle-link">Все статьи ↗</a></div><div class="categories-grid">
<?php foreach($categories as $i=>$c):$count=count($byCat((int)$c['id']));?><a class="category-card" href="<?=h(url('index.php',['category'=>$c['slug']]))?>"><span class="cat-symbol"><?=h($c['icon']??'📚')?></span><span class="cat-inner"><strong><?=h($c['title'])?></strong><small><?=h($c['description'])?></small><span class="cat-count"><?=$count?> <?=($count%10===1&&$count%100!==11)?'статья':(($count%10>=2&&$count%10<=4&&($count%100<12||$count%100>14))?'статьи':'статей')?></span></span><span class="cat-arrow">↗</span></a><?php endforeach;?></div></section>
<?php if($featured):?><section class="sections-block featured-block"><div class="section-heading"><div><div class="eyebrow">РЕКОМЕНДУЕМ ПРОЧИТАТЬ</div><h2>Популярные руководства</h2><p>Важные материалы для знакомства с игровым миром.</p></div></div><div class="cards-grid"><?php foreach(array_slice($featured,0,6) as $a)render_card($a,$categories);?></div></section><?php endif;?>
<section class="community-banner"><span class="community-orb">✧</span><div><div class="eyebrow">ПРИСОЕДИНЯЙСЯ К НАМ</div><h2>Есть вопросы? Мы на связи.</h2><p>Следи за новостями SLAVIA ROLEPLAY и общайся с другими игроками.</p></div><div class="community-actions"><a href="<?=h(safe_http_url($s['vk_url']))?>" target="_blank" rel="noopener noreferrer">ВКонтакте ↗</a><a href="<?=h(safe_http_url($s['tg_url']))?>" target="_blank" rel="noopener noreferrer">Telegram ↗</a></div></section>
<?php render_footer();
