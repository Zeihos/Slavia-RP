<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'");

const DATA_ROOT = __DIR__ . '/data/';
const GUARD_PREFIX = "<?php http_response_code(404); exit; ?>\n";
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function data_path(string $name): string {
    if (!preg_match('/^[a-z_]+$/', $name)) throw new RuntimeException('Invalid storage key');
    return DATA_ROOT . $name . '.php';
}
function store_read(string $name, mixed $fallback = []): mixed {
    $path = data_path($name);
    if (!is_file($path)) return $fallback;
    $raw = file_get_contents($path);
    if ($raw === false || !str_starts_with($raw, GUARD_PREFIX)) return $fallback;
    $value = json_decode(substr($raw, strlen(GUARD_PREFIX)), true);
    return json_last_error() === JSON_ERROR_NONE ? $value : $fallback;
}
function store_write(string $name, mixed $value): void {
    if (!is_dir(DATA_ROOT) && !mkdir(DATA_ROOT, 0750, true) && !is_dir(DATA_ROOT)) throw new RuntimeException('Storage not writable');
    $target = data_path($name);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = tempnam(DATA_ROOT, '.slv');
    if ($tmp === false) throw new RuntimeException('Storage not writable');
    try {
        if (file_put_contents($tmp, GUARD_PREFIX . $json, LOCK_EX) === false) throw new RuntimeException('Storage not writable');
        chmod($tmp, 0640);
        if (!rename($tmp, $target)) throw new RuntimeException('Unable to save data');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function store_mutate(string $name, callable $callback, mixed $default = []): mixed {
    $lock = DATA_ROOT . '.storage.lock';
    $fp = fopen($lock, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Storage lock failure');
    try { $out = $callback(store_read($name, $default)); store_write($name, $out); return $out; }
    finally { flock($fp, LOCK_UN); fclose($fp); }
}
function seed(): void {
    $defaults = [
        'settings' => [
            'site_name' => 'SLAVIA ROLEPLAY', 'tagline' => 'ТВОЯ НОВАЯ ИСТОРИЯ',
            'hero_heading' => 'ГОРОД ЖДЁТ ТВОЮ ИСТОРИЮ',
            'hero_description' => 'Открой свой путь в мире SLAVIA ROLEPLAY: знакомства, автомобили, городская жизнь и решения, которые определяют твой сюжет.',
            'announcement' => 'Проект в разработке. Следите за официальными объявлениями о запуске.',
            'launch_status' => 'development',
            'vk_url' => 'https://vk.ru/slavia_rp', 'telegram_url' => 'https://t.me/slavia_rp',
            'forum_url' => '', 'download_android' => '', 'download_ios' => '', 'download_pc' => '',
            'contact_email' => '', 'domain' => '', 'footer_note' => 'Независимый игровой RolePlay-проект. Не является частью других игровых проектов.'
        ],
        'servers' => [],
        'products' => [],
        'news' => [
            ['id'=>'welcome','title'=>'Добро пожаловать в SLAVIA ROLEPLAY','excerpt'=>'Знакомьтесь с новым игровым проектом и его официальными сообществами.','body'=>"SLAVIA ROLEPLAY — это проект о собственных историях в большом городе. Мы создаём пространство для RolePlay, общения, развития персонажа и ярких игровых событий.\n\nСледите за нашими официальными сообществами ВКонтакте и Telegram: там появятся подтверждённые новости и информация о дальнейшем развитии.\n\nИнформация о доступности игровых серверов, клиента и дате открытия будет опубликована отдельно.",'category'=>'Проект','date'=>'2026-10-28','published'=>true]
        ],
        'faqs' => [
            ['id'=>'f1','question'=>'Как начать играть?','answer'=>'Следите за разделом «Начать играть». Официальные ссылки на установку и доступные серверы появятся здесь, когда они будут подтверждены.'],
            ['id'=>'f2','question'=>'Когда состоится открытие?','answer'=>'Подтверждённая дата пока не опубликована на этом сайте. Новости о запуске появятся в официальных сообществах проекта.'],
            ['id'=>'f3','question'=>'Где следить за новостями?','answer'=>'В наших официальных сообществах: vk.ru/slavia_rp и t.me/slavia_rp.'],
            ['id'=>'f4','question'=>'Как связаться с командой?','answer'=>'Используйте форму обратной связи на сайте или официальные сообщества.']
        ],
        'tickets' => []
    ];
    foreach ($defaults as $name=>$value) if (!file_exists(data_path($name))) store_write($name, $value);
}
seed();
function settings(): array { return store_read('settings', []); }
function safe_url(mixed $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' ? $url : '';
}
function page_url(string $page='home', array $args=[]): string {
    return 'index.php?' . http_build_query(['page'=>$page]+$args, '', '&', PHP_QUERY_RFC3986);
}
function active_page(string $page, string $candidate): string { return $page===$candidate ? ' aria-current="page"' : ''; }
function csrf(): string {
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf(), $_POST['csrf'])) {
        http_response_code(419); exit('Неверный или устаревший токен формы. Обновите страницу.');
    }
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function redirect(string $target): never { header('Location: '.$target, true, 303); exit; }
function get_flash(): array {
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return is_array($f) ? $f : [];
}
function set_flash(string $kind, string $message): void { $_SESSION['flash']=['kind'=>$kind,'message'=>$message]; }
function status_label(string $status): string {
    return match($status) { 'online'=>'Серверы открыты','testing'=>'Тестирование','maintenance'=>'Технические работы',default=>'В разработке' };
}
function fmt_date(string $date): string {
    $ts = strtotime($date);
    if (!$ts) return $date;
    $months=[1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
    return date('j',$ts).' '.($months[(int)date('n',$ts)]??'').' '.date('Y',$ts);
}
function trimmed(string $key, int $max=2000): string {
    $val = $_POST[$key]??'';
    if (!is_string($val)) return '';
    return (function_exists('mb_substr')?mb_substr(trim($val),0,$max):substr(trim($val),0,$max));
}
function slug_id(): string { return bin2hex(random_bytes(8)); }
