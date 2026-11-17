<?php
require dirname(__DIR__).'/config.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if((int)$connect->query("SELECT GET_LOCK('nashenas_platform',10)")->fetch_row()[0]!==1)throw new RuntimeException('Platform busy');
try {
 foreach(explode(';',file_get_contents(dirname(__DIR__).'/lib/schema.sql')) as $sql)if(trim($sql)!=='')$connect->query($sql);
 foreach(['onboarding_done'=>'TINYINT NOT NULL DEFAULT 0','orientation_status'=>"VARCHAR(16) NOT NULL DEFAULT 'pending'",'orientation_attempt'=>"VARCHAR(16) NOT NULL DEFAULT ''",'orientation_cipher'=>'LONGTEXT NULL'] as $column=>$type)
  if(!$connect->query("SHOW COLUMNS FROM platform_accounts LIKE '$column'")->num_rows)$connect->query("ALTER TABLE platform_accounts ADD COLUMN `$column` $type");
 echo "Engagement schema ready.\n";
} finally {$connect->query("SELECT RELEASE_LOCK('nashenas_platform')");}
