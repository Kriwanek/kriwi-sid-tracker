<?php
// /api/term_poll.php — Attendance API
// BUILD 2026-07-25 r13: aktive Teammitglieder dürfen bis zum Zusageschluss antworten

declare(strict_types=1);
@ini_set('display_errors','0');
@ini_set('display_startup_errors','0');
@error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');

function kb_poll_out(array $payload, int $status=200): void {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function kb_poll_norm_role(?string $role): string {
    $r=mb_strtolower(trim((string)$role),'UTF-8');
    $r=str_replace(['-',' ','/'],'_',$r);
    return preg_replace('/_+/','_',$r) ?: 'member';
}
function kb_poll_term_id(): int {
    return (int)($_POST['termin_id'] ?? $_GET['termin_id'] ?? $_POST['event_id'] ?? $_GET['event_id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
}
function kb_poll_counts(array $counts): array {
    return [
        'yes'=>(int)($counts['yes']??0),
        'later'=>(int)($counts['later']??$counts['late']??0),
        'sick'=>(int)($counts['sick']??0),
        'no'=>(int)($counts['no']??0),
    ];
}
function kb_poll_status(string $raw): ?string {
    $s=mb_strtolower(trim($raw),'UTF-8');
    return match($s){
        'yes','ja','dabei','anwesend','komm','komme','1','✅','✔' => 'yes',
        'later','late','später','spaeter','2','⏰' => 'later',
        'sick','krank','3','🤒' => 'sick',
        'no','nein','nicht','abwesend','4','0','❌','✖' => 'no',
        default => null,
    };
}
function kb_poll_table_exists(PDO $pdo,string $table): bool {
    try{$st=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table));return (bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}
function kb_poll_columns(PDO $pdo,string $table): array {
    try{return $pdo->query("SHOW COLUMNS FROM `".str_replace('`','',$table)."`")->fetchAll(PDO::FETCH_COLUMN,0)?:[];}catch(Throwable $e){return [];}
}
function kb_poll_col_exists(PDO $pdo,string $table,string $col): bool {
    return in_array($col,kb_poll_columns($pdo,$table),true);
}
function kb_poll_first_col(array $cols,array $candidates): ?string {
    foreach($candidates as $c) if(in_array($c,$cols,true)) return $c;
    return null;
}
function kb_poll_event(PDO $pdo,int $terminId): ?array {
    if($terminId<=0 || !kb_poll_table_exists($pdo,'termine'))return null;
    $cols=kb_poll_columns($pdo,'termine');
    $select=['id'];
    foreach(['team_id','start_at','rsvp_lock_at','rsvp_until','zusage_bis','rsvp_bis','rsvp_deadline','status','deleted_at','is_deleted'] as $c){
        if(in_array($c,$cols,true))$select[]="`$c`";
    }
    $where=['id=:id'];
    if(in_array('deleted_at',$cols,true))$where[]="(deleted_at IS NULL OR deleted_at='' OR deleted_at='0000-00-00 00:00:00')";
    if(in_array('is_deleted',$cols,true))$where[]="(is_deleted=0 OR is_deleted IS NULL)";
    $st=$pdo->prepare('SELECT '.implode(',',$select).' FROM termine WHERE '.implode(' AND ',$where).' LIMIT 1');
    $st->execute([':id'=>$terminId]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function kb_poll_access(PDO $pdo,array $me,array $event): array {
    $uid=(int)($me['id']??0);
    $global=kb_poll_norm_role((string)($me['role']??''));
    $teamId=(int)($event['team_id']??0);
    $globalManagers=['super_admin','admin','administrator','owner'];
    if(in_array($global,$globalManagers,true))return ['member'=>true,'manager'=>true,'role'=>$global];
    if($teamId<=0)return ['member'=>true,'manager'=>false,'role'=>$global];

    $membershipRole='';
    $member=false;
    if(kb_poll_table_exists($pdo,'memberships')){
        try{
            $mCols=kb_poll_columns($pdo,'memberships');
            $userCol=kb_poll_first_col($mCols,['user_id','member_id']);
            $teamCol=kb_poll_first_col($mCols,['team_id','mannschaft_id']);
            $roleCol=kb_poll_first_col($mCols,['role','team_role','membership_role']);
            if($userCol && $teamCol){
                $where=["`$userCol`=:uid","`$teamCol`=:tid"];
                if(in_array('status',$mCols,true))$where[]="(status IN ('approved','active') OR status IS NULL OR status='')";
                if(in_array('deleted_at',$mCols,true))$where[]="(deleted_at IS NULL OR deleted_at='' OR deleted_at='0000-00-00 00:00:00')";
                $order=[];
                if(in_array('is_primary',$mCols,true))$order[]='is_primary DESC';
                $order[]='id DESC';
                $sql='SELECT '.($roleCol?"`$roleCol`":"'member'").' AS membership_role FROM memberships WHERE '.implode(' AND ',$where).' ORDER BY '.implode(',',$order).' LIMIT 1';
                $st=$pdo->prepare($sql);
                $st->execute([':uid'=>$uid,':tid'=>$teamId]);
                $row=$st->fetch(PDO::FETCH_ASSOC);
                if($row){
                    $member=true;
                    $membershipRole=kb_poll_norm_role((string)($row['membership_role']??'member'));
                }
            }
        }catch(Throwable $e){}
    }
    if(!$member && kb_poll_table_exists($pdo,'users')){
        try{
            $uCols=kb_poll_columns($pdo,'users');
            foreach(['team_id','default_team_id','primary_team_id'] as $teamCol){
                if(!in_array($teamCol,$uCols,true))continue;
                $st=$pdo->prepare("SELECT 1 FROM users WHERE id=:uid AND `$teamCol`=:tid LIMIT 1");
                $st->execute([':uid'=>$uid,':tid'=>$teamId]);
                if($st->fetchColumn()){$member=true;break;}
            }
        }catch(Throwable $e){}
    }
    foreach(['kb_primary_team_id','primary_team_id','my_team_id','kb_team_id','team_id'] as $k){
        if((int)($_SESSION[$k]??0)===$teamId)$member=true;
    }
    $effective=$membershipRole!==''?$membershipRole:$global;
    $managerRoles=['team_lead','teamlead','teamleiter','leiter','manager','trainer','coach','co_trainer','co_coach','cotrainer','betreuer','staff'];
    return ['member'=>$member,'manager'=>$member && in_array($effective,$managerRoles,true),'role'=>$effective];
}
function kb_poll_lock_at(array $event): string {
    foreach(['rsvp_lock_at','rsvp_until','zusage_bis','rsvp_bis','rsvp_deadline'] as $c){
        $v=trim((string)($event[$c]??''));
        if($v!=='' && $v!=='0000-00-00 00:00:00') return $v;
    }
    return trim((string)($event['start_at']??''));
}
function kb_poll_is_locked(array $event): bool {
    $v=kb_poll_lock_at($event);
    if($v==='')return false;
    $ts=@strtotime($v);
    return $ts!==false && $ts<=time();
}

try{
    require_once __DIR__.'/../includes/config.php';
    require_once __DIR__.'/../includes/auth_kb.php';
    if(function_exists('kb_require_auth'))kb_require_auth();
    $me=function_exists('kb_user')?kb_user():[];
    $uid=(int)($me['id']??0);
    if($uid<=0)kb_poll_out(['ok'=>false,'error'=>'auth_required'],401);
    if(!($pdo??null) instanceof PDO)throw new RuntimeException('DB unavailable');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    require_once __DIR__.'/../includes/kb_attendance.php';
}catch(Throwable $e){
    error_log('[Kickerbay term_poll bootstrap] '.$e->getMessage());
    kb_poll_out(['ok'=>false,'error'=>'server_error'],500);
}

$fn=strtolower((string)($_GET['fn']??$_POST['fn']??'counts'));
if(!in_array($fn,['counts','set','reset_my','reset_all','my_bulk'],true))$fn='counts';

if($fn==='my_bulk'){
    $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??''))),fn($v)=>$v>0)));
    $items=[];
    foreach($ids as $id){
        $event=kb_poll_event($pdo,$id); if(!$event)continue;
        $access=kb_poll_access($pdo,$me,$event); if(!$access['member'])continue;
        $items[$id]=kb_att_user_state($pdo,$id,$uid);
    }
    kb_poll_out(['ok'=>true,'items'=>$items,'ts'=>time()]);
}

$terminId=kb_poll_term_id();
if($terminId<=0)kb_poll_out(['ok'=>false,'error'=>'bad_id'],400);
$event=kb_poll_event($pdo,$terminId);
if(!$event)kb_poll_out(['ok'=>false,'error'=>'not_found'],404);
$access=kb_poll_access($pdo,$me,$event);
if(!$access['member']){
    error_log('[Kickerbay term_poll access_denied] user='.$uid.' termin='.$terminId.' team='.(int)($event['team_id']??0).' role='.(string)($me['role']??''));
    kb_poll_out(['ok'=>false,'error'=>'access_denied'],403);
}

if($fn==='counts'){
    try{
        $my=kb_att_user_state($pdo,$terminId,$uid);
        kb_poll_out(['ok'=>true,'counts'=>kb_poll_counts(kb_att_counts($pdo,$terminId)),'my'=>['status'=>$my],'locked'=>kb_poll_is_locked($event),'lock_at'=>kb_poll_lock_at($event),'ts'=>time()]);
    }catch(Throwable $e){kb_poll_out(['ok'=>false,'error'=>'counts_failed'],500);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')kb_poll_out(['ok'=>false,'error'=>'method_not_allowed'],405);
$client=(string)($_POST['csrf']??$_POST['kb_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));
$server=(string)($_SESSION['kb_csrf']??$_SESSION['csrf']??'');
if($client===''||$server===''||!hash_equals($server,$client))kb_poll_out(['ok'=>false,'error'=>'csrf'],403);

try{
    if(in_array($fn,['set','reset_my'],true) && kb_poll_is_locked($event)){
        kb_poll_out(['ok'=>false,'error'=>'rsvp_locked','lock_at'=>kb_poll_lock_at($event)],403);
    }
    if($fn==='set'){
        $status=kb_poll_status((string)($_POST['status']??''));
        if($status===null)kb_poll_out(['ok'=>false,'error'=>'invalid_status'],400);
        $current=kb_att_user_state($pdo,$terminId,$uid);
        if($current!==$status && !kb_att_set($pdo,$terminId,$uid,$status))throw new RuntimeException('attendance save failed');
        kb_poll_out(['ok'=>true,'counts'=>kb_poll_counts(kb_att_counts($pdo,$terminId)),'my'=>['status'=>$status],'ts'=>time()]);
    }
    if($fn==='reset_my'){
        if(!kb_att_reset_my($pdo,$terminId,$uid))throw new RuntimeException('attendance reset failed');
        kb_poll_out(['ok'=>true,'counts'=>kb_poll_counts(kb_att_counts($pdo,$terminId)),'my'=>['status'=>null],'ts'=>time()]);
    }
    if($fn==='reset_all'){
        if(!$access['manager'])kb_poll_out(['ok'=>false,'error'=>'access_denied'],403);
        if(!kb_att_reset_all($pdo,$terminId))throw new RuntimeException('attendance reset all failed');
        kb_poll_out(['ok'=>true,'counts'=>kb_poll_counts(kb_att_counts($pdo,$terminId)),'ts'=>time()]);
    }
}catch(Throwable $e){
    error_log('[Kickerbay term_poll '.$fn.'] '.$e->getMessage());
    kb_poll_out(['ok'=>false,'error'=>'save_failed'],500);
}

kb_poll_out(['ok'=>false,'error'=>'unknown_action'],400);
