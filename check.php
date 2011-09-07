<?
error_reporting(E_ALL); 

include_once('config.php');
include_once('core/fmt.php');
include_once('core/struct.php');
include_once('core/dbsimple/Generic.php'); // including simple conecting for DB
include_once('core/dbc.class.php'); // including simple conecting for DB

$DB = DbSimple_Generic::connect($db_config['dbc_dns']);
$DB->setErrorHandler("databaseErrorHandler");
// $DB->setIdentPrefix($db_config['db_prefix']);
$dbc = new DBCparser();

define('URL_START','https://raw.github.com/mangos/mangos/');
define('URL_DBC_FMT','/src/game/DBCfmt.h');
define('URL_UPDATE_FIELDS','/src/game/UpdateFields.h');
define('URL_DBC_STRUCT','/src/game/DBCStructure.h');
$branch = '400';

function getStructFilesGIT($branch, &$output, &$info){
	$url = URL_START.$branch.URL_DBC_FMT;

	$output = '';
	$I = '\\b[A-Z_][A-Z0-9_]*\\b';
	$regex = "^\w{5}\s\w{4}(.*)\[\]=\"(.*)\";$";
	$file = file($url);

	foreach ($file as $line) {
		$line = rtrim($line);
		if (preg_match("#$regex#i", $line, $regs)) {
			$regs[1] = str_replace('Entry','',str_replace('fmt','',trim($regs[1])));
			$output[] = array($regs[1],$regs[2]);
		}
	}

	$info=$url;
}

function _struct($d){
	$count = $d['columns'];
	$str = '';
	$req = '	%s => \'%s\'%s';
	for($i=0;$i<$count;$i++){
		$end = ($i+1 == $count)? '':','.ch(13).ch(10);
		$str .= sprintf($req,$i,$i,$end);

	}
	return $str;
}

function get_type($char=null){
	switch($char){
		case 'i': return 'uint32';
		case 'n': return 'uint32';
		case 'f': return 'float';
		case 'd': return 'sorted';
		case 's': return 'string';
		case 'b': return 'uint8';
		case 'l': return 'bool';
		case 'x': return 'unknown';
		case 'X': return 'unknown';
		default:  return 'unknown';
	};
}

function update_xml(){
	global $DBCstruct, $DBCfmt;

	foreach($DBCfmt as $name => $format){
		$fh = fopen('xml/'.$name.'.xml', 'wb');
		fwrite($fh, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n");
		fwrite($fh, "<!-- \$id: $name.xml ".date('Y-m-d H:i:s')."  SergiK_KilleR $ -->\r\n");
		fwrite($fh, "\r\n<file name=\"$name\" format=\"$format\">\r\n");
		for($i=0;$i<strlen($format);$i++){
			if(@$DBCstruct[$name][$i]==''){
				fwrite($fh, "\t<field id=\"$i\" count=\"1\" name=\"unk$i\" type=\"".get_type($format[$i])."\" key=\"0\" />\r\n");
			}else{
				if(is_array($DBCstruct[$name][$i])){
					if($DBCstruct[$name][$i][1]===1){
						fwrite($fh, "\t<field id=\"".$i."\" count=\"1\" name=\"".$DBCstruct[$name][$i][0]."\" type=\"".get_type($format[$i])."\" key=\"1\" />\r\n");
					}else{
						$count = $DBCstruct[$name][$i][1];
						fwrite($fh, "\t<field id=\"".$i."\" count=\"$count\" name=\"".$DBCstruct[$name][$i][0]."\" type=\"".get_type($format[$i])."\" key=\"0\" />\r\n");
						$i +=($count-1);
					}
				}else{
					fwrite($fh, "\t<field id=\"$i\" count=\"1\" name=\"".$DBCstruct[$name][$i]."\" type=\"".get_type($format[$i])."\" key=\"0\" />\r\n");
				}
			}
		}
		fwrite($fh, "</file>");
	}
}

function getListFiles($dir="dbc") {
	// Открыть заведомо существующий каталог и начать считывать его содержимое
	$list = array();
	if (is_dir($dir)) {
	    if ($dh = opendir($dir)) {
	        while ($file = readdir($dh)) {
				if(strlen($file)>4) {
					$filename = explode('.',$file);
					if($filename[1]=$dir)
						$list[] = $filename[0];
				}
	        }
	        closedir($dh);
	    }
	}
	return $list;
}

$f_dbc = getListFiles('dbc');
$f_xml = getListFiles('xml');

$res = $DB->selectPage($count,"SELECT `file` FROM `_dbc_info_`");
$db_rows['count'] = $count;
$db_rows['no_format'] = $DB->selectCell("SELECT COUNT(`file`) FROM `_dbc_info_` WHERE `format`=''");
$db_rows['incorrect_length'] = $DB->selectCell("SELECT COUNT(`file`) FROM `_dbc_info_` WHERE LENGTH(`format`) != 0 && LENGTH(`format`) != `columns`");
$t = 0;

foreach($f_dbc as $tmp){
	$dbc->_set($tmp);
	if($dbc->checkSizeOf() === false)
		$t++;
}
$db_rows['incorrect_size'] =  $t;

?>
<pre>
Данные по файлам:
	количество dbc-файлов: <?=count($f_dbc);?> 
	количество xml-файлов: <?=count($f_xml);?> 
Данные по БД:
	количество записей: <?=$db_rows['count'];?> 
	неформатированые записи: <?=$db_rows['no_format'];?> 
	несовпадает длинна: <?=$db_rows['incorrect_length'];?> 
	несовпадает размер строк: <?=$db_rows['incorrect_size'];?> 
</pre>

<div>
	<form method="POST">
		<input type="submit" name="update_info" value="update_info" />
		<input type="submit" name="update_xml" value="update_xml" />
		<input type="submit" name="update_fmt_from_db" value="update_fmt_from_db" disabled />
		<input type="submit" name="update_struct" value="update_struct" disabled />
		<input type="submit" name="unk" value="unk" disabled />
		<input type="submit" name="check_struct_files" value="check_struct_files 400" disabled />
	</form>
</div>
<div>
<pre>
<?
if(isset($_POST['update_info'])){
	foreach($f_dbc as $f){
		$dbc->_set($f);
		$dbc->getHeader();
	}
}
if(isset($_POST['update_xml'])){
	update_xml();
}
if(isset($_POST['update_fmt_from_db'])){
	$data = $DB->selectPage($count,"SELECT * FROM `_dbc_info_`");
	$ffmt = fopen('core/fmt.php.ini','wb');
	$str_fmt = '\'%s\' => \'%s\'%s // rows: %s, cols: %s%c%c';
	fprintf($ffmt,'<?php%c%c// dbc format v4.0.0%c%c$DBCfmt = array(%c%c',13,10,13,10,13,10);
	foreach($data as $c => $d){
		$strlen = strlen($d['format']);
		$str_inc = ($c+1 == $count)? '':',';

		if($strlen!=$d['columns']){
			$d['columns'] = $d['columns'].'('.$strlen.')';
			$DB->query("UPDATE `_dbc_info_` SET `valid`=0 WHERE `file`=? ",$d['file']);
		}
		// $struct = _struct($d);
		fprintf($ffmt,$str_fmt,$d['file'],$d['format'],$str_inc,$d['rows'],$d['columns'],13,10);
	}
	fwrite($ffmt,');');
}

if(isset($_POST['update_struct'])){
	$data = $DB->selectPage($count,"SELECT * FROM `_dbc_info_`");
	$fs = fopen('core/struct.php.ini','wb');
	$str_s = '\'%s\' => array()%s // rows: %s, cols: %s%c%c';
	fprintf($fs,'<?php%c%c// dbc format v4.0.0%c%c$DBCstruct = array(%c%c',13,10,13,10,13,10);
	foreach($data as $c => $d){
		$strlen = strlen($d['format']);
		$str_inc = ($c+1 == $count)? '':',';

		if($strlen!=$d['columns']){
			$d['columns'] = $d['columns'].'('.$strlen.')';
			$DB->query("UPDATE `_dbc_info_` SET `valid`=0 WHERE `file`=? ",$d['file']);
		}
		fprintf($fs,$str_s,$d['file'],$str_inc,$d['rows'],$d['columns'],13,10);
	}
	fwrite($fs,');');

}

if(isset($_POST['check_struct_files'])){
	getStructFilesGIT($branch,$output,$info);
	$i=0;

	$dom = new DOMDocument();
	$dom->preserveWhiteSpace = false;
	$dom->substituteEntities = true;
	print "URL: ".$info;
	print "<br>Branch: ".$branch."<br>";
	foreach($output as $arr){
		$i++;
		$xmlfile = 'xml/'.$arr[0].'.xml';
		if(file_exists($xmlfile)){
			$dom->Load($xmlfile);
			$tt = $dom->getElementsByTagName('file')->item(0);
			// $arr["L"] = $tt->getAttribute('format');
			$arr["L"] = $DBCfmt[$arr[0]];
			if($arr["L"]!=$arr[1])
				print_r($arr);
		}else{
		}
	}	
}


?></pre></div>

