<?php

/*
 * libasynql
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

use poggit\libasynql\generic\GenericStatementFileParseException;
use poggit\libasynql\generic\GenericStatementFileParser;
use poggit\libasynql\GenericStatement;

require_once __DIR__ . "/../cli-autoload.php";

function constToCamel(string $prefix, string $const) : string{
	$camel = "";
	foreach(explode("_", $const) as $word) {
		$camel .= ucfirst(strtolower($word));
	}
	if(stripos($camel, $prefix) === 0) {
		$camel = substr($camel, strlen($prefix));
	}
	return lcfirst($camel);
}

if(!isset($argv[4])){
	echo "[!] Usage: php " . escapeshellarg($argv[0]) . " fx <src> <fqn> <SQL file>\n";
	echo "[*] Generates a query name constants interface file from the SQL file\n";
	exit(2);
}

[, , $srcDir, $fqn] = $argv;

if(!is_dir($srcDir)){
	echo "[!] $srcDir: No such directory\n";
	exit(2);
}

if(!preg_match('/^[a-z_]\w*(\\\\[a-z_]\w*)*$/i', $fqn)){
	echo "[!] $fqn: Invalid FQN\n";
	exit(2);
}
$fqnPieces = explode("\\", $fqn);

$EOL = PHP_EOL;
$INDENT = "\t";
$prefix = "";
$STRUCT = "interface";

$sqlFiles = [];

$i = 4;
while(isset($argv[$i]) && strpos($argv[$i], "--") === 0){
	if($argv[$i] === "--prefix"){
		$prefix = $argv[$i + 1];
		$i += 2;
		continue;
	}
	if($argv[$i] === "--eol"){
		switch(strtoupper($argv[$i + 1])){
			case "CRLF":
				$EOL = "\r\n";
				break;
			case "LF":
				$EOL = "\n";
				break;
			default:
				echo "[!] Invalid EOL option '" . $argv[$i + 1] . "'\n";
				exit(2);
		}
		$i += 2;
		continue;
	}
	if($argv[$i] === "--spaces"){
		$indentSize = $argv[$i + 1];
		if(!is_numeric($indentSize)){
			echo "[!] Invalid --spaces option: number expected\n";
			exit(2);
		}
		$INDENT = str_repeat(" ", (int) $indentSize);
		$i += 2;
		continue;
	}
	if($argv[$i] === "--sql"){
		$sqlFiles = array_map(function(SplFileInfo $file){
			return $file->getPathname();
		}, iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[$i + 1])), '/\.sql$/')));
		$i += 2;
		continue;
	}
	if($argv[$i] === "--struct"){
		$STRUCT = $argv[$i + 1];
		$i += 2;
		continue;
	}
	echo "[!] Unknown option $argv[$i]\n";
	exit(2);
}
if(empty($sqlFiles)){
	if(!isset($argv[$i])){
		echo "[!] Missing input files\n";
		exit(2);
	}
	for($iMax = count($argv); $i < $iMax; ++$i){
		$sqlFile = $argv[$i];
		if(!is_file($sqlFile)){
			echo "[!] $sqlFile: No such file\n";
			exit(2);
		}
		$sqlFiles[] = $sqlFile;
	}
}

/** @var GenericStatement[][] $results */
$results = [];
foreach($sqlFiles as $sqlFile){
	echo "[*] Parsing $sqlFile\n";
	$fh = fopen($sqlFile, "rb");
	$parser = new GenericStatementFileParser($sqlFile, $fh);
	try{
		$parser->parse();
	}catch(GenericStatementFileParseException $e){
		echo "[!] " . $e->getMessage() . "\n";
		exit(1);
	}

	foreach($parser->getResults() as $stmt){
		$results[$stmt->getName()][$sqlFile] = $stmt;
	}
}
ksort($results, SORT_NATURAL);

$itfFile = realpath($srcDir) . "/" . str_replace("\\", "/", $fqn) . ".php";
@mkdir(dirname($itfFile), 0777, true);

$fh = fopen($itfFile, "wb");
fwrite($fh, '<?php' . $EOL);
fwrite($fh, '' . $EOL);
fwrite($fh, '/*' . $EOL);
fwrite($fh, ' * Auto-generated by libasynql-fx' . $EOL);
fwrite($fh, ' * Created from ' . implode(", ", array_map("basename", $sqlFiles)) . $EOL);
fwrite($fh, ' */' . $EOL);
fwrite($fh, '' . $EOL);
fwrite($fh, 'declare(strict_types=1);' . $EOL);
fwrite($fh, '' . $EOL);
fwrite($fh, 'namespace ' . implode("\\", array_slice($fqnPieces, 0, -1)) . ';' . $EOL);
fwrite($fh, '' . $EOL);
fwrite($fh, 'use Generator;' . $EOL);
fwrite($fh, 'use poggit\libasynql\DataConnector;' . $EOL);
fwrite($fh, 'use SOFe\AwaitGenerator\Await;' . $EOL);
fwrite($fh, '' . $EOL);
fwrite($fh, $STRUCT . ' ' . array_slice($fqnPieces, -1)[0] . '{' . $EOL);
fwrite($fh, "{$INDENT}public function __construct(private DataConnector \$conn) {}$EOL");
$constLog = [];
foreach($results as $queryName => $stmts){
	$const = preg_replace('/[^A-Z0-9]+/i', "_", strtoupper($queryName));
	if(ctype_digit($queryName[0])){
		$const = "_" . $const;
	}
	if(isset($constLog[$const])){
		$i = 2;
		while(isset($constLog[$const . "_" . $i])){
			++$i;
		}
		echo "Warning: Similar query names {$constLog[$const]} and {$queryName}, generating numerically-assigned identifier {$const}_{$i}\n";
		$const .= "_" . $i;
	}
	$constLog[$const] = $queryName;
	$descLines = [];
	$docFile = null;
	$docString = "";
	foreach($stmts as $stmt){
		if(strlen($stmt->getDoc()) > strlen($docString)){
			$docFile = $stmt->getFile();
			$docString = $stmt->getDoc();
		}
	}
	if($docFile !== null){
		$descLines[] = "<i>(Description from {$docFile})</i>";
		$descLines[] = "";
		foreach(explode("\n", $docString) as $line){
			$descLines[] = $line;
		}
		$descLines[] = "";
	}
	$descLines[] = "<h4>Declared in:</h4>";
	foreach($stmts as $stmt){
		$descLines[] = "- {$stmt->getFile()}:{$stmt->getLineNumber()}";
	}
	/** @var GenericStatement $stmt0 */
	$stmt0 = array_values($stmts)[0];
	$file0 = array_keys($stmts)[0];
	$variables = $vars0 = $stmt0->getVariables();
	$varFiles = [];
	foreach($variables as $varName => $variable){
		$varFiles[$varName] = [$file0 => $variable->isOptional()];
	}
	foreach($stmts as $file => $stmt){
		if($file === $file0){
			continue;
		}
		$vars = $stmt->getVariables();
		foreach($vars0 as $varName => $var0){
			if(isset($vars[$varName])){
				/** @noinspection NotOptimalIfConditionsInspection */
				if(!$var0->equals($vars[$varName], $diff) && $diff !== "type" && $diff !== "defaultValue"){
					echo "[!] Conflict: $queryName :$varName have different declarations ($diff) in $file0 and $file\n";
					exit(1);
				}
				if($var0->isOptional() !== $vars[$varName]->isOptional()){
					echo "[*] Notice: :$varName is " . ($var0->isOptional() ? "optional" : "required") . " for $queryName in $file0 but " . ($var0->isOptional() ? "required" : "optional") . " in $file\n";
				}
				$varFiles[$varName][$file] = $vars[$varName]->isOptional();
			}else{
				echo "[*] Notice: :$varName is defined for $queryName in $file0 but not in $file\n";
			}
		}
		foreach($vars as $varName => $var){
			if(!isset($vars0[$varName])){
				$opt = $var->isOptional() ? "optional" : "required";
				echo "[*] Notice: :$varName is $opt for $queryName in $file but not defined in $file0\n";
				$varFiles[$varName][$file] = $var->isOptional();
			}
		}
	}
	$argList = "";
	$argMap = "";
	if(!empty($varFiles)){
		foreach($varFiles as $varName => $files){
			$var = $vars0[$varName];
			$varType = "";
			if($var->isNullable()) {
				$varType .= "?";
			}
			$varType .= $var->getType();
			if($var->isList()) {
				$varDocType = $varType . "[]";
				$varPhpType = "array";
			} else {
				$varDocType = $varType;
				$varPhpType = $varType;
			}
			$argList .= $varPhpType;
			$argList .= " \$$varName";
			$argMap .= "\"$varName\" => \$$varName, ";
			if($var->isOptional()) {
				$argList .= var_export($var->getDefault(), true);
			}
			$argList .= ", ";

			$descLines[] = "@param $varDocType \$$varName";
		}
	}
	if(stripos(trim($stmt->getQuery()), "select") === 0) {
		$execute = "executeSelect";
		$return = "list<array<string, mixed>>";
	} elseif(stripos(trim($stmt->getQuery()), "insert") === 0) {
		$execute = "executeInsert";
		$return = "int";
	} else {
		$execute = "executeChange";
		$return = "int";
	}
	$descLines[] = "@return Generator<mixed, 'all'|'once'|'race'|'reject'|'resolve'|array{'resolve'}|Generator<mixed, mixed, mixed, mixed>|null, mixed, $return>";
	fwrite($fh, $EOL);
	fwrite($fh, "{$INDENT}/**" . $EOL);
	foreach($descLines as $line){
		fwrite($fh, (strlen($line) > 0 ? "{$INDENT} * $line" : "{$INDENT} *") . $EOL);
	}
	fwrite($fh, "{$INDENT} */" . $EOL);
	$method = constToCamel($prefix, $const);
	fwrite($fh, "{$INDENT}public function {$method}({$argList}) : Generator {" . $EOL);
	$quotedName = json_encode($queryName);
	fwrite($fh, "{$INDENT}{$INDENT}\$this->conn->$execute($quotedName, [$argMap], yield Await::RESOLVE, yield Await::REJECT);" . $EOL);
	fwrite($fh, "{$INDENT}{$INDENT}return yield Await::ONCE;" . $EOL);
	fwrite($fh, "{$INDENT}}" . $EOL);
}

fwrite($fh, '}' . $EOL);
fclose($fh);
exit(0);
