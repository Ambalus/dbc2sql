<?php
/**
* 0x00 	char[4] always 'WDBC' 
* 0x04 	uint32 	nRecords - number of records in the file 
* 0x08 	uint32 	nFields - number of 4-byte fields per record 
* 0x0C 	uint32 	sizeRecord = nFields * 4 (not always true!) 
* 0x10 	uint32 	string block size
**/

namespace DBC;

use DbSimple_Generic;
use DBC\Format;

/**
 * Description of Parser
 *
 * @author Ambalus
 */
class Parser {
	/**
	 * Папка с dbc файлами
	 */
	private static $dir = 'DBFilesClient/';

	// unused
	private static $assert = [];

	/**
	 * Сигнатура заголовка
	 */
	const SIGNATURE = 'WDBC';

	/**
	 * Размер заголовка со служебной информацией
	 */
	const DEF_HEADER_SIZE = 20;
	const INDEX_PRIMORY_KEY = 1;

	/**
	 * Размер поля в байтах (по-умолчанию)
	 */
	const DEFAULT_FIELD_SIZE = 4;

	/**
	 * Расширение файла
	 */
	const EXTENSION = '.dbc';

	/**
	 * Префикс таблицы с данными
	 */
	const TBL_PREFIX_CONTENT = 'dbc_';

	/**
	 * Префикс таблицы со строковыми данными
	 */
	const TBL_PREFIX_STRING  = 'str_';

	// deprecated or unused
	const DB_TABLE_INFO = '_dbc_info_';

	// unused
	const ASSERT_FORMAT = '/Assert/Format.php';

	/*
	 * Open file handler
	 * 
	 * @var resource
	 */
	var $handler = null;

	/*
	 * XML handler (object DOMDocument)
	 * 
	 * @var resource
	 */
	var $XML = null;

	/*
	 * Current (open) file
	 * 
	 * @var array
	 */
	var $file = null; // unused ?
	

	/*
	 * File name
	 * 
	 * @var string
	 */
	var $name = null;

	/*
	 * File format
	 * 
	 * @var string
	 */
	var $format = '';
	var $field = null;
	var $header = null;

	/*
	 * Count records
	 * 
	 * @var integer
	 */
	var $countRecords = 0;

	/*
	 * Count fields
	 * 
	 * @var integer
	 */
	var $countFields = 0;

	/*
	 * Size records
	 * 
	 * @var integer
	 */
	var $sizeRecord = 0;

	/*
	 * Size string
	 * 
	 * @var integer
	 */
	var $sizeString = 0;

	/*
	 * @var integer
	 */
	var $sizeRecordFormat = 0;

	/*
	 * @var array
	 */
	var $tableStrings = [];

	/*
	 * Valid structure (flag)
	 * 
	 * @var bool
	 */
	var $isValid = false;
	var $isDefaultFormatSize = 0;

	static $attrField = [
		'count'=> '',
		'name' => '',
		'type' => '',
		'key'  => ''
	];

	var $_STR = array(
		'FILE_NOT_EXISTS' => 'Файл <b>%s</b> не найден',
		'INCORRECT_SIGNATURE' => 'Заголовок файла некорректен (%s)',
		'INCORRECT_FORMAT_FILE' => 'Ошибка в формате файла',
		'DIFF_COUNT_FIELDS' => 'fields count diff (dbc: %d, xml: %d)',
		'DIFF_SIZE_RECORDS' => 'Record size diff (dbc: %d, xml: %d)'
	);
	var $error = null;

	function __construct($file = null){
		global $dbDNS;

		$this->DB = DbSimple_Generic::connect($dbDNS);
		$this->DB->setErrorHandler("databaseErrorHandler");
		// $this->DB->setLogger("databaseLogHandler");
		// $this->DB->setIdentPrefix($db_config['db_prefix']);
		$this->initDB();

		if($file === null){
			return;
		}
		
		$this->name = str_replace(self::EXTENSION,'',$file);
		$this->handler = fopen(self::$dir.$this->name.self::EXTENSION, "rb");
		if(!$this->handler){
			$this->error = sprintf($this->_STR['FILE_NOT_EXISTS'], $file);
		}
	}

	private function resetAll(){
		$this->handler = null;
		$this->XML = null;
		$this->name = null;
		$this->format = '';
		$this->field = null;
		$this->header = null;
		$this->countRecords = 0;
		$this->countFields = 0;
		$this->sizeRecord = 0;
		$this->sizeString = 0;
		$this->sizeRecordFormat = 0;
		$this->tableStrings = [];
		$this->isValid = false;
		$this->isDefaultFormatSize = 0;
	}

	/**
	 * 
	 * @param string $filename имя файла
	 */
	public function set($filename = null){
		$this->resetAll();
		if($filename == null){
			trigger_error(sprintf($this->_STR['FILE_NOT_EXISTS'], $filename), E_USER_ERROR);
		}

		$this->name = str_replace(self::EXTENSION,'',$filename);
		$this->handler = fopen(self::$dir.$this->name.self::EXTENSION, "rb");

		if($this->handler){
			$row = $this->DB->selectRow("SELECT * FROM ?# WHERE `file`=?",self::DB_TABLE_INFO,$this->name);
			if(isset($row['format']) && $row['format'] != ''){
				$this->format = $row['format'];
			}
			if(isset($row['size_record']) && $row['size_record'] > 0){ // fix/hack
				$this->sizeRecord = $row['size_record'];
			}
		}else{
			trigger_error(sprintf($this->_STR['FILE_NOT_EXISTS'], $this->name), E_USER_ERROR);
		}
	}

	/**
	 * Сброс позиции указателя файла на начало файла
	 */
	private function resetHandler(){
		fseek($this->handler,0);
	}

	/**
	 * 
	 * @param type $write Ключ записи данных заголовка в БД
	 * @return boolean Возвращает false если заголовок файла не совпадает с сигнатурой
	 */
	public function getHeader($write = false){
		$this->resetHandler();

		$this->header = fread($this->handler, self::DEF_HEADER_SIZE);

		$h = substr($this->header,0,4);
		if($h !== self::SIGNATURE){
			return false;
		}

		$this->countRecords = (int) base_convert(bin2hex(strrev(substr($this->header,4,4))), 16, 10);
		$this->countFields = (int) base_convert(bin2hex(strrev(substr($this->header,8,4))), 16, 10);
		$this->sizeRecord = (int) base_convert(bin2hex(strrev(substr($this->header,12,4))), 16, 10);
		$this->sizeString = (int) base_convert(bin2hex(strrev(substr($this->header,16,4))), 16, 10);
		$this->isDefaultFieldSize = ($this->sizeRecord/$this->countFields == self::DEFAULT_FIELD_SIZE);
		$this->isValid = $this->checkSizeOf();

		if($this->isDefaultFieldSize){
			$this->format = $this->getDefaultFormat();
		}

		if($write === true){
			$this->writeHeaderInfo();
		}

		return true;
	}

	public function getHeaderInfo(){
		$this->getHeader();

		return [
			'countRecords' => $this->countRecords,
			'countFields' => $this->countFields,
			'sizeRecord' => $this->sizeRecord,
			'sizeString' => $this->sizeString,
			'isValid' => (int) $this->isValid,
			'isDefaultFieldSize' => (int) $this->isDefaultFieldSize,
			'format' => $this->format
		];
	}

	private function getDefaultFormat(){
		$f = '';
		for($i=0;$i<$this->countFields;$i++) {
			$f .= 'x';
		}
		return $f;
	}

	/*
	 * Считает размер строки по заданному формату
	 *
	 * @set integer $sizeRecordFormat
	 * @return void
	 */
	private function getFormatRecord($offset = false){
		$c = strlen($this->format);
		if($offset){
			$c = $offset;
		}

		$sizeRecordFormat = 0;
		for($i=0;$i<$c;$i++) {
			switch($this->format[$i]){
				case Format::FT_NA:
				case Format::FT_STRING:
				case Format::FT_FLOAT:
				case Format::FT_INT:
				case Format::FT_SORT:
				case Format::FT_IND:
					$sizeRecordFormat += 4;
					break;
				case Format::FT_NA_BYTE:
				case Format::FT_BYTE:
				case Format::FT_LOGIC:
					$sizeRecordFormat += 1;
					break;
				default:
					return false;
			}
		}
		if($offset){
			return $sizeRecordFormat;
		}
		$this->sizeRecordFormat = $sizeRecordFormat;

		return true;
	}

	private function checkSizeOf(){
		if(!$this->getFormatRecord()){
			return false;
		}

		if($this->sizeRecordFormat != $this->sizeRecord){
			return false;
		}

		// проверка на совпадение количества полей
		$c = strlen($this->format);
		if ($c != $this->countFields){
			return false;
		}

		return true;
	}

	private function getFields(){
		$this->field = array();
		if(!$this->XML){
			for($i=0;$i<$this->countFields;$i++){
				$this->field[$i] = array(
					'count' => 1,
					'name' => 'field'.($i+1),
					'type' => 'uint32',
					'key' => 0
				);
			}
			return;
		}
		
		foreach($this->XML->getElementsByTagName('field') as $field){
			$this->field[$field->getAttribute('id')] = array(
				'count' => (int) $field->getAttribute('count'),
				'name' => (string) $field->getAttribute('name'),
				'type' => (string) $field->getAttribute('type'),
				'key' => (int) $field->getAttribute('key')
			);
		}
	}

	public function createTable(){
		if($this->error != ''){
			return false;
		}

		if(!$this->countFields){
			return false;
		}

		$sql = "CREATE TABLE ?# (\n";
		$this->getFields(); // return $this->fields

		for($i=0; $i<$this->countFields; $i++) {
			$pkey = '';
			$field 	= $this->field[$i]['name'];
			if($this->field[$i]['key'] == self::INDEX_PRIMORY_KEY){ // prymory key
				$pkey = $field;
			}

			$sql .= "`$field`";
			switch($this->format[$i]){
				case Format::FT_FLOAT:
					$sql .= " FLOAT NOT NULL DEFAULT '0'";
					break;
				case Format::FT_IND:
					$sql .= " INT UNSIGNED NOT NULL DEFAULT '0'";
					break;
				case Format::FT_NA:
				case Format::FT_INT:
					$sql .= " INT NOT NULL DEFAULT '0'";
					break;
				case Format::FT_SORT:
					$sql .= " DOUBLE NOT NULL DEFAULT '0'";
					break;
				case Format::FT_NA_BYTE:
				case Format::FT_LOGIC:
					$sql .= " TINYINT UNSIGNED NOT NULL DEFAULT '0'";
					break;
				case Format::FT_BYTE:
					$sql .= " SMALLINT UNSIGNED NOT NULL DEFAULT '0'";
					break;
				case Format::FT_STRING:
					$sql .= " TEXT NOT NULL";
					break;
				default:
					$this->error = $this->_STR['INCORRECT_FORMAT_FILE'];
					return false;
			}
			$sql .= ($i+1==$this->countFields)? "\n":",\n";
		}

		
		if($pkey != ''){
			$sql .= ", PRIMARY KEY (`$pkey`)\n";
		}

		$sql .= sprintf(") ENGINE=InnoDB DEFAULT CHARSET=utf8  COMMENT='Export of %s';",$this->name);
		
		$this->DB->query("DROP TABLE IF EXISTS ?#",self::TBL_PREFIX_CONTENT.$this->name);
		$this->DB->query($sql,self::TBL_PREFIX_CONTENT.$this->name);

		if($this->sizeString > 1){
			$this->DB->query("DROP TABLE IF EXISTS ?#",self::TBL_PREFIX_STRING.$this->name);
			$this->DB->query("
				CREATE TABLE ?# (
					`index` INT UNSIGNED NOT NULL,
					`string` TEXT NOT NULL,
					PRIMARY KEY (`index`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8  COMMENT='Export of ".$this->name." (strings)';
			",self::TBL_PREFIX_STRING.$this->name);
		}
	}

	/*
	 * Проверка на валидность:
	 *  - валидность заголовка
	 *	- длина формата с количеством столбцов
	 *  - размер блока с данными
	 *  - размер строковых значений
	 */
	public function hasValidFile(){
		// если проверка проводилась - возвращаемся
		if($this->isValid){
			return true;
		}

		$this->isValid = false;

		// проверка на валидность заголовка
		if(!$this->getHeader()){
			return $this->isValid;
		}

		// проверка на валидность заданного формата данных
		if(!$this->getFormatRecord()){
			return $this->isValid;
		}
		// проверка на валидность размера блока с данными
		if(!$this->checkSizeOf()){
			return $this->isValid;
		}

		$this->isValid = true;

		return $this->isValid;
	}

	public function getData(){

		$this->createTable();
		if($this->sizeString > 1){
			$this->getStringTable(true);
		}
		for ($row = 0; $row < $this->countRecords; $row++) {
			$this->getRecord($row+1,$out);
			$this->DB->query("INSERT INTO ?# VALUES(?a)",self::TBL_PREFIX_CONTENT.$this->name,$out);
			unset($out);
		}

		if($this->sizeString > 1){
			$ix = 0;
			$arChunk = [];
			foreach($this->tableStrings as $key => $val){
				$ix++;
				$arChunk[] = "('$key',".$this->DB->escape($val).")";

				if($ix%1000 == 0){
					$this->DB->query("INSERT INTO ?# VALUES ".implode(',',$arChunk)."",self::TBL_PREFIX_STRING.$this->name);
					$arChunk = [];
				}
			}
			if(!empty($arChunk)){
				$this->DB->query("INSERT INTO ?# VALUES ".implode(',',$arChunk)."",self::TBL_PREFIX_STRING.$this->name);
			}
		}

		fclose($this->handler);
		return true;
	}

	public function getStringTable($reset = false){
		if(!$reset){
			return $this->tableStrings;
		}

		$this->resetHandler();
		fseek($this->handler, self::DEF_HEADER_SIZE + $this->sizeRecord*$this->countRecords+1);
		$this->tableStrings = [];
		$i = 0;
		$j = 1;
		$text = '';
		while(!feof($this->handler)){
			$ch = fread($this->handler, 1);
			$i++;
			if($ch === chr(0)){
				$this->tableStrings[$j] = $text;
				$j = $i+1;
				$text = '';
			}else{
				$text .= $ch;
			}
		}
		fseek($this->handler, self::DEF_HEADER_SIZE);

		return $this->tableStrings;
	}

	public function getRecord($row,&$out){
		// перемещаем курсор на начало строки $row ($row не всегда первая строка)
		fseek($this->handler, self::DEF_HEADER_SIZE + $this->sizeRecord*($row-1));

		for($cell = 1; $cell <= $this->countFields; $cell++) {
			if(!isset($this->format[$cell-1])){
				return;
			}
			switch ($this->format[$cell-1]) {
				case Format::FT_NA:
				case Format::FT_INT:
				case Format::FT_IND:
					$t = unpack("V", fread($this->handler, 4));
					$out[$cell] = $t[1];
					break;
				case Format::FT_SORT:
				case Format::FT_FLOAT:
					$t = unpack("f", fread($this->handler, 4));
					$out[$cell] = $t[1];
					break;
				case Format::FT_NA_BYTE:
				case Format::FT_BYTE:
				case Format::FT_LOGIC:
					$t = unpack("C", fread($this->handler, 1));
					$out[$cell] = $t[1];
					break;
				case Format::FT_STRING:
					$t = unpack("V", fread($this->handler, 4));
					$ptr = $t[1];
					$s = $this->getString($row,$cell,$ptr);
					$out[$cell] = $s;
					break;
				default:
					break;
			}
		}
	}

	private function getString($row,$cell,$index){
		if(isset($this->tableStrings[$index])){
			return $this->tableStrings[$index];
		}

		$text = "";
		if($index > 0){
			if($index > $this->sizeString){
				return "ERROR: not a string field";
				// return $index;
			}
			// move to string table
			fseek($this->handler, self::DEF_HEADER_SIZE + $this->sizeRecord*$this->countRecords + $index);
			while(($ch = fread($this->handler, 1)) != chr(0)){
				$text .= $ch;
			}

			// move to start current row
			fseek($this->handler, self::DEF_HEADER_SIZE + $this->sizeRecord*($row-1));

			// seek to offset position
			$offset = $this->getFormatRecord($cell);
			fseek($this->handler, $offset, SEEK_CUR);
		}

		return $text;
	}

	public function getLastRecord(){
		return $this->countRecords;
	}

	private function initDB(){
		$result = $this->DB->selectRow("SHOW TABLES LIKE '_dbc_info_'");
		if(!$result){
			$this->DB->query("
				CREATE TABLE `_dbc_info_` (
				  `file` varchar(120) DEFAULT NULL,
				  `format` varchar(80) DEFAULT NULL,
				  `valid` int(10) DEFAULT NULL,
				  `rows` int(11) DEFAULT NULL,
				  `columns` int(11) DEFAULT NULL,
				  `size_record` int(11) DEFAULT NULL,
				  `size_string` int(11) DEFAULT NULL,
				  PRIMARY KEY (`file`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8
			");
			/*
			 * // UNUSED
			self::$assert = require(dirname(__FILE__).self::ASSERT_FORMAT);
			foreach(self::$assert as $k => $v){
				if($this->DB->selectCell("SELECT file FROM ?# WHERE file=?",self::DB_TABLE_INFO,$k)){
					$this->DB->query("REPLACE INTO ?# (`file`,`format`,`valid`) VALUES (?,?,1)",self::DB_TABLE_INFO,$k,$v);
				}else{
					$this->DB->query("INSERT IGNORE INTO ?# (`file`,`format`) VALUES (?,?)",self::DB_TABLE_INFO,$k,$v);
				}
			}
			 */
		}

		$result2 = $this->DB->selectRow("SHOW TABLES LIKE '_dbc_fields_'");
		if(!$result2){
			$this->DB->query("
				CREATE TABLE `_dbc_fields_` (
				  `filename` varchar(120) DEFAULT NULL,
				  `id` int(12) DEFAULT NULL,
				  `field` varchar(120) DEFAULT NULL,
				  `isKey` tinyint(11) DEFAULT NULL,
				  `count` int(11) DEFAULT NULL,
				  `type` varchar(120) DEFAULT NULL,
				  `type_string` varchar(120) DEFAULT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8
			");
		}
	}

	// deprecated
	/*private function writeHeaderInfo(){
		$this->initDB();

		$res = $this->DB->selectRow("SELECT * FROM ?# WHERE `file`=?",self::DB_TABLE_INFO,$this->name);
		if(isset($res['file'])){
			$this->DB->query("
				UPDATE ?# SET 
				`valid`=?,
				`rows`=?d,
				`columns`=?d,
				`size_record`=?d,
				`size_string`=?d
				WHERE `file`=?
			",self::DB_TABLE_INFO,$this->isValid ? 1 : 0,$this->countRecords,$this->countFields,$this->sizeRecord,$this->sizeString,$this->name);
		}else{
			$this->DB->query("
				REPLACE INTO ?# VALUES (?,?,?d,?d,?d,?d,?d)
			",self::DB_TABLE_INFO,$this->name,$this->format,$this->isValid ? 1 : 0,$this->countRecords,$this->countFields,$this->sizeRecord,$this->sizeString);
		}
		return;
	}*/

}

