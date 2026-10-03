<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User');
if($_SERVER['REQUEST_METHOD']==='OPTIONS')exit;
session_start();
$db=new SQLite3(__DIR__.'/patente.db');
$db->enableExceptions(true);
$in=json_decode(file_get_contents('php://input'),true)?:[];
$uri=str_replace('/api.php','',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH))?:'/';
$uri=preg_replace('#^/patente#','',$uri);
$m=$_SERVER['REQUEST_METHOD'];

function uid(){global $db;$s=$_SESSION['u']??'';if(!$s)$s=$_SERVER['HTTP_X_USER']??'';if(!$s)$s=$_GET['uid']??'';if(!$s)$s=$_GET['u']??'';if(!$s)return 0;$r=$db->querySingle("SELECT id FROM users WHERE username='".$db->escapeString($s)."'",true);return $r['id']??0;}
function hp($p){return hash('sha256',$p);}
function esc($s){global $db;return $db->escapeString((string)$s);}

// 认证
if($uri==='/api/login'&&$m==='POST'){
  $u=trim($in['u']??'');$p=$in['p']??'';
  $r=$db->querySingle("SELECT id,is_admin FROM users WHERE username='".esc($u)."' AND password='".hp($p)."'",true);
  if(!$r){echo json_encode(['e'=>'用户名或密码错误']);exit;}
  $_SESSION['u']=$u;echo json_encode(['ok'=>1,'u'=>$u,'id'=>$r['id'],'adm'=>$r['is_admin']??0]);exit;
}
if($uri==='/api/register'&&$m==='POST'){
  $u=trim($in['u']??'');$p=$in['p']??'';$p2=$in['p2']??'';$e=trim($in['e']??'');
  if($p!==$p2){echo json_encode(['e'=>'两次密码不一致']);exit;}
  if($db->querySingle("SELECT id FROM users WHERE username='".esc($u)."'")){echo json_encode(['e'=>'用户名已存在']);exit;}
  $db->exec("INSERT INTO users(username,password,email) VALUES('".esc($u)."','".hp($p)."','".esc($e)."')");
  $nid=$db->lastInsertRowID();$_SESSION['u']=$u;echo json_encode(['ok'=>1,'u'=>$u,'id'=>$nid]);exit;
}
if($uri==='/api/logout'&&$m==='POST'){session_destroy();echo json_encode(['ok'=>1]);exit;}

// 章节列表(带题目数)
if($uri==='/api/chapters'){
  $r=$db->query("SELECT c.*,(SELECT COUNT(*) FROM questions q WHERE q.chapter_id=c.id) as cnt FROM chapters c ORDER BY c.sort");
  $list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}

// 题目列表(按章节/题组)
if($uri==='/api/questions'){
  $ch=(int)($_GET['chapter']??0);$g=trim($_GET['group']??'');
  $sql="SELECT * FROM questions WHERE 1=1";
  if($ch)$sql.=" AND chapter_id=$ch";
  if($g)$sql.=" AND gcode='".esc($g)."'";
  $sql.=" ORDER BY gcode,id";
  $r=$db->query($sql);$list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}

// 答题:记录历史+错题
if($uri==='/api/answer'&&$m==='POST'){
  $id=uid();$qid=(int)($in['question_id']??0);$ua=(int)($in['answer']??-1);$mode=trim($in['mode']??'chapter');
  if($ua<0){echo json_encode(['e'=>'empty']);exit;}
  $q=$db->querySingle("SELECT answer FROM questions WHERE id=$qid",true);
  if(!$q){echo json_encode(['e'=>'no question']);exit;}
  $correct=($ua===(int)$q['answer'])?1:0;
  $db->exec("INSERT INTO user_history(user_id,question_id,user_answer,is_correct,mode) VALUES($id,$qid,$ua,$correct,'".esc($mode)."')");
  if(!$correct){
    $w=$db->querySingle("SELECT id FROM user_wrong WHERE user_id=$id AND question_id=$qid",true);
    if($w)$db->exec("UPDATE user_wrong SET wrong_count=wrong_count+1,last_wrong_at=datetime('now','localtime') WHERE id=".$w['id']);
    else $db->exec("INSERT INTO user_wrong(user_id,question_id) VALUES($id,$qid)");
  }
  echo json_encode(['ok'=>1,'correct'=>$correct,'answer'=>(int)$q['answer']]);exit;
}

// 错题本
if($uri==='/api/wrong'){
  $id=uid();
  $r=$db->query("SELECT q.*,w.wrong_count FROM user_wrong w JOIN questions q ON q.id=w.question_id WHERE w.user_id=$id ORDER BY w.last_wrong_at DESC");
  $list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}
if($uri==='/api/wrong_del'&&$m==='POST'){$id=uid();$db->exec("DELETE FROM user_wrong WHERE user_id=$id AND question_id=".(int)$in['question_id']);echo json_encode(['ok'=>1]);exit;}

// 收藏
if($uri==='/api/fav'&&$m==='POST'){
  $id=uid();$qid=(int)($in['question_id']??0);
  $f=$db->querySingle("SELECT id FROM user_fav WHERE user_id=$id AND question_id=$qid",true);
  if($f){$db->exec("DELETE FROM user_fav WHERE id=".$f['id']);$st=0;}else{$db->exec("INSERT INTO user_fav(user_id,question_id) VALUES($id,$qid)");$st=1;}
  echo json_encode(['ok'=>1,'fav'=>$st]);exit;
}
if($uri==='/api/favs'){
  $id=uid();
  $r=$db->query("SELECT q.* FROM user_fav f JOIN questions q ON q.id=f.question_id WHERE f.user_id=$id ORDER BY f.id DESC");
  $list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}

// 模拟考试:随机抽题
if($uri==='/api/exam'){
  $id=uid();$n=(int)($_GET['n']??30);
  $r=$db->query("SELECT * FROM questions ORDER BY RANDOM() LIMIT $n");
  $list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}
if($uri==='/api/exam_submit'&&$m==='POST'){
  $id=uid();$total=(int)($in['total']??0);$correct=(int)($in['correct']??0);$wrong=(int)($in['wrong']??0);$time=(int)($in['used_time']??0);
  $db->exec("INSERT INTO user_exam(user_id,total,correct,wrong,used_time) VALUES($id,$total,$correct,$wrong,$time)");
  echo json_encode(['ok'=>1]);exit;
}

// 历史与统计
if($uri==='/api/history'){
  $id=uid();$lim=(int)($_GET['limit']??50);
  $r=$db->query("SELECT h.*,q.q_it,q.q_zh,q.answer FROM user_history h JOIN questions q ON q.id=h.question_id WHERE h.user_id=$id ORDER BY h.id DESC LIMIT $lim");
  $list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;
}
if($uri==='/api/stats'){
  $id=uid();
  $total=$db->querySingle("SELECT COUNT(*) FROM user_history WHERE user_id=$id")?:0;
  $correct=$db->querySingle("SELECT COUNT(*) FROM user_history WHERE user_id=$id AND is_correct=1")?:0;
  $wrong=$db->querySingle("SELECT COUNT(*) FROM user_wrong WHERE user_id=$id")?:0;
  $fav=$db->querySingle("SELECT COUNT(*) FROM user_fav WHERE user_id=$id")?:0;
  $exams=$db->querySingle("SELECT COUNT(*) FROM user_exam WHERE user_id=$id")?:0;
  $chapters=$db->querySingle("SELECT COUNT(*) FROM chapters")?:0;
  $allq=$db->querySingle("SELECT COUNT(*) FROM questions")?:0;
  echo json_encode(['total'=>$total,'correct'=>$correct,'wrong'=>$wrong,'fav'=>$fav,'exams'=>$exams,'chapters'=>$chapters,'all_questions'=>$allq,'rate'=>$total?round($correct/$total*100):0]);exit;
}

// 设置(考试日期)
if($uri==='/api/setting'&&$m==='POST'){
  $id=uid();$d=trim($in['exam_date']??'');
  $s=$db->querySingle("SELECT user_id FROM user_setting WHERE user_id=$id",true);
  if($s)$db->exec("UPDATE user_setting SET exam_date='".esc($d)."' WHERE user_id=$id");
  else $db->exec("INSERT INTO user_setting(user_id,exam_date) VALUES($id,'".esc($d)."')");
  echo json_encode(['ok'=>1]);exit;
}
if($uri==='/api/setting'){
  $id=uid();$s=$db->querySingle("SELECT exam_date FROM user_setting WHERE user_id=$id",true);
  echo json_encode(['exam_date'=>$s['exam_date']??'']);exit;
}

echo json_encode(['e'=>'Not found']);
