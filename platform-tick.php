<?php
require dirname(__DIR__).'/config.php';
require dirname(__DIR__).'/lib/Platform.php';
function jijibot($method,$data=[]) {
    $ch=curl_init('https://api.telegram.org/bot'.API_KEY.'/'.$method);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10]);
    $r=curl_exec($ch);curl_close($ch);return json_decode($r?:'null');
}
if ((int)$connect->query("SELECT GET_LOCK('nashenas_platform', 2)")->fetch_row()[0]!==1) exit;
try {(new Platform($connect,$settings))->tick(true);}
finally {$connect->query("SELECT RELEASE_LOCK('nashenas_platform')");}
