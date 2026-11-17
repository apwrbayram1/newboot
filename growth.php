<?php
// Real transactions on temporary tables; every Telegram call remains mocked.
$connect->query("UPDATE platform_settings SET value='1' WHERE name IN ('age_required','reply_navigation','onboarding_required')");
$connect->query("UPDATE platform_settings SET value='0' WHERE name='ads_enabled'");
$gx=new Platform($connect,['SUPERADMIN_IDS'=>'996004','BOT_USERNAME'=>'FixtureBot']);
$ga=996001;$gb=996002;$gg=996003;$gs=996004;$gm=996005;$gc=996006;$go=996007;$gp=996008;$gr=996009;
foreach([$ga,$gb,$gg,$gs,$gm,$gc,$go,$gp,$gr] as $id)invoke($gx,'account',$id);
$place=invoke($gx,'locationData');$city=reset($place['cities']);$province=$city['province_id'];$cityId=$city['id'];
$connect->query("UPDATE platform_accounts SET age=25,age_set_at=".time().",accepted=1,name_set=1,gender='male',language='en',title='Private profile name',province_id=$province,city_id=$cityId,onboarding_done=1 WHERE id IN ($ga,$gb,$gs,$go,$gp)");
$connect->query("UPDATE platform_accounts SET role='miss',available=1,heartbeat=".time()." WHERE id=$gp");
$gxEvent=11000000;$gxMessage=11000000;
$GLOBALS['telegram_stub']=function($method,$data){
 if($method==='getMe')return (object)['ok'=>true,'result'=>(object)['id'=>777000,'username'=>'FixtureBot']];
 if($method==='getChatMember')return (object)['ok'=>true,'result'=>(object)['status'=>($data['user_id']??0)==777000?'administrator':'member']];return null;
};
$gxSend=function(int $id,array $data)use($gx,&$gxEvent,&$gxMessage){$event=(object)['update_id'=>++$gxEvent,'message'=>(object)array_merge(['message_id'=>++$gxMessage,'from'=>(object)['id'=>$id,'first_name'=>'SECRET_FIRST_NAME','username'=>'SECRET_USERNAME'],'chat'=>(object)['id'=>$id,'type'=>'private']],$data)];$gx->handle($event);return $event;};
$gxMap=function(int $id)use($connect){$r=row($connect,"SELECT payload FROM platform_navigation WHERE user_id=$id");return $r?json_decode($r['payload'],true)['map']:[];};
$gxPress=function(int $id,string $wanted)use($gxMap,$gxSend){foreach($gxMap($id) as $label=>$action)if($action===$wanted){$gxSend($id,['text'=>$label]);return $action;}foreach($gxMap($id) as $label=>$action)if(is_string($action)&&str_starts_with($action,$wanted)){$gxSend($id,['text'=>$label]);return $action;}throw new RuntimeException('Missing growth action '.$wanted);};
$gxBalance=fn(int $id)=>(int)invoke($gx,'account',$id)['balance'];
$gxDeny=function(callable $f,string $label){try{$f();throw new LogicException('Expected rejection: '.$label);}catch(RuntimeException $e){check(true,$label);}};
$gxText=function(int $id){foreach(array_reverse($GLOBALS['calls']) as [$method,$data])if(($data['chat_id']??0)===$id&&isset($data['text']))return $data['text'];throw new RuntimeException('No text');};
$gxSend($ga,['text'=>'/start']);$gxSend($ga,['text'=>'/start']);$gxSend($gb,['text'=>'/start']);
check($gxBalance($ga)===10&&$gxBalance($gb)===10,'Repeated starts grant exactly one ten-coin welcome gift');
check((int)row($connect,"SELECT COUNT(*) n FROM platform_ledger WHERE reference='welcome:$ga'")['n']===1,'Welcome credit has a unique ledger reference');
invoke($gx,'action',$ga,'freecoins');invoke($gx,'action',$gb,'freecoins');
$ra=row($connect,"SELECT referral_token FROM platform_growth WHERE user_id=$ga")['referral_token'];$rb=row($connect,"SELECT referral_token FROM platform_growth WHERE user_id=$gb")['referral_token'];
check(strlen($ra)===24&&strlen($rb)===24&&$ra!==$rb,'Completed profiles receive independent opaque referral links');
invoke($gx,'action',$ga,'anonlink');$link=row($connect,"SELECT token FROM platform_anon_links WHERE user_id=$ga")['token'];
$gxSend($gg,['text'=>'/start a_'.$link]);$gxPress($gg,'language:en');
$ages=[];foreach($gxMap($gg) as $action)if(is_string($action)&&preg_match('/^agepick:(\d+):/',$action,$m))$ages[]=(int)$m[1];
check($ages===range(12,50),'Age keyboard includes every age from twelve through fifty');
$gxPress($gg,'agepick:18:');$gxPress($gg,'ageconfirm:');$gxPress($gg,'accept');
$guest=invoke($gx,'account',$gg);check($guest['age']==18&&$guest['accepted']&&!$guest['name_set']&&!$guest['province_id'],'Anonymous sender passes adult eligibility without completing a profile');
$beforeG=$gxBalance($gg);$beforeA=$gxBalance($ga);$gxSend($gg,['text'=>'Contact me at +989123456789']);
check(!row($connect,"SELECT token FROM platform_anon_messages WHERE sender=$gg"),'Anonymous messaging retains phone-number moderation');
$offset=count($GLOBALS['calls']);$sent=$gxSend($gg,['text'=>'A friendly anonymous hello 👋']);
$message=row($connect,"SELECT * FROM platform_anon_messages WHERE sender=$gg ORDER BY created DESC LIMIT 1");
check($message&&$message['status']==='sent'&&$gxBalance($gg)===$beforeG&&$gxBalance($ga)===$beforeA,'Anonymous message delivery costs neither participant coins');
$body=TendencyTest::decrypt($message['payload_cipher']);check($body['params']['text']==='A friendly anonymous hello 👋'&&!str_contains($message['payload_cipher'],'friendly'),'Anonymous message content is preserved and encrypted at rest');
$leaked=false;$delivered=false;foreach(array_slice($GLOBALS['calls'],$offset) as [$method,$data])if(($data['chat_id']??0)===$ga){$raw=json_encode($data);if(str_contains($raw,'SECRET_')||str_contains($raw,'Private profile name'))$leaked=true;if(($data['text']??'')==='A friendly anonymous hello 👋')$delivered=true;}
check($delivered&&!$leaked,'Delivery includes the content without sender name, username or profile');
$gxDeny(fn()=>invoke($gx,'action',$gb,'anon:read:'.$message['token']),'Another user cannot read a private anonymous ticket');
$gxDeny(fn()=>invoke($gx,'action',$gs,'anon:read:'.$message['token']),'Superadmin role does not bypass anonymous-inbox ownership');
$gxPress($ga,'anon:reply:');$gxSend($ga,['text'=>'Thanks for your message 💛']);
check((int)row($connect,"SELECT COUNT(*) n FROM platform_anon_messages WHERE recipient=$gg")['n']===1&&!invoke($gx,'account',$gg)['name_set'],'Anonymous replies reach a sender who still has no completed profile');
invoke($gx,'action',$gg,'anon:inbox:0');check(str_contains($gxText($gg),'inbox'),'Incomplete senders can access replies without entering paid matching');
invoke($gx,'action',$ga,'anon:block:'.$message['token']);invoke($gx,'action',$gg,'anon:again:'.$message['thread_token']);$gxSend($gg,['text'=>'Blocked follow-up']);
check((int)row($connect,"SELECT COUNT(*) n FROM platform_anon_messages WHERE sender=$gg")['n']===1,'Anonymous block prevents both follow-ups and new-link contacts');
invoke($gx,'action',$gg,'anon:cancel');invoke($gx,'action',$ga,'anon:rotate');
$rotation=row($connect,"SELECT token FROM platform_ui WHERE actor=$ga AND kind='anonrotate' AND used=0 ORDER BY expires DESC LIMIT 1")['token'];
$gxDeny(fn()=>invoke($gx,'action',$gb,'anon:rotateyes:'.$rotation),'Rotation confirmation belongs only to the link owner');
invoke($gx,'action',$ga,'anon:rotateyes:'.$rotation);$newLink=row($connect,"SELECT token FROM platform_anon_links WHERE user_id=$ga")['token'];
check($newLink!==$link&&!row($connect,"SELECT * FROM platform_anon_links WHERE token='$link'"),'Rotating a link immediately invalidates its old token');
$gxSend($gg,['text'=>'/start a_'.$link]);check(!invoke($gx,'anonIntent',$gg),'Old anonymous links cannot start a new message');
invoke($gx,'action',$ga,'anon:unblock');$gxPress($ga,'anon:unblockyes:');
$gxSend($gg,['text'=>'/start a_'.$newLink]);invoke($gx,'action',$ga,'anon:rotate');$gxPress($ga,'anon:rotateyes:');
$gxSend($gg,['text'=>'Stale compose attempt']);check((int)row($connect,"SELECT COUNT(*) n FROM platform_anon_messages WHERE sender=$gg")['n']===1,'Rotation also rejects a message composed from an already-open old link');
// Under-18 rejection survives forged older buttons, commands and anonymous deep links.
$gxSend($gm,['text'=>'/start']);$gxPress($gm,'language:en');$agePick=$gxPress($gm,'agepick:17:');$gxPress($gm,'ageconfirm:');
$gxSend($gm,['text'=>'/wallet']);check(invoke($gx,'account',$gm)['age']==17&&!invoke($gx,'account',$gm)['accepted']&&str_contains($gxText($gm),'adults only'),'Confirmed under-eighteen age blocks normal bot access');
$gxDeny(fn()=>invoke($gx,'action',$gm,preg_replace('/^agepick:17:/','agepick:18:',$agePick)),'Old age buttons cannot overwrite a confirmed underage age');
$currentLink=row($connect,"SELECT token FROM platform_anon_links WHERE user_id=$ga")['token'];$gxSend($gm,['text'=>'/start a_'.$currentLink]);$gxSend($gm,['text'=>'Not delivered']);
check(!row($connect,"SELECT * FROM platform_anon_messages WHERE sender=$gm"),'Underage rejection also blocks anonymous messaging');
// Last valid referral before actual profile completion wins.
$gxSend($gc,['text'=>'/start r_'.$ra]);$gxSend($gc,['text'=>'/start r_'.$rb]);$gxSend($gc,['text'=>'/start r_'.str_repeat('0',24)]);
check(row($connect,"SELECT inviter_id FROM platform_growth WHERE user_id=$gc")['inviter_id']==$gb&&$gxBalance($gc)===10,'Last valid pre-completion invite replaces earlier attribution without repeating welcome coins');
$gxPress($gc,'language:en');$gxPress($gc,'agepick:22:');$gxPress($gc,'ageconfirm:');$gxPress($gc,'accept');$gxSend($gc,['text'=>'Sky']);$gxPress($gc,'genderpick:female:');$gxPress($gc,'locpick:');$gxPress($gc,'locpick:');
$credited=row($connect,"SELECT * FROM platform_growth WHERE user_id=$gc");check($credited['referral_paid']==1&&$credited['inviter_id']==$gb&&$gxBalance($gc)===20&&$gxBalance($gb)===20&&$gxBalance($ga)===10,'Completing setup credits ten coins to the final inviter and ten to the invitee');
$gxSend($gc,['text'=>'/start r_'.$ra]);invoke($gx,'settleReferral',$gc);check($gxBalance($gc)===20&&$gxBalance($gb)===20&&$gxBalance($ga)===10,'Completed accounts cannot change attribution or claim another referral reward');
$gxSend($go,['text'=>'/start r_'.$rb]);check($gxBalance($go)===10&&!row($connect,"SELECT inviter_id FROM platform_growth WHERE user_id=$go")['inviter_id'],'Previously completed profiles cannot be newly counted as invitees');
invoke($gx,'action',$gb,'freecoins');check(str_contains($gxText($gb),'Successful invites: <b>1</b>')&&str_contains($gxText($gb),'earned: <b>10</b>'),'Inviters can see successful-invite counts and earned coin totals');
// A failed wallet write must roll back both sides and remain recoverable.
$connect->query("UPDATE platform_accounts SET age=24,age_set_at=".time().",accepted=1,name_set=1,gender='male',language='en' WHERE id=$gr");
$gxSend($gr,['text'=>'/start r_'.$rb]);$connect->query("UPDATE platform_accounts SET province_id=$province,city_id=$cityId WHERE id=$gr");
$connect->query("UPDATE platform_accounts SET balance=9223372036854775807 WHERE id=$gb");
try{invoke($gx,'settleReferral',$gr);throw new LogicException('Expected wallet overflow');}catch(RuntimeException $e){check(true,'Wallet write failures abort referral settlement');}
check(!row($connect,"SELECT referral_paid FROM platform_growth WHERE user_id=$gr")['referral_paid']&&!row($connect,"SELECT * FROM platform_ledger WHERE reference='referral:inviter:$gr'")&&$gxBalance($gr)===10,'A failed referral transaction leaves no partial credit or claimed reward');
$connect->query("UPDATE platform_accounts SET balance=20 WHERE id=$gb");invoke($gx,'settleReferral',$gr);check($gxBalance($gr)===20&&$gxBalance($gb)===30,'Referral settlement can safely retry after the underlying failure is fixed');
// Busy recipients keep their current keyboard; content waits in their inbox.
invoke($gx,'membershipCheck',$ga,invoke($gx,'channelConfig'));invoke($gx,'membershipCheck',$gp,invoke($gx,'channelConfig'));invoke($gx,'connectPaid',$ga,$gp);
$connect->query("UPDATE platform_anon_messages SET created=".(time()-10)." WHERE sender=$gg");$gxSend($gg,['text'=>'/start a_'.$currentLink]);$gxSend($gg,['text'=>'A message for later']);
$pending=row($connect,"SELECT * FROM platform_anon_messages WHERE sender=$gg AND status='pending'");check($pending&&invoke($gx,'active',$ga)!==null,'Anonymous content waits while the recipient is in a paid conversation');
invoke($gx,'finish',invoke($gx,'active',$ga),'user_left');invoke($gx,'action',$ga,'anon:read:'.$pending['token']);check(row($connect,"SELECT status FROM platform_anon_messages WHERE token='{$pending['token']}'")['status']==='sent','Queued anonymous content is delivered when its owner opens it later');
check(!in_array('getFile',array_column($GLOBALS['calls'],0),true),'Anonymous messages never download media to resend it');
// Broadcast audiences also respect the adult-only gate.
invoke($gx,'action',$gs,'campaign:new:broadcast');$gxSend($gs,['text'=>'A test announcement']);$ageCampaign=(int)row($connect,"SELECT MAX(id) n FROM platform_campaigns WHERE creator=$gs")['n'];
invoke($gx,'action',$gs,'campaign:publish:'.$ageCampaign);$gxPress($gs,'campaign:confirm:');
check(!row($connect,"SELECT * FROM platform_campaign_deliveries WHERE campaign_id=$ageCampaign AND user_id=$gm"),'Confirmed minors are excluded from broadcast audiences');
$connect->query("UPDATE platform_campaigns SET status='cancelled' WHERE id=$ageCampaign");
unset($GLOBALS['telegram_stub']);
