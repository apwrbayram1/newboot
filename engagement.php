<?php
// All tables are connection-local temporary tables; Telegram is mocked by the parent.
$connect->query("UPDATE platform_settings SET value='1' WHERE name IN ('reply_navigation','onboarding_required')");
$connect->query("INSERT INTO platform_settings(name,value) VALUES('tendency_seconds','1800'),('tendency_enabled','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$eng=new Platform($connect,['SUPERADMIN_IDS'=>'995004']);$eu=995001;$ev=995002;$ea=995003;$es=995004;$ef=995005;$eb=995006;
foreach([$eu,$ev,$ea,$es,$ef,$eb] as $id)invoke($eng,'account',$id);
$connect->query("UPDATE platform_accounts SET language='en',accepted=1,name_set=1,gender='male',balance=100 WHERE id IN ($ea,$es,$ev,$ef,$eb)");
$connect->query("UPDATE platform_accounts SET balance=100 WHERE id=$eu");
$connect->query("UPDATE platform_accounts SET role='admin' WHERE id=$ea");$connect->query("UPDATE platform_accounts SET role='banned' WHERE id=$eb");
$startCalls=count($GLOBALS['calls']);$eventId=9900000;$botAdmin=false;$membership=[$eu=>'left'];$failSend=[];
$GLOBALS['telegram_stub']=function($method,$data)use(&$botAdmin,&$membership,&$failSend){
 if($method==='getMe')return (object)['ok'=>true,'result'=>(object)['id'=>777000,'username'=>'MockBot']];
 if($method==='getChat')return (object)['ok'=>true,'result'=>(object)['id'=>-10012345678,'type'=>'channel','title'=>'Channel fixture','username'=>'FixtureChannel']];
 if($method==='getChatMember'){
  if(($data['user_id']??0)==777000)$status=$botAdmin?'administrator':'member';else $status=$membership[$data['user_id']]??'member';
  if($status==='error')return (object)['ok'=>false,'error_code'=>503];
  return (object)['ok'=>true,'result'=>(object)['status'=>$status]];
 }
 if(str_starts_with($method,'send')&&isset($failSend[$data['chat_id']??0])){
  $f=&$failSend[$data['chat_id']];if($f['remaining']-->0)return (object)['ok'=>false,'error_code'=>$f['code'],'description'=>'Mock delivery error','parameters'=>(object)['retry_after'=>20]];
 }
 return null;
};
$evsend=function(int $id,array $body)use($eng,&$eventId){$event=(object)['update_id'=>$eventId++,'message'=>(object)array_merge(['message_id'=>$eventId,'chat'=>(object)['id'=>$id,'type'=>'private'],'from'=>(object)['id'=>$id,'username'=>'fixture_user']],$body)];return $eng->handle($event);};
$nav=function(int $id)use($connect){return json_decode(row($connect,"SELECT payload FROM platform_navigation WHERE user_id=$id")['payload'],true)['map'];};
$press=function(int $id,string $wanted)use($nav,$evsend){foreach($nav($id) as $label=>$action)if($action===$wanted){$evsend($id,['text'=>$label]);return $action;}foreach($nav($id) as $label=>$action)if(is_string($action)&&str_starts_with($action,$wanted)){$evsend($id,['text'=>$label]);return $action;}throw new RuntimeException('Missing reply action '.$wanted);};
$latestCard=function(int $id){foreach(array_reverse($GLOBALS['calls']) as [$method,$data])if(($data['chat_id']??0)===$id&&isset($data['text']))return $data;throw new RuntimeException('No card');};
$expectDenied=function(callable $f,string $label){try{$f();throw new LogicException('Expected denial: '.$label);}catch(RuntimeException $e){check(true,$label);}};
$evsend($eu,['text'=>'/start']);$keys=json_decode($latestCard($eu)['reply_markup'],true);
check(isset($keys['keyboard'])&&!isset($keys['inline_keyboard']),'Language selection uses a keyboard below the text box');
invoke($eng,'action',$ea,'channel:configure');
$forward=['forward_origin'=>(object)['type'=>'channel','chat'=>(object)['id'=>-10012345678,'type'=>'channel','title'=>'Fixture channel']],'text'=>'Channel post'];
$evsend($ea,$forward);check(!row($connect,'SELECT * FROM platform_channel_gate WHERE id=1'),'Channel setup refuses activation without bot administrator rights');
$botAdmin=true;$evsend($ea,$forward);$token=row($connect,"SELECT token FROM platform_ui WHERE actor=$ea AND kind='channel' AND used=0 ORDER BY expires DESC LIMIT 1")['token'];
invoke($eng,'action',$ea,'channel:save:'.$token);check((int)row($connect,'SELECT enabled FROM platform_channel_gate WHERE id=1')['enabled']===1,'Forwarded channel is verified and enabled only after confirmation');
$press($eu,'language:en');check(str_contains($latestCard($eu)['text'],'Join the channel'),'Channel join is required before age acceptance and onboarding');
$label=array_key_first(array_filter($nav($eu),fn($a)=>is_array($a)&&isset($a['url'])));$evsend($eu,['text'=>$label]);check(str_contains($latestCard($eu)['text'],'https://t.me/FixtureChannel'),'Join-link reply button supplies a clickable channel URL');
$press($eu,'join:check');check(!(int)row($connect,"SELECT accepted FROM platform_accounts WHERE id=$eu")['accepted'],'Recheck does not bypass channel membership');
$membership[$eu]='member';$press($eu,'join:check');$press($eu,'accept');check(row($connect,"SELECT state FROM platform_accounts WHERE id=$eu")['state']==='onboard:name','Joining leads to age acceptance and then name input');
$evsend($eu,['text'=>'Alex']);$press($eu,'genderpick:male:');check(str_contains($latestCard($eu)['text'],'province'),'Basic onboarding continues to mandatory province selection');
$press($eu,'locpick:');check(str_contains($latestCard($eu)['text'],'city'),'Province selection filters the mandatory city choices');
$press($eu,'locpick:');$a=invoke($eng,'account',$eu);check($a['province_id']&&$a['city_id']&&$a['onboarding_done'],'Selecting a city completes onboarding without requiring a test');
$evsend($eu,['text'=>'/wallet']);check(str_contains($latestCard($eu)['text'],'Wallet')&&!invoke($eng,'latestOrientationAttempt',$eu),'Users can open the wallet without ever taking a test');
$evsend($eu,['text'=>'/test']);
$press($eu,'qs:');$attempt=invoke($eng,'activeTendency',$eu);check($attempt&&$attempt['deadline']-$attempt['started']===1800,'Optional test retains the thirty-minute duration');
$bank=TendencyTest::bank('quick');
foreach($bank['questions'] as $q){
 $a=invoke($eng,'activeTendency',$eu);$value=match($q['role']){'dominant'=>3,'submissive'=>2,'switch'=>1,default=>-3};if($q['reverse'])$value=-$value;
 $press($eu,'qa:'.$a['token'].':'.$a['question_index'].':'.$a['revision'].':'.($value+3));
}
invoke($eng,'action',$eu,'welcome:orientation');
check(!invoke($eng,'activeTendency',$eu)&&str_contains($latestCard($eu)['text'],'100%')&&str_contains($latestCard($eu)['text'],'83%')&&str_contains($latestCard($eu)['text'],'67%'),'Test completion offers the three highest-scoring dimensions for confirmation');
$press($eu,'orientation:yes:');$a=invoke($eng,'account',$eu);$approved=TendencyTest::decrypt($a['orientation_cipher']);
check((int)$a['onboarding_done']===1&&$a['orientation_status']==='confirmed'&&count($approved['roles'])===3,'Optional confirmation saves the top three encrypted');
check(array_column($approved['roles'],'id')===['dominant','submissive','switch'],'Top-three selection follows actual scores');
invoke($eng,'action',$eu,'profile');check(str_contains($latestCard($eu)['text'],'My orientation'),'Only the owner’s personal profile displays confirmed orientation');
invoke($eng,'action',$ea,'manage:'.$eu);check(!str_contains($latestCard($ea)['text'],'Dominant'),'Administrative account views do not reveal private test-derived orientation');
$expectDenied(fn()=>invoke($eng,'normal',$ef),'Incomplete accounts cannot enter matching through a direct service path');
// A second completed-test fixture exercises rejection, retaking and leaving the field blank.
$source=invoke($eng,'latestOrientationAttempt',$eu);$vt=bin2hex(random_bytes(8));
invoke($eng,'query',"UPDATE platform_accounts SET province_id=?,city_id=? WHERE id=?",[$a['province_id'],$a['city_id'],$ev]);
invoke($eng,'query',"INSERT INTO platform_tendency_attempts(token,user_id,mode,version,status,started,deadline,completed,payload_cipher) VALUES(?,?,?,?,'completed',?,?,?,?)",[$vt,$ev,$source['mode'],$source['version'],time()-60,time()+1740,time(),$source['payload_cipher']]);
invoke($eng,'action',$ev,'home');invoke($eng,'action',$ev,'welcome:orientation');$press($ev,'orientation:no:');$press($ev,'orientation:retry:');$press($ev,'qs:');
$retry=invoke($eng,'activeTendency',$ev);check($retry!==null&&invoke($eng,'account',$ev)['onboarding_done'],'An optional retake does not undo completed onboarding');
invoke($eng,'endTendency',$ev,$retry['token'],'cancelled',false);invoke($eng,'action',$ev,'welcome:orientation');$press($ev,'orientation:blank:');
$v=invoke($eng,'account',$ev);check($v['onboarding_done']&&$v['orientation_status']==='blank'&&$v['orientation_cipher']===null,'After rejecting results, leaving orientation blank completes welcome');
invoke($eng,'action',$eu,'language:fa');check(str_contains($latestCard($eu)['text'],'ناشناس')&&str_contains(implode(' ',array_keys($nav($eu))),'چت ناشناس'),'Main panel uses the requested anonymous-chat name');
$press($eu,'specialsoon');check(str_contains($latestCard($eu)['text'],'در آینده'),'Special-chat button shows the future-feature notice');
invoke($eng,'action',$eu,'home');$main=$nav($eu);check(in_array('onlineroles',$main,true)&&in_array('features',$main,true)&&!in_array('list:miss',$main,true)&&!in_array('list:mister',$main,true),'Main menu groups roles and settings instead of exposing individual entries');
$press($eu,'onlineroles');check(count($nav($eu))===5&&in_array('list:miss',$nav($eu),true)&&in_array('list:mister',$nav($eu),true)&&in_array('onlinerole:submissive',$nav($eu),true)&&in_array('onlinerole:switch',$nav($eu),true),'Online roles contains four roles and a back button');
$press($eu,'onlinerole:submissive');check(str_contains($latestCard($eu)['text'],'در آینده'),'Submissive placeholder explains it is not yet available');$press($eu,'onlineroles');
$press($eu,'onlinerole:switch');check(str_contains($latestCard($eu)['text'],'در آینده'),'Switch placeholder explains it is not yet available');
invoke($eng,'action',$eu,'features');check(count($nav($eu))===5&&in_array('features:options',$nav($eu),true)&&in_array('features:guide:0',$nav($eu),true)&&in_array('features:support',$nav($eu),true)&&in_array('features:rules',$nav($eu),true),'Features and settings contains the requested four entries plus back');
$press($eu,'features:options');check(in_array('features',$nav($eu),true),'Bot settings returns to its parent menu');$press($eu,'features');
$press($eu,'features:guide:0');check(in_array('features',$nav($eu),true),'Guide returns to the features menu');$press($eu,'features:guide:1');check(in_array('features',$nav($eu),true),'Guide pagination preserves its parent menu');$press($eu,'features');
$press($eu,'features:support');check(str_contains($latestCard($eu)['text'],'@RalOgre')&&in_array('features',$nav($eu),true),'Support retains its contact and returns to the parent menu');$press($eu,'features');
$press($eu,'features:rules');check(str_contains($latestCard($eu)['text'],'رضایت')&&str_contains($latestCard($eu)['text'],'اختیاری')&&in_array('features',$nav($eu),true),'Rules cover consent and the optional test, with a back button');
invoke($eng,'action',$eu,'language:en');
// Leaving invalidates access without banning the account or destroying profile information.
$membership[$eu]='left';$evsend($eu,['text'=>'/wallet']);check(str_contains($latestCard($eu)['text'],'Join the channel')&&invoke($eng,'account',$eu)['role']==='user','Commands recheck membership and revoke access without changing roles');
$membership[$eu]='member';$press($eu,'join:check');check(invoke($eng,'account',$eu)['onboarding_done'],'Rejoining restores access without repeating completed welcome');
$gate=invoke($eng,'channelConfig');
invoke($eng,'query','INSERT INTO platform_memberships(user_id,channel_id,revision,member,checked,next_check) SELECT id,?,?,1,?,? FROM platform_accounts ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),revision=VALUES(revision),member=1,checked=VALUES(checked),next_check=VALUES(next_check)',[$gate['channel_id'],$gate['revision'],time(),time()+86400]);
$connect->query("UPDATE platform_memberships SET next_check=0,checked=".(time()-86401)." WHERE user_id=$eu");
$membership[$eu]='left';invoke($eng,'membershipTick');check(!(int)row($connect,"SELECT member FROM platform_memberships WHERE user_id=$eu")['member'],'Daily sweep rechecks users even when they do not send a command');
$membership[$eu]='error';$evsend($eu,['text'=>'/wallet']);check(str_contains($latestCard($eu)['text'],'temporarily unavailable'),'Telegram membership errors block gated access without pretending the user left');
$membership[$eu]='member';$evsend($eu,['text'=>'/start']);
$expectDenied(fn()=>invoke($eng,'action',$eu,'channel:configure'),'Ordinary users cannot configure required channels');
$expectDenied(fn()=>invoke($eng,'action',$eu,'campaign:new:ad'),'Ordinary users cannot compose advertisements');
// Capture exact Telegram entities and reusable media IDs.
invoke($eng,'action',$ea,'campaign:new:ad');$entities=[(object)['type'=>'bold','offset'=>3,'length'=>4]];
$evsend($ea,['photo'=>[(object)['file_id'=>'SMALL_FILE_ID'],(object)['file_id'=>'LARGE_FILE_ID']],'caption'=>'😀 Bold photo','caption_entities'=>$entities]);
$ad=(int)row($connect,"SELECT MAX(id) n FROM platform_campaigns WHERE creator=$ea")['n'];$payload=json_decode(invoke($eng,'campaign',$ad)['payload'],true);
check($payload['params']['photo']==='LARGE_FILE_ID'&&$payload['params']['caption_entities'][0]['offset']===3,'Composer preserves reusable file ID and Telegram UTF-16 formatting offsets');
invoke($eng,'action',$ea,'campaign:buttons:'.$ad);$evsend($ea,['text'=>"Visit website | https://example.com\nJoin us | https://t.me/FixtureChannel"]);
invoke($eng,'action',$ea,'campaign:preview:'.$ad);$photoCalls=array_values(array_filter($GLOBALS['calls'],fn($call)=>$call[0]==='sendPhoto'));
check(end($photoCalls)[1]['photo']==='LARGE_FILE_ID'&&json_decode(end($photoCalls)[1]['caption_entities'],true)[0]['type']==='bold','Preview reuses the attachment ID and original formatted caption');
invoke($eng,'action',$ea,'campaign:publish:'.$ad);
invoke($eng,'action',$ea,'campaign:new:ad');$evsend($ea,['text'=>'Second advertisement','entities'=>[(object)['type'=>'italic','offset'=>0,'length'=>6]]]);$ad2=(int)row($connect,"SELECT MAX(id) n FROM platform_campaigns WHERE creator=$ea")['n'];invoke($eng,'action',$ea,'campaign:publish:'.$ad2);
invoke($eng,'outreachSetting',$ea,'ads_every_events',1);invoke($eng,'outreachSetting',$ea,'ads_cooldown_seconds',30);
invoke($eng,'query','UPDATE platform_outreach_state SET events=0,last_ad=0,last_campaign=0 WHERE user_id=?',[$eu]);
$offset=count($GLOBALS['calls']);invoke($eng,'maybeAdvertisement',$eu);$delivered=array_slice($GLOBALS['calls'],$offset);
check(count(array_filter($delivered,fn($call)=>$call[0]==='sendPhoto'&&$call[1]['photo']==='LARGE_FILE_ID'))===1,'Eligible interactions deliver an active advertisement by file ID');
$offset=count($GLOBALS['calls']);invoke($eng,'maybeAdvertisement',$eu);check(count($GLOBALS['calls'])===$offset,'Per-user cooldown prevents repeated advertisements');
$connect->query("UPDATE platform_outreach_state SET last_ad=".(time()-31)." WHERE user_id=$eu");invoke($eng,'maybeAdvertisement',$eu);
check(str_contains($latestCard($eu)['text'],'Second advertisement'),'Multiple active advertisements rotate without immediate repetition');
invoke($eng,'action',$eu,'quiz');$press($eu,'qs:');$offset=count($GLOBALS['calls']);invoke($eng,'maybeAdvertisement',$eu);check(count($GLOBALS['calls'])===$offset,'Advertisements do not interrupt an active preference test');
$at=invoke($eng,'activeTendency',$eu);invoke($eng,'endTendency',$eu,$at['token'],'cancelled',false);invoke($eng,'outreachSetting',$ea,'ads_enabled',0);
// Broadcast publication is a one-use, revision-bound review; sending is queued.
invoke($eng,'action',$ea,'campaign:new:broadcast');$evsend($ea,['document'=>(object)['file_id'=>'DOCUMENT_FILE_ID'],'caption'=>'Broadcast text','caption_entities'=>[(object)['type'=>'italic','offset'=>0,'length'=>9]]]);
$bc=(int)row($connect,"SELECT MAX(id) n FROM platform_campaigns WHERE creator=$ea")['n'];invoke($eng,'markReachability',$ev,false);
invoke($eng,'action',$ea,'campaign:publish:'.$bc);check((int)row($connect,"SELECT COUNT(*) n FROM platform_campaign_deliveries WHERE campaign_id=$bc")['n']===0,'Opening a broadcast review sends nothing and queues no recipients');
$press($ea,'campaign:confirm:');$review=row($connect,"SELECT token FROM platform_ui WHERE actor=$ea AND kind='campaignreview' AND used=1 ORDER BY expires DESC LIMIT 1")['token'];
check((int)row($connect,"SELECT COUNT(*) n FROM platform_campaign_deliveries WHERE campaign_id=$bc")['n']>0,'Confirming broadcasts snapshots a recipient queue');
check(!row($connect,"SELECT * FROM platform_campaign_deliveries WHERE campaign_id=$bc AND user_id IN ($ev,$eb)"),'Unreachable and banned accounts are excluded from the audience');
$expectDenied(fn()=>invoke($eng,'action',$ea,'campaign:confirm:'.$review),'Broadcast confirmation cannot queue the same message twice');
$expectDenied(fn()=>invoke($eng,'action',$ea,'campaign:text:'.$bc),'Queued broadcast content cannot change under its recipients');
$first=row($connect,"SELECT * FROM platform_campaign_deliveries WHERE campaign_id=$bc ORDER BY id LIMIT 1");$failedUser=(int)$first['user_id'];$failSend[$failedUser]=['code'=>429,'remaining'=>1];
invoke($eng,'broadcastTick');$j=row($connect,'SELECT * FROM platform_campaign_deliveries WHERE id='.$first['id']);
check($j['status']==='pending'&&$j['retry_at']>=time()+18,'Broadcast rate limits respect Telegram retry_after');
$offset=count($GLOBALS['calls']);invoke($eng,'broadcastTick');check(count($GLOBALS['calls'])===$offset,'Global broadcast backoff prevents immediate retry storms');
$connect->query("UPDATE platform_settings SET value='0' WHERE name='outreach_retry_at'");$connect->query("UPDATE platform_campaign_deliveries SET retry_at=0 WHERE campaign_id=$bc");$failSend[$failedUser]=['code'=>403,'remaining'=>1];
for($i=0;$i<40&&row($connect,"SELECT status FROM platform_campaigns WHERE id=$bc")['status']!=='completed';$i++)invoke($eng,'broadcastTick');
check(row($connect,"SELECT status FROM platform_campaigns WHERE id=$bc")['status']==='completed','Background delivery finishes the broadcast with visible delivery states');
check(row($connect,'SELECT status FROM platform_campaign_deliveries WHERE id='.$first['id'])['status']==='failed'&&!row($connect,"SELECT reachable FROM platform_outreach_state WHERE user_id=$failedUser")['reachable'],'Blocked recipients are marked unreachable for later broadcasts');
$docs=array_values(array_filter(array_slice($GLOBALS['calls'],$startCalls),fn($call)=>$call[0]==='sendDocument'));
check(count($docs)>0&&count(array_filter($docs,fn($call)=>$call[1]['document']!=='DOCUMENT_FILE_ID'))===0,'Broadcast attachments are sent using only their saved file IDs');
check(!in_array('getFile',array_column(array_slice($GLOBALS['calls'],$startCalls),0),true),'Outreach never downloads attachments');
// Required-channel API errors must never prevent a paying user from leaving.
$connect->query("UPDATE platform_accounts SET role='miss',available=1,heartbeat=".time().",onboarding_done=1,state='' WHERE id=$ev");
$membership[$eu]='member';$membership[$ev]='member';invoke($eng,'membershipCheck',$eu,$gate);invoke($eng,'membershipCheck',$ev,$gate);
invoke($eng,'connectPaid',$eu,$ev);check(invoke($eng,'active',$eu)!==null,'Eligible users can still start a paid connection');
$membership[$eu]='error';$evsend($eu,['text'=>BotLocale::text('en','v3.chat.end')]);$press($eu,'confirmclose:');
check(invoke($eng,'active',$eu)===null,'A user can confirm chat exit during a membership API outage');
$membership[$eu]='member';
$allReply=true;foreach(array_slice($GLOBALS['calls'],$startCalls) as [$method,$data])if(isset($data['reply_markup'])&&isset(json_decode($data['reply_markup'],true)['inline_keyboard']))$allReply=false;
check($allReply,'Default menus, test answers and campaign controls all use reply keyboards');
unset($GLOBALS['telegram_stub']);
