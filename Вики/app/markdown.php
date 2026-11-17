<?php
require_once __DIR__.'/core.php';
function inline_md(string $s):string {
    $s=h($s);
    // Allow only explicit HTTPS/HTTP and local wiki links.
    $s=preg_replace_callback('~\[([^\[\]]{1,120})\]\(([^)\s]{1,400})\)~u',function($m){
      $href=html_entity_decode($m[2],ENT_QUOTES,'UTF-8');
      $valid=preg_match('~^https?://[^\s<>"\']+$~i',$href)||preg_match('~^(?:index\.php\?(?:article|category|q|tag)=[a-zA-Z0-9%_\-+&=]+|#[a-z0-9-]+)$~',$href);
      return $valid?'<a href="'.h($href).'"'.(str_starts_with($href,'http')?' target="_blank" rel="noopener noreferrer"':'').'>'.$m[1].'</a>':$m[1];
    },$s);
    $s=preg_replace('~\*\*(.+?)\*\*~u','<strong>$1</strong>',$s);
    $s=preg_replace('~\*(.+?)\*~u','<em>$1</em>',$s);
    $s=preg_replace('~`([^`]+)`~u','<code>$1</code>',$s);
    return $s;
}
function markdown_html(string $raw):string {
  $lines=preg_split('/\R/u',str_replace("\t",'    ',str_replace("\r",'', $raw)));
  $out='';$para=[];$list='';$code=false;$codeLines=[];$quote=[];
  $flushP=function()use(&$out,&$para){if($para){$out.='<p>'.inline_md(implode(' ',$para)).'</p>'; $para=[];}};
  $flushL=function()use(&$out,&$list){if($list){$out.='</'.$list.'>';$list='';}};
  $flushQ=function()use(&$out,&$quote){if($quote){$out.='<blockquote>'.inline_md(implode(' ',$quote)).'</blockquote>'; $quote=[];}};
  foreach($lines as $line){
    if(preg_match('/^\s*```/',$line)){
       $flushP();$flushL();$flushQ();
       if($code){$out.='<pre><code>'.h(implode("\n",$codeLines)).'</code></pre>';$codeLines=[];$code=false;}else $code=true;
       continue;
    }
    if($code){$codeLines[]=$line;continue;}
    $line=rtrim($line);
    if(trim($line)===''){$flushP();$flushL();$flushQ();continue;}
    if(preg_match('/^(#{1,4})\s+(.+)$/u',$line,$m)){
       $flushP();$flushL();$flushQ();$level=min(4,strlen($m[1])+1);$id=slugify($m[2]);
       $out.='<h'.$level.' id="'.h($id). '" class="article-heading">'.inline_md($m[2]).'<a class="anchor" href="#'.h($id).'" aria-label="Ссылка на раздел">#</a></h'.$level.'>';continue;
    }
    if(preg_match('/^\s*[-*]\s+(.+)$/u',$line,$m)){
       $flushP();$flushQ();if($list!=='ul'){$flushL();$out.='<ul>';$list='ul';}$out.='<li>'.inline_md($m[1]).'</li>';continue;
    }
    if(preg_match('/^\s*\d+[.)]\s+(.+)$/u',$line,$m)){
       $flushP();$flushQ();if($list!=='ol'){$flushL();$out.='<ol>';$list='ol';}$out.='<li>'.inline_md($m[1]).'</li>';continue;
    }
    if(preg_match('/^>\s?(.+)$/u',$line,$m)){$flushP();$flushL();$quote[]=$m[1];continue;}
    if(preg_match('/^---+\s*$/',$line)){$flushP();$flushL();$flushQ();$out.='<hr>';continue;}
    $flushL();$flushQ();$para[]=$line;
  }
  $flushP();$flushL();$flushQ();if($code)$out.='<pre><code>'.h(implode("\n",$codeLines)).'</code></pre>';
  return $out;
}
function article_toc(string $raw):array {
  $result=[];$inside=false;
  foreach(preg_split('/\R/u',$raw) as $line){if(preg_match('/^```/',$line)){$inside=!$inside;continue;}if($inside)continue;
    if(preg_match('/^(#{1,3})\s+(.+)$/u',trim($line),$m)){$result[]=['title'=>$m[2],'slug'=>slugify($m[2]),'level'=>strlen($m[1])];}
  }return $result;
}
