<?php

namespace DBC;

class Format {
	const FT_NA = 'x';       // unknown, size 0x4
	const FT_NA_BYTE = 'X';  // unknown, size 0x1
	const FT_STRING = 's';   // char*/string, size 0x4
	const FT_FLOAT = 'f';    // float, size 0x4
	const FT_IND = 'n';      // uint32, size 0x4
	const FT_INT = 'i';      // uint32, size 0x4
	const FT_BYTE = 'b';     // uint8, size 0x1
	const FT_SORT = 'd';     // sorted, size 0x4, sorted by this field, field is not included
	const FT_LOGIC = 'l';    // bool/logical, size 0x1
}
