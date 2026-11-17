<?php
require dirname(__DIR__).'/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$sql=file_get_contents(dirname(__DIR__).'/lib/schema.sql');
foreach (explode(';',$sql) as $statement) if(trim($statement)!=='') $connect->query($statement);
// Existing installations keep their acceptance, wallet and profile data.
foreach (['age'=>'INT NOT NULL DEFAULT 0','age_set_at'=>'BIGINT NOT NULL DEFAULT 0','onboarding_done'=>"TINYINT NOT NULL DEFAULT 0", 'orientation_status'=>"VARCHAR(16) NOT NULL DEFAULT 'pending'", 'orientation_attempt'=>"VARCHAR(16) NOT NULL DEFAULT ''", 'orientation_cipher'=>"LONGTEXT NULL", 'language'=>"VARCHAR(2) NOT NULL DEFAULT ''", 'panel'=>"VARCHAR(16) NOT NULL DEFAULT 'normal'", 'previous_role'=>"VARCHAR(16) NOT NULL DEFAULT 'user'", 'public_key'=>"VARCHAR(16) NOT NULL DEFAULT ''", 'ui_version'=>"INT NOT NULL DEFAULT 0", 'name_set'=>"TINYINT NOT NULL DEFAULT 0", 'gender'=>"VARCHAR(16) NOT NULL DEFAULT ''", 'gender_changes'=>"INT NOT NULL DEFAULT 0", 'phone'=>"VARCHAR(32) NOT NULL DEFAULT ''", 'province_id'=>"BIGINT NOT NULL DEFAULT 0", 'city_id'=>"BIGINT NOT NULL DEFAULT 0", 'telegram_username'=>"VARCHAR(32) NOT NULL DEFAULT ''"] as $column=>$type) {
    $existing=$connect->query("SHOW COLUMNS FROM platform_accounts LIKE '$column'");
    if (!$existing->num_rows) $connect->query("ALTER TABLE platform_accounts ADD COLUMN `$column` $type");
}
if(!$connect->query("SHOW COLUMNS FROM platform_queue LIKE 'rules'")->num_rows)$connect->query('ALTER TABLE platform_queue ADD COLUMN rules TEXT NULL');
foreach($connect->query("SELECT id FROM platform_accounts WHERE public_key='' ")->fetch_all(MYSQLI_ASSOC) as $row) {
    $key=bin2hex(random_bytes(8));$statement=$connect->prepare("UPDATE platform_accounts SET public_key=? WHERE id=? AND public_key='' ");$statement->bind_param('ss',$key,$row['id']);$statement->execute();
}
$indexes=$connect->query("SHOW INDEX FROM platform_accounts WHERE Key_name='public_key'");if(!$indexes->num_rows)$connect->query('ALTER TABLE platform_accounts ADD INDEX public_key(public_key)');
require dirname(__DIR__).'/lib/Rules.php';
require dirname(__DIR__).'/lib/Catalog.php';
ProfileCatalog::seed($connect);
// Obsolete pending admin forms bypassed review; replace them with the guided flows.
$connect->query("UPDATE platform_accounts SET state='' WHERE state IN ('lookup','grant','role','reset') OR state LIKE 'setting:%'");
if(!$connect->query("SHOW COLUMNS FROM platform_messages LIKE 'text_cipher'")->num_rows)$connect->query('ALTER TABLE platform_messages ADD COLUMN text_cipher LONGTEXT NULL');
// One-time removal of incompatible free-text caches; valid catalog selections remain.
if(!$connect->query("SELECT name FROM platform_settings WHERE name='profile_v3_cleaned'")->num_rows) {
    $backup=$connect->query('SELECT id,interests,preferences,enabled_preferences FROM platform_accounts')->fetch_all(MYSQLI_ASSOC);
    $path=dirname(__DIR__).'/runtime/backups/pre-profile-v3-data.json';
    file_put_contents($path,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($path,0600);
    $connect->query("UPDATE platform_accounts SET interests='',preferences='',enabled_preferences='',state='' WHERE state LIKE 'edit:interests' OR state LIKE 'edit:preferences' OR interests<>'' OR preferences<>'' OR enabled_preferences<>''");
    $connect->query("INSERT INTO platform_settings(name,value) VALUES('profile_v3_cleaned','1')");
}
$connect->query("UPDATE platform_settings SET value=REPLACE(value,'میستر','مستر') WHERE value LIKE '%میستر%'");
if(!$connect->query("SELECT name FROM platform_settings WHERE name='mm_profiles_seeded'")->num_rows){
    $connect->query("INSERT IGNORE INTO platform_mm_profiles(user_id,title,bio) SELECT id,title,COALESCE(bio,'') FROM platform_accounts WHERE role IN ('miss','mister')");
    $connect->query("INSERT INTO platform_settings(name,value) VALUES('mm_profiles_seeded','1')");
}
if(!$connect->query("SELECT name FROM platform_settings WHERE name='mm_choices_seeded'")->num_rows){
    $connect->query("INSERT IGNORE INTO platform_choices(user_id,scope,option_id) SELECT c.user_id,'mm_interests',c.option_id FROM platform_choices c JOIN platform_accounts a ON a.id=c.user_id WHERE c.scope='interests' AND a.role IN ('miss','mister')");
    $connect->query("INSERT INTO platform_settings(name,value) VALUES('mm_choices_seeded','1')");
}
// Removed gender choice: require a replacement without consuming a change.
if(!$connect->query("SELECT name FROM platform_settings WHERE name='gender_binary_v1'")->num_rows){
    $removed=$connect->query("SELECT id,gender,gender_changes FROM platform_accounts WHERE gender='other'")->fetch_all(MYSQLI_ASSOC);
    if($removed){$backupPath=dirname(__DIR__).'/runtime/backups/removed-gender-choice.json';file_put_contents($backupPath,json_encode($removed,JSON_THROW_ON_ERROR));chmod($backupPath,0600);}
    $connect->query("UPDATE platform_requests r JOIN platform_accounts a ON a.id=r.customer OR a.id=r.provider SET r.status='expired' WHERE r.kind='booking' AND r.status='pending' AND a.gender='other'");
    $connect->query("UPDATE platform_ui u JOIN platform_accounts a ON a.id=u.actor SET u.used=1 WHERE u.kind='gender' AND a.gender='other'");
    $connect->query("UPDATE platform_accounts SET gender='',state='',available=0 WHERE gender='other'");
    $connect->query("INSERT INTO platform_settings(name,value) VALUES('gender_binary_v1','1')");
}
$keyPath=dirname(__DIR__).'/runtime/archive.key';
if(!is_file($keyPath)){file_put_contents($keyPath,random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),LOCK_EX);chmod($keyPath,0600);}
if(!$connect->query("SHOW COLUMNS FROM platform_tendency_attempts LIKE 'id'")->num_rows)$connect->query('ALTER TABLE platform_tendency_attempts ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT UNIQUE');
$tendencyKey=dirname(__DIR__).'/runtime/tendency.key';
if(!is_file($tendencyKey)){file_put_contents($tendencyKey,random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),LOCK_EX);chmod($tendencyKey,0600);}
require_once dirname(__DIR__).'/lib/InterestsMigration.php';
InterestsMigration::run($connect);
echo "Platform schema ready.\n";
