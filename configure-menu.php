<?php
/** Configure translated default menus and account-specific command descriptions. */
require dirname(__DIR__).'/config.php';
require dirname(__DIR__).'/lib/Platform.php';
function jijibot($method,$data=[]) {
    $ch=curl_init('https://api.telegram.org/bot'.API_KEY.'/'.$method);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);
    $result=json_decode(curl_exec($ch)?:'null');curl_close($ch);
    if(!$result || !$result->ok)throw new RuntimeException('Telegram command menu update failed');
    return $result;
}
$commands=['start','mm','admin','superadmin'];
foreach(['default','all_private_chats'] as $scope)foreach(['','en','fa'] as $language) {
    jijibot('setMyCommands',[
        'scope'=>json_encode(['type'=>$scope]), 'language_code'=>$language,
        'commands'=>json_encode(array_map(fn($command)=>['command'=>$command,'description'=>BotLocale::text($language?:'en','command.'.$command)],$commands),JSON_UNESCAPED_UNICODE)
    ]);
}
jijibot('setChatMenuButton',['menu_button'=>json_encode(['type'=>'commands'])]);
$ids=array_filter(array_map('trim',explode(',',($settings['ADMIN_IDS']??'').','.($settings['SUPERADMIN_IDS']??''))));
foreach($connect->query("SELECT id FROM platform_accounts WHERE language IN ('en','fa')")->fetch_all(MYSQLI_ASSOC) as $row)$ids[]=$row['id'];
$platform=new Platform($connect,$settings);
foreach(array_unique($ids) as $id)$platform->syncCommands((int)$id);
echo "English and Persian Telegram command menus configured.\n";
