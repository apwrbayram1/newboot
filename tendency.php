<?php
// Uses the existing connection-local temporary tables and mocked Telegram API.
$quiz=new Platform($connect,['SUPERADMIN_IDS'=>'994004']);$qu=994001;$qv=994002;$qm=994003;$qo=994004;
foreach([$qu,$qv,$qm,$qo] as $id)invoke($quiz,'account',$id);
$connect->query("UPDATE platform_accounts SET name_set=1,gender='male',accepted=1,language='en',title='Quiz fixture',balance=100 WHERE id BETWEEN 994001 AND 994004");
$connect->query("UPDATE platform_accounts SET role='miss',available=1,heartbeat=".time()." WHERE id=$qm");
$screen=function(int $id){foreach(array_reverse($GLOBALS['calls']) as [$method,$data])if(($data['chat_id']??0)===$id&&isset($data['text']))return $data;throw new RuntimeException('Missing quiz screen');};
$denied=function(callable $f,string $label){try{$f();throw new LogicException('Expected refusal: '.$label);}catch(RuntimeException $e){check(true,$label);}};
$start=function(int $id,string $mode='quick')use($quiz,$screen){
 invoke($quiz,'action',$id,'quiz');$rows=json_decode($screen($id)['reply_markup'],true)['inline_keyboard'];
 foreach($rows as $row)foreach($row as $b)if(str_starts_with($b['callback_data']??'','p:qs:')){
  $token=substr($b['callback_data'],5);$payload=json_decode(invoke($quiz,'one','SELECT payload FROM platform_ui WHERE token=?',[$token])['payload'],true);
  if($payload['mode']===$mode){invoke($quiz,'action',$id,'qs:'.$token);return invoke($quiz,'activeTendency',$id);}
 }throw new RuntimeException('No quiz start button');
};
$rating=function(int $id,?int $rating)use($quiz){$a=invoke($quiz,'activeTendency',$id);invoke($quiz,'action',$id,'qa:'.$a['token'].':'.$a['question_index'].':'.$a['revision'].':'.($rating===null?'s':$rating+3));};
$quick=TendencyTest::bank('quick');$full=TendencyTest::bank('full');
check(count($quick['questions'])===36&&count($quick['roles'])===12,'Original quick mode has 36 items across 12 areas');
check(count($full['questions'])===54&&count($full['roles'])===18,'Original full mode has 54 items across 18 areas');
foreach($full['questions'] as $item)if(!$item['text_en']||!$item['text_fa'])throw new RuntimeException('Missing bilingual item');check(true,'Every original question has English and Persian text');
$affirm=[];$opposite=[];foreach($quick['questions'] as $i=>$q){$affirm[$i]=$q['reverse']?-3:3;$opposite[$i]=-$affirm[$i];}
check(count(array_filter(TendencyTest::score($quick,$affirm)['scores'],fn($r)=>$r['score']===100))===12,'Coherent endorsements score 100 including correctly reversed items');
check(count(array_filter(TendencyTest::score($quick,$opposite)['scores'],fn($r)=>$r['score']===0))===12,'Opposite coherent answers score zero');
check(count(array_filter(TendencyTest::score($quick,array_fill(0,36,0))['scores'],fn($r)=>$r['score']===50))===12,'Neutral answers consistently map to the midpoint');
check(count(array_filter(TendencyTest::score($quick,array_fill(0,36,null))['scores'],fn($r)=>$r['score']===null))===12,'Skipped answers remain insufficient instead of turning into zero scores');
$single=array_fill(0,36,null);$single[0]=3;check(TendencyTest::score($quick,$single)['scores']['dominant']['score']===null,'One answered item cannot create a confident-looking score');
invoke($quiz,'action',$qu,'home');check(str_contains($screen($qu)['reply_markup'],'p:quiz'),'Normal menu links to the built-in test');
invoke($quiz,'action',$qu,'profile');check(str_contains($screen($qu)['reply_markup'],'p:quiz'),'Personal profile links to owner-only results');
$a=$start($qu);$b=$start($qv,'full');
check($a&&$b&&$a['token']!==$b['token'],'Different users can take independent tests simultaneously with no queue');
check((int)$a['deadline']-(int)$a['started']===1800,'Each test snapshots its own thirty-minute deadline');
check(row($connect,"SELECT balance FROM platform_accounts WHERE id=$qu")['balance']==100,'The built-in test does not charge coins');
check(isset($screen($qu)['protect_content'])&&$screen($qu)['protect_content']==='true','The first private question uses protected-content delivery');
$offset=count($GLOBALS['calls']);$rating($qu,3);$calls=array_slice($GLOBALS['calls'],$offset);
check(in_array('editMessageText',array_column($calls,0),true)&&!in_array('sendMessage',array_column($calls,0),true),'Answering edits the protected question card without message clutter');
$updated=invoke($quiz,'activeTendency',$qu);check((int)$updated['question_index']===1&&$updated['deadline']===$a['deadline'],'Answering advances one item without extending the timer');
$ratingRoute='qa:'.$a['token'].':0:0:6';invoke($quiz,'action',$qu,$ratingRoute);
check((int)invoke($quiz,'activeTendency',$qu)['question_index']===1,'Duplicate or stale answer callbacks cannot skip a question');
$denied(fn()=>invoke($quiz,'action',$qv,$ratingRoute),'Another tester cannot answer someone else’s question');
$denied(fn()=>invoke($quiz,'action',$qo,$ratingRoute),'Superadmins have no bypass into another person’s answers');
invoke($quiz,'action',$qu,'qb:'.$updated['token'].':'.$updated['revision']);$back=invoke($quiz,'activeTendency',$qu);
check((int)$back['question_index']===0&&$back['deadline']===$a['deadline'],'Back navigation preserves the original deadline');
$rating($qu,-3);$payload=TendencyTest::decrypt(invoke($quiz,'activeTendency',$qu)['payload_cipher']);check($payload['answers'][0]===-3,'Going back permits replacing an answer');
invoke($quiz,'action',$qu,'language:fa');invoke($quiz,'action',$qu,'quizresume');$afterLang=invoke($quiz,'activeTendency',$qu);
check(str_contains($screen($qu)['text'],'پرسش')&&$afterLang['deadline']===$a['deadline']&&(int)$afterLang['question_index']===1,'Switching language preserves answers, progress and remaining time');
$offset=count($GLOBALS['calls']);$quiz->handle((object)['update_id'=>996001,'message'=>(object)['message_id'=>996001,'from'=>(object)['id'=>$qu],'chat'=>(object)['id'=>$qu,'type'=>'private'],'text'=>'This is not an answer button']]);
check(!in_array('copyMessage',array_column(array_slice($GLOBALS['calls'],$offset),0),true),'Typed text during a test is never relayed as an anonymous chat message');
$denied(fn()=>invoke($quiz,'normal',$qu),'Testing users cannot simultaneously enter normal matching');
$denied(fn()=>invoke($quiz,'requestBooking',$qu,$qm),'Testing users cannot open a paid booking');
$providerAttempt=$start($qm);check((int)row($connect,"SELECT available FROM platform_accounts WHERE id=$qm")['available']===0,'Starting a provider’s private test takes them offline');
$denied(fn()=>invoke($quiz,'action',$qm,'online'),'A provider cannot go online during a test');
$denied(fn()=>invoke($quiz,'connectPaid',$qo,$qm),'A provider in a test cannot receive a paid connection');
invoke($quiz,'endTendency',$qm,$providerAttempt['token'],'cancelled',false);
check(invoke($quiz,'activeTendency',$qm)===null&&invoke($quiz,'tendencyOwned',$qm,$providerAttempt['token'])['payload_cipher']===null,'Ending an incomplete attempt discards encrypted answers and frees that user immediately');
// Finish the 54-item English flow; semantic answers match reverse-coded items.
while($active=invoke($quiz,'activeTendency',$qv)){$q=$full['questions'][(int)$active['question_index']];$rating($qv,$q['reverse']?-3:3);}
$saved=invoke($quiz,'tendencyOwned',$qv,$b['token'],'completed');$plain=TendencyTest::decrypt($saved['payload_cipher']);
check($plain['result']['answered']===54&&count($plain['result']['scores'])===18,'Completing the full test saves all 18 dimension scores');
check(!str_contains($saved['payload_cipher'],'questions')&&!str_contains($saved['payload_cipher'],'dominant'),'Question snapshots, raw answers and result scores are encrypted at rest');
check(str_contains($screen($qv)['text'],'private result'),'Completion opens the tester’s private result');
$denied(fn()=>invoke($quiz,'tendencyResult',$qu,$b['token']),'A different user cannot read a saved result even with its token');
$denied(fn()=>invoke($quiz,'tendencyResult',$qo,$b['token']),'A superadmin cannot read another person’s saved result');
$denied(fn()=>invoke($quiz,'tendencyDetail',$qo,$b['token'],'dominant'),'Role explanations cannot be used to bypass result ownership');
invoke($quiz,'tendencyDetail',$qv,$b['token'],'dominant');check(str_contains($screen($qv)['text'],'100%'),'Owners can open detailed score explanations');
invoke($quiz,'tendencyResult',$qv,$b['token'],99999);check(str_contains($screen($qv)['text'],'Page 3 of 3'),'Large forged result pages are clamped');
invoke($quiz,'profilePreview',$qo,$qv);check(!str_contains($screen($qo)['reply_markup'],'qr:')&&!str_contains($screen($qo)['text'],'private result'),'Public profile previews never expose quiz results or result links');
$exchange=invoke($quiz,'exchangeInfo',$qv,invoke($quiz,'account',$qv));check(array_keys($exchange)===['name','identity','province_id','city_id'],'Paid identity exchanges do not contain test results');
$again=$start($qv);check($again&&$again['token']!==$b['token'],'A completed tester can immediately retake on the same day without cooldown');
$connect->query("UPDATE platform_tendency_attempts SET deadline=".(time()-1)." WHERE token='{$again['token']}'");invoke($quiz,'tendencyTick');
check(invoke($quiz,'tendencyOwned',$qv,$again['token'])['status']==='expired'&&invoke($quiz,'activeTendency',$qv)===null,'Timeout expires only that attempt and frees the user');
check(invoke($quiz,'tendencyOwned',$qv,$b['token'])['status']==='completed','A timed-out attempt preserves previously completed results');
$again2=$start($qv);check($again2!==null,'An expired tester can restart immediately');
invoke($quiz,'endTendency',$qv,$again2['token'],'cancelled',false);
while($active=invoke($quiz,'activeTendency',$qu))$rating($qu,0);
$quSaved=invoke($quiz,'tendencyOwned',$qu,$a['token'],'completed');invoke($quiz,'tendencyResult',$qu,$a['token']);
check(str_contains($screen($qu)['text'],'نتیجهٔ خصوصی')&&str_contains($screen($qu)['text'],'50%'),'Persian completion and saved result screens are fully localized');
invoke($quiz,'tendencyHistory',$qu,999);check(str_contains($screen($qu)['reply_markup'],$a['token']),'Private history contains only the requesting user’s result links');
$denied(fn()=>invoke($quiz,'action',$qu,'qx:'.$b['token']),'Result deletion cannot target another tester');
invoke($quiz,'action',$qv,'qx:'.$b['token']);$buttons=array_merge(...json_decode($screen($qv)['reply_markup'],true)['inline_keyboard']);$delete=substr($buttons[0]['callback_data'],2);
$denied(fn()=>invoke($quiz,'action',$qo,$delete),'Deletion confirmations are bound to the result owner');
invoke($quiz,'action',$qv,$delete);check(!row($connect,"SELECT token FROM platform_tendency_attempts WHERE token='{$b['token']}'"),'Confirmed deletion removes only that owner’s selected completed result');
check(invoke($quiz,'tendencyOwned',$qu,$a['token'])['status']==='completed','Deleting one result cannot affect a different tester’s data');
invoke($quiz,'action',$qo,'category:quiz');check(str_contains($screen($qo)['reply_markup'],'setting:tendency_seconds'),'Superadmin test settings expose duration and bilingual titles');
$denied(fn()=>invoke($quiz,'action',$qu,'category:quiz'),'Normal users cannot change test settings');
$switched=SessionRules::defaults();$switched['tendency_enabled']=0;SessionRules::validate($switched);check(true,'Superadmins can disable new tests without invalid policy errors');
foreach($GLOBALS['calls'] as [$method,$data])if(isset($data['reply_markup']))foreach(json_decode($data['reply_markup'],true)['inline_keyboard']??[] as $row)foreach($row as $button)if(strlen($button['callback_data']??'')>64)throw new RuntimeException('Oversized quiz callback');check(true,'Every quiz callback remains within Telegram’s 64-byte limit');
// Settings honor existing snapshots while controlling only new starts.
invoke($quiz,'action',$qo,'sv:tendency_enabled:0');$buttons=array_merge(...json_decode($screen($qo)['reply_markup'],true)['inline_keyboard']);$confirmation=substr($buttons[0]['callback_data'],2);invoke($quiz,'action',$qo,$confirmation);
invoke($quiz,'action',$qv,'quiz');check(!str_contains($screen($qv)['reply_markup'],'p:qs:')&&str_contains($screen($qv)['text'],'paused'),'Disabling new tests removes start controls and shows the paused state');
invoke($quiz,'tendencyResult',$qu,$a['token']);check(str_contains($screen($qu)['text'],'50%'),'Disabling new tests keeps saved results readable');
invoke($quiz,'action',$qo,'sv:tendency_enabled:1');$buttons=array_merge(...json_decode($screen($qo)['reply_markup'],true)['inline_keyboard']);invoke($quiz,'action',$qo,substr($buttons[0]['callback_data'],2));
$durable=$start($qv);$deadline=$durable['deadline'];invoke($quiz,'action',$qo,'sv:tendency_seconds:900');$buttons=array_merge(...json_decode($screen($qo)['reply_markup'],true)['inline_keyboard']);invoke($quiz,'action',$qo,substr($buttons[0]['callback_data'],2));
check(invoke($quiz,'activeTendency',$qv)['deadline']===$deadline,'Changing the configured duration never extends an active attempt');
invoke($quiz,'endTendency',$qv,$durable['token'],'cancelled',false);$longer=$start($qv);
check((int)$longer['deadline']-(int)$longer['started']===900,'A new reviewed attempt uses the newly configured duration');
invoke($quiz,'endTendency',$qv,$longer['token'],'cancelled',false);
// Ban cancels private unfinished answers without making them visible to moderation.
$bannedAttempt=$start($qm);$connect->query("UPDATE platform_accounts SET role='banned' WHERE id=$qm");$offset=count($GLOBALS['calls']);invoke($quiz,'tendencyTick');
check(invoke($quiz,'tendencyOwned',$qm,$bannedAttempt['token'])['payload_cipher']===null&&!array_filter(array_slice($GLOBALS['calls'],$offset),fn($x)=>str_contains($x[1]['text']??'','Quiz fixture')),'Banning discards unfinished answers without leaking quiz content into staff messages');
