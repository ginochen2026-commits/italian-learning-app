<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User');
if($_SERVER['REQUEST_METHOD']==='OPTIONS')exit;
session_start();
$db=new SQLite3(__DIR__.'/english.db');
$db->enableExceptions(true);
$in=json_decode(file_get_contents('php://input'),true)?:[];
$uri=str_replace('/api.php','',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH))?:'/';$uri=preg_replace('#^/en#','',$uri);
$m=$_SERVER['REQUEST_METHOD'];

function uid(){global $db;$s=$_SESSION['u']??'';if(!$s)$s=$_SERVER['HTTP_X_USER']??'';if(!$s)$s=$_GET['uid']??'';if(!$s)$s=$_GET['u']??'';if(!$s)return 0;$r=$db->querySingle("SELECT id FROM users WHERE username='".$db->escapeString($s)."'",true);return $r['id']??0;}
function hp($p){return hash('sha256',$p);}
function esc($s){global $db;return $db->escapeString((string)$s);}
function seed_user($uid){global $db;$db->exec("INSERT INTO categories(name,user_id) VALUES('英语三百句',$uid)");$catId=$db->lastInsertRowID();$map=[];$r=$db->query("SELECT DISTINCT category FROM seed_sentences ORDER BY id");while($x=$r->fetchArray(SQLITE3_ASSOC)){$cn=$x['category'];$db->exec("INSERT INTO word_banks(name,user_id,category_id) VALUES('".esc($cn)."',$uid,$catId)");$map[$cn]=$db->lastInsertRowID();}$s=$db->query("SELECT category,english,chinese FROM seed_sentences");while($y=$s->fetchArray(SQLITE3_ASSOC)){$bid=$map[$y['category']]??0;$db->exec("INSERT INTO words(italian,chinese,user_id,bank_id) VALUES('".esc($y['english'])."','".esc($y['chinese'])."',$uid,$bid)");}}

// Login
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
  $nid=$db->lastInsertRowID();seed_user($nid);$_SESSION['u']=$u;echo json_encode(['ok'=>1,'u'=>$u,'id'=>$nid]);exit;
}
if($uri==='/api/logout'&&$m==='POST'){session_destroy();echo json_encode(['ok'=>1]);exit;}

// Words
if($uri==='/api/words'||preg_match('#^/api/words/(\d+)$#',$uri,$mm)){
  $id=uid();$bid=isset($mm)?(int)$mm[1]:0;
  $sql="SELECT * FROM words WHERE user_id=$id";if($bid)$sql.=" AND bank_id=$bid";$sql.=" ORDER BY id";
  $r=$db->query($sql);$w=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$w[]=$x;
  echo json_encode($w);exit;
}
if($uri==='/api/add_word'&&$m==='POST'){
  $id=uid();if(!$id){echo json_encode(['e'=>'请先登录']);exit;}$it=trim($in['i']??'');$zh=trim($in['c']??'');
  if(!$it||!$zh){echo json_encode(['e'=>'empty']);exit;}
  $bid=(int)($in['bank']??0);
  $db->exec("INSERT INTO words(italian,chinese,user_id,bank_id) VALUES('".esc($it)."','".esc($zh)."',$id,$bid)");
  echo json_encode(['ok'=>1,'id'=>$db->lastInsertRowID()]);exit;
}
if($uri==='/api/del_word'&&$m==='POST'){$db->exec("DELETE FROM words WHERE id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/word_update'&&$m==='POST'){$id=uid();if(!$id){echo json_encode(['e'=>'请先登录']);exit;}$wid=(int)($in['id']??0);$it=trim($in['italian']??'');$zh=trim($in['chinese']??'');if(!$wid||!$it||!$zh){echo json_encode(['e'=>'empty']);exit;}$db->exec("UPDATE words SET italian='".esc($it)."',chinese='".esc($zh)."' WHERE id=$wid AND user_id=$id");echo json_encode(['ok'=>1]);exit;}

// Banks
if($uri==='/api/banks'){$id=uid();$r=$db->query("SELECT * FROM word_banks WHERE user_id=$id");$b=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$b[]=$x;echo json_encode($b);exit;}
if($uri==='/api/bank_create'&&$m==='POST'){$id=uid();if(!$id){echo json_encode(['e'=>'请先登录']);exit;}$n=trim($in['name']??'');$cid=(int)($in['category_id']??0);$db->exec("INSERT INTO word_banks(name,user_id,category_id) VALUES('".esc($n)."',$id,$cid)");echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/bank_rename'&&$m==='POST'){$db->exec("UPDATE word_banks SET name='".esc(trim($in['name']??''))."' WHERE id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/bank_move'&&$m==='POST'){$db->exec("UPDATE word_banks SET category_id=".(int)($in['category_id']??0)." WHERE id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/bank_delete'&&$m==='POST'){$db->exec("DELETE FROM word_banks WHERE id=".(int)$in['id']);$db->exec("UPDATE words SET bank_id=0 WHERE bank_id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}

// Categories
if($uri==='/api/categories'){$id=uid();$r=$db->query("SELECT * FROM categories WHERE user_id=$id ORDER BY id");$c=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$c[]=$x;echo json_encode($c);exit;}
if($uri==='/api/cat_create'&&$m==='POST'){$id=uid();if(!$id){echo json_encode(['e'=>'请先登录']);exit;}$n=trim($in['name']??'');if(!$n){echo json_encode(['e'=>'empty']);exit;}$db->exec("INSERT INTO categories(name,user_id) VALUES('".esc($n)."',$id)");echo json_encode(['ok'=>1,'id'=>$db->lastInsertRowID()]);exit;}
if($uri==='/api/cat_rename'&&$m==='POST'){$db->exec("UPDATE categories SET name='".esc(trim($in['name']??''))."' WHERE id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/cat_delete'&&$m==='POST'){$db->exec("DELETE FROM categories WHERE id=".(int)$in['id']);$db->exec("UPDATE word_banks SET category_id=0 WHERE category_id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}

// Translate
if($uri==='/api/tr'){
  $q=$_GET['q']??'';if(!$q){echo json_encode(['e'=>'no query']);exit;}
  $cn=preg_match('/[\x{4e00}-\x{9fff}]/u',$q);
  $sl=$cn?'zh-CN':'en';$tl=$cn?'en':'zh-CN';
  $url="https://translate.googleapis.com/translate_a/single?client=dict-chrome-ex&sl=$sl&tl=$tl&dt=t&q=".urlencode($q);
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
  $resp=@file_get_contents($url,false,$ctx);
  if($resp){$d=json_decode($resp,true);echo json_encode(['ok'=>1,'r'=>implode('',array_map(function($s){return$s[0];},$d[0]??[]))]);exit;}
  echo json_encode(['e'=>'fail']);exit;
}

// Feedback
if($uri==='/api/feedback'){$r=$db->query("SELECT * FROM feedback ORDER BY id DESC LIMIT 200");$f=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$f[]=$x;echo json_encode($f);exit;}
if($uri==='/api/feedback_add'&&$m==='POST'){$id=uid();$c=trim($in['content']??'');if(!$c){echo json_encode(['e'=>'empty']);exit;}$u=($_SESSION['u']??'')?:($_SERVER['HTTP_X_USER']??'');if(!$u)$u='游客';$db->exec("INSERT INTO feedback(user_id,username,content,created_at) VALUES($id,'".esc($u)."','".esc($c)."',datetime('now','localtime'))");echo json_encode(['ok'=>1]);exit;}
if($uri==='/api/feedback_del'&&$m==='POST'){$db->exec("DELETE FROM feedback WHERE id=".(int)$in['id']);echo json_encode(['ok'=>1]);exit;}

if($uri==='/api/admin_users'){$id=uid();$adm=$db->querySingle("SELECT is_admin FROM users WHERE id=$id")?:0;if(!$adm){echo json_encode(['e'=>'无权限']);exit;}$r=$db->query("SELECT u.id,u.username,u.email,u.is_admin,(SELECT COUNT(*) FROM words w WHERE w.user_id=u.id) as wc,(SELECT COUNT(*) FROM word_banks b WHERE b.user_id=u.id) as bc,(SELECT COUNT(*) FROM categories c WHERE c.user_id=u.id) as cc FROM users u ORDER BY u.id");$list=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$list[]=$x;echo json_encode($list);exit;}

if($uri==='/api/dict_wrong'&&$m==='POST'){$id=uid();if(!$id){echo json_encode(['e'=>'请先登录']);exit;}$words=$in['words']??[];$bank=$db->querySingle("SELECT id FROM word_banks WHERE user_id=$id AND name='听写错词'",true);if(!$bank){$db->exec("INSERT INTO word_banks(name,user_id,category_id) VALUES('听写错词',$id,0)");$bid=$db->lastInsertRowID();}else{$bid=$bank['id'];}$n=0;foreach($words as $w){$it=trim($w['i']??'');$zh=trim($w['c']??'');if(!$it||!$zh)continue;$wid=$db->querySingle("SELECT id FROM words WHERE user_id=$id AND italian='".esc($it)."' AND chinese='".esc($zh)."'",true);if(!$wid)continue;$ex=$db->querySingle("SELECT id FROM bank_words WHERE bank_id=$bid AND word_id=".$wid['id'],true);if(!$ex){$db->exec("INSERT INTO bank_words(bank_id,word_id,user_id) VALUES($bid,".$wid['id'].",$id)");$n++;}}echo json_encode(['ok'=>1,'bid'=>$bid,'added'=>$n]);exit;}

echo json_encode(['e'=>'Not found']);
