<?php

interface iDBCparser{
	function set($name,$path,$build);
	function getHeader();
	function GetStructFields();
	function CheckFormat();
	function getFormatRecord($offset);
	function getData($limit,$offset);
	function getDataTable($limit,$offset);
	function getRecord($row,&$out);
	/*private function getString($row,$cell,$index);*/
	/*private function _end();*/
	function showErrors();

	// SQL data
	function _getTableForSQL();
	function _getReferenceTable();
	function _getForeignKeysForSQL();
	/*private function createForeignKeysForSQL();*/
	function getDataSQL($element_id,$gzip,$foreign_keys,$data_insert);

	// PTCH data
	function getHeaderPTCH();
	function patchDBC($source,$patch,$exec,$name);

	// extends method DBCimport
	// function createTable();
}

/**
* 0x00 	char[4] always 'WDBC' 
* 0x04 	uint32 	nRecords - number of records in the file 
* 0x08 	uint32 	nFields - number of 4-byte fields per record 
* 0x0C 	uint32 	recordSize = nFields * 4 (not always true!) 
* 0x10 	uint32 	string block size
**/

define('DEF_HEADER_SIZE',20);
if(!defined('INDEX_PRIMORY_KEY')){
	define('INDEX_PRIMORY_KEY',1);
}
/*implements iDBC*/
class DBCparser implements iDBCparser
{
/**
* 0x00 	char[4] always 'WDBC' 
* 0x04 	uint32 	nRecords - number of records in the file 
* 0x08 	uint32 	nFields - number of 4-byte fields per record 
* 0x0C 	uint32 	recordSize = nFields * 4 (not always true!) 
* 0x10 	uint32 	string block size
**/

	/* constants */
	const DBC_SIGN = "WDBC";
	const PATCH_SIGN = "PTCH";
	const DEFAULT_BUILD_CLIENT = 15890;

	static $_bases_formats = array(
		"n", // FT_NUMERIC
		"i", // FT_INT
		"u", // FT_UINT
		"f", // FT_FLOAT
		"l", // FT_LOGIC
		"b", // FT_BYTE
		"x", // FT_NA
		"X", // FT_NA_BYTE
		"s", // FT_STRING
	);

	/*vars */
	var $name = "";
	var $path = "";
	var $file = null; // resurce
	var $format = "";
	var $build = null;
	var $header = array();
	var $recordCount = 0;
	var $fieldCount = 0;
	var $recordSize = 0;
	var $stringSize = 0;
	var $is_valid = false;
	var $data = array();
	var $_data = array();

	/* SQL */
	var $sql_tbl_name = false;

	/* errors */
	var $arErrors = array();

	/* out data */
	var $info = array(
		// основные данные в заголовке
		"NAME" => "",
		"SIGN" => "",
		"FORMAT_DATA" => "",
		"PATH" => "",
		"RECORD_COUNT" => "",
		"FIELD_COUNT" => "",
		"RECORD_SIZE" => "",
		"STRING_SIZE" => "",
		// проверка данных, доп сведения, не содержится в заголовке
		"VALID_FILE_SIZE_FULL" => "", // проверка на совпадение размера файла с данными из заголовка
		"4BYTES_BY_FIELD"	=> "", // $this->fieldCount * 4 bytes === $this->recordSize ? Y : N
		"VALID_FIELD_SIZE" => "",
	);

	function __construct($arFormat = null,$name = null){
		if(!is_array($arFormat)){
			throw new Exception("Константы класса/интерфейса ".__CLASS__." не определены");
		}

		// определяем константы
		foreach($arFormat as $key => $val){
			if(!defined($key)){
				define($key,$val);
			}
		}
		// преверяем, не пропустили ли мы базовые константы
		// foreach(self::$_bases_formats as $fmt){
			// if(!defined("$fmt")){
				// throw new Exception("Отсутствует по крайней мере 1 базовя константа (".$fmt.")");
			// }
		// }

		if($name){
			$this->name = $name;
		}
	}

	/*
	* var(in): filename, path (path to file), build
	* var(set): (string) name, (string) path, (resurce) file, (array) headerData, (int) build
	*/
	public function set($name,$path = "", $build = null){
		if($this->name == "" && $name == ""){
			throw new Exception("Не заданно название файла");
		}
		$this->name = $name;
		// used or DBC files only
		$this->path = $path;

		if(file_exists($_SERVER["DOCUMENT_ROOT"].$this->path)){
			$this->file = fopen($_SERVER["DOCUMENT_ROOT"].$this->path, "rb");
		}else{
			$this->arErrors[] = array(
				"DBC_FILE_NOT_FOUND",
				"Подключаемый DBC файл не найден",
				__FUNCTION__
			);
			return false;
		}

		$this->info["NAME"] = $this->name;
		$this->info["PATH"] = $this->path;

		if($build){
			$this->build = $build;
			$this->info["BUILD"] = $build;
		}
	}

	/*
	* var(in): 
	* var(set): (array) headerData
	* return: (bool) is_valid
	*/
	public function getHeader(){ // DBC
		fseek($this->file, 0);

		$h = substr(fread($this->file,4),0,4); // 0+4 bytes

		if($h !== self::DBC_SIGN){ // Проверка заголовка
			$this->info["VALID_FIELD_SIZE"] = "N";
			$this->info["VALID_FIELD_SIZE_FULL"] = "N";
			$this->arErrors[] = array(
				"BROKEN_HEADER_DBC",
				"Некорректный заголовок файла (".$h.")",
				__FUNCTION__
			);
			return false;
		}

		$this->info["SIGN"] = $h;

		// read next 16 bytes header
		$values = array();
		for($i=4;$i<20;$i+=4){ // 4+4*4 bytes
			$str = fread($this->file,4);
			$val = unpack("i",$str);
			$values[] = $val[1];
		}
		list($this->recordCount,$this->fieldCount,$this->recordSize,$this->stringSize) = $values;
		$this->info["RECORD_COUNT"] = $this->recordCount;
		$this->info["FIELD_COUNT"] = $this->fieldCount;
		$this->info["RECORD_SIZE"] = $this->recordSize;
		$this->info["STRING_SIZE"] = $this->stringSize;

		// если каждое поле по 4 байта
		$this->info["4BYTES_BY_FIELD"] = ($this->fieldCount*4 == $this->recordSize)? "Y" : "N";

		/* если перед вызовом данного метода задано свойство $format, то выполняем проверку */
		$this->info["VALID_FIELD_SIZE"] = "N";		
		if($this->CheckFormat()){
			$this->info["FORMAT_DATA"] = $this->format;
			$size = $this->getFormatRecord(null);
			if($size == $this->recordSize){
				$this->info["VALID_FIELD_SIZE"] = "Y";
			}else{
				$this->arErrors[] = array(
					"INCORRECT_ROW_SIZE",
					"Не корректный размер записи  (должно быть: ".$this->recordSize.", расчитано: ".$size.")",
					__FUNCTION__
				);
			}
		}

		// VALID_FILE_SIZE_FULL
		$data_size = DEF_HEADER_SIZE + $this->recordCount*$this->recordSize + $this->stringSize;
		$fsize = filesize($_SERVER["DOCUMENT_ROOT"].$this->path);
		$this->info["VALID_FILE_SIZE_FULL"] = ($data_size == $fsize) ? "Y" : "N";

		$this->is_valid = ($this->info["VALID_FIELD_SIZE"] == "Y" && $this->info["VALID_FILE_SIZE_FULL"] == "Y");

		return $this->is_valid;
	}

	/*
	* var(in): (bool) full_data
	* var(set): (array) fieldStruct
	*/
	public function GetStructFields(){
		global $el,$section;

		if(!empty($this->fieldStruct))
			return;

		if(!$this->build){
			$this->arErrors[] = array(
				"BUILD_CLIENT_NOT_FOUND",
				"Не задана версия клиента. Устанавливаем по дефолту (".self::DEFAULT_BUILD_CLIENT.")",
				__FUNCTION__
			);
			$this->build = self::DEFAULT_BUILD_CLIENT;
		}

		// Выбираем все весрии клиентов меньше заданной
		$arFilter = array("IBLOCK_ID" => 3,"<=CODE" => $this->build,"ACTIVE" => "Y");
		$resPatch = CIBlockElement::GetList(array("SORT"=>"ASC"),$arFilter, false, false);
		$structBuilds = array();
		while($arPatch = $resPatch->GetNext()){
			$structBuilds[] = $arPatch["ID"];
		}

		// фильтруем поля данных в зависимости от версии клиента
		$arFilter = array("IBLOCK_ID"=> 2,"SECTION_CODE"=>strtolower($this->name),"PROPERTY_struct_add" => $structBuilds);
		$db_elemens = $el->GetList(array("SORT"=>"ASC"), $arFilter, false, false);

		$this->fieldStruct = array();
		while($obElement = $db_elemens->GetNextElement()){
			$_el = $obElement->GetFields();
			$_el["PROPERTIES"] = $obElement->GetProperties();
			$fk = array(
				"NAME" => $_el["NAME"],
				"SORT" => $_el["SORT"],
				"PRIMARY_KEY" => ($_el["PROPERTIES"]["INDEX_PRIMORY_KEY"]["VALUE_ENUM"][0] == "Y")? "Y" : "N",
				"TYPE" => $_el["PROPERTIES"]["struct_type"]["VALUE"],
				"LINK_OTHER_TBL" => false,
			);
			if($_el["PROPERTIES"]["struct_indx_othr_tbl"]["VALUE"]){
				$res = $el->GetByID($_el["PROPERTIES"]["struct_indx_othr_tbl"]["VALUE"]);
				$ar = $res->GetNext();
				$resSection = $section->GetByID($ar["IBLOCK_SECTION_ID"]);
				$arSection = $resSection->GetNext();
	
				$fk["LINK_OTHER_TBL"] = array(
					"INDEX_NAME" => "idx_".strtolower($this->name)."_".strtolower($_el["NAME"]),
					"REFERENCE_TABLE" => $arSection["NAME"],
					"REFERENCE_FIELD"	=> $ar["NAME"]
				);
			}
			$this->fieldStruct[] = $fk;
		}		
	}

	public function CheckFormat(){
		if(trim($this->format) == ""){
			$this->arErrors[] = array(
				"FORMAT_NOT_FOUND",
				"Отсутствует формат данных",
				__FUNCTION__
			);
			return false;
		}
		$c = strlen($this->format);

		for($i=0;$i<$c;$i++) {
			if(!in_array($this->format[$i],self::$_bases_formats)){
				$arFilter = array("IBLOCK_ID" => 9,"ACTIVE" => "Y","PROPERTY_sign" => $this->format[$i]);
				$arSelect = array("ID","NAME");
				$res = CIBlockElement::GetList(array("SORT"=>"ASC"),$arFilter,false,false,$arSelect);
				$ar = $res->GetNext();
				if(!isset($ar["NAME"]) || !defined($ar["NAME"])){
					$this->arErrors[] = array(
						"UNKNOWN_FIELD_FORMAT",
						"Неизвестный фрмат поля (индекс: ".($i+1).", значение: ".$this->format[$i].")",
						__FUNCTION__
					);
					return false;
				}
			}
		}
		return true;
	}

	/*
	* var(in):
	* var(out): (int) size
	*/
	public function getFormatRecord($offset = false){
		$c = strlen($this->format);
		if($offset){
			$c = $offset;
		}

		$size = 0;
		for($i=0;$i<$c;$i++) {
			if(in_array($this->format[$i],self::$_bases_formats)){
				switch($this->format[$i]){
					case FT_NUMERIC:
					case FT_INT:
					case FT_UINT:
					case FT_STRING:
					case FT_FLOAT:
					case FT_SORT:
					case FT_NA:
						$size += 4;
						break;
					case FT_NA_BYTE:
					case FT_BYTE:
					case FT_LOGIC:
						$size += 1;
						break;
					default:
						break;
				}
			}else{
				$arFilter = array("IBLOCK_ID" => 9,"ACTIVE" => "Y","PROPERTY_sign" => $this->format[$i]);
				$arSelect = array("ID","NAME","PROPERTY_size");
				$res = CIBlockElement::GetList(array("SORT"=>"ASC"),$arFilter,false,false,$arSelect);
				$ar = $res->GetNext();
				if(!isset($ar["NAME"]) || !defined($ar["NAME"])){
					$this->arErrors[] = array(
						"UNKNOWN_FIELD_FORMAT",
						"Неизвестный фрмат поля (индекс: ".($i+1).", значение: ".$this->format[$i].")",
						__FUNCTION__
					);
					return false;
				}
				if(isset($ar["PROPERTY_SIZE_VALUE"]) && $ar["PROPERTY_SIZE_VALUE"] > 0){
					$size += intval($ar["PROPERTY_SIZE_VALUE"]);
				}else{
					$this->arErrors[] = array(
						"UNKNOWN_FIELD_SIZE",
						"Неопределенный размер поля (индекс: ".($i+1).", значение: ".$this->format[$i].")",
						__FUNCTION__
					);
					return false;
				}
			}
		}
		return $size;
	}

	/*
	*	if first field ID - sorted data by this field by asc
	* var(in): (int) limit, (int) offset
	* var(set): (array) _data
	*/
	public function getData($limit = 1000,$offset = 1){
		$this->_data = array();

		if(!$this->is_valid){
			return false;
		}
		if(($size = $this->getFormatRecord()) != $this->recordSize){
			$this->arErrors[] = array(
				"RECORD_SIZE_FORMAT_UNCORRECT",
				"Не корректная размерность формата строки (recordSize: ".$this->recordSize." bytes, formatRecordSize: ".$size." bytes)",
				__FUNCTION__
			);
			return false;
		}

		if($limit){
			$offset_count = ($offset-1)*$limit + 1;
			$count = $limit*$offset;
			if($count > $this->recordCount){
				$count = $this->recordCount;
			}
		}else{
			$offset_count = 1;
			$count = $this->recordCount;
		}

		$this->GetStructFields();
		for($row = $offset_count; $row <= $count; $row++) {
			if($this->getRecord($row,&$out)){
				$dataRow = array();
				foreach($out as $i => $val){
					//$key = (isset($this->fieldStruct[$i]["NAME"]))? $this->fieldStruct[$i]["NAME"] : "field".$i;
					$dataRow[$i] = $val;
				}
				$this->_data[$row] = $dataRow;
				unset($out);
			}
		}

		// если первое поле - ключ, то сортируем данные по возрастанию
		if($this->format[0] == "n" || $this->fieldStruct[0]["PRIMARY_KEY"] == "Y"){
			// Функция сравнения
			/*function cmp($a, $b) {
				if ($a[1] == $b[1]) {
					return 0;
				}
				return (intval($a[1]) < intval($b[1])) ? -1 : 1;
			}
			usort($this->_data, 'cmp');*/
		}

		return;
	}

	public function getDataTable($limit = 100,$offset = 1){
		$this->data = array(
			"FIELDS"	=> array(),
			"ITEMS"		=> array(),
		);

		// get fields name
		$this->GetStructFields();
		$fields = array();
		for($i=0;$i<$this->fieldCount;$i++){
			if(isset($this->fieldStruct[$i]["NAME"])){
				$fields[$i+1] =  $this->fieldStruct[$i];
			}else{
				$fields[$i+1] = array(
					"NAME" => "field".$i,
					"SORT" => ($i+1)*10,
					"PRIMARY_KEY" => "N",
					"TYPE" => "unknown",
					"LINK_OTHER_TBL" => ""
				);
			}
		}
		$this->data["FIELDS"] = $fields;
		unset($fields);

		$this->getData($limit,$offset);
		$this->data["ITEMS"] = $this->_data;

		return;
	}

	/*
	* var(in): (int) $row
	* var(set): (array) _data
	*/
	public function getRecord($row,&$out){

		// перемещаем курсор на начало строки $row ($row не всегда первая строка)
		fseek($this->file, DEF_HEADER_SIZE + $this->recordSize*($row-1));

		for ($cell = 1; $cell <= $this->fieldCount; $cell++) {
			if(in_array($this->format[$cell-1],self::$_bases_formats)){
				switch($this->format[$cell-1]){
					case FT_NUMERIC:
						$t = unpack("V", fread($this->file, 4));
						$out[$cell] = $t[1];
						break;
					case FT_INT:
					case FT_UINT:
						$t = unpack("V", fread($this->file, 4));
						$out[$cell] = $t[1];
						break;
					case FT_FLOAT:
						$t = unpack("f", fread($this->file, 4));
						$out[$cell] = $t[1];
						break;
					case FT_NA_BYTE:
					case FT_BYTE:
					case FT_LOGIC:
						$t = unpack("C", fread($this->file, 1));
						$out[$cell] = $t[1];
						break;
					case FT_NA:
						$t = unpack("V", fread($this->file, 4));
						$out[$cell] = $t[1];
						break;
					case FT_STRING:
						$t = unpack("V", fread($this->file, 4));
						$ptr = $t[1];
						$s = $this->getString($row,$cell,$ptr);
						$out[$cell] = $s;
						break;
					default:
						$this->arErrors[] = array(
							"UNKNOWN_FIELD_FORMAT",
							"Неизвестный фрмат поля (индекс: ".($i+1).", значение: ".$this->format[$i].")",
							__FUNCTION__
						);
						return false;
						break;
				}
			}else{
				// необходимо изменить
				switch($this->format[$cell-1]){
					case FT_BIG_BYTE: // used in Achievement_Criteria.dbc?
						$t = unpack("C", fread($this->file, 8));
						$out[$cell] = $t[1];
						break;
					case FT_BYTEMASK:
						$d = bin2hex(fread($this->file, 4));
						$d2 = array();
						for($tmp = 0;$tmp < 4;$tmp++){
							$d2[] = "0x".substr($d,$tmp*2,2);
						}
						$mask = implode(" | ",$d2);

						$out[$cell] = $mask;
						break;
					case FT_HEXMASK:
						$d = unpack("V",fread($this->file, 4));
						$mask = $d[1];
						// $out[$cell] = $d[1]." | 0x".dechex($d[1]);
						$out[$cell] = "0x".dechex($d[1]);
						break;
					case FT_MIDDLE_BYTE: // used in CharBaseInfo.dbc?
						$t = unpack("C", fread($this->file, 2));
						$out[$cell] = $t[1];
						break;
				default:
					$this->arErrors[] = array(
						"UNKNOWN_FIELD_FORMAT",
						"Неизвестный фрмат поля (индекс: ".($cell+1).", значение: ".$this->format[$cell-1].")",
						__FUNCTION__
					);
					return false;
					break;
				}
			}
		}
		return true;
	}

	private function getString($row,$cell,$index){
		$text = "";
		if($index > 0){
			if($index > $this->stringSize){
				return "ERROR: not a string field";
				// return $index;
			}
			// move to string table
			fseek($this->file, 4*5 + $this->recordSize*$this->recordCount + $index);
			while(($ch = fread($this->file, 1)) != chr(0))
				$text .= $ch;

			// move to start current row
			fseek($this->file, 4*5 + $this->recordSize*($row-1));

			$offset_bytes = $this->getFormatRecord($cell);
			fseek($this->file, $offset_bytes, SEEK_CUR);
		}

		return $text;
	}

	/*private function _end(){
		// fclose($this->file);
	}*/

	public function showErrors(){
		print_r($this->arErrors);
	}

/**********************************************/
/*************** DBC DATA EXPORT **************/
/**********************************************/

	public function _getTableForSQL(){
		return $this->add_table;
	}

	public function _getReferenceTable(){
		return $this->reference_tables;
	}

	public function _getForeignKeysForSQL(){
		return $this->add_foreign_keys;
	}

	private function createForeignKeysForSQL(){
		$rkey = array();
		for($i=0; $i<$this->fieldCount; $i++) {
			if($this->fieldStruct[$i]["LINK_OTHER_TBL"]){
				$rkey[] = sprintf(
					"\nADD FOREIGN KEY (`%s`) REFERENCES `dbc_%s` (`%s`)\n\t ON UPDATE CASCADE\n\t ON DELETE CASCADE",
					$this->fieldStruct[$i]["NAME"],
					strtolower($this->fieldStruct[$i]["LINK_OTHER_TBL"]["REFERENCE_TABLE"]),
					$this->fieldStruct[$i]["LINK_OTHER_TBL"]["REFERENCE_FIELD"]
				);
			}
		}
		$this->add_foreign_keys = sprintf(
			"\nALTER TABLE\n\t`dbc_%s`%s\n;",
			$this->sql_tbl_name,
			implode(",",$rkey)
		);
		
		return;
	}

	public function getDataSQL($elementID,$gzip = false,$data_insert = true,$foreign_keys = false){
		global $el;

		$sql_sprite = array(
			"SPRITE_KEY" => "\n\tKEY `%s` (`%s`)",
			"SPRITE_REFERENCES" => "\nADD FOREIGN KEY (`%s`) REFERENCES `dbc_%s` (`%s`)\n\t ON UPDATE CASCADE\n\t ON DELETE CASCADE",
			"SPRITE_INSERT" => "\nINSERT INTO `dbc_%s` VALUES %s;",
			"SPRITE_CREATE_TABLE" => "\nDROP TABLE IF EXISTS `dbc_%s`;\nCREATE TABLE `dbc_%s` (%s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8  COMMENT='Export of %s';\n",
		);
		$sql = array(
			"INFO" => "",
			"TABLE" => "",
			"DATA" => "",
			"FOREIGN_KEYS" => ""
		);
		$this->add_foreign_keys = false;
		$this->add_table = false;
		$this->fieldStruct = false;
		$this->reference_tables = false;

		$db_element = $el->GetByID($elementID);
		$obElement = $db_element->GetNextElement();
		$_el = $obElement->GetFields();
		$_el["PROPERTIES"] = $obElement->GetProperties();

		$this->id = $_el["ID"];

		// build
		if(!$this->build){
			$ex = explode("_",$_el["CODE"]);
			$this->build = array_pop($ex);
		}
		$path = CFile::GetPath($_el["PROPERTIES"]["dbc_file"]["VALUE"]);
		$this->set($_el["NAME"],$path);
		$this->format = $_el["PROPERTIES"]["dbc_format"]["VALUE"];
		$this->getHeader();

		if(!$this->CheckFormat()){
			return false;
		}

		// version
		$ar_res = $el->GetById($_el["PROPERTIES"]["dbc_build_client"]["VALUE"]);
		$ar = $ar_res->GetNext();
		$this->version = $ar["NAME"];

		$this->_data = array();

/*********** CREATE TABLE ***************/
		$sql["INFO"] = "/*
DBC2SQL PHP Tools Data Transfer\n
Export date			: ".gmdate("d.m.Y H:i:s")." GMT
Client Version		: ".$this->version."
Client Build		: ".$this->build."
File name			: ".$this->name."
Count records		: ".$this->recordCount."
Count fields		: ".$this->fieldCount."
Size record			: ".$this->recordSize."
Size string			: ".$this->stringSize."
*/\n\n
SET FOREIGN_KEY_CHECKS=0;
SET AUTOCOMMIT=0;
\n";


		$table_mask = "%s%s"; // +add prefix
		if(!$this->sql_tbl_name){
			$this->sql_tbl_name = sprintf($table_mask,strtolower($this->name),$gzip? "" : "_".$this->build);
		}

		$this->GetStructFields();
		$fields = array();
		// $fkey = array();
		$pkey = false;
		for($i=0; $i<$this->fieldCount; $i++) {
			if(isset($this->fieldStruct[$i])){
				$field 	= $this->fieldStruct[$i]["NAME"];
				
				if($this->fieldStruct[$i]["PRIMARY_KEY"] == "Y") // primary key
					$pkey = $field;

				if($this->fieldStruct[$i]["LINK_OTHER_TBL"]){ // foreign key
					/*$fkey[] = sprintf(
						$fkey_struct["SPRITE_KEY"],
						$this->fieldStruct[$i]["LINK_OTHER_TBL"]["INDEX_NAME"],
						$this->fieldStruct[$i]["NAME"]
					);*/
					$this->reference_tables[] = $this->fieldStruct[$i]["LINK_OTHER_TBL"]["REFERENCE_TABLE"];
				}
			}else{
				$field 	= "unk".($i+1);
			}
			switch($this->format[$i]){
				case FT_NUMERIC:
					$type = " INT unsigned NOT NULL"; // AUTO_INCREMENT
					break;
				case FT_UINT:
					$type = " INT unsigned NOT NULL";
					break;
				case FT_NA:
				case FT_INT:
					$type = " INT NOT NULL";
					break;
				case FT_FLOAT:
					$type = " FLOAT NOT NULL";
					break;
				case FT_MIDDLE_BYTE:
				case FT_BIG_BYTE:
					$type = " INT NOT NULL";
					break;
				case FT_SORT:
					$type = " DOUBLE NOT NULL";
					break;
				case FT_LOGIC:
				case FT_NA_BYTE:
					$type = " TINYINT UNSIGNED NOT NULL";
					break;
				case FT_BYTE:
					$type = " SMALLINT UNSIGNED NOT NULL";
					break;
				case FT_BYTEMASK:
				case FT_STRING:
				default:
					$type = " TEXT NOT NULL";
					break;
			}
			$fields[] = "\n\t`".trim($field)."`".$type;
		}

		if($pkey){
			$fields[] = "\n\tPRIMARY KEY (`$pkey`)";
		}

		// create table
		$this->add_table = sprintf(
			$sql_sprite["SPRITE_CREATE_TABLE"],
			$this->sql_tbl_name,
			$this->sql_tbl_name,
			implode(",",$fields),
			$this->name
		);
		$sql["TABLE"] = $this->add_table;

		// add foreign keys
		if($this->reference_tables){
			$this->reference_tables = array_unique($this->reference_tables);
			$this->createForeignKeysForSQL();
			if($foreign_keys){
				$sql["FOREIGN_KEYS"] = $this->add_foreign_keys;
			}
		}

		// add content
		if($data_insert){
			$limit_insert = 500;

			$this->getData(false);
			$rows = array();
			foreach($this->_data as $i => $row){
				foreach($row as $j => $cell){
					$row[$j] = mysql_escape_string($cell);
					if(!is_numeric($row[$j])){
						$row[$j] = "\"".$row[$j]."\"";
					}
				}
				$rows[] = "(".implode(",",$row).")";
			}
			$rows_chunk = array_chunk($rows,$limit_insert);
			unset($rows);

			$sql["DATA"] = "/*\n----------------- DATA ----------------- */";
			$sql["DATA"] .= "START TRANSACTION;";
			foreach($rows_chunk as $key => $chunk){
				// if($key > 0)
					// $sql["DATA"] .= "\nSAVEPOINT point".$key.";";

				$sql["DATA"] .= sprintf(
					$sql_sprite["SPRITE_INSERT"],
					$this->sql_tbl_name,
					implode(",",$chunk)
				);
			}
			$sql["DATA"] .= "\nCOMMIT;";
			$sql["DATA"] .= "\n/*\n-------------- END DATA -------------- */";
		}
/***************************/

		// if gzip == true, then return .gz file
		// unset($sql["TABLE"]);
		$out_str = implode("\n",$sql);
		$path = "/upload/tmp/";
		$file = "dbc_".$this->sql_tbl_name.".sql";
		$fout = fopen($_SERVER["DOCUMENT_ROOT"].$path.$file,"wb");
		fwrite($fout,$out_str);
		fclose($fout);

		if($gzip){
			/********* GZIP ***************/	
			$gz_file = "dbc_".strtolower($this->name)."_".$this->build.".tar.gz";
			$fput = $_SERVER["DOCUMENT_ROOT"].$path.$gz_file;
			// создаем объект, флаг сжатия установлен
			$oArchiver = new CArchiver($fput, true);

			chdir($_SERVER["DOCUMENT_ROOT"].$path);
			$oArchiver->add($file);

			// вывод ошибок если есть
			$this->arErrors = &$oArchiver->GetErrors();

			unlink($_SERVER["DOCUMENT_ROOT"].$path.$file);
			/****************************/
			return $fput;
		}else{
			// unset($sql["INFO"]);
			return $path.$file;
		}
	}

/**********************************************/
/***************** PATCH DATA *****************/
/**********************************************/

	public function getHeaderPTCH(){
		/* struct header information */
		// $this->headerDataHex["PTCH"] = array( // 16 bytes, 4 fields
			// "SIGN" => "", // 4 bytes, string
			// "patchSize" => "", // 4 bytes, packed int
			// "sizeBefore" => "", // 4 bytes, packed int
			// "sizeAfter" => "", // 4 bytes, pachecd int
		// );
		// $this->headerDataHex["MD5_"] = array( // 40 bytes, 4 fields
			// "SIGN" => "", // 4 bytes, string
			// "md5BlockSize" => "", // 4 bytes, packed int
			// "md5sizeBefore" => "", // 16 bytes, string hex
			// "md5sizeAfter" => "", // 16 bytes, string hex
		// );
		// $this->headerDataHex["XFRM"] = array( // 12 bytes, 3 fields
			// "SIGN" => "", // 4 bytes, string
			// "xfrmBlockSize" => "", // 4 bytes, packed int
			// "xfrmType" => "", // 4 bytes, string
		// );
		// $this->headerDataHex["BSDIFF"] = array( // 32 bytes
			// "SIGN" => "", // 8 bytes, string
			// "ctrlBlockSize" => "", // 8 bytes, packed bigint
			// "diffBlockSize" => "", // 8 bytes, packed bigint
			// "sizeAfter" => "", // 8 bytes, packed bigint
		// );

		/* get source data */
		$this->ptchHandler = fopen($_SERVER["DOCUMENT_ROOT"].$this->ptchFile,"rb");

		/* convert source data */
		// PTCH
		fseek($this->ptchHandler,0);
		if(($sign = fread($this->ptchHandler, 4)) == "PTCH"){
			for($i=0;$i<3;$i++){
				$x = unpack("V",fread($this->ptchHandler,4));
				$v[$i] = $x[1];
			}
			$this->headerDataHex["PTCH"] = array(
				"SIGN" 		=> $sign, // 4 bytes, string
				"patchSize" => $v[0], // 4 bytes, packed int
				"sizeBefore"=> $v[1], // 4 bytes, packed int
				"sizeAfter" => $v[2], // 4 bytes, packed int
			);
		}else{
			$this->set($this->name,$this->ptchFile);
			$this->getHeader();
			$this->headerDataHex["PTCH"] = array(
				"NEW"		=> "Y",
				"SIGN" 		=> "WDBC",
				"RECORD_COUNT" => $this->recordCount,
				"FIELD_COUNT" => $this->fieldCount,
				"RECORD_SIZE" => $this->recordSize,
				"STRING_SIZE" => $this->stringSize,
			);
			return;
		}

		// MD5_
		fseek($this->ptchHandler,16);
		if(($sign = fread($this->ptchHandler, 4)) == "MD5_"){
			$x = unpack("V",fread($this->ptchHandler, 4));
			$s = strtoupper(bin2hex(fread($this->ptchHandler, 32)));
			$this->headerDataHex["MD5_"] = array(
				"SIGN" 			=> $sign, // 4 bytes, string
				"md5BlockSize" 	=> $x[1], // 4 bytes
				"md5sizeBefore" => substr($s,0,32), // 16 bytes
				"md5sizeAfter" 	=> substr($s,32,32), // 16 bytes
			);
		}

		// XFRM
		fseek($this->ptchHandler,16+40);
		if(($sign = fread($this->ptchHandler, 4)) == "XFRM"){
			$x = unpack("V",fread($this->ptchHandler, 4));
			$type = fread($this->ptchHandler, 4);
			$this->headerDataHex["XFRM"] = array(
				"SIGN" 			=> $sign, // 4 bytes, string
				"xfrmBlockSize" => $x[1], // 4 bytes
				"xfrmType" 		=> $type, // 4 bytes, string
			);
			
			switch($this->headerDataHex["XFRM"]["xfrmType"]){
				case "BSD0":
					// BSDIFF
					// $this->headerDataBin["BSDIFF"] = fread($this->ptchHandler, 4+1+8); // 37 bytes
					fseek($this->ptchHandler,16+40+12);

					// fread($this->ptchHandler, 4);
					$x = unpack("V",fread($this->ptchHandler, 4));
					fseek($this->ptchHandler,1,SEEK_CUR);
					$this->headerDataHex["BSDIFF"] = array(
						"unpackedSize" => $x[1], // 4 bytes
						"SIGN" => fread($this->ptchHandler, 8), // 8 bytes, string
					);
					break;
				case "COPY":
					$this->headerDataBin["BSDIFF"] = fread($this->ptchHandler, 4); // 4 bytes
					$this->headerDataHex["BSDIFF"] = array(
						"unpackedSize" => base_convert(bin2hex(strrev(substr($this->headerDataBin["BSDIFF"],0,4))),16,10), // 4 bytes
					);
					break;
				default:
					$err = sprintf("Unknown patch type: {0}", $this->headerDataHex["XFRM"]["xfrmType"]);
					break;
			}
		}

	}

	public function patchDBC($source,$patch,$exec,$name){
		if(!$source || !$patch){
			return false;
		}

		$in = $_SERVER["DOCUMENT_ROOT"].$source;
		$diff = $_SERVER["DOCUMENT_ROOT"].$patch;
		$_path = "/upload/tmp/".$name;
		$out = $_SERVER["DOCUMENT_ROOT"].$_path;

		$_str = "%s %s %s %s";
		$str = sprintf($_str,$exec,$in,$diff,$out);

		$output = array();
		exec($str,$output,$return_code);
		/*
		* $output - return output data in console
		* $return_code - 0-255 code (0 - complate, 1-255 - errors)
		*/
		if(intval($return_code) > 0){
			// errors
			return array(false,"ERROR_CODE_".$return_code);
		}else{
			// ok
			return array(
				$_path, // path to new dbc file
				implode("\n",$output), // output data in console
			);
		}
	}

	function __destruct(){
		// $this->_end();
	}
}
?>