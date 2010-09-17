<?php

$db_config = array(
	'dbc_dns'		=> 'mysql://root:root@localhost:3306/dbc',
	'db_encoding'	=> 'utf8',		// set encoding DB
	'db_prefix'		=> 'dbc_',
);

function databaseErrorHandler($message, $info) {
	// Если использовалась @, ничего не делать.
	if (!error_reporting()) return;
	// Выводим подробную информацию об ошибке.
	print "<table class=report>";
	print "<tr><td><font class=red><b>[SQL Error]</b></font>: $message</td></tr>";
	print "<tr><td><pre>".print_r($info, true)."</div></pre></td></tr>";
	print "</table>";
	exit();
}

?>
