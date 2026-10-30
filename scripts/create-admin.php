<?php
require dirname(__DIR__).'/bootstrap.php';
try{$pdo=\App\Support\Database::connection();}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Run from CLI.');}
$exists=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_type='admin'")->fetchColumn();if($exists){fwrite(STDERR,"Admin đã tồn tại.\n");exit(1);}
$name=trim(readline('Admin name: '));$email=strtolower(trim(readline('Admin email: ')));$password=readline('Admin password (at least 12 chars): ');
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Thông tin không hợp lệ.\n");exit(1);}
$q=$pdo->prepare("INSERT INTO users(name,email,password,account_type) VALUES(?,?,?,'admin')");$q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);fwrite(STDOUT,"Đã tạo tài khoản Admin #".$pdo->lastInsertId().".\n");
