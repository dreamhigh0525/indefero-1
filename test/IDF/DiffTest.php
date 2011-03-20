<?php

include 'IDF/Diff.php';

class IDF_DiffTest extends PHPUnit_Framework_TestCase
{
    public function testParse()
    {
        $datadir = DATADIR.'/'.__CLASS__;

        foreach (glob($datadir.'/*.diff') as $difffile)
        {
            $diffprefix = 0;
            if (strpos($difffile, '-git-') != false || strpos($difffile, '-hg-') != false)
            {
                $diffprefix = 1;
            }

            $expectedfile = str_replace('.diff', '.expected', $difffile);
            $expectedcontent = @file_get_contents($expectedfile);

            $diffcontent = file_get_contents($difffile);
            $diff = new IDF_Diff($diffcontent, $diffprefix);
            $this->assertEquals(unserialize($expectedcontent),
                                $diff->parse(),
                                'parsed diff '.$difffile.' does not match');
        }
    }
}

