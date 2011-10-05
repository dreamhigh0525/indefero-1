<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008-2011 Céondo Ltd and contributors.
#
# InDefero is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# InDefero is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

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
            $diffcontent = file_get_contents($difffile);
            $diff = new IDF_Diff($diffcontent, $diffprefix);
            $this->assertEquals(require_once($expectedfile),
                                $diff->parse(),
                                'parsed diff '.$difffile.' does not match');
        }
    }
}

