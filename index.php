<?php

function databaseErrorHandler($message, $info) {
	// Если использовалась @, ничего не делать.
	if (!error_reporting()){
		return;
	}
	// Выводим подробную информацию об ошибке.
	print "<table class=report>";
	print "<tr><td><font class=red><b>[SQL Error]</b></font>: $message</td></tr>";
	print "<tr><td><pre>".print_r($info, true)."</div></pre></td></tr>";
	print "</table>";
	exit();
}

include_once __DIR__ .'/vendor/autoload.php';

error_reporting(E_ALL); 
ini_set("max_execution_time", 6000000);
ini_set('memory_limit', -1);
set_time_limit(6000000);

$conf = require("config.php"); // including conecting info
$dbDNS = $conf['connection'][$conf['default']];

$DB = DbSimple_Generic::connect($dbDNS);
$DB->setErrorHandler("databaseErrorHandler");

$dbc = new DBC\Parser();
$finder = new Symfony\Component\Finder\Finder;
$iterator = $finder->files()->name("*.dbc")->depth(0)->in('DBFilesClient');

print "<pre>";
foreach ($iterator as $i => $file) {
	$f = $file->getRealpath();
	$filename = $file->getRelativePathname();
	$dbc->set($filename);
	$dbc->getHeader();
	if($dbc->isDefaultFieldSize){
		print "\n".$filename.' - default format (0x04 per field)';
		$dbc->createTable();
		$dbc->getData();
	}else{
		print "\n".$filename.': '.print_r($dbc->getHeaderInfo(),true);
	}
}
