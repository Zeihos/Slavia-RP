<?php
declare(strict_types=1);
require $argv[1];
function assert_site(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
store_write('regression',['value'=>11]);
assert_site(store_read('regression')['value']===11,'Site data roundtrip failed');
$broken=GUARD_PREFIX.'{broken';
file_put_contents(data_path('regression'),$broken);
$rejected=false;
try {store_mutate('regression',static fn($old)=>['value'=>22]);}
catch(RuntimeException $exception){$rejected=true;}
assert_site($rejected,'Site corrupt data did not block mutation');
assert_site(file_get_contents(data_path('regression'))===$broken,'Site corrupted data overwritten');
$hash=password_hash('old-example-pass',PASSWORD_DEFAULT);
$_SESSION['admin_auth']=true;
$_SESSION['admin_seen']=time();
$_SESSION['admin_version']=hash('sha256',$hash);
assert_site(admin_session_valid(['password_hash'=>$hash]),'Valid site session rejected');
$updated=password_hash('new-example-pass',PASSWORD_DEFAULT);
assert_site(!admin_session_valid(['password_hash'=>$updated]),'Old site session still valid after password change');
echo "Site integrity and session checks: PASS\n";
