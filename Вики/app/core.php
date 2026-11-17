<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (PHP_SAPI !== 'cli') {
        ini_set('session.use_strict_mode','1');
        ini_set('session.cookie_httponly','1');
        ini_set('session.cookie_samesite','Lax');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure','1');
    }
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path='index.php',array $params=[]):string {return $path.($params?'?'.http_build_query($params):'');}
function dbfile(string $name):string { if(!preg_match('/^[a-z_]+$/',$name))throw new RuntimeException('Wrong storage name');return WIKI_STORAGE.'/'.$name.'.json'; }
function data_read(string $name):array {
    $file=dbfile($name); if(!is_file($file))return [];
    $raw=file_get_contents($file);
    if($raw===false)throw new RuntimeException('Невозможно прочитать Wiki: '.$name);
    try {$decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    catch(JsonException $exception){throw new RuntimeException('Повреждены данные Wiki: '.$name,0,$exception);}
    if(!is_array($decoded))throw new RuntimeException('Неверный формат данных Wiki: '.$name);
    return $decoded;
}
function data_save(string $name,array $data):void {
    if(!is_dir(WIKI_STORAGE))mkdir(WIKI_STORAGE,0750,true);
    $lock=fopen(WIKI_STORAGE.'/.'. $name .'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Storage locked');
    try {
        $file=dbfile($name);$tmp=tempnam(WIKI_STORAGE,'tmp_');
        if($tmp===false)throw new RuntimeException('Cannot create temp file');
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json)===false){@unlink($tmp);throw new RuntimeException('Cannot write data');}
        @chmod($tmp,0640);
        if(!rename($tmp,$file)) {@unlink($tmp);throw new RuntimeException('Cannot save file');}
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
// Atomic read-modify-write for concurrent admin operations.
function data_update(string $name, callable $callback):array {
    if(!is_dir(WIKI_STORAGE))mkdir(WIKI_STORAGE,0750,true);
    $lock=fopen(WIKI_STORAGE.'/.'.$name.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Storage locked');
    try {
        $file=dbfile($name);$current=data_read($name);
        $next=$callback($current);if(!is_array($next))throw new RuntimeException('Invalid data update');
        $tmp=tempnam(WIKI_STORAGE,'tmp_');if($tmp===false)throw new RuntimeException('Cannot create temp file');
        $json=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json)===false){@unlink($tmp);throw new RuntimeException('Cannot write data');}
        @chmod($tmp,0640);if(!rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Cannot save file');}
        return $next;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function installed():bool {return is_file(WIKI_STORAGE.'/installed.lock') && count(data_read('users'))>0;}
function must_install():void {if(!installed()){header('Location: install.php');exit;}}
function settings():array {return array_replace([
 'name'=>'SLAVIA ROLEPLAY','wiki_title'=>'База знаний','subtitle'=>'Всё, что нужно знать об игровом мире SLAVIA','announcement'=>'База знаний развивается вместе с проектом. Уточняйте сведения в официальных сообществах.','site_url'=>'','forum_url'=>'','discord_url'=>'','vk_url'=>'https://vk.ru/slavia_rp','tg_url'=>'https://t.me/slavia_rp','download_url'=>'','contact_email'=>'','footer_text'=>'Неофициальные значения механик не публикуются без подтверждения.'
],data_read('settings'));}
function csrf_token():string {if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return $_SESSION['csrf'];}
function csrf_field():string{return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">';}
function csrf_check():void {if(!hash_equals(csrf_token(),(string)($_POST['csrf']??''))) {http_response_code(403);exit('Ошибка защиты формы. Обновите страницу и повторите действие.');}}
function redirect(string $to):never {header('Location: '.$to, true, 303);exit;}
function flash(string $message,string $type='success'):void {$_SESSION['flash']=['message'=>$message,'type'=>$type];}
function get_flash():?array {$f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f;}
function current_user():?array {
    $id=$_SESSION['uid']??null;
    if(!$id)return null;
    foreach(data_read('users') as $u) {
        if(($u['id']??null)!==$id)continue;
        $version=$_SESSION['auth_version']??null;
        if(!is_string($version)||!hash_equals(hash('sha256',(string)($u['password_hash']??'')),$version)){
            unset($_SESSION['uid'],$_SESSION['auth_version']);
            return null;
        }
        return $u;
    }
    unset($_SESSION['uid'],$_SESSION['auth_version']);
    return null;
}
function login_required():array {$u=current_user();if(!$u)redirect('login.php');return $u;}
function slugify(string $v):string {
  $map=['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $v=mb_lower_fallback(trim($v));$v=strtr($v,$map);$v=preg_replace('/[^a-z0-9]+/','-',$v);return trim((string)$v,'-');
}
function mb_lower_fallback(string $s):string {return function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtr(strtolower($s),array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY)));}
function excerpt(string $t,int $n=170):string {$t=trim(preg_replace('/\s+/u',' ',strip_tags($t))??'');return function_exists('mb_substr')?mb_substr($t,0,$n):substr($t,0,$n);}
function by_slug(array $items,string $slug):?array {foreach($items as $it)if(($it['slug']??'')===$slug)return $it;return null;}
function category_of(array $cats,int $id):?array {foreach($cats as $c)if((int)$c['id']===$id)return $c;return null;}
function published(array $articles):array {return array_values(array_filter($articles,fn($a)=>($a['status']??'draft')==='published'));}
function date_ru(string $date):string {$t=strtotime($date);return $t?date('d.m.Y',$t):'—';}
function base_url():string {$s=settings();if(!empty($s['site_url']))return rtrim((string)$s['site_url'],'/').'/';$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');$host=$_SERVER['HTTP_HOST']??'localhost';if(!preg_match('/^[a-z0-9.:-]+$/i',$host))$host='localhost';$dir=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/'));return ($https?'https':'http').'://'.$host.($dir==='/'?'':rtrim($dir,'/')).'/';}
function safe_http_url(mixed $v):string {$v=trim((string)$v);return preg_match('~^https?://[^\s<>"\']+$~i',$v)?$v:'';}
function is_editor():bool{return current_user()!==null;}
