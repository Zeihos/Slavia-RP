<?php
declare(strict_types=1);
require $argv[1];
function assert_wiki(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
data_save('regression',['value'=>11]);
assert_wiki(data_read('regression')['value']===11,'Wiki data roundtrip failed');
$broken='{broken';
file_put_contents(dbfile('regression'),$broken);
$rejected=false;
try {data_update('regression',static fn($old)=>['value'=>22]);}
catch(RuntimeException $exception){$rejected=true;}
assert_wiki($rejected,'Wiki corrupt data did not block mutation');
assert_wiki(file_get_contents(dbfile('regression'))===$broken,'Wiki corrupted data overwritten');
$hash=password_hash('old-example-pass',PASSWORD_DEFAULT);
data_save('users',[['id'=>1,'username'=>'tester','password_hash'=>$hash]]);
$_SESSION['uid']=1;
$_SESSION['auth_version']=hash('sha256',$hash);
assert_wiki(current_user()!==null,'Valid Wiki session rejected');
$updated=password_hash('new-example-pass',PASSWORD_DEFAULT);
data_update('users',static fn($users)=>[['id'=>1,'username'=>'tester','password_hash'=>$updated]]);
assert_wiki(current_user()===null,'Old Wiki session still valid after password change');
echo "Wiki integrity and session checks: PASS\n";
