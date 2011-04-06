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

class MonotoneStdioMock implements IDF_Scm_Monotone_IStdio
{
    // unused
    public function __construct(IDF_Project $project) {}

    // unused
    public function start() {}

    // unused
    public function stop() {}

    private $outputMap = array();

    public function setExpectedOutput(array $args, array $options, $output)
    {
        @$this->outputMap[serialize($args)][serialize($options)] = $output;
    }

    public function exec(array $args, array $options = array())
    {
        $optoutputs = @$this->outputMap[serialize($args)];
        if ($optoutputs === null) {
            return false;
        }

        return @$optoutputs[serialize($options)];
    }

    // unused
    public function getLastOutOfBandOutput() {}
}

class IDF_Scm_Monotone_Test extends PHPUnit_Framework_TestCase
{
    private $proj = null;

    public function setUp()
    {
        $this->proj = new IDF_Project();
        $this->proj->id = 1;
        $this->proj->name = $this->proj->shortname = 'Test';
        $this->proj->create();

        $this->proj->getConf()->setVal('mtn_master_branch', 'master.branch');
    }

    public function tearDown()
    {
        $this->proj->delete();
    }

    public function createMock(array $args = array(), array $options = array(), $output = null)
    {
        $instance = new IDF_Scm_Monotone($this->proj, new MonotoneStdioMock($this->proj));
        if (count($args) > 0) {
            $instance->getStdio()->setExpectedOutput($args, $options, $output);
        }
        return $instance;
    }

    public function testGetStdio()
    {
        $instance = $this->createMock();
        $this->assertNotNull($instance->getStdio());
    }

    public function testGetRepositorySize()
    {
        $this->markTestSkipped('Cannot mock real repository file');
    }

    public function testIsAvailable()
    {
        $instance = $this->createMock(array('interface_version'), array(), '13.0');
        $this->assertTrue($instance->isAvailable());

        $instance->getStdio()->setExpectedOutput(array('interface_version'), array(), '12.7');
        $this->assertFalse($instance->isAvailable());

        $instance->getStdio()->setExpectedOutput(array('interface_version'), array(), 'foo');
        $this->assertFalse($instance->isAvailable());
    }

    public function testGetBranches()
    {
        $instance = $this->createMock(array('branches'), array(), "foo\nbar.baz");
        $this->assertEquals(array(
              'h:foo' => 'foo',
              'h:bar.baz' => 'bar.baz',
        ), $instance->getBranches());
    }

    public function testGetMainBranch()
    {
        $instance = $this->createMock();
        $this->assertEquals('master.branch', $instance->getMainBranch());
        $instance->project->getConf()->setVal('mtn_master_branch', '');
        $this->assertEquals('*', $instance->getMainBranch());
    }

    public function testGetArchiveStream()
    {
        $instance = $this->createMock(array('select', 'abc123'), array(), "1234567890123456789012345678901234567890\n");
        $ziprender = $instance->getArchiveStream('abc123');
        $this->assertTrue($ziprender instanceof IDF_Scm_Monotone_ZipRender);

        $thrown = false;
        try {
            $ziprender = $instance->getArchiveStream('foo');
        }
        catch (IDF_Scm_Exception $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testInBranches()
    {
        // returns the branches the given commit is in
    }

    public function testGetTags()
    {
        $stdio =<<<END
     tag "foo-1.0"
revision [5db0a3dfb923d050d096c7c63ab23592c7ebc4c3]
  signer [de84b575d5e47254393eba49dce9dc4db98ed42d]
branches "org.company.foo"

     tag "foo-1.1"
revision [a4a773ecc74c1b80a03c60f57c6cc7bec85fb2cf]
  signer [7fe029d85af4de40700778b9784ef488fac2c79c]
branches "org.company.foo"

     tag "bar-1.0"
revision [09a00eb14482dde8876436351c6c4392b1e7f0b1]
  signer [7fe029d85af4de40700778b9784ef488fac2c79c]
branches "org.company.bar" "org.company.bar.release-1.0"
END;
        $instance = $this->createMock(array('tags'), array(), $stdio);
        $this->assertEquals(array(
            't:foo-1.0' => 'foo-1.0',
            't:foo-1.1' => 'foo-1.1',
            't:bar-1.0' => 'bar-1.0',
        ), $instance->getTags());
    }

    public function testInTags()
    {
        // returns the tags that are attached to the given commit
    }

    public function testGetTree()
    {
        // test root and sub tree fetching
    }

    public function testFindAuthor()
    {
        $this->markTestSkipped('This functionality here should reside in IDF_Scm');
    }

    public function testGetAnonymousAccessUrl()
    {
        // test the generation of the anonymous remote URL
    }

    public function testGetAuthAccessUrl()
    {
        // test the generation of the authenticated remote URL (only really visible for SSH)
    }

    public function testFactory()
    {
        $this->markTestSkipped('Cannot mock real repository');
    }

    public function testValidateRevision()
    {
        // test valid, invalid and ambigious
    }

    public function testDisambiguateRevision()
    {
        // test for array of commit objects
    }

    public function testGetPathInfo()
    {
        // return the info (creation date, last commit, et cetera) for a single file and commit
    }

    public function testGetFile()
    {
        // test cmd_only and full file fetching
    }

    public function testGetChanges()
    {
        // test retrieving the changes of a specific revision
    }

    public function testGetCommit()
    {
        // test get commit information with and without a diff text
        // test multiple branches, dates, authors, aso
    }

    public function testGetExtraProperties()
    {
        // test array('parents' => array(rev1, rev2, ...)) or array() if root revision
    }

    public function testIsCommitLarge()
    {
        // test for true / false with commits with more than 100 changes
    }

    public function testGetChangeLog()
    {
        // test with no commit, empty $n
        // test logging stops at unknown branches
        // test logging stops at $n
    }
}

