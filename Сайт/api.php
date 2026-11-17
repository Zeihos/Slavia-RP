<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$resource=(string)($_GET['resource']??'status');
if($resource!=='status'){http_response_code(404);echo json_encode(['error'=>'Not found']);exit;}
$s=settings();$servers=store_read('servers',[]);
echo json_encode(['project'=>'SLAVIA ROLEPLAY','status'=>$s['launch_status']??'development','status_label'=>status_label($s['launch_status']??'development'),'servers'=>array_map(static fn($r)=>['name'=>$r['name']??'','host'=>$r['host']??'','active'=>!empty($r['active'])],$servers),'updated_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
