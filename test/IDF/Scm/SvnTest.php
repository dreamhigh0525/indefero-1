<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2011 Céondo Ltd and contributors.
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

class IDF_Scm_SvnTest extends PHPUnit_Framework_TestCase
{
    private $proj = null;

    public function setUp()
    {
        $this->proj = new IDF_Project();
        $this->proj->id = 1;
        $this->proj->name = $this->proj->shortname = 'test';
        $this->proj->create();
    }

    public function tearDown()
    {
        $this->proj->delete();
    }

    public function createMock($reponame)
    {
        $repourl = 'file://'.DATADIR.'/'.__CLASS__.'/'.$reponame;
        $instance = new IDF_Scm_Svn($repourl, $this->proj);
        return $instance;
    }

    public function testAccessHistoryOfRenamedAndDeletedFiles()
    {
        $instance = $this->createMock(__FUNCTION__);
        $this->assertEquals('new-file', $instance->getPathInfo('new-file', 1)->fullpath);
        $this->assertEquals('alternate-name', $instance->getPathInfo('alternate-name', 2)->fullpath);
    }
}

