<?php
declare(strict_types=1);
require $argv[1];
start_app();
function assert_forum(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
$hash=password_hash('old-example-pass',PASSWORD_DEFAULT);
$db=['users'=>['1'=>['id'=>1,'username'=>'tester','role'=>'user','password_hash'=>$hash]]];
$_SESSION['uid']=1;
$_SESSION['auth_version']=hash('sha256',$hash);
assert_forum(user($db)!==null,'Valid forum session rejected');
$db['users']['1']['password_hash']=password_hash('new-example-pass',PASSWORD_DEFAULT);
assert_forum(user($db)===null,'Old forum session still valid after password change');
echo "Forum session checks: PASS\n";
