<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
header('Content-Type: application/xml; charset=utf-8');
$s=settings();
$base=safe_url($s['domain']??'');
if(!$base){http_response_code(404);echo '<!-- Set domain in admin panel to enable sitemap -->';exit;}
$base=rtrim($base,'/');$pages=['home','about','start','servers','news','shop','community','faq','rules','support','privacy','terms'];
$items=array_map(static fn($p)=>$base.'/'.page_url($p),$pages);
foreach(store_read('news',[]) as $n)if(!empty($n['published']))$items[]=$base.'/'.page_url('article',['id'=>$n['id']]);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach($items as $url)echo '<url><loc>'.e($url).'</loc></url>';
echo '</urlset>';
