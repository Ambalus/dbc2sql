<?php
/**
* 0x00 	char[4] always 'WDBC' 
* 0x04 	uint32 	nRecords - number of records in the file 
* 0x08 	uint32 	nFields - number of 4-byte fields per record 
* 0x0C 	uint32 	recordSize = nFields * 4 (not always true!) 
* 0x10 	uint32 	string block size
**/

namespace PTCH;

/**
 * Description of Parser
 *
 * @author Ambalus
 */
class Parser {

}
