<?php

// +---------------------------------------------------------------------------+
// | Separate Template Engine Version 1.5.0 (http://separate.esud.info/)       |
// | Copyright 2004-2013 Eduard Sudnik                                         |
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

class SeparateTemplate
{
    public static $instance;
    public static $globalAssigns = array();
    public static $globalXAssigns = array();
    public static $filePath;
    public static $defaultFormatter = null;
    public static $parameters;

    protected $source;
    protected $assigns = array();
    protected $blocks = array();
    protected $xassigns = array();
    protected $blockAssigns = array();
    protected $blockXAssigns = array();
    protected $formatterCache = array(); //used for better performance

    //HACK: statement identifier is used to avoid PHP injection
    //which is possible using prepared statements like this:
    //<!-- IF true){} echo(time()); if(true --> ... <!-- END IF -->
    protected $statementIdentifier = null;

    public static function instance()
    {
        if(!self::$instance)
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function booleanToString($value)
    {
        if($value == 1 || $value == '1' || $value) {return 'TRUE';}
        return 'FALSE';
    }

    public static function setDefaultFormatter(AbstractValueFormatter $formatter)
    {
        self::$defaultFormatter = $formatter;
    }

    public static function getDefaultFormatter()
    {
        return self::$defaultFormatter;
    }

    public static function getParameters()
    {
        return self::$parameters;
    }

    public static function setParameterValue($name, $value)
    {
        self::$parameters[$name] = $value;
    }

    public static function getParameterValue($name)
    {
        if(!self::isParameterSet($name))
        {
            throw new Exception('Required parameter not found: ' . $name);
        }

        return self::$parameters[$name];
    }

    public static function isParameterSet($name)
    {
        if(array_key_exists($name, self::$parameters))
        {
            return true;
        }

        return false;
    }

    function __construct()
    {
        self::$globalAssigns = array();
        self::$globalXAssigns = array();
    }

    //checks if template variable is assigned using this priority:
    //1. Local assigns
    //2. Block assigns (of same template only)
    //3. Global assigns
    public function isAssigned($name)
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
    //1. Local fast assigns
    //2. Block fast assigns (of same template only)
    //3. Global fast assigns
    public function isXAssigned($name)
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

    public function loadSourceFromFile($filePath)
    {
        $this->source = null;
        $this->assigns = array();
        $this->xassigns = array();
        $this->blocks = null;
        self::$globalAssigns = array();
        self::$globalXAssigns = array();
        self::$filePath = null;

        //check file
        if(!is_file($filePath))
        {
            throw new Exception('Template file not found (File path: ' . $filePath . ')');
        }

        //file path
        self::$filePath = $filePath;

        //source
        $this->source = file_get_contents(self::$filePath);

        //file directory
        $directory = strrev(stristr(strrev(self::$filePath), '/'));

        //initialize includes
        $this->source = $this->initializeIncludes($this->source, $directory);

        //initialize parameters
        $this->initializeParameters();

        //create statement identifier
        $this->statementIdentifier = $this->createStatementIdentifier();

        //add statement identifier
        $this->source = $this->addStatementIdentifier($this->source);

        //initialize blocks
        $this->initializeBlocks();

        return self::instance();
    }

    public function fetch($blockName)
    {
        if(isset($this->blocks[$blockName]))
        {
            return clone $this->blocks[$blockName];
        }

        throw new Exception('Unable to fetch block for block name: ' . $blockName);
    }

    public function assign($name, $value, $reassign = false)
    {
        //if block is assigned
        if(is_object($value))
        {
            if(!($value instanceof SeparateTemplate))
            {
                throw new Exception('Invalid value assigned');
            }

            if($reassign == false)
            {
                $this->assigns[$name][] = clone $value;
            }
            else
            {
                $this->assigns[$name] = array();
                $this->assigns[$name][0] = clone $value;
            }
        }
        //if variable is assigned
        else
        {
            if($reassign == false)
            {
                $this->assigns[$name][] = $value;
            }
            else
            {
                $this->assigns[$name] = array();
                $this->assigns[$name][0] = $value;
            }
        }
    }

    public function xassign($name, $value) {$this->xassigns[$name] = $value;}

    public function assignForGlobal($name, $value) {self::$globalAssigns[$name] = $value;}

    public function xassignForGlobal($name, $value) {self::$globalXAssigns[$name] = $value;}

    public function assignForBlock($name, $value) {$this->blockAssigns[$name] = $value;}

    public function xassignForBlock($name, $value) {$this->blockXAssigns[$name] = $value;}

    public function getVariableNames($includeBlocksFlag = true)
    {
        $variables = array();

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

    public function getXVariableNames($includeBlocksFlag = true, $removeDuplicatesFlag = true)
    {
        $variables = array();

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

    public function getBlockNames($includeSubblocksFlag = true)
    {
        $blocks = array();

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

    public function display()
    {
        //get compiled source
        $source = $this->compile();

        //create HTML source
        ob_start();
        eval('?>' . $source);
        $compiledHtmlSource = ob_get_contents();
        ob_end_clean();
        
        //remove statement identifier
        //which was added in compilePhpUnsafeSource because of security
        $compiledHtmlSource = str_replace($this->statementIdentifier, '', $compiledHtmlSource);    

        //output HTML source
        echo($compiledHtmlSource);

        //return compiled HTML source
        return $compiledHtmlSource;
    }

    private function compilePhpUnsafeSource($source)
    {
        //do not allow php indicators with '<script language='php'>'
        $searchTokens = array('php', 'phP', 'pHp', 'pHP', 'Php', 'PhP', 'PHp', 'PHP');
        $replaceTokens = array();
        foreach($searchTokens as $token)
        {
            $replaceTokens[] = $this->statementIdentifier . $token;    
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

    private function compileComments($source)
    {
        //remove source comments
        return preg_replace("/<!---\s+(.+?)\s+--->/ms", "", $source);
    }

    private function compileIfStatements($source)
    {
        //compile IF
        $source = preg_replace("/" . $this->statementIdentifier . "<!-- IF (.+?) -->/ms", "<?php if(\$1) { ?>", $source);

        //compile ELSE IF
        $source = preg_replace("/" . $this->statementIdentifier . "<!-- ELSE IF (.+?) -->/ms", "<?php } elseif(\$1) { ?>", $source);

        //compile ELSE
        $source = preg_replace("/" . $this->statementIdentifier . "<!-- ELSE -->/ms", "<?php } else { ?>", $source);

        //compile END IF
        $source = preg_replace("/" . $this->statementIdentifier . "<!-- END IF -->/ms", "<?php } ?>", $source);

        return $source;
    }

    private function compileGlobalXAssigns($source)
    {
        $searches = array();
        $replaces = array();
        foreach(self::$globalXAssigns as $key => $value)
        {
            $searches[] = '#{' . $key . '}';
            $replaces[] = $value;
        }

        //return replaced source text
        return str_replace($searches, $replaces, $source);
    }

    private function addStatementIdentifier($source)
    {
        $source = str_replace('<!-- IF ', $this->statementIdentifier . '<!-- IF ', $source);
        $source = str_replace('<!-- ELSE IF ', $this->statementIdentifier . '<!-- ELSE IF ', $source);
        $source = str_replace('<!-- ELSE -->', $this->statementIdentifier . '<!-- ELSE -->', $source);
        $source = str_replace('<!-- END IF -->', $this->statementIdentifier . '<!-- END IF -->', $source);
        return $source;
    }

    private function includeAssigns($assigns)
    {
        if($assigns == null)
        {
            return;
        }

        foreach($assigns as $name => $value)
        {
            $this->assigns[$name] = array();
            $this->assigns[$name][0] = $value;
        }
    }

    private function compileValiablesAndBlocks($assigns = array(), $xassigns = array())
    {
        //include global assigns
        $this->includeAssigns(self::$globalAssigns);

        //merge triggered block assigns and block
        //assigns of this template to one array
        $blockAssigns = array_merge($this->blockAssigns, $assigns);

        //include triggered block assigns from parent
        //template and block assigns of this template
        $this->includeAssigns($blockAssigns);

        //merge triggered fast block assigns and fast block
        //assigns of this template to one array
        $blockXAssigns = array_merge($this->blockXAssigns, $xassigns);

        //include triggered fast block assigns from parent
        //template and fast block assigns of this template
        //HACK: no global fast assigns because they will be assigned once
        $this->xassigns = array_merge($this->xassigns, $blockXAssigns);

        //if nothing to assign
        if($this->assigns == null && $this->xassigns == null)
        {
            //return original source
            return $this->source;
        }

        $searches = array();
        $replaces = array();

        //handle normal assigns
        foreach($this->assigns as $key => $value)
        {
            $keyPattern = str_replace('[', '\[', str_replace(']', '\]', $key));

            //if current assign is used in the template source
            if(preg_match_all('(\${(\(([a-zA-Z0-9]+)\)|)' . $keyPattern . '})', $this->source, $matches))
            {
                //replace string
                $replace = '';

                //generate replace string for current key
                for($i = 0; $i < count($this->assigns[$key]); $i++)
                {
                    //is block assign
                    if(is_object($this->assigns[$key][$i]))
                    {
                        //add compiled block source using block assigns
                        $replace .= $this->assigns[$key][$i]->compileValiablesAndBlocks($blockAssigns, $blockXAssigns);
                    }
                    //is variable assign
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
                        $formatterClass = $matches[2][$i] . 'Formatter';
                        if(!isset($this->formatterCache[$formatterClass])) //if formatter instance is not cached
                        {
                            //create formatter instance and add it to cache
                            $this->formatterCache[$formatterClass] = new $formatterClass();
                        }

                        //format value using given formatter
                        $formattedReplace = $this->formatterCache[$formatterClass]->formatValue($replace);
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

                    $searches[] = $matches[0][$i];
                    $replaces[] = $formattedReplace;
                }
            }
        }

        //handle fast assigns
        foreach($this->xassigns as $key => $value)
        {
            $searches[] = '#{' . $key . '}';
            $replaces[] = $value;
        }

        //replace source text
        $this->source = str_replace($searches, $replaces, $this->source);

        return $this->source;
    }

    private function removeUnassignedVariables($source)
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
        $blockNames = array();
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
            $this->blocks[$blockName] = new SeparateTemplate();
            $this->blocks[$blockName]->loadBlockSource($this->determineBlockSource($this->source, $blockName));

            //replace block source with block variable
            $search = '/<!-- BEGIN (' . $blockName . ') -->(.*)<!-- END (' . $blockName . ') -->/ms';
            $replace = '${(Block)' . $blockName . '}';
            $this->source = preg_replace($search, $replace, $this->source);
        }
    }

    private function determineBlockName($sourceRow)
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

    private function determineBlockSource($source, $blockName)
    {
        $blockSource = substr($source, strpos($source, '<!-- BEGIN ' . $blockName . ' -->') + strlen('<!-- BEGIN ' . $blockName . ' -->'));
        $blockSource = substr($blockSource, 0, strpos($blockSource, '<!-- END ' . $blockName . ' -->'));
        return $blockSource;
    }

    private function initializeIncludes($source, $baseDirectory)
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
        self::$parameters = array();

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

    private function createStatementIdentifier()
    {
        return md5(uniqid(rand(), true));
    }
    
    private function compile()
    {
        //compile variables and fast variables (no global fast assigns)
        $source = $this->compileValiablesAndBlocks();

        //compile global fast variables
        $source = $this->compileGlobalXAssigns($source);

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
    
    //this method is used to load  source for block template.
    //the main difference of this method is that no include
    //initialization and parameter initialization is required because
    //it is already done in root template
    private function loadBlockSource($source)
    {
        $this->source = null;
        $this->assigns = array();
        $this->xassigns = array();
        $this->blocks = null;

        //source
        $this->source = $source;

        //initialize blocks
        $this->initializeBlocks();

        return self::instance();
    }
}

abstract class AbstractValueFormatter 
{
    public abstract function formatValue($value);
}

?>