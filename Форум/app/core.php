<?php
declare(strict_types=1);
const STORE = __DIR__ . '/../storage';
const MAX_TOPIC_TITLE = 110;
const MAX_POST_BODY = 12000;

function start_app(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('SLAVIA_FORUM');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        ini_set('session.use_strict_mode','1');
        session_start();
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: private, no-store');
}
function installed(): bool { return is_file(STORE.'/forum.json'); }
function db_read(): array {
    $raw = @file_get_contents(STORE.'/forum.json');
    $db = $raw === false ? null : json_decode($raw,true);
    if (!is_array($db)) throw new RuntimeException('Файл хранилища недоступен или повреждён.');
    return $db;
}
function db_write(callable $fn): mixed {
    if (!is_dir(STORE) || !is_writable(STORE)) throw new RuntimeException('Папка storage недоступна для записи.');
    $lock=fopen(STORE.'/forum.lock','c+');
    if (!$lock) throw new RuntimeException('Не удалось открыть блокировку данных.');
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('Нет доступа к блокировке.');
        $db=db_read();
        $out=$fn($db);
        save_db($db);
        return $out;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
function save_db(array $db): void {
    $encoded=json_encode($db,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $tmp=STORE.'/forum.'.bin2hex(random_bytes(8)).'.tmp';
    if (file_put_contents($tmp,$encoded,LOCK_EX)===false) throw new RuntimeException('Ошибка записи данных.');
    if (!rename($tmp,STORE.'/forum.json')) { @unlink($tmp);throw new RuntimeException('Ошибка сохранения данных.'); }
    @chmod(STORE.'/forum.json',0600);
}
function h(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function txt(mixed $s): string {return nl2br(h($s),false);}
function lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s,'UTF-8');
    return strtr(strtolower($s), array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY)));
}
function n_chars(string $s): int {return preg_match_all('/./us',$s) ?: 0;}
function user(array $db): ?array {
    $id=(string)($_SESSION['uid']??'');
    if($id==='' || !isset($db['users'][$id]))return null;
    $account=$db['users'][$id];
    $version=$_SESSION['auth_version']??null;
    if(!is_string($version)||!hash_equals(hash('sha256',(string)$account['password_hash']),$version)){
        unset($_SESSION['uid'],$_SESSION['auth_version']);
        return null;
    }
    return $account;
}
function is_staff(?array $u): bool {return $u!==null && (empty($u['banned_until']) || ($u['banned_until']!==-1 && $u['banned_until']<=time())) && in_array($u['role'],['admin','moderator'],true);}
function is_admin(?array $u): bool {return is_staff($u) && $u['role']==='admin';}
function need_user(?array $u): array {
    if (!$u) throw new RuntimeException('Сначала войдите в аккаунт.');
    if (!empty($u['banned_until']) && ($u['banned_until']===-1 || $u['banned_until']>time())) throw new RuntimeException('Доступ к действиям ограничен администрацией.');
    return $u;
}
function need_staff(?array $u): array { $u=need_user($u);if (!is_staff($u)) throw new RuntimeException('Недостаточно прав.');return $u; }
function need_admin(?array $u): array { $u=need_user($u);if (!is_admin($u)) throw new RuntimeException('Недостаточно прав.');return $u; }
function visible_board(array $b,?array $u): bool {return ($b['access']??'public')==='public'||is_staff($u);}
function can_post_board(array $b,?array $u): bool {return visible_board($b,$u) && (($b['posting']??'all')==='all'||is_staff($u));}
function token(): string {return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function check_token(): void {if (!isset($_POST['csrf']) || !hash_equals(token(),(string)$_POST['csrf'])) throw new RuntimeException('Форма устарела. Обновите страницу.');}
function csrf(): string {return '<input type="hidden" name="csrf" value="'.h(token()).'">';}
function q(int $id): string {return 'index.php?page=topic&id='.$id;}
function board_url(int $id): string {return 'index.php?page=board&id='.$id;}
function nav(string $url): never {header('Location: '.$url, true,303);exit;}
function flash(string $m,string $t='success'):void {$_SESSION['flash'][]=['text'=>$m,'type'=>$t];}
function flashes():array {$f=$_SESSION['flash']??[];unset($_SESSION['flash']);return $f;}
function poststr(string $key,int $limit=12000): string {
    $s=trim(str_replace("\r",'',(string)($_POST[$key]??'')));
    if (n_chars($s)>$limit) throw new RuntimeException('Слишком длинное поле.');
    return $s;
}
function ensure_length(string $s,int $min,int $max,string $label):string {
    $n=n_chars($s);
    if ($n<$min || $n>$max) throw new RuntimeException($label.': допустимо от '.$min.' до '.$max.' символов.');
    return $s;
}
function rate(string $name,int $limit,int $seconds): void {
    $client=substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,64);
    $uid=(string)($_SESSION['uid']??'guest');
    $key=hash('sha256',$name.'|'.$client.'|'.$uid);
    $file=STORE.'/rate-'.$key.'.json';
    $fh=fopen($file,'c+');
    if (!$fh) throw new RuntimeException('Ошибка ограничения запросов.');
    try {
        flock($fh,LOCK_EX);
        $raw=stream_get_contents($fh);
        $items=$raw?json_decode($raw,true):[];
        if(!is_array($items))$items=[];
        $items=array_values(array_filter($items,fn($v)=>is_int($v)&&$v>time()-$seconds));
        if(count($items)>=$limit) throw new RuntimeException('Слишком много действий. Повторите позже.');
        $items[]=time();ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode($items));fflush($fh);
    } finally {flock($fh,LOCK_UN);fclose($fh);}
}
function nextid(array &$db,string $name): int {$db['next'][$name] = (int)($db['next'][$name]??0)+1;return $db['next'][$name];}
function dt(int $ts): string {return date('d.m.Y H:i',$ts);}
function role_label(string $role):string {return ['admin'=>'Администратор','moderator'=>'Модератор','user'=>'Игрок'][$role]??'Игрок';}
function user_name(array $db,int $uid):string {return h($db['users'][(string)$uid]['username']??'Удалённый игрок');}
function find_board(array $db,int $id,?array $u):array {
    $b=$db['boards'][(string)$id]??null;
    if(!$b || !visible_board($b,$u)) throw new RuntimeException('Раздел не найден.');
    return $b;
}
function find_topic(array $db,int $id,?array $u):array {
    $t=$db['topics'][(string)$id]??null;
    if(!$t) throw new RuntimeException('Тема не найдена.');
    find_board($db,(int)$t['board_id'],$u);
    return $t;
}
function topic_posts(array $db,int $id):array {
    $posts=array_values(array_filter($db['posts'],fn($p)=>$p['topic_id']===$id));
    usort($posts,fn($a,$b)=>$a['id']<=>$b['id']);return $posts;
}
function topic_sort(array $a,array $b):int {return (($b['pinned']<=>$a['pinned']) ?: ($b['updated_at']<=>$a['updated_at']));}
function settings(array $db,string $key,string $fallback=''):string {return (string)($db['settings'][$key]??$fallback);}
function clean_external_url(string $url): string {
    if ($url==='')return '';
    if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)) throw new RuntimeException('Нужен полный адрес https://...');
    return $url;
}
function safe_redirect():string {
    $v=(string)($_POST['return']??'index.php');
    return preg_match('/^index\.php(?:\?[a-zA-Z0-9_=&%.-]{0,200})?$/D',$v)?$v:'index.php';
}
function page_slice(array $items,int $page,int $per=15):array {return array_slice($items,($page-1)*$per,$per);}
function pages(int $total,int $current,int $per,string $url):string {
    $last=(int)ceil($total/$per);if($last<=1)return '';
    $out='<nav class="pagination" aria-label="Страницы">';
    for($i=max(1,$current-3);$i<=min($last,$current+3);$i++)$out.='<a class="'.($i===$current?'active':'').'" href="'.h($url).(str_contains($url,'?')?'&':'?').'p='.$i.'">'.$i.'</a>';
    return $out.'</nav>';
}
function seed_data(string $name,string $password):array {
    $now=time();
    $cats=[
        ['id'=>1,'name'=>'Информационный центр','description'=>'Официальная информация о проекте','sort'=>10],
        ['id'=>2,'name'=>'Игровое сообщество','description'=>'Общение, предложения и события','sort'=>20],
        ['id'=>3,'name'=>'Серверы и организации','description'=>'Игровые серверы, государственные и криминальные структуры','sort'=>30],
        ['id'=>4,'name'=>'Обращения и поддержка','description'=>'Помощь, жалобы и обжалования','sort'=>40],
        ['id'=>5,'name'=>'Команда SLAVIA','description'=>'Закрытое пространство команды проекта','sort'=>50],
    ];
    $spec=[
      [1,'Новости проекта','Официальные публикации и обновления','megaphone','staff','public'],
      [1,'Правила и документы','Правила сервера и информация для игроков','book-open','staff','public'],
      [1,'Как начать играть','Инструкции и ответы на частые вопросы','compass','staff','public'],
      [2,'Общение игроков','Обсуждения, знакомства и игровые истории','message-circle','all','public'],
      [2,'Предложения и идеи','Предлагайте улучшения игрового процесса','lightbulb','all','public'],
      [2,'Мероприятия','Конкурсы и встречи игрового сообщества','calendar-days','all','public'],
      [3,'Обсуждение серверов','Раздел для игроков всех серверов','server','all','public'],
      [3,'Государственные организации','Полиция, правительство, медицина и другие структуры','landmark','all','public'],
      [3,'Криминальные организации','Обсуждение криминальных фракций','shield','all','public'],
      [4,'Техническая поддержка','Проблемы запуска, ошибки, вопросы','headset','all','public'],
      [4,'Жалобы на игроков','Публикация жалоб и доказательств','flag','all','public'],
      [4,'Обжалование наказаний','Обращения по блокировкам и наказаниям','scale','all','public'],
      [5,'Раздел администрации','Внутренние обсуждения команды','shield-check','staff','staff'],
    ];
    $boards=[];
    foreach($spec as $i=>$r){$id=$i+1;$boards[(string)$id]=['id'=>$id,'category_id'=>$r[0],'name'=>$r[1],'description'=>$r[2],'icon'=>$r[3],'posting'=>$r[4],'access'=>$r[5],'sort'=>($i+1)*10];}
    return [
        'format'=>1,'created_at'=>$now,
        'next'=>['users'=>1,'topics'=>0,'posts'=>0,'reports'=>0,'categories'=>count($cats),'boards'=>count($boards)],
        'users'=>['1'=>['id'=>1,'username'=>$name,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin','created_at'=>$now,'banned_until'=>0]],
        'categories'=>array_combine(array_map('strval',array_column($cats,'id')),$cats),
        'boards'=>$boards,'topics'=>[],'posts'=>[],'reports'=>[],
        'settings'=>[
            'forum_name'=>'SLAVIA ROLEPLAY','subtitle'=>'Твоя новая история начинается здесь',
            'welcome'=>'Официальное сообщество SLAVIA ROLEPLAY. Общайся, узнавай новости, предлагай идеи и участвуй в жизни проекта.',
            'vk'=>'https://vk.ru/slavia_rp','telegram'=>'https://t.me/slavia_rp','site'=>'',
            'registration'=>'1',
            'rules'=>"Добро пожаловать на форум SLAVIA ROLEPLAY.\n\n1. Уважайте участников и команду проекта.\n2. Запрещены спам, мошенничество, угрозы и публикация чужих личных данных.\n3. Создавайте темы в подходящих разделах.\n4. Не выдавайте себя за администрацию.\n5. При подаче жалоб указывайте факты и доказательства.\n\nПодробные игровые правила будут опубликованы администрацией проекта.",
        ],
    ];
}
