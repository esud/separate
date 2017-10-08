<?php

// +---------------------------------------------------------------------------+
// | Separate Template Engine Version 1.6.0 (http://separate.esud.info/)       |
// | Copyright 2004-2017 Eduard Sudnik                                         |
// |                                                                           |
// | Permission is hereby granted, free of charge, to any person obtaining a   |
// | copy of this software and associated documentation files (the "Software"),| 
// | to deal in the Software without restriction, including without limitation | 
// | the rights to use, copy, modify, merge, publish, distribute, sublicense,  | 
// | and/or sell copies of the Software, and to permit persons to whom the     |     
// | Software is furnished to do so, subject to the following conditions:      |
// |                                                                           |
// | The above copyright notice and this permission notice shall be included   |
// | in all copies or substantial portions of the Software.                    |
// |                                                                           |
// | THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS   |
// | OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF                |
// | MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN |
// | NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,  |
// | DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR     |
// | OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE |
// | USE OR OTHER DEALINGS IN THE SOFTWARE.                                    |
// +---------------------------------------------------------------------------+

namespace separate;

class Template
{
    protected static $instance;
    protected static $globalAssigns = [];
    protected static $globalXAssigns = [];
    protected static $filePath;
    protected static $defaultFormatter = null;
    protected static $parameters = [];
    protected static $formatterCache = []; //used for better performance   

    //HACK: this secret token is primary used to avoid PHP injections
    //when prepared statements code like this is assigned to variable:
    //<!-- IF true){} echo(time()); if(true --> ... <!-- END IF -->
    protected static $secretToken = null;

    protected $source;
    protected $assigns = [];
    protected $blocks = [];
    protected $xassigns = [];
    protected $blockAssigns = [];
    protected $blockXAssigns = [];

    public static function instance() : Template
    {
        if(!self::$instance)
        {
            throw new \Exception('Template not initialized');
        }
        return self::$instance;
    }

    public static function booleanToString(bool $value) : string
    {
        if($value) {return 'TRUE';}
        return 'FALSE';
    }

    public static function setDefaultFormatter(ValueFormatter $formatter)
    {
        self::$defaultFormatter = $formatter;
    }

    public static function getDefaultFormatter() : ValueFormatter
    {
        return self::$defaultFormatter;
    }

    public static function getParameters() : array
    {
        return self::$parameters;
    }

    public static function setParameterValue(string $name, string $value)
    {
        self::$parameters[$name] = $value;
    }

    public static function initialize(string $filePath) : Template
    {
        //check file
        if(!is_file($filePath))
        {
            throw new \Exception('Template file not found: ' . $filePath);
        }
  
        self::$instance = new static(); 
 
        self::$instance->assigns = [];
        self::$instance->xassigns = [];
        self::$instance->blocks = [];
        self::$instance->blockAssigns = [];
        self::$instance->blockXAssigns = [];

        self::$globalAssigns = [];
        self::$globalXAssigns = [];
        self::$filePath = $filePath;
        self::$parameters = [];
        self::$formatterCache = [];

        //source
        self::$instance->source = file_get_contents(self::$filePath);

        //file directory
        $directory = strrev(stristr(strrev(self::$filePath), '/'));

        //initialize includes
        self::$instance->source = self::$instance->initializeIncludes(self::$instance->source, $directory);

        //initialize parameters
        self::$instance->initializeParameters();

        //generate secret token
        self::$secretToken = md5(uniqid(rand(), true));

        //add secret token
        self::$instance->source = self::$instance->addSecretToken(self::$instance->source);

        //initialize blocks
        self::$instance->initializeBlocks();

        return self::instance();
    }

    public static function display() : string
    {
        if(!self::$instance)
        {
            throw new \Exception('Template not initialized');
        }

        //get compiled source
        $source = self::$instance->compile();

        //create HTML source
        ob_start();
        eval('?' . '>' . $source); //HACK: by default in this function the php mode is active, so we disable it with php-end-token first
        $compiledHtmlSource = ob_get_contents();
        ob_end_clean();
        
        //remove secret token
        $compiledHtmlSource = str_replace(self::$secretToken, '', $compiledHtmlSource);    

        //output HTML source
        echo($compiledHtmlSource);

        //return compiled HTML source
        return $compiledHtmlSource;
    }

    //checks if template variable is assigned using this priority:
    //1. local assigns
    //2. block assigns (of same template only)
    //3. global assigns
    public function isAssigned(string $name) : bool
    {
        //check local assigns
        if(isset($this->assigns[$name]))
        {
            return true;
        }

        //check block assigns of this template
        if(isset($this->blockAssigns[$name]))
        {
            return true;
        }

        //check global assigns
        if(isset(self::$globalAssigns[$name]))
        {
            return true;
        }

        //not found
        return false;
    }

    //checks if fast template variable is assigned using this priority:
    //1. local fast assigns
    //2. block fast assigns (of same template only)
    //3. global fast assigns
    public function isXAssigned(string $name) : bool
    {
        //check local fast assigns
        if(isset($this->xassigns[$name]))
        {
            return true;
        }

        //check block fast assigns of this template
        if(isset($this->blockXAssigns[$name]))
        {
            return true;
        }

        //check global fast assigns
        if(isset(self::$globalXAssigns[$name]))
        {
            return true;
        }

        //not found
        return false;
    }
    
    public function fetch(string $blockName) : Template
    {
        if(isset($this->blocks[$blockName]))
        {
            return clone $this->blocks[$blockName];
        }

        throw new \Exception('Unable to fetch block for block name: ' . $blockName);
    }

    public function assign(string $name, $value, bool $reassign = false)
    {
        //if block is assigned
        if(is_object($value))
        {
            if(!($value instanceof Template))
            {
                throw new \Exception('Invalid value assigned');
            }

            if(!$reassign)
            {
                $this->assigns[$name][] = clone $value;
            }
            else
            {
                $this->assigns[$name] = [];
                $this->assigns[$name][0] = clone $value;
            }
        }
        //if variable is assigned
        else
        {  
            if(!$reassign)
            {
                $this->assigns[$name][] = (string)$value;
            }
            else
            {
                $this->assigns[$name] = [];
                $this->assigns[$name][0] = (string)$value;
            }
        }
    }

    public function xassign(string $name, string $value) {$this->xassigns[$name] = $value;}

    public function assignForGlobal(string $name, string $value) {self::$globalAssigns[$name][0] = $value;}

    public function xassignForGlobal(string $name, string $value) {self::$globalXAssigns[$name] = $value;}

    public function assignForBlock(string $name, string $value) {$this->blockAssigns[$name][0] = $value;}

    public function xassignForBlock(string $name, string $value) {$this->blockXAssigns[$name] = $value;}

    public function getVariableNames(bool $includeBlocksFlag = true) : array
    {
        $variables = [];

        //get variables of current block
        if(preg_match_all('(\${(.+?)})', $this->source, $matches))
        {
            for ($i = 0; $i < count($matches[0]); $i++)
            {
                //filter block variables
                if(strlen($matches[1][$i]) >= 7 && substr($matches[1][$i], 0, 7) == '(Block)')
                {
                    continue;
                }

                //remove '(Formatter)' from '(Formatter)VARIABLE' if exists
                //and add extracted variable to array
                $removePosition = stripos($matches[1][$i], ')');
                if($removePosition)
                {
                    $variables[$i] = substr($matches[1][$i], $removePosition + 1);
                }
                else
                {
                    $variables[$i] = $matches[1][$i];
                }
            }
        }

        //get block variables
        if($includeBlocksFlag && $this->blocks != null)
        {
            foreach($this->blocks as $block)
            {
                $variables = array_merge($variables, $block->getVariableNames());
            }
        }

        //remove duplicate entries
        $variables = array_unique($variables);

        return $variables;
    }

    public function getXVariableNames(bool $includeBlocksFlag = true, bool $removeDuplicatesFlag = true) : array
    {
        $variables = [];

        //get variables of current block
        if(preg_match_all('(\#{(.+?)})', $this->source, $matches))
        {
            $variables = $matches[1];
        }

        //get block variables
        if($includeBlocksFlag && $this->blocks != null)
        {
            foreach($this->blocks as $block)
            {
                //retrieve variables from block and do not remove duplicate entries for better performance
                $variables = array_merge($variables, $block->getXVariableNames(true, false));
            }
        }

        //remove duplicate entries
        if($removeDuplicatesFlag)
        {
            $variables = array_unique($variables);
        }

        return $variables;
    }

    public function getBlockNames(bool $includeSubblocksFlag = true) : array
    {
        $blocks = [];

        if(preg_match_all('(\${\(Block\)(.+)})', $this->source, $matches))
        {
            for ($i = 0; $i < count($matches[0]); $i++)
            {
                $blocks[$i] = $matches[1][$i];
            }
        }

        if($includeSubblocksFlag && $this->blocks != null)
        {
            foreach($this->blocks as $block)
            {
                $blocks = array_merge($blocks, $block->getBlockNames());
            }
        }

        //remove duplicate entries
        $blocks = array_unique($blocks);

        return $blocks;
    }

    private function compileComments(string $source) : string
    {
        //remove source comments
        //HACK: here we replace comments with space. this is important because this 
        //make php code injections like in following example not possible
        //<<!--- dummy --->?p<!--- dummy --->hp echo(time()); ?_> (remove _ sign, it was added only because of code highlighting problem)
        return preg_replace("/<!---\s+(.+?)\s+--->/ms", ' ', $source);
    }

    private function addSecretToken(string $source) : string
    {
        $source = str_replace('<!-- IF ', self::$secretToken . '<!-- IF ', $source);
        $source = str_replace('<!-- ELSE IF ', self::$secretToken . '<!-- ELSE IF ', $source);
        $source = str_replace('<!-- ELSE -->', self::$secretToken . '<!-- ELSE -->', $source);
        $source = str_replace('<!-- END IF -->', self::$secretToken . '<!-- END IF -->', $source);
        return $source;
    }

    private function compileVariablesAndBlocks(array $assigns = [], array $xassigns = []) : string
    {
        $blockAssigns = array_merge($assigns, $this->blockAssigns); //this assigns will be delegated to child blocks
        $this->assigns = array_merge($blockAssigns, $this->assigns);

        $blockXAssigns = array_merge($xassigns, $this->blockXAssigns); //this xassigns will be delegated to child blocks
        $this->xassigns = array_merge($blockXAssigns, $this->xassigns);

        //if nothing to assign
        if($this->assigns == null && $this->xassigns == null)
        {
            //return original source
            return $this->source;
        }

        $searchReplacePairs = [];

        //handle normal assigns
        foreach($this->assigns as $key => $value)
        {
            $keyPattern = str_replace('[', '\[', str_replace(']', '\]', $key));

            //if current assign is used in the template source
            if(preg_match_all('(\${(\(([a-zA-Z0-9]+)\)|)' . $keyPattern . '})', $this->source, $matches))
            {
                //replace string
                $replace = '';

                //build replace string for current key
                for($i = 0; $i < count($this->assigns[$key]); $i++)
                {
                    //if block assign
                    if(is_object($this->assigns[$key][$i]))
                    {
                        //add compiled block source using block assigns
                        $replace .= $this->assigns[$key][$i]->compileVariablesAndBlocks($blockAssigns, $blockXAssigns);
                    }
                    //if variable assign
                    else
                    {
                        //string value
                        $replace .= $this->assigns[$key][$i];
                    }
                }

                //replace each varible with value
                for($i = 0; $i < count($matches[0]); $i++)
                {
                    //if custom formatter set
                    if($matches[2][$i] != 'Block' && $matches[2][$i] != '')
                    {
                        //initialize value formatter
                        $formatterClass = "\\" . $matches[2][$i] . 'Formatter';
                        if(!isset(self::$formatterCache[$formatterClass])) //if formatter instance is not cached
                        {
                            //create formatter instance and add it to cache
                            self::$formatterCache[$formatterClass] = new $formatterClass(); 
                        }

                        //format value using given formatter
                        $formattedReplace = self::$formatterCache[$formatterClass]->formatValue($replace);
                    }
                    //if default formatter
                    elseif(self::$defaultFormatter != null && $matches[2][$i] != 'Block')
                    {
                        $formattedReplace = self::$defaultFormatter->formatValue($replace);
                    }
                    //do not format value
                    else
                    {
                        $formattedReplace = $replace;
                    }

                    $searchReplacePairs[$matches[0][$i]] = $formattedReplace;
                }
            }
        }

        //handle fast assigns
        foreach($this->xassigns as $key => $value)
        {
            $searchReplacePairs['#{' . $key . '}'] = $value;
        }

        //replace source text
        //HACK: here we use strtr instead of str_replace to avoid variable injections where
        //for example variable A is replaced with value BCD and C is another valiable which 
        //will be replaced later with X. the problem is that BCD will be also replaced with BXD
        $this->source = strtr($this->source, $searchReplacePairs);      
  
        return $this->source;
    }

    private function removeUnassignedVariables(string $source) : string
    {
        //remove variables for normal assigns
        $source = preg_replace('(\${.+?})', '', $source);

        //remove variables from fast assigns
        $source = preg_replace('(\#{.+?})', '', $source);

        return $source;
    }

    private function initializeBlocks()
    {
        //retrieve block names
        $blockNames = [];
        $insideBlockFlag = false;
        $currentBlockName = null;
        foreach(explode("\n", $this->source) as $sourceRow)
        {
            //if block begin
            if(strpos($sourceRow, '<!-- BEGIN ') !== false && strpos($sourceRow, ' -->') !== false)
            {
                //if not inside block
                if(!$insideBlockFlag)
                {
                    //we are inside block
                    $insideBlockFlag = true;

                    //determine current block name
                    $currentBlockName = $this->determineBlockName($sourceRow);

                    //add current block name to array
                    $blockNames[] = $currentBlockName;
                }
            }

            //if block end
            if(strpos($sourceRow, '<!-- END ') !== false && strpos($sourceRow, ' -->') !== false)
            {
                //if we are inside block and this end belongs to current block
                if($insideBlockFlag && $currentBlockName == $this->determineBlockName($sourceRow))
                {
                    //we are not inside block
                    $insideBlockFlag = false;
                }
            }
        }

        //for each block name
        foreach($blockNames as $blockName)
        {
            //create new block template
            $this->blocks[$blockName] = new Template();
            $this->blocks[$blockName]->loadBlockSource($this->determineBlockSource($this->source, $blockName));

            //replace block source with block variable
            $search = '/<!-- BEGIN (' . $blockName . ') -->(.*)<!-- END (' . $blockName . ') -->/ms';
            $replace = '${(Block)' . $blockName . '}';
            $this->source = preg_replace($search, $replace, $this->source);
        }
    }

    private function determineBlockName(string $sourceRow) : string
    {
        $startPosition = strpos($sourceRow, '<!-- BEGIN ');
        $commandLength = 11;
        if($startPosition === false)
        {
            $startPosition = strpos($sourceRow, '<!-- END ');
            $commandLength = 9;
        }

        $blockName = substr($sourceRow, $startPosition + $commandLength);
        $blockName = substr($blockName, 0, strpos($blockName, ' -->'));
        return trim($blockName);
    }

    private function determineBlockSource(string $source, string $blockName) : string
    {
        $blockSource = substr($source, strpos($source, '<!-- BEGIN ' . $blockName . ' -->') + strlen('<!-- BEGIN ' . $blockName . ' -->'));
        $blockSource = substr($blockSource, 0, strpos($blockSource, '<!-- END ' . $blockName . ' -->'));
        return $blockSource;
    }

    private function initializeIncludes(string $source, string $baseDirectory)
    {
        preg_match_all('/<!-- INCLUDE ([^>]*) -->/ms', $source, $matches);
        for ($i = 0; $i < count($matches[0]); $i++)
        {
            //load included source
            $incSource = file_get_contents($baseDirectory . $matches[1][$i]);

            //directory of included file
            $directory = $baseDirectory . strrev(stristr(strrev($matches[1][$i]), '/'));

            //init includes of included source
            $incSource = $this->initializeIncludes($incSource, $directory);

            //add included source
            $source = str_replace($matches[0][$i], $incSource, $source);
        }

        return $source;
    }

    private function initializeParameters()
    {
        //search for parameters
        preg_match_all("/<!-- PARAMETER ([0-9a-zA-Z._-]+) '(.+?)' -->/ms", $this->source, $matches);

        for ($i = 0; $i < count($matches[0]); $i++)
        {
            //fetch parameter name and value from definition
            $name = $matches[1][$i];
            $value = $matches[2][$i];

            //add new parameter
            self::$parameters[$name] = $value;

            //remove parameter definition from source
            $this->source = str_replace($matches[0][$i], '', $this->source);
        }
    }
    
    private function compile() : string
    {
        //compile variables, fast variables and blocks
        $source = $this->compileVariablesAndBlocks(self::$globalAssigns, self::$globalXAssigns);

        //remove unassigned variables
        $source = $this->removeUnassignedVariables($source);

        //compile php unsafe source
        $source = $this->compilePhpUnsafeSource($source);
        
        //compile comments
        $source = $this->compileComments($source);
        
        //compile if statements
        $source = $this->compileIfStatements($source);
        
        return $source;
    }
    
    //this method is used to load source for block template.
    //the main difference of this method is that no include
    //initialization and parameter initialization is required because
    //it is already done in root template
    private function loadBlockSource(string $source)
    {
        $this->assigns = [];
        $this->xassigns = [];
        $this->blocks = null;
        $this->source = $source;

        //initialize blocks
        $this->initializeBlocks();
    }

    private function compilePhpUnsafeSource(string $source) : string
    {
        //do not allow php indicators with '<script language='php'>'
        //HACK: support of this tag is removed from php7, however
        //we leave it in code, in case someone uses customized php parser
        $searchTokens = ['php', 'phP', 'pHp', 'pHP', 'Php', 'PhP', 'PHp', 'PHP'];
        $replaceTokens = [];
        foreach($searchTokens as $token)
        {
            $replaceTokens[] = self::$secretToken . $token;    
        }
        
        //do not allow php begin indicators
        $searchTokens[] = '<' . '?';
        $replaceTokens[] = '<' . "?php echo('<' . '?'); ?>";
        //
        $searchTokens[] = '<' . '%';
        $replaceTokens[] = '<' . "?php echo('<' . '%'); ?>";
        
        //perform source replace
        return str_replace($searchTokens, $replaceTokens, $source);
    }

    private function compileIfStatements(string $source) : string
    {
        //compile IF
        $source = preg_replace("/" . self::$secretToken . "<!-- IF (.+?) -->/ms", "<?php if(\$1) { ?>", $source);

        //compile ELSE IF
        $source = preg_replace("/" . self::$secretToken . "<!-- ELSE IF (.+?) -->/ms", "<?php } elseif(\$1) { ?>", $source);

        //compile ELSE
        $source = preg_replace("/" . self::$secretToken . "<!-- ELSE -->/ms", "<?php } else { ?>", $source);

        //compile END IF
        $source = preg_replace("/" . self::$secretToken . "<!-- END IF -->/ms", "<?php } ?>", $source);

        return $source;
    }
}

abstract class ValueFormatter 
{
    public abstract function formatValue(string $value) : string;
}

?>
