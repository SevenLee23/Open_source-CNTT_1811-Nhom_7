<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $pdo=\App\Support\Database::connection();
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new InvalidArgumentException('Use POST.');
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer\s+(\S+)$/i',$auth,$match)){http_response_code(401);throw new RuntimeException('Bearer API key required.');}
    $q=$pdo->prepare('SELECT e.*,p.id project_id FROM environments e JOIN projects p ON p.id=e.project_id WHERE e.api_key_hash=? AND p.is_active=1');$q->execute([hash('sha256',$match[1])]);$env=$q->fetch();
    if(!$env){http_response_code(401);throw new RuntimeException('Invalid API key.');}
    if(isset($body['environment']) && $body['environment']!==$env['env_key']){http_response_code(403);throw new RuntimeException('API key is scoped to another environment.');}
    $userKey=trim((string)($body['user_id']??''));$keys=$body['flags']??[];
    if($userKey===''||!is_array($keys)||!$keys){http_response_code(422);throw new InvalidArgumentException('user_id and non-empty flags[] are required.');}
    foreach($keys as $key)if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$key)){http_response_code(422);throw new InvalidArgumentException('Invalid feature key.');}
    $v=$pdo->prepare('SELECT id,version_number FROM versions WHERE id=? AND environment_id=?');$v->execute([(int)$env['current_version_id'],(int)$env['id']]);$version=$v->fetch();
    if(!$version)throw new RuntimeException('Environment has no active version.');
    $evaluator=new \App\Services\FlagEvaluator();$results=[];
    foreach(array_values(array_unique($keys)) as $key){$result=$evaluator->evaluate($env['project_id'],$env['id'],$version['id'],$userKey,$key);$results[$key]=$result;$log=$pdo->prepare('INSERT INTO decision_logs(project_id,environment_id,version_id,user_key,feature_key,enabled,reason_code) VALUES(?,?,?,?,?,?,?)');$log->execute([$env['project_id'],$env['id'],$version['id'],$userKey,$key,$result['enabled']?1:0,$result['reason']]);}
    echo json_encode(['success'=>true,'environment'=>$env['env_key'],'version'=>(int)$version['version_number'],'results'=>(object)$results],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage(),'results'=>(object)[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
