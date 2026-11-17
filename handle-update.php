<?php
chdir(dirname(__DIR__));
$GLOBALS['incoming_update'] = stream_get_contents(STDIN);
require getcwd() . '/bot.php';
