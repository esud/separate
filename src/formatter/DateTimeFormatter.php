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

class DateTimeFormatter extends AbstractValueFormatter
{
    public function formatValue($value)
    {
        $resultDate = null;
        $resultTime = null;
        
        //check if value is numeric
        if(!is_numeric($value))
        {
            //value cannot be formatted
            //in this case we return original value
            return $value;
        }
        
        //create human readable date
        $resultDate = date('d.m.Y', $value); 
        
        //create human readable time
        $resultTime = date('H:i', $value);
        
        return $resultDate . ' ' . $resultTime;
    }
}

?>