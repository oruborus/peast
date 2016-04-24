<?php
namespace Peast\Syntax;

class Scanner
{
    protected $column = 0;
    
    protected $line = 1;
    
    protected $index = 0;
    
    protected $length;
    
    protected $chars = array();
    
    protected $config;
    
    protected $symbols = array();
    
    protected $symbolChars = array();
    
    protected $maxSymbolLength;
    
    protected $lineTerminatorsSplitter;
    
    protected $hexDigits = array(
        "0", "1", "2", "3", "4", "5", "6", "7", "8", "9",
        "a", "b", "c", "d", "e", "f",
        "A", "B", "C", "D", "E", "F"
    );
    
    function __construct($source, $encoding = null)
    {
        if (!$encoding) {
            $encoding = mb_detect_encoding($source);
        }
        
        if ($encoding && !preg_match("/UTF-?8/i", $encoding)) {
            $source = mb_convert_encoding($source, "UTF-8", $encoding);
        }
        
        $this->chars = preg_split('/(?<!^)(?!$)/u', $source);
        $this->length = count($this->chars);
    }
    
    public function setConfig(Config $config)
    {
        $symbolMap = array();
        $this->symbols = array();
        $this->maxSymbolLength = -1;
        foreach ($config->getSymbols() as $symbol) {
            $symbolMap[] = $symbol;
            $len = strlen($symbol);
            $this->maxSymbolLength = max($len, $this->maxSymbolLength);
            if (!isset($this->symbols[$len])) {
                $this->symbols[$len] = array();
            }
            $this->symbols[$len][] = $symbol;
        }
        $this->symbolChars = array_unique($symbolMap);
        
        $terminatorsSeq = implode("|", $config->getLineTerminatorsSequences());
        $this->lineTerminatorsSplitter = "/$terminatorsSeq/u";
        
        $this->config = $config;
        
        return $this;
    }
    
    public function getColumn()
    {
        return $this->column;
    }
    
    public function getLine()
    {
        return $this->line;
    }
    
    public function getIndex()
    {
        return $this->index;
    }
    
    public function getPosition()
    {
        return new Position(
            $this->getLine(),
            $this->getColumn(),
            $this->getIndex()
        );
    }
    
    public function setPosition(Position $position)
    {
        $this->line = $position->getLine();
        $this->column = $position->getColumn();
        $this->index = $position->getIndex();
        return $this;
    }
    
    protected function isWhitespace($char)
    {
        return in_array($char, $this->config->getWhitespaces(), true);
    }
    
    protected function scanWhitespaces()
    {
        $index = $this->index;
        $buffer = "";
        while ($index < $this->length) {
            $char = $this->chars[$index];
            if ($this->isWhitespace($char)) {
                $buffer .= $char;
                $index++;
            } else {
                break;
            }
        }
        if ($buffer !== "") {
            $len = $index - $this->index;
            $this->index = $index;
            return array(
                "source" => $this->splitLines($buffer),
                "length" => $len,
                "whitespace" => true
            );
        }
        return null;
    }
    
    protected function isSymbol($char)
    {
        return in_array($char, $this->symbolChars);
    }
    
    protected function scanSymbols()
    {
        $index = $this->index;
        $buffer = "";
        $bufferLen = 0;
        while ($index < $this->length && $bufferLen < $this->maxSymbolLength) {
            $char = $this->chars[$index];
            if ($this->isSymbol($char)) {
                $buffer .= $char;
                $index++;
                $bufferLen++;
            } else {
                break;
            }
        }
        if ($bufferLen) {
            while ($bufferLen > 0) {
                if (!isset($this->symbols[$bufferLen]) ||
                    !in_array($buffer, $this->symbols[$bufferLen])) {
                    $bufferLen--;
                    $buffer = substr($buffer, 0, $bufferLen);
                } else {
                    break;
                }
            }
            if ($bufferLen) {
                $this->index += $bufferLen;
                return array(
                    "source" => $buffer,
                    "length" => $bufferLen,
                    "whitespace" => false
                );
            }
        }
        return null;
    }
    
    protected function scanOther()
    {
        $index = $this->index;
        $buffer = "";
        while ($index < $this->length) {
            $char = $this->chars[$index];
            if (!$this->isWhitespace($char) && !$this->isSymbol($char)) {
                $buffer .= $char;
                $index++;
            } else {
                break;
            }
        }
        if ($buffer !== "") {
            $len = $index - $this->index;
            $this->index = $index;
            return array(
                "source" => $buffer,
                "length" => $len,
                "whitespace" => false
            );
        }
        return null;
    }
    
    protected function splitLines($str)
    {
        return preg_split($this->lineTerminatorsSplitter, $str);
    }
    
    protected function isHexDigit($char)
    {
        return in_array($char, $this->hexDigits, true);
    }
    
    public function getToken()
    {
        if ($this->index < $this->length) {
            if ($source = $this->scanWhitespaces()) {
                return $source;
            } elseif ($source = $this->scanSymbols()) {
                return $source;
            } elseif ($source = $this->scanOther()) {
                return $source;
            }
        }
        return null;
    }
    
    protected function consumeToken($token)
    {
        if ($token["whitespace"]) {
            $linesCount = count($token["source"]) - 1;
            $this->line += $linesCount;
            $this->column += mb_strlen($token["source"][$linesCount]);
        } else {
            $this->column += $token["length"];
        }
    }
    
    protected function unconsumeToken($token)
    {
        $this->index -= $token["length"];
    }
    
    public function consumeWhitespacesAndComments($lineTerminator = true)
    {
        if (!$lineTerminator) {
            $position = $this->getPosition();
        }
        $comment = $processed = 0;
        while ($token = $this->getToken()) {
            $processed++;
            $source = $token["source"];
            if ($token["whitespace"]) {
                if (count($source) > 1) {
                    if (!$lineTerminator) {
                        $this->setPosition($position);
                        return null;
                    } elseif ($comment === 1) {
                        $comment = 0;
                    }
                }
                $this->consumeToken($token);
            } elseif (!$comment && $source === "//") {
                $comment = 1;
                $this->consumeToken($token);
            }elseif (!$comment && $source === "/*") {
                $comment = 2;
                $this->consumeToken($token);
            } elseif ($comment === 2 && $source === "*/") {
                $comment = 0;
                $this->consumeToken($token);
            } else {
                $this->unconsumeToken($token);
                return $processed > 1;
            }
        }
        return $comment ? null : $processed;
    }
    
    public function consume($string)
    {
        $this->consumeWhitespacesAndComments();
        
        $token = $this->getToken();
        if (!$token || $token["source"] !== $string) {
            $this->unconsumeToken($token);
            return false;
        }
        
        $this->consumeToken($token);
        
        return true;
    }
    
    public function consumeIdentifier()
    {
        $this->consumeWhitespacesAndComments();
        
        $start = true;
        $index = $this->index;
        $buffer = "";
        while ($index < $this->length) {
            $char = $this->chars[$index];
            if ($char === "$" || $char === "_" ||
                ($char >= "A" && $char <= "Z") ||
                ($char >= "a" && $char <= "z") ||
                (!$start && $char >= "0" && $char <= "9")) {
                $index++;
                $buffer .= $char;
            } elseif ($start &&
                      preg_match($this->config->getIdRegex(), $char)) {
                $index++;
                $buffer .= $char;
            } elseif (!$start &&
                      preg_match($this->config->getIdRegex(true), $char)) {
                $index++;
                $buffer .= $char;
            } elseif ($char === "\\" &&
                      isset($this->chars[$index + 1]) &&
                      $this->chars[$index + 1] === "u") {
                //UnicodeEscapeSequence
                $valid = true;
                $subBuffer = "\\u";
                if (isset($this->chars[$index + 2]) &&
                    $this->chars[$index + 2] === "{" &&
                    isset($this->chars[$index + 3])) {
                    
                    $oneMatched = false;
                    $subBuffer .= "{";
                    for ($i = $index + 4; $i < $this->length; $i++) {
                        if ($this->isHexDigit($this->chars[$index])) {
                            $oneMatched = true;
                            $subBuffer .= $this->chars[$index];
                        } elseif ($oneMatched && $this->chars[$index] === "}") {
                            $subBuffer .= $this->chars[$index];
                            break;
                        } else {
                            $valid = false;
                            break;
                        }
                    }
                    
                } else {
                    for ($i = $index + 3; $i <= $index + 7; $i++) {
                        if (isset($this->chars[$i]) &&
                            $this->isHexDigit($this->chars[$index])) {
                            $subBuffer .= $this->chars[$i];
                        } else {
                            $valid = false;
                            break;
                        }
                    }
                }
                
                if (!$valid) {
                    break;
                }
                
                $buffer .= $subBuffer;
                $index += strlen($subBuffer);
                
            } else {
                break;
            }
            $start = false;
        }
        
        if ($buffer !== "") { 
            $this->column += $index - $this->index;
            $this->index = $index; 
            return $buffer;
        }
        
        return null;
    }
    
    public function consumeRegularExpression()
    {
        $postion = $this->getPosition();
        
        if ($this->index + 1 < $this->length &&
            $this->chars[$this->index] === "/" &&
            !in_array($this->chars[$this->index + 1], array("/", "*"), true)) {
            
            $this->index++;
            $this->column++;
            
            $inClass = false;
            $source = "/";
            $valid = true;
            while (true) {
                if ($inClass) {
                    $sub = $this->consumeUntil(array("]"), false);
                    if (!$sub) {
                        $valid = false;
                        break;
                    } else {
                        $source .= $sub;
                        $inClass = false;
                    }
                } else {
                    $sub = $this->consumeUntil(array("[", "/"), false);
                    if (!$sub) {
                        $valid = false;
                        break;
                    } else {
                        $source .= $sub;
                        $lastChar = substr($sub, -1);
                        if ($lastChar === "/") {
                            break;
                        } else {
                            $inClass = true;
                        }
                    }
                }
            }
            
            if (!$inClass && $valid) {
                while ($this->index < $this->length) {
                    $char = $this->chars[$this->index];
                    if ($char >= "a" && $char <= "z") {
                        $source .= $char;
                        $this->index++;
                        $this->column++;
                    } else {
                        break;
                    }
                }
                return $source;
            }
        }
        
        $this->setPosition($postion);
        
        return null;
    }
    
    public function consumeNumber()
    {
        $nextChar = $this->index < $this->length ?
                    $this->chars[$this->index] :
                    null;
        if (!(($nextChar >= "0" && $nextChar <= "9") || $nextChar === ".")) {
            return null;
        }
        
        $postion = $this->getPosition();
        
        $decimal = true;
        $source = "";
        $num = $this->scanOther();
        if ($num) {
            //Split exponent part
            $parts = preg_split("/e/i", $num["source"]);
            $n = $parts[0];
            //If it begins with 0 it can be a binary (0b), an octal (0o)
            //an hexdecimal (0x) or a integer number with no decimal part
            if ($n[0] === "0" && isset($n[1])) {
                $decimal = false;
                $char = strtolower($n[1]);
                if ((
                        $char === "b" &&
                        $this->config->supportsBinaryNumberForm() &&
                        !preg_match("/^0[bB][01]+$", $n)
                    ) || (
                        $char === "o" &&
                        $this->config->supportsOctalNumberForm() &&
                        !preg_match("/^0[oO][0-7]+$", $n)
                    ) || (
                        $char === "x" &&
                        !preg_match("/^0[xX][0-9a-fA-F]+$", $n)
                    ) || (
                        $char >= "0" && $char <= "9" &&
                        !preg_match("/^\d+$/", $n)
                    )) {
                    $this->unconsumeToken($num);
                    return null;
                }
            } elseif (!preg_match("/^\d+$/", $n)) {
                $this->unconsumeToken($num);
                return null;
            }
            $this->consumeToken($num);
            $source .= $num["source"];
            //Validate exponent part
            if (isset($parts[1])) {
                $expPart = $parts[1];
                if ($expPart === "") {
                    $sign = $this->scanSymbols();
                    if ($sign["source"] !== "+" && $sign["source"] !== "-") {
                        $this->setPosition($position);
                        return null;
                    }
                    $this->consumeToken($sign);
                    $expNum = $this->scanOther();
                    $this->consumeToken($expNum);
                    $expPart = $expNum["source"];
                    $source .= $sign["source"] . $expPart;
                }
                if (!preg_match("/^\d+$/", $expPart)) {
                    $this->setPosition($position);
                    return null;
                }
            }
        }
        //Validate decimal part
        $dot = $this->scanSymbols();
        if (!$dot || $dot["source"] !== ".") {
            if ($dot) {
                $this->unconsumeToken($dot);
            }
        } elseif (!$decimal) {
            //If decimal part is not allowed exit
            $this->setPosition($position);
            return null;
        } else {
            $this->consumeToken($dot);
            $source .= ".";
            $decPart = $this->scanOther();
            if (!$decPart) {
                $this->setPosition($position);
                return null;
            }
            //Split exponent part
            $parts = preg_split("/e/i", $decPart["source"]);
            if (!preg_match("/^\d+$/", $parts[0])) {
                $this->setPosition($position);
                return null;
            }
            $this->consumeToken($decPart);
            $source .= $decPart["source"];
            //Validate exponent part
            if (isset($parts[1])) {
                $expPart = $parts[1];
                if ($expPart === "") {
                    $sign = $this->scanSymbols();
                    if ($sign["source"] !== "+" && $sign["source"] !== "-") {
                        $this->setPosition($position);
                        return null;
                    }
                    $this->consumeToken($sign);
                    $expNum = $this->scanOther();
                    $this->consumeToken($expNum);
                    $expPart = $expNum["source"];
                    $source .= $sign["source"] . $expPart;
                }
                if (!preg_match("/^\d+$/", $expPart)) {
                    $this->setPosition($position);
                    return null;
                }
            }
        }
        return $source;
    }
    
    public function consumeArray($sequence)
    {
        $position = $this->getPosition();
        foreach ($sequence as $string) {
            if ($this->consume($string) === false) {
                $this->setPosition($position);
                return false;
            }
        }
        return true;
    }
    
    public function notBefore($tests)
    {
        $position = $this->getPosition();
        foreach ($tests as $test) {
            $testFn = is_array($test) ? "consumeArray" : "consume";
            if ($this->$testFn($test)) {
                $this->setPosition($position);
                return false;
            }
        }
        return true;
    }
    
    public function consumeOneOf($tests)
    {
        foreach ($tests as $test) {
            if ($this->consume($test)) {
                return $test;
            }
        }
        return null;
    }
    
    public function consumeUntil($stop, $allowLineTerminator = true)
    {
        foreach ($stop as $s) {
            $stopMap[$s[0]] = array(strlen($s), $s);
        }
    	$index = $this->index;
    	$escaped = false;
    	$buffer = "";
    	$lineTerminators = $this->config->getLineTerminators();
    	$valid = false;
    	while ($index < $this->length) {
    		$char = $this->chars[$index];
    		$buffer .= $char;
    		$index++;
    		if ($escaped) {
    		    $escaped = false;
    		} elseif ($char === "\\") {
    		    $escaped = true;
    		} elseif (!$allowLineTerminator &&
    		          in_array($char, $lineTerminators, true)) {
    		    break;
    		} elseif (isset($stopMap[$char])) {
    		    $len = $stopMap[$char][0];
    		    if ($len === 1) {
    		        $valid = true;
    		        break;
    		    }
    		    $seq = array_slice($this->chars, $index, $len);
    		    if (implode("", $seq) === $stopMap[$char][1]) {
    		        $index += $len - 1;
    		        $valid = true;
    		        break;
    		    }
    		}
    	}
    	
    	if (!$valid) {
    	    return null;
    	}
    	
	    if (!$lineTerminators) {
	        $this->column += ($index - $this->index);
	    } else {
	        $lines = $this->splitLines($buffer);
	        $linesCount = count($lines) - 1;
	        $this->line += $linesCount;
            $this->column += mb_strlen($lines[$linesCount]);
	    }
	    $this->index = $index;
	    
	    return $buffer;
    }
}