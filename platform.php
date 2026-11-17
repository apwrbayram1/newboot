<?php
// Real InnoDB integration with mocked Telegram and connection-local temporary tables.
require dirname(__DIR__).'/config.php';
require dirname(__DIR__).'/lib/Platform.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
// Temporary tables shadow production tables only on this database connection.
foreach(['platform_accounts','platform_settings','platform_sessions','platform_participants','platform_queue','platform_ledger','platform_orders','platform_audit','platform_messages','platform_moderation','platform_catalog','platform_choices','platform_ui','platform_processed','platform_mm_profiles','platform_requests','platform_tendency_attempts','platform_tendency_active','platform_navigation','platform_channel_gate','platform_memberships','platform_campaigns','platform_campaign_deliveries','platform_outreach_state','platform_growth','platform_anon_links','platform_anon_threads','platform_anon_intents','platform_anon_messages','platform_anon_blocks','user','chat','chatvip'] as $table) {
    $definition=$connect->query('SHOW CREATE TABLE '.$table)->fetch_row()[1];
    $connect->query(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
}
// Exercise the retained inline compatibility mode in the existing regression suite.
// The new engagement suite below separately exercises the default reply-keyboard flow.
$connect->query("INSERT INTO platform_settings(name,value) VALUES('reply_navigation','0'),('onboarding_required','0'),('age_required','0')");
require dirname(__DIR__).'/lib/Catalog.php';
ProfileCatalog::seed($connect);
$GLOBALS['calls']=[];
function jijibot($method,$data=[]) {$GLOBALS['calls'][]=[$method,$data];if(isset($GLOBALS['telegram_stub'])){ $stub=($GLOBALS['telegram_stub'])($method,$data);if($stub!==null)return $stub;}if(in_array($method,$GLOBALS['fail_methods']??[],true))return (object)['ok'=>false];return (object)['ok'=>true,'result'=>(object)['message_id'=>count($GLOBALS['calls'])+100000]];}
function check($condition,$message) {if(!$condition) throw new RuntimeException($message);echo "PASS $message\n";}
function invoke($p,$method,...$args) {$r=new ReflectionMethod($p,$method);return $r->invoke($p,...$args);}
function row($db,$sql) {return $db->query($sql)->fetch_assoc();}
$ids=[-810001,-810002,-810003,-810004];
$connect->query("SELECT GET_LOCK('nashenas_platform_tests',10)");
$cleanup=function()use($connect,$ids){
    $list=implode(',',$ids);
    $connect->query("DELETE FROM platform_participants WHERE user_id IN ($list)");
    $connect->query("DELETE FROM platform_queue WHERE user_id IN ($list)");
    $connect->query("DELETE FROM platform_sessions WHERE customer IN ($list) OR provider IN ($list)");
    $connect->query("DELETE FROM platform_ledger WHERE user_id IN ($list)");
    $connect->query("DELETE FROM platform_orders WHERE user_id IN ($list)");
    $connect->query("DELETE FROM platform_accounts WHERE id IN ($list)");
    $connect->query("DELETE FROM platform_audit WHERE actor IN ($list)");
};
try {
    $cleanup();$r=SessionRules::defaults();SessionRules::validate($r);
    check(SessionRules::closeRefund($r,299,'customer')===0,'Customer exit has no refund');
    check(SessionRules::closeRefund($r,300,'provider')===3,'Provider exit at five minutes refunds three');
    check(SessionRules::closeRefund($r,599,'provider')===3,'Refund remains three before ten minutes');
    check(SessionRules::closeRefund($r,600,'provider')===0,'No refund at ten minutes');
    check(SessionRules::unusedRefund($r,1000,1600,5,1120)===4,'Nonresponse unused-time refund rounds upward');
    $bad=$r;$bad['early_refund_coins']=6;
    try {SessionRules::validate($bad);throw new RuntimeException('Invalid refund accepted');}catch(InvalidArgumentException $e){check(true,'Invalid policy rejected');}
    $p=new Platform($connect,['SUPERADMIN_IDS'=>'-810004']);
    foreach($ids as $id)invoke($p,'account',$id);
    $connect->query("UPDATE platform_accounts SET name_set=1,gender='male',language='en'");
    $connect->query("UPDATE platform_accounts SET accepted=1,balance=100 WHERE id IN (-810001,-810003)");
    $connect->query("UPDATE platform_accounts SET role='miss',title='Test Miss',accepted=1,available=1,heartbeat=".time()." WHERE id=-810002");
    invoke($p,'connectPaid',-810001,-810002);
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==95,'Initial debit exactly five coins');
    $s=invoke($p,'active',-810001);$sid=$s['id'];
    check(count($connect->query("SELECT * FROM platform_participants WHERE session_id=$sid")->fetch_all())===2,'Session claims both participants');
    try{invoke($p,'action',-810001,'confirmclose:0');throw new LogicException('Stale close accepted');}catch(RuntimeException $e){check(invoke($p,'active',-810001)!==null,'Old exit confirmation cannot close a new session');}
    try{invoke($p,'connectPaid',-810003,-810002);throw new LogicException('Double booking accepted');}catch(RuntimeException $e){check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810003')['balance']==100,'Double booking does not charge');}
    try{invoke($p,'action',-810002,'confirmclose:'.$sid);throw new LogicException('Early close accepted');}catch(RuntimeException $e){check(invoke($p,'active',-810001)!==null,'Provider cannot leave before five minutes');}
    $connect->query("UPDATE platform_sessions SET started=".(time()-301)." WHERE id=$sid");
    invoke($p,'action',-810002,'confirmclose:'.$sid);
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==98,'Early provider exit returns three coins');
    invoke($p,'finish',$s,'provider_left',3);
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==98,'Repeated close cannot duplicate refund');
    $connect->query('UPDATE platform_accounts SET available=1 WHERE id=-810002');
    invoke($p,'connectPaid',-810001,-810002);$s=invoke($p,'active',-810001);$sid=$s['id'];
    $connect->query("UPDATE platform_sessions SET started=".(time()-600).",paid_until=".time()." WHERE id=$sid");
    $p->tick();
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==92,'First extension debits one coin');
    $p->tick();check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==92,'Timer does not bill twice for one interval');
    $connect->query("UPDATE platform_accounts SET balance=0 WHERE id=-810001");
    $connect->query("UPDATE platform_sessions SET paid_until=".time()." WHERE id=$sid");$p->tick();
    check(invoke($p,'active',-810001)===null,'Insufficient funds closes session');
    $connect->query('UPDATE platform_accounts SET balance=100 WHERE id=-810001');
    $connect->query('UPDATE platform_accounts SET available=1 WHERE id=-810002');
    invoke($p,'connectPaid',-810001,-810002);$s=invoke($p,'active',-810001);$sid=$s['id'];
    $connect->query("UPDATE platform_sessions SET started=".(time()-130).",paid_until=".(time()+470).",pending_since=".(time()-125).",unanswered=3 WHERE id=$sid");$p->tick();
    check(invoke($p,'active',-810001)===null,'Unanswered messages end session');
    check(row($connect,'SELECT strikes FROM platform_accounts WHERE id=-810002')['strikes']==1,'Nonresponsive provider gets a strike');
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==99,'Unused time refunded for nonresponse');
    try{invoke($p,'normal',-810002);throw new LogicException('Provider queued');}catch(RuntimeException $e){check(true,'Provider excluded from normal matching');}
    invoke($p,'normal',-810001);invoke($p,'normal',-810003);
    check(invoke($p,'active',-810001)['kind']==='normal','Normal users match in a paid fixed-duration session');
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==97,'Normal matching debits exactly two coins per person');
    invoke($p,'finish',invoke($p,'active',-810001),'user_left');
    invoke($p,'invoice',-810001,true);
    $o=row($connect,'SELECT * FROM platform_orders WHERE user_id=-810001 ORDER BY created DESC LIMIT 1');
    check($o['stars']==5 && $o['coins']==100,'Test offer is 100 coins for five Stars');
    $checkout=(object)['pre_checkout_query'=>(object)['id'=>'test','from'=>(object)['id'=>-810001],'invoice_payload'=>$o['payload'],'currency'=>'XTR','total_amount'=>5]];
    $p->handle($checkout);check(end($GLOBALS['calls'])[1]['ok']==='true','Valid Stars checkout accepted');
    $checkout->pre_checkout_query->total_amount=6;$p->handle($checkout);check(end($GLOBALS['calls'])[1]['ok']==='false','Tampered checkout rejected');
    $payment=(object)['message'=>(object)['from'=>(object)['id'=>-810001],'successful_payment'=>(object)['invoice_payload'=>$o['payload'],'currency'=>'XTR','total_amount'=>5,'telegram_payment_charge_id'=>'integration-test-charge']]];
    $p->handle($payment);$p->handle($payment);
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==197,'Confirmed Stars credited once, duplicate ignored');
    try{invoke($p,'panel',-810001,true);throw new LogicException('Unauthorized superadmin');}catch(RuntimeException $e){check(true,'Normal user denied superadmin');}
    try{invoke($p,'action',-810001,'connect:-810002:oldterms');throw new LogicException('Outdated price accepted');}catch(RuntimeException $e){check(true,'Changed prices require renewed customer confirmation');}
    invoke($p,'panel',-810004,true);check(true,'Configured superadmin can open settings');
    check(!str_contains(end($GLOBALS['calls'])[1]['text'],'\\n'),'Chat panel renders real line breaks');
    $connect->query("UPDATE platform_accounts SET accepted=1,role='admin' WHERE id=-810003");
    invoke($p,'panel',-810003,false);check(true,'Admin can inspect wallets');
    try{invoke($p,'panel',-810003,true);throw new LogicException('Admin reached superadmin');}catch(RuntimeException $e){check(true,'Admin cannot edit superadmin settings');}
    $connect->query('UPDATE platform_accounts SET available=1,strikes=0 WHERE id=-810002');
    invoke($p,'connectPaid',-810001,-810002);$s=invoke($p,'active',-810001);$sid=$s['id'];
    $connect->query("UPDATE platform_sessions SET started=".(time()-310).",pending_since=".(time()-301).",unanswered=1 WHERE id=$sid");$p->tick();
    check(invoke($p,'active',-810001)===null,'Single unanswered message has a hard silence timeout');
    $connect->query('UPDATE platform_accounts SET available=1,strikes=0 WHERE id=-810002');
    invoke($p,'connectPaid',-810001,-810002);$s=invoke($p,'active',-810001);$sid=$s['id'];
    $before=row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance'];
    $connect->query("UPDATE platform_sessions SET paid_until=".(time()-60)." WHERE id=$sid");$p->tick();
    check(invoke($p,'active',-810001)===null && row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']==$before,'Downtime never causes catch-up charges');
    try{invoke($p,'action',-810002,'pref:invalid');throw new LogicException('Stale preference accepted');}catch(RuntimeException $e){check(true,'Retired preference buttons are rejected');}
    $cb=(object)['callback_query'=>(object)['id'=>'mock','from'=>(object)['id'=>-810001],'data'=>'p:wallet','message'=>(object)['message_id'=>1,'chat'=>(object)['id'=>-810001,'type'=>'private']]]];
    $p->handle($cb);check(end($GLOBALS['calls'])[0]==='editMessageText','Panel navigation edits the existing message');
    $connect->query("UPDATE platform_accounts SET role='banned' WHERE id=-810001");
    $p->handle($cb);check(str_contains(end($GLOBALS['calls'])[1]['text'],'account is suspended'),'Suspended accounts cannot access purchases or matching');

    // Language onboarding and panel navigation exercise actual update routing.
    $p=new Platform($connect,['SUPERADMIN_IDS'=>'-810004']);
    $sendUpdate=function(int $id,string $text)use($p){
        return $p->handle((object)['message'=>(object)['message_id'=>10,'from'=>(object)['id'=>$id,'first_name'=>'Test','language_code'=>'fa'],'chat'=>(object)['id'=>$id,'type'=>'private'],'text'=>$text]]);
    };
    $click=function(int $id,string $action)use($p){
        return $p->handle((object)['callback_query'=>(object)['id'=>'mock','from'=>(object)['id'=>$id],'data'=>'p:'.$action,'message'=>(object)['message_id'=>10,'chat'=>(object)['id'=>$id,'type'=>'private']]]]);
    };
    $lastText=fn()=>end($GLOBALS['calls'])[1]['text']??'';
    $lastButtons=fn()=>array_merge(...json_decode(end($GLOBALS['calls'])[1]['reply_markup'],true)['inline_keyboard']);
    $sendUpdate(-810005,'/start');
    check(str_contains($lastText(),'Language / زبان') && !str_contains($lastText(),'18+'),'First screen selects language before age acceptance');
    $click(-810005,'accept');
    check(row($connect,'SELECT accepted FROM platform_accounts WHERE id=-810005')['accepted']==0 && str_contains($lastText(),'Language / زبان'),'Old acceptance button cannot bypass language selection');
    $click(-810005,'language:fa');
    check(str_contains($lastText(),'۱۸ سال') && !str_contains($lastText(),'Welcome'),'Persian language selection shows Persian age agreement');
    check(row($connect,'SELECT language FROM platform_accounts WHERE id=-810005')['language']==='fa','Language choice persists in the database');
    $click(-810005,'wallet');
    check(row($connect,'SELECT accepted FROM platform_accounts WHERE id=-810005')['accepted']==0 && str_contains($lastText(),'۱۸ سال'),'Paid functions remain gated until acceptance');
    $click(-810005,'accept');
    check(row($connect,'SELECT state FROM platform_accounts WHERE id=-810005')['state']==='onboard:name' && row($connect,'SELECT accepted FROM platform_accounts WHERE id=-810005')['accepted']==1,'Age acceptance opens name onboarding');
    $sendUpdate(-810005,'Navid');
    $token=row($connect,"SELECT token FROM platform_ui WHERE actor=-810005 AND kind='gender' AND used=0 ORDER BY expires DESC LIMIT 1")['token'];$click(-810005,'genderpick:male:'.$token);
    check(!array_intersect(array_column($lastButtons(),'callback_data'),['p:admin','p:super','p:studio']),'Normal panel contains no admin, superadmin or provider entry');
    $sendUpdate(-810005,'/options');$click(-810005,'language');$click(-810005,'language:en');
    check(str_contains($lastText(),'Nashenas') && row($connect,'SELECT language FROM platform_accounts WHERE id=-810005')['language']==='en','Language can be changed later through options');
    $catalog=BotLocale::catalog('en');$fa=BotLocale::catalog('fa');
    check(array_keys($catalog)===array_keys($fa),'English and Persian catalogs cover exactly the same messages');
    foreach($catalog as $key=>$value) {
        preg_match_all('/\{(\w+)\}/',$value,$enSlots);preg_match_all('/\{(\w+)\}/',$fa[$key],$faSlots);
        $enSlots=$enSlots[1];$faSlots=$faSlots[1];sort($enSlots);sort($faSlots);
        if($enSlots!==$faSlots)throw new RuntimeException('Translation placeholder mismatch: '.$key);
    }
    check(true,'Translations preserve every dynamic value placeholder');
    foreach(SessionRules::defaults() as $key=>$value)check(isset($catalog['setting.'.$key],$fa['setting.'.$key]),'Setting has bilingual labels: '.$key);
    $click(-810005,'language:fa');$sendUpdate(-810005,'/wallet');
    check(str_contains($lastText(),'بسته') && str_contains($lastButtons()[0]['text'],'استارز'),'Wallet and package buttons are translated');
    invoke($p,'invoice',-810005,true);
    $invoice=end($GLOBALS['calls']);
    check($invoice[0]==='sendInvoice' && $invoice[1]['title']==='بستهٔ آزمایشی · 100 سکه' && str_contains($invoice[1]['description'],'قوانین'),'Stars invoices use the selected language');
    $p->showError(-810005,'Access denied');check(str_contains($lastText(),'دسترسی ندارید'),'Permission errors are translated');
    $p->showError(-810005,'Enter 1–80 characters');check(str_contains($lastText(),'نویسه') && str_contains($lastText(),'80'),'Dynamic validation errors are translated');
    $sendUpdate(-810002,'/start');
    check(row($connect,'SELECT panel FROM platform_accounts WHERE id=-810002')['panel']==='normal' && !in_array('p:studio',array_column($lastButtons(),'callback_data'),true),'/start always opens the normal panel even for providers');
    $sendUpdate(-810002,'/mm');
    check(str_contains($lastText(),'Miss & Mister panel') && row($connect,'SELECT panel FROM platform_accounts WHERE id=-810002')['panel']==='mm','/mm opens the separate provider panel');
    check(!array_intersect(array_column($lastButtons(),'callback_data'),['p:normal','p:list:miss','p:admin','p:super']),'Provider panel does not contain normal matching or admin controls');
    $click(-810002,'profile');$click(-810002,'panelhome');
    check(str_contains($lastText(),'Miss & Mister panel'),'Provider profile returns to the provider panel');
    $click(-810002,'language:fa');
    check(str_contains($lastText(),'پنل میس و مستر') && str_contains(implode(' ',array_column($lastButtons(),'text')),'علایق'),'Provider panel and interest labels are translated');
    $sendUpdate(-810003,'/admin');
    check(str_contains($lastText(),'Admin panel') && !array_intersect(array_column($lastButtons(),'callback_data'),['p:normal','p:super','p:studio']),'/admin opens a separate admin panel');
    $click(-810003,'prompt:lookup');$click(-810003,'cancel');
    check(str_contains($lastText(),'Admin panel'),'Canceling an admin action returns to the admin panel');
    $connect->query('UPDATE platform_accounts SET accepted=1 WHERE id=-810004');
    $sendUpdate(-810004,'/superadmin');
    check(str_contains($lastText(),'Superadmin panel') && !array_intersect(array_column($lastButtons(),'callback_data'),['p:normal','p:admin','p:studio']),'/superadmin opens its own control panel');
    $click(-810004,'language:fa');
    check(str_contains($lastText(),'پنل مدیر ارشد') && str_contains(implode(' ',array_column($lastButtons(),'text')),'پاسخ‌گویی'),'Superadmin controls are translated');
    $click(-810004,'setting:initial_coins');
    check(str_contains($lastText(),'هزینهٔ اولیه') && str_contains($lastText(),'مقدار فعلی'),'Setting prompts use translated labels');
    $click(-810004,'sc:initial_coins');$sendUpdate(-810004,'۵');
    $buttons=$lastButtons();$confirm=array_values(array_filter($buttons,fn($b)=>str_starts_with($b['callback_data']??'','p:apply:')))[0];$click(-810004,substr($confirm['callback_data'],2));
    check(row($connect,"SELECT value FROM platform_settings WHERE name='initial_coins'")['value']==='5','Numeric settings accept Persian digits');
    $click(-810004,'options');$click(-810004,'language:en');
    check(str_contains($lastText(),'Superadmin panel'),'Language changes preserve the superadmin panel');
    $sendUpdate(-810004,'/start');
    check(!array_intersect(array_column($lastButtons(),'callback_data'),['p:admin','p:super','p:studio']),'/start contains only normal controls for superadmins too');
    // Both parties receive connection events in their own chosen language.
    $connect->query("UPDATE platform_accounts SET role='user',balance=100 WHERE id=-810001");
    $connect->query("UPDATE platform_accounts SET available=1,strikes=0,heartbeat=".time()." WHERE id=-810002");
    $p=new Platform($connect,['SUPERADMIN_IDS'=>'-810004']);
    $startCalls=count($GLOBALS['calls']);invoke($p,'connectPaid',-810001,-810002);$session=invoke($p,'active',-810001);
    $newCalls=array_slice($GLOBALS['calls'],$startCalls);
    check(str_contains($newCalls[0][1]['text'],'Connected to') && str_contains($newCalls[1][1]['text'],'ارتباط برقرار شد'),'Connection notices are independently localized for each participant');
    $before=row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance'];
    invoke($p,'action',-810001,'typing:'.$session['id']);
    $action=end($GLOBALS['calls']);
    check($action[0]==='sendChatAction' && $action[1]['chat_id']===-810002 && $action[1]['action']==='typing','Manual typing status goes only to the active partner');
    check(row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']===$before && row($connect,'SELECT unanswered FROM platform_sessions WHERE id='.$session['id'])['unanswered']==0,'Typing status does not charge coins or count as a message');
    try{invoke($p,'action',-810001,'typing:0');throw new LogicException('Stale typing signal sent');}catch(RuntimeException $e){check(true,'Typing button is bound to its original connection');}
    $beforeCalls=count($GLOBALS['calls']);
    $p->handle((object)['message'=>(object)['message_id'=>11,'from'=>(object)['id'=>-810001],'chat'=>(object)['id'=>-810001,'type'=>'private'],'text'=>'Hello']]);
    $relayed=array_slice($GLOBALS['calls'],$beforeCalls);
    check($relayed[0][0]==='sendChatAction' && $relayed[0][1]['action']==='typing' && $relayed[1][0]==='copyMessage','Text delivery sends a typing indicator before relaying');
    $beforeCalls=count($GLOBALS['calls']);
    $p->handle((object)['message'=>(object)['message_id'=>12,'from'=>(object)['id'=>-810002],'chat'=>(object)['id'=>-810002,'type'=>'private'],'photo'=>[(object)['file_id'=>'mock']]]]);
    $relayed=array_slice($GLOBALS['calls'],$beforeCalls);
    check($relayed[0][0]==='sendChatAction' && $relayed[0][1]['action']==='upload_photo' && $relayed[0][1]['chat_id']===-810001,'Media delivery uses the matching upload status');
    $connect->query('UPDATE platform_sessions SET paid_until='.time().' WHERE id='.$session['id']);$beforeCalls=count($GLOBALS['calls']);$p->tick();
    check(str_contains(array_slice($GLOBALS['calls'],$beforeCalls)[0][1]['text'],'Extended'),'Extension billing notifications use the customer language');
    $beforeCalls=count($GLOBALS['calls']);invoke($p,'finish',invoke($p,'active',-810001),'user_left');$ended=array_slice($GLOBALS['calls'],$beforeCalls);
    $ended=array_values(array_filter($ended,fn($c)=>!isset(json_decode($c[1]['reply_markup']??'{}',true)['remove_keyboard'])));
    check(str_contains($ended[0][1]['text'],'Connection ended') && str_contains($ended[1][1]['text'],'ارتباط پایان یافت'),'Session end notifications use each participant language');
    $sendUpdate(-810006,'/start');$click(-810006,'language:en');
    check(str_contains($lastText(),'18 and over') && str_contains($lastButtons()[0]['text'],'18+'),'English onboarding remains complete');
    $saved=row($connect,'SELECT balance,accepted FROM platform_accounts WHERE id=-810005');
    $fresh=new Platform($connect,[]);$fresh->handle((object)['message'=>(object)['message_id'=>20,'from'=>(object)['id'=>-810005],'chat'=>(object)['id'=>-810005,'type'=>'private'],'text'=>'/start']]);
    check(str_contains($lastText(),'ناشناس') && row($connect,'SELECT balance,accepted FROM platform_accounts WHERE id=-810005')===$saved,'Stored language survives handler restarts without changing wallet or acceptance');
    // Contact and forward moderation: visible text, entities, captions and profiles.
    $rules=SessionRules::defaults();
    $blockedText=[
        '+98 912 345 6789','09123456789','۰۹۱۲۳۴۵۶۷۸۹','٠٩١٢٣٤٥٦٧٨٩','０９１２３４５６７８９',
        '0 9 1 2 3 4 5 6 7 8 9','zero nine one two three four five six seven eight nine',
        'صفر نه یک دو سه چهار پنج شش هفت هشت نه',
        'https://example.com','example.com','example.ir','t.me/alice','t . me / alice',
        'example [dot] com','example dot com','example نقطه ir','http://127.0.0.1',
        'alice@example.com','@alice','@ a l i c e','@ali'."\u{200B}".'ce',
        'ID: REPLACE_ME','آیدی من: alice_123','شناسه: 12345678','telegram: alice_123',
        'ｈｔｔｐｓ：／／example.com','example'."\u{200B}".'.com','&#64;alice',
    ];
    foreach($blockedText as $content)check(MessageModeration::text($content,$rules)!==null,'Blocked contact pattern #'.(array_search($content,$blockedText,true)+1));
    foreach(['Hello. How are you?','I like Telegram very much','Tomorrow is 2026-10-02','I have 100 coins','I am 24 years old','Friendly conversation','سلام، حالت چطوره؟','تا ۲ دقیقهٔ دیگر برمی‌گردم.'] as $content)check(MessageModeration::text($content,$rules)===null,'Ordinary conversation remains allowed: '.$content);
    check(MessageModeration::text('click here',$rules,[(object)['type'=>'text_link','url'=>'https://example.com']])==='links','Hidden clickable links are blocked');
    check(MessageModeration::text('Alice',$rules,[(object)['type'=>'text_mention','user'=>(object)['id'=>1234]]])==='ids','Hidden Telegram user mentions are blocked');
    check(MessageModeration::message((object)['photo'=>[],'caption'=>'@alice'],$rules)==='ids','Media captions receive the same contact checks');
    check(MessageModeration::message((object)['photo'=>[],'caption'=>'hello','caption_entities'=>[(object)['type'=>'text_link','url'=>'https://x.com']]],$rules)==='links','Hidden links in captions are blocked');
    check(MessageModeration::message((object)['contact'=>(object)['phone_number'=>'09123456789']],$rules)==='contacts','Contact cards are blocked');
    foreach(['forward_origin','forward_from','forward_from_chat','forward_sender_name','forward_date'] as $field)check(MessageModeration::message((object)[$field=>'mock','photo'=>[]],$rules)==='forwards','Forward marker blocked: '.$field);
    check(MessageModeration::message((object)['is_automatic_forward'=>true,'text'=>'hello'],$rules)==='forwards','Automatically forwarded messages are blocked');
    $disabled=$rules;foreach(['phones','links','ids','forwards','contacts'] as $category)$disabled['moderation_block_'.$category]=0;
    check(MessageModeration::message((object)['text'=>'@alice https://x.com 09123456789','forward_origin'=>(object)[]],$disabled)===null,'Superadmin can disable the moderation rules');
    $bad=$rules;$bad['moderation_block_links']=2;
    try{SessionRules::validate($bad);throw new LogicException('Invalid moderation flag');}catch(InvalidArgumentException $e){check(true,'Moderation flags accept only zero or one');}
    $p=new Platform($connect,['SUPERADMIN_IDS'=>'-810004']);
    $connect->query("UPDATE platform_accounts SET balance=100,role='user' WHERE id=-810001");
    $connect->query('UPDATE platform_accounts SET available=1,strikes=0,heartbeat='.time().' WHERE id=-810002');
    invoke($p,'connectPaid',-810001,-810002);$session=invoke($p,'active',-810001);
    $before=row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance'];
    $message=function(int $id,int $number,array $fields) {
        return (object)['update_id'=>900000+$number,'message'=>(object)array_merge(['message_id'=>$number,'from'=>(object)['id'=>$id],'chat'=>(object)['id'=>$id,'type'=>'private']],$fields)];
    };
    $callOffset=count($GLOBALS['calls']);$p->handle($message(-810001,300,['text'=>'09123456789']));
    $new=array_slice($GLOBALS['calls'],$callOffset);
    check(!in_array('copyMessage',array_column($new,0),true) && str_contains(end($new)[1]['text'],'Message not sent'),'Blocked customer content never reaches Telegram relay');
    check(row($connect,'SELECT unanswered FROM platform_sessions WHERE id='.$session['id'])['unanswered']==0 && row($connect,'SELECT balance FROM platform_accounts WHERE id=-810001')['balance']===$before,'Blocked messages do not affect reply counters or debit coins');
    check(row($connect,'SELECT COUNT(*) AS total FROM platform_moderation WHERE user_id=-810001')['total']==1,'Blocked attempt is recorded without message contents');
    $p->handle($message(-810001,300,['text'=>'09123456789']));
    check(row($connect,'SELECT COUNT(*) AS total FROM platform_moderation WHERE user_id=-810001')['total']==1,'Duplicate updates do not duplicate moderation records');
    $callOffset=count($GLOBALS['calls']);$p->handle($message(-810001,301,['text'=>'/stop','forward_origin'=>(object)['type'=>'hidden_user','sender_user_name'=>'Alice']]));
    check(invoke($p,'active',-810001)!==null && !in_array('copyMessage',array_column(array_slice($GLOBALS['calls'],$callOffset),0),true),'Forwarded commands are blocked before command execution');
    $callOffset=count($GLOBALS['calls']);$p->handle($message(-810001,302,['text'=>'Hello friend']));
    $relayed=array_slice($GLOBALS['calls'],$callOffset);$copy=$relayed[1];
    $map=row($connect,'SELECT * FROM platform_messages WHERE source_chat=-810001 AND source_message=302');
    check($map['target_message']==$callOffset+100002 && $map['target_chat']==-810002 && $map['source_message']==302,'Relayed source and destination message IDs are persisted');
    check(!isset($copy[1]['reply_markup']),'Received messages have no per-message reaction buttons');
    $counter=row($connect,'SELECT unanswered FROM platform_sessions WHERE id='.$session['id'])['unanswered'];
    $react=function(int $id,string $key,string $token)use($p,$map) {
        return $p->handle((object)['callback_query'=>(object)['id'=>'react','from'=>(object)['id'=>$id],'data'=>'p:react:'.$token.':'.$key,'message'=>(object)['message_id'=>(int)$map['target_message'],'chat'=>(object)['id'=>$id,'type'=>'private']]]]);
    };
    $callOffset=count($GLOBALS['calls']);$react(-810002,'love',$map['token']);$events=array_slice($GLOBALS['calls'],$callOffset);
    $reaction=array_values(array_filter($events,fn($event)=>$event[0]==='setMessageReaction'))[0];
    check($reaction[1]['chat_id']===-810001 && $reaction[1]['message_id']===302 && json_decode($reaction[1]['reaction'],true)[0]['emoji']==='❤','Reaction button applies a reaction to the matching original message');
    check(row($connect,'SELECT unanswered FROM platform_sessions WHERE id='.$session['id'])['unanswered']==$counter,'Provider reactions do not count as a reply');
    $callOffset=count($GLOBALS['calls']);$react(-810002,'love',$map['token']);$events=array_slice($GLOBALS['calls'],$callOffset);$reaction=array_values(array_filter($events,fn($event)=>$event[0]==='setMessageReaction'))[0];
    check(json_decode($reaction[1]['reaction'],true)===[],'Tapping the selected reaction again removes it');
    $react(-810002,'like',$map['token']);$react(-810002,'none',$map['token']);
    check(row($connect,'SELECT target_reaction FROM platform_messages WHERE id='.$map['id'])['target_reaction']==='','Clear reaction removes stored selection');
    $callOffset=count($GLOBALS['calls']);
    try{$react(-810003,'like',$map['token']);throw new LogicException('Third party reaction');}catch(RuntimeException $e){check(!in_array('setMessageReaction',array_column(array_slice($GLOBALS['calls'],$callOffset),0),true),'Other accounts cannot react through a guessed token');}
    $native=(object)['message_reaction'=>(object)['chat'=>(object)['id'=>-810002,'type'=>'private'],'message_id'=>(int)$map['target_message'],'user'=>(object)['id'=>-810002,'is_bot'=>false],'date'=>time()+5,'new_reaction'=>[(object)['type'=>'emoji','emoji'=>'🔥']]]];
    $p->handle($native);
    check(row($connect,'SELECT target_reaction FROM platform_messages WHERE id='.$map['id'])['target_reaction']==='🔥','Native reaction updates are mirrored when Telegram supplies them');
    $native->message_reaction->date=time()-5;$native->message_reaction->new_reaction=[];$p->handle($native);
    check(row($connect,'SELECT target_reaction FROM platform_messages WHERE id='.$map['id'])['target_reaction']==='🔥','Delayed native reaction updates cannot overwrite newer reactions');
    $native->message_reaction->date=time()+10;$native->message_reaction->new_reaction=[(object)['type'=>'paid']];$p->handle($native);
    check(row($connect,'SELECT target_reaction FROM platform_messages WHERE id='.$map['id'])['target_reaction']==='🔥','Unsupported paid reactions are not mistaken for removal');
    $GLOBALS['fail_methods']=['setMessageReaction'];
    $callOffset=count($GLOBALS['calls']);$react(-810002,'sad',$map['token']);
    $fallback=array_values(array_filter(array_slice($GLOBALS['calls'],$callOffset),fn($event)=>$event[0]==='sendMessage'));
    check(count($fallback)===1 && $fallback[0][1]['chat_id']===-810001 && str_contains($fallback[0][1]['text'],'Your chat partner reacted') && json_decode($fallback[0][1]['reply_parameters'],true)['message_id']===302,'Unsupported native reactions fall back to a localized reply on the original message');
    $GLOBALS['fail_methods']=[];
    $callOffset=count($GLOBALS['calls']);$p->handle($message(-810002,304,['caption'=>'@alice','photo'=>[(object)['file_id'=>'mock']]]));
    check(!in_array('copyMessage',array_column(array_slice($GLOBALS['calls'],$callOffset),0),true) && str_contains(end($GLOBALS['calls'])[1]['text'],'پیام ارسال نشد'),'Provider media captions are blocked with Persian feedback');
    $callOffset=count($GLOBALS['calls']);$p->handle($message(-810002,305,['text'=>'Hello back']));
    check(in_array('copyMessage',array_column(array_slice($GLOBALS['calls'],$callOffset),0),true) && row($connect,'SELECT unanswered FROM platform_sessions WHERE id='.$session['id'])['unanswered']==0,'Only a delivered provider message resets the response timer');
    invoke($p,'finish',invoke($p,'active',-810001),'user_left');
    $callOffset=count($GLOBALS['calls']);
    try{$react(-810002,'sad',$map['token']);throw new LogicException('Expired reaction');}catch(RuntimeException $e){check(!in_array('setMessageReaction',array_column(array_slice($GLOBALS['calls'],$callOffset),0),true),'Reaction buttons stop working after the connection ends');}
    invoke($p,'action',-810005,'edit:bio');
    $p->handle($message(-810005,306,['text'=>'Find me at @alice']));
    check(row($connect,'SELECT bio,state FROM platform_accounts WHERE id=-810005')['bio']==='' && row($connect,'SELECT state FROM platform_accounts WHERE id=-810005')['state']==='edit:bio','Blocked profile edits preserve the edit prompt and do not save contact details');
    check(invoke($p,'publicText',-810005,'@alice')==='[اطلاعات تماس پنهان شد]','Existing public contact details are hidden on display');
    invoke($p,'action',-810004,'moderation:0');
    check(str_contains(end($GLOBALS['calls'])[1]['text'],'Blocked content'),'Superadmin can review the moderation log');

    require __DIR__.'/redesign.php';
    require __DIR__.'/profiles.php';
    require __DIR__.'/tendency.php';
    require __DIR__.'/engagement.php';
    require __DIR__.'/growth.php';
    echo "All checks passed; Telegram calls were mocked.\n";
}finally{$cleanup();$connect->query("SELECT RELEASE_LOCK('nashenas_platform_tests')");}
