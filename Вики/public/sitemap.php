<?php
require_once __DIR__.'/../app/core.php';must_install();header('Content-Type: application/xml; charset=utf-8');
$base=base_url();echo '<?xml version="1.0" encoding="utf-8"?>'."\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
$items=['index.php'];foreach(data_read('categories') as $c)$items[]=url('index.php',['category'=>$c['slug']]);foreach(published(data_read('articles')) as $a)$items[]=url('index.php',['article'=>$a['slug']]);
foreach($items as $item)echo '<url><loc>'.h($base.$item).'</loc></url>';echo '</urlset>';
