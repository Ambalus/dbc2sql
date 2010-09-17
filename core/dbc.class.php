<?php
/**
* 0x00 	char[4] always 'WDBC' 
* 0x04 	uint32 	nRecords - number of records in the file 
* 0x08 	uint32 	nFields - number of 4-byte fields per record 
* 0x0C 	uint32 	recordSize = nFields * 4 (not always true!) 
* 0x10 	uint32 	string block size
**/
define('DEF_HEADER_SIZE',20);

class DBCparser
{
	private static $_signature = 'WDBC';

	var $error = '';
	var $file = null;
	var $filename = null;
	var $format = '';
	var $field = null;
	var $countRecords = 0;
	var $countFields = 0;
	var $sizeRecord = 0;
	var $sizeString = 0;
	var $formatRecordSize = 0;
	var $isValid = false;

	function __construct($file){
		global $db_config;
		if($this->file = fopen("dbc/".$file, "rb")){
			$ex = explode('.',$file);
			$this->filename = $ex[0];

			include_once('dbsimple/Generic.php'); // including simple conecting for DB
			$this->DB = DbSimple_Generic::connect($db_config['dbc_dns']);
			$this->DB->setErrorHandler("databaseErrorHandler");
			$this->DB->setIdentPrefix($db_config['db_prefix']);
			$this->DB->query("SET NAMES ?",$db_config['db_encoding']);

			$this->dom = new DOMDocument();
			$this->dom->preserveWhiteSpace = false;
			$this->dom->substituteEntities = true;
			$xmlfile = 'xml/'.$this->filename.'.xml';
			if(file_exists($xmlfile)){
				$this->dom->Load($xmlfile);
				$this->XML = $this->dom->getElementsByTagName('file')->item(0);
				$this->format = $this->XML->getAttribute('format');
				return;
			}
		}else{
			$this->error = "<font color=\"red\">Не могу открыть файл <b>".$this->filename."</b>.</font>";
		}
	}

	public function getHeader(){
		$this->header = fread($this->file, DEF_HEADER_SIZE);

		if($h = substr($this->header,0,4) !== self::$_signature){
			$text['INCORRECT_SIGNATURE'] = 'file\'s signature incorrect ( %s )';
			$this->error = sprintf($text['INCORRECT_SIGNATURE'],$h);
			return false;
		}
		$this->countRecords = base_convert(bin2hex(strrev(substr($this->header,4,4))), 16, 10);
		$this->countFields = base_convert(bin2hex(strrev(substr($this->header,8,4))), 16, 10);
		$this->sizeRecord = base_convert(bin2hex(strrev(substr($this->header,12,4))), 16, 10);
		$this->sizeString = base_convert(bin2hex(strrev(substr($this->header,16,4))), 16, 10);
		//$this->isValud = $this->isValidFormatFile();
		$this->writeDBCInfo();

		/*if($this->countFields*4 != $this->sizeRecord){
			$text['INCORRECT_SIZE_BLOCK'] = 'diff size blocks (%d | %d)';
			$this->error = sprintf($text['INCORRECT_SIZE_BLOCK'],$this->countFields*4,$this->sizeRecord);
			return false;
		}*/

		return true;
	}

	private function getFormatRecord(){
		$this->formatRecordSize = 0;
		$c = strlen($this->format);
		for($i=0;$i<$c;$i++) {
			switch($this->format[$i]){
				case 'x':
				case 's':
				case 'f':
				case 'i':
				case 'd':
				case 'n':
					$this->formatRecordSize += 4;
					break;
				case 'X':
				case 'b':
				case 'l':
					$this->formatRecordSize += 1;
					break;
				default:
					$text['INCORRECT_FORMAT_FILE'] = 'error dbc\'s format';
					$this->error = $text['INCORRECT_FORMAT_FILE'];
					return false;
					break;
			}
		}
		return true;
	}
	
	private function getFields(){
		$this->field = array();
		foreach($this->XML->getElementsByTagName('field') as $field){
			$this->field[$field->getAttribute('id')] = array(
				'count' => (int) $field->getAttribute('count'),
				'name' => (string) $field->getAttribute('name'),
				'type' => (string) $field->getAttribute('type'),
				'key' => (int) $field->getAttribute('key')
			);
		}
	}

	private function createTable(){
		if($this->error != '')
			return false;

		if(!$this->countFields)
			return false;

		$sql = "CREATE TABLE ?# (\n";
		$this->getFields(); // return $this->fields

		$collums = 0;
		$ittr = 0;
		for($i=0; $i<$this->countFields; $i++) {
			if($collums>1 && $ittr==$collums){
				$collums = 0;
				$ittr = 0;
			}

			$field 	= $this->field[$i]['name'];
			if($this->field[$i]['key']==1){ // prymary key
				$pkey[] = $field;
			}

			if($this->field[$i]['count']>1){
				$collums = $this->field[$i]['count'];
				$temp_f = $field;
			}

			if($collums>1 && $ittr<$collums){
				$ittr++;
				$field = $temp_f."_".$ittr;
			}else{
				$collums = 0;
				$ittr = 0;
			}
			switch($this->format[$i]){
				case 'f':
				case 'i':
				case 'd':
				case 'b':
				case 'l':
				case 'x':
				case 'X':
				case 'n':
					$sql .= ($i==($this->countFields-1))? " `$field` int(12) DEFAULT NULL\n" : " `$field` int(12) DEFAULT NULL,\n";
					break;
				case 's':
					$sql .= ($i==($this->countFields-1))? " `$field` varchar(255) DEFAULT NULL\n" : " `$field` varchar(255) DEFAULT NULL,\n";
					break;
				default:
					$this->error .= " Ошибка формата!!! В стоке формата присутствуют посторонние символы.";
					return false;
					break;
			}
		}

		if(!empty($pkey)){
			$int=0;
			$sql .= ",\nPRIMARY KEY (";
			foreach($pkey as $keyID){
				$sql .= ($int == (count($pkey)-1))? "`$keyID`" : "`$keyID`,";
				$int++;
			}
			$sql .= ")\n";
		}

		$sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
		
		$this->DB->query("DROP TABLE IF EXISTS ?#",'dbc_'.$this->filename);
		$this->DB->query($sql,'dbc_'.$this->filename);

		unset($collums);
		unset($ittr);
		unset($sql);
		unset($int);
		unset($field);
		unset($temp_f);
		unset($key);
		unset($pkey);
		unset($keyID);
		unset($i);
	}

	public function isValidFormatFile(){
		if($this->isValid) return true;
		if(!$this->getHeader()) return false;
		if(!$this->getFormatRecord()) return false;

		$c = strlen($this->format);
		if ($c != $this->countFields) {
			$text['DIFF_COUNT_FIELDS'] = 'fields count diff (dbc: %d, xml: %d)';
			$this->error = sprintf($text['DIFF_FIELDS_FORMAT'],$this->countFields,$c);
			return false;
		}

		if ($this->sizeRecord != $this->formatRecordSize) {
			$text['DIFF_SIZE_RECORDS'] = 'Record size diff (dbc: %d, xml: %d)';
			$this->error = sprintf($text['DIFF_SIZE_RECORDS'],$this->sizeRecord,$this->formatRecordSize);
			return false;
		}

		$this->isValid = true;
		return true;
	}

	public function getData(){
		if(!$this->isValidFormatFile()) return false;

		$this->createTable();
		for ($row = 1; $row <= $this->countRecords; $row++) {
			$this->getRecord($row,$out);
			$this->DB->query("INSERT INTO ?# VALUES(?a)",'dbc_'.$this->filename,$out);
			unset($out);
		}

		fclose($this->file);
		return true;
	}

	public function getRecord($row,&$out){
		for ($cell = 1; $cell <= $this->countFields; $cell++) {
			switch ($this->format[$cell-1]) {
				case 'x': //unknown 4 bytes
				case 'i': //unsigned integer 4 bytes
				case 'd': //order unknown 4 bytes
				case 'n': //order unsigned integer 4 bytes
					$t = unpack("V", fread($this->file, 4));
					$out[$cell] = $t[1];
					break;
				case 'f': //float 4 bytes
					$t = unpack("f", fread($this->file, 4));
					$out[$cell] = $t[1];
					break;
				case 'X': //unknown 1 byte
				case 'b': //unsigned integer 1 byte
				case 'l': //boolean 1 byte
					$t = unpack("C", fread($this->file, 1));
					$out[$cell] = $t[1];
					break;
				case 's': //string pointer 4 bytes
					$t = unpack("V", fread($this->file, 4));
					$ptr = $t[1];
					$s = "";
					if ($ptr != 0){
						if ($ptr > $this->sizeString) {
							$out[$cell] = "error: not a string field";
						}else{
							fseek($this->file, 4*5 + $this->sizeRecord*$this->countRecords + $ptr);

							while(($ch = fread($this->file, 1)) != chr(0))
								$s .= $ch;

							fseek($this->file, 4*5 + $this->sizeRecord*($row-1));
							$bytes = 0;
							for ($i = 1; $i <= $cell; $i++) {
								$char = $this->format[$i-1];
								if ($char == 'X' || $char == 'b' || $char == 'l') {
									$bytes += 1;
								} else {
									$bytes += 4;
								}
							}
							fseek($this->file, $bytes, SEEK_CUR);

							$out[$cell] = $s;
						}
					}else $out[$cell] = $s;
					break;
				default:
					break;
			}
		}
	}

	public function writeDBCInfo(){
		$result = $this->DB->selectRow("ANALYZE TABLE `_dbc_info_`");
		if($result['Msg_type'] == 'Error'){
			$this->DB->query("
				CREATE TABLE `_dbc_info_` (
				  `file` varchar(120) DEFAULT NULL,
				  `valid` int(10) DEFAULT NULL,
				  `rows` int(11) DEFAULT NULL,
				  `columns` int(11) DEFAULT NULL,
				  `size_record` int(11) DEFAULT NULL,
				  `size_string` int(11) DEFAULT NULL,
				  PRIMARY KEY (`file`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8
			");
		}
		$this->DB->query("
			REPLACE INTO `_dbc_info_` VALUES (?,?d,?d,?d,?d,?d)
		",$this->filename.'.dbc',$this->isValid? 1:0,$this->countRecords,$this->countFields,$this->sizeRecord,$this->sizeString);
		
		return;
	}

	function __destruct(){}
}

?>