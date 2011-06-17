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

class IDF_Scm_MonotoneTest extends PHPUnit_Framework_TestCase
{
    private $proj = null;

    public function setUp()
    {
        $this->proj = new IDF_Project();
        $this->proj->id = 1;
        $this->proj->name = $this->proj->shortname = 'test';
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
        $repodir = DATADIR.'/'.__CLASS__.'/%s.mtn';
        $GLOBALS['_PX_config']['mtn_repositories'] = $repodir;
        $instance = $this->createMock();
        $this->assertEquals(335872, $instance->getRepositorySize());
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
        $instance = $this->createMock();

        $stdio = "4567890123456789012345678901234567890123\n";
        $instance->getStdio()->setExpectedOutput(array('select', '456'), array(), $stdio);

        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "main.branch"
    trust "trusted"

      key [aea5d3716d31281171aaecf3a7c227e5545b0504]
signature "ok"
     name "branch"
    value "feature.branch"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', '4567890123456789012345678901234567890123'), array(), $stdio);

        $out = $instance->inBranches('456', null);
        $this->assertEquals(array('h:main.branch', 'h:feature.branch'), $out);
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
        $instance = $this->createMock();

        $stdio = "3456789012345678901234567890123456789012\n";
        $instance->getStdio()->setExpectedOutput(array('select', '345'), array(), $stdio);

        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "tag"
    value "release-1.0rc"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "tag"
    value "release-1.0"
    trust "trusted"

      key [aea5d3716d31281171aaecf3a7c227e5545b0504]
signature "ok"
     name "tag"
    value "release-1.0"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', '3456789012345678901234567890123456789012'), array(), $stdio);

        $out = $instance->inTags('345', null);
        $this->assertEquals(array('t:release-1.0rc', 't:release-1.0'), $out);
    }

    public function testGetTree()
    {
        $instance = $this->createMock();
        //
        // non-existing revision
        //
        $this->assertEquals(array(), $instance->getTree('789'));

        $stdio = "7890123456789012345678901234567890123456\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:789'), array(), $stdio);

        $stdio =<<<END
      dir ""
    birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]

        file "NEWS"
     content [bf51bb66d1c1ffde9ed2fffe2e8c00942deeaa03]
        size "2104"
       birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
   path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
content_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]

      dir "doc"
    birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]

        file "doc/AUTHORS"
     content [de9ed2fffe2e8c0094bf51bb66d1c1ff2deeaa03]
        size "17024"
       birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
   path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
content_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
END;
        $instance->getStdio()->setExpectedOutput(array('get_extended_manifest_of', '7890123456789012345678901234567890123456'), array(), $stdio);

        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "author"
    value "joe@user.com"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "some.branch"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "changelog"
    value "initial revision"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "date"
    value "2011-01-24T00:00:23"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', '276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f'), array(), $stdio);

        //
        // root directory
        //
        $entries = $instance->getTree('t:789');
        $this->assertEquals(3, count($entries));

        $file = $entries[0];
        $this->assertEquals('', $file->fullpath);
        $this->assertEquals('', $file->efullpath);
        $this->assertEquals('', $file->file);
        $this->assertEquals('tree', $file->type);
        $this->assertEquals(0, $file->size);
        $this->assertEquals('276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f', $file->rev);
        $this->assertEquals('joe@user.com', $file->author);
        $this->assertEquals('2011-01-24 00:00:23', $file->date);
        $this->assertEquals('initial revision', $file->log);

        $file = $entries[1];
        $this->assertEquals('NEWS', $file->fullpath);
        $this->assertEquals('NEWS', $file->efullpath);
        $this->assertEquals('NEWS', $file->file);
        $this->assertEquals('blob', $file->type);
        $this->assertEquals(2104, $file->size);
        $this->assertEquals('bf51bb66d1c1ffde9ed2fffe2e8c00942deeaa03', $file->hash);
        $this->assertEquals('276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f', $file->rev);
        $this->assertEquals('joe@user.com', $file->author);
        $this->assertEquals('2011-01-24 00:00:23', $file->date);
        $this->assertEquals('initial revision', $file->log);

        $file = $entries[2];
        $this->assertEquals('doc', $file->fullpath);
        $this->assertEquals('doc', $file->efullpath);
        $this->assertEquals('doc', $file->file);
        $this->assertEquals('tree', $file->type);
        $this->assertEquals(0, $file->size);
        $this->assertEquals('276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f', $file->rev);
        $this->assertEquals('joe@user.com', $file->author);
        $this->assertEquals('2011-01-24 00:00:23', $file->date);
        $this->assertEquals('initial revision', $file->log);

        //
        // sub directory
        //
        $entries = $instance->getTree('t:789', 'doc');
        $this->assertEquals(1, count($entries));

        $file = $entries[0];
        $this->assertEquals('doc/AUTHORS', $file->fullpath);
        $this->assertEquals('doc/AUTHORS', $file->efullpath);
        $this->assertEquals('AUTHORS', $file->file);
        $this->assertEquals('blob', $file->type);
        $this->assertEquals(17024, $file->size);
        $this->assertEquals('de9ed2fffe2e8c0094bf51bb66d1c1ff2deeaa03', $file->hash);
        $this->assertEquals('276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f', $file->rev);
        $this->assertEquals('joe@user.com', $file->author);
        $this->assertEquals('2011-01-24 00:00:23', $file->date);
        $this->assertEquals('initial revision', $file->log);

        //
        // non-existing sub directory
        //
        $this->assertEquals(array(), $instance->getTree('t:789', 'foo'));
    }

    public function testFindAuthor()
    {
        $this->markTestSkipped('code under test should reside in IDF_Scm');
    }

    public function testGetAnonymousAccessUrl()
    {
        $this->markTestSkipped('cannot test this static method');
    }

    public function testGetAuthAccessUrl()
    {
        $this->markTestSkipped('cannot test this static method');
    }

    public function testFactory()
    {
        $this->markTestSkipped('cannot test this static method');
    }

    public function testValidateRevision()
    {
        $stdio = "\n";
        $instance = $this->createMock(array('select', 't:123'), array(), $stdio);
        $this->assertEquals(IDF_Scm::REVISION_INVALID, $instance->validateRevision('t:123'));

        $stdio = "1234567890123456789012345678901234567890\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:123'), array(), $stdio);
        $this->assertEquals(IDF_Scm::REVISION_VALID, $instance->validateRevision('t:123'));

        $stdio = "1234567890123456789012345678901234567890\n".
                 "1234567890123456789012345678901234567891\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:123'), array(), $stdio);
        $this->assertEquals(IDF_Scm::REVISION_AMBIGUOUS, $instance->validateRevision('t:123'));
    }

    public function testDisambiguateRevision()
    {
        $instance = $this->createMock();

        $stdio = "1234567890123456789012345678901234567890\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:123'), array(), $stdio);

        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "author"
    value "joe@user.com"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "main.branch"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "changelog"
    value "something changed"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "date"
    value "2011-03-19T13:59:47"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', '1234567890123456789012345678901234567890'), array(), $stdio);

        $ret = $instance->disambiguateRevision('t:123');
        $this->assertTrue(is_array($ret));
        $this->assertEquals(1, count($ret));
        $this->assertTrue($ret[0] instanceof stdClass);

        $this->assertEquals('joe@user.com', $ret[0]->author);
        $this->assertEquals('main.branch', $ret[0]->branch);
        $this->assertEquals('something changed', $ret[0]->title);
        $this->assertEquals('1234567890123456789012345678901234567890', $ret[0]->commit);
        $this->assertEquals('2011-03-19 13:59:47', $ret[0]->date);
    }

    public function testGetPathInfo()
    {
        $instance = $this->createMock();
        //
        // non-existing revision
        //
        $this->assertFalse($instance->getPathInfo('AUTHORS', 'foo'));

        $stdio = "1234567890123456789012345678901234567890\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:123'), array(), $stdio);

        $stdio =<<<END
      dir ""
    birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]

      dir "doc"
    birth [a10037b1aa8a905018b72e6bd96fb8f8475f0f65]
path_mark [a10037b1aa8a905018b72e6bd96fb8f8475f0f65]

        file "doc/AUTHORS"
     content [de9ed2fffe2e8c0094bf51bb66d1c1ff2deeaa03]
        size "17024"
       birth [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
   path_mark [276264b0b3f1e70fc1835a700e6e61bdbe4c3f2f]
content_mark [fdb579b6682d78fac24912e7a82a8209b9a54099]
END;
        $instance->getStdio()->setExpectedOutput(array('get_extended_manifest_of', '1234567890123456789012345678901234567890'), array(), $stdio);

        //
        // non-existing file
        //
        $this->assertFalse($instance->getPathInfo('foo', 't:123'));

        //
        // existing file
        //
        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "author"
    value "joe@user.com"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "some.branch"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "changelog"
    value "update the source paths
"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "date"
    value "2011-01-24T00:00:23"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', 'fdb579b6682d78fac24912e7a82a8209b9a54099'), array(), $stdio);

        $file = $instance->getPathInfo('doc/AUTHORS', 't:123');
        $this->assertEquals('doc/AUTHORS', $file->fullpath);
        $this->assertEquals('doc/AUTHORS', $file->efullpath);
        $this->assertEquals('de9ed2fffe2e8c0094bf51bb66d1c1ff2deeaa03', $file->hash);
        $this->assertEquals('AUTHORS', $file->file);
        $this->assertEquals('blob', $file->type);
        $this->assertEquals(17024, $file->size);
        $this->assertEquals('fdb579b6682d78fac24912e7a82a8209b9a54099', $file->rev);
        $this->assertEquals('joe@user.com', $file->author);
        $this->assertEquals('2011-01-24 00:00:23', $file->date);
        $this->assertEquals('update the source paths', $file->log);

        //
        // existing directory
        //
        $stdio =<<<END
      key [10b5b36b4aadc46c0a946b6e76e087ccdddf8b86]
signature "ok"
     name "author"
    value "mary@jane.com"
    trust "trusted"

      key [10b5b36b4aadc46c0a946b6e76e087ccdddf8b86]
signature "ok"
     name "branch"
    value "feature.branch"
    trust "trusted"

      key [10b5b36b4aadc46c0a946b6e76e087ccdddf8b86]
signature "ok"
     name "changelog"
    value "initial build working"
    trust "trusted"

      key [10b5b36b4aadc46c0a946b6e76e087ccdddf8b86]
signature "ok"
     name "date"
    value "2006-03-13T08:06:22"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', 'a10037b1aa8a905018b72e6bd96fb8f8475f0f65'), array(), $stdio);

        $file = $instance->getPathInfo('doc', 't:123');
        $this->assertEquals('doc', $file->fullpath);
        $this->assertEquals('doc', $file->efullpath);
        $this->assertEquals('doc', $file->file);
        $this->assertEquals('tree', $file->type);
        $this->assertEquals(0, $file->size);
        $this->assertEquals('a10037b1aa8a905018b72e6bd96fb8f8475f0f65', $file->rev);
        $this->assertEquals('mary@jane.com', $file->author);
        $this->assertEquals('2006-03-13 08:06:22', $file->date);
        $this->assertEquals('initial build working', $file->log);
    }

    public function testGetFile()
    {
        $instance = $this->createMock();
        $thrown = false;
        try
        {
            $instance->getFile(null, true);
        }
        catch (Pluf_Exception_NotImplemented $e)
        {
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $stdio = 'Foobar';
        $instance->getStdio()->setExpectedOutput(array('get_file', '1234567890123456789012345678901234567890'), array(), $stdio);

        $obj = new stdClass();
        $obj->hash = '1234567890123456789012345678901234567890';

        $this->assertEquals('Foobar', $instance->getFile($obj));
    }

    public function testGetChanges()
    {
        $instance = $this->createMock();

        $this->assertFalse($instance->getChanges('t:234'));

        $stdio = "2345678901234567890123456789012345678901\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:234'), array(), $stdio);

        $stdio =<<<END
format_version "1"

new_manifest [cd109f812792d6d3de50b2c6d3ba3dc230a5c309]

old_revision [3996c236cea1cde8e3be0b034b5d26a85378d718]

delete "old_dir"

delete "old_dir/old_file"

rename "dir_with_old_name"
    to "new_dir/dir_with_new_name"

add_dir "new_dir"

add_file "new_dir/new_file"
 content [da39a3ee5e6b4b0d3255bfef95601890afd80709]

patch "existing_file"
 from [da39a3ee5e6b4b0d3255bfef95601890afd80709]
   to [d53a205a336e07cf9eac45471b3870f9489288ec]

clear "new_dir/dir_with_new_name"
 attr "some-key"

  set "existing_file"
 attr "multi
line
key"
value "
and another
multiline
value"
END;
        $instance->getStdio()->setExpectedOutput(array('get_revision', '2345678901234567890123456789012345678901'), array(), $stdio);

        $expected = (object) array(
            'additions'  => array('new_dir', 'new_dir/new_file'),
            'deletions'  => array('old_dir', 'old_dir/old_file'),
            'renames'    => array('dir_with_old_name' => 'new_dir/dir_with_new_name'),
            'copies'     => array(), // this is always empty
            'patches'    => array('existing_file'),
            'properties' => array(
                'new_dir/dir_with_new_name' => array(
                    'some-key' => null,
                ),
                'existing_file' => array(
                    "multi\nline\nkey" => "\nand another\nmultiline\nvalue",
                ),
            ),
        );

        $this->assertEquals($expected, $instance->getChanges('t:234'));

        // FIXME: properly handle and test merge revisions (issue 581)
    }

    public function testGetCommit()
    {
        $instance = $this->createMock();

        $this->assertFalse($instance->getCommit('t:234'));

        $stdio = "2345678901234567890123456789012345678901\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:234'), array(), $stdio);

        $stdio = "1234567890123456789012345678901234567891\n".
                 "1234567890123456789012345678901234567892\n";
        $instance->getStdio()->setExpectedOutput(array('parents', '2345678901234567890123456789012345678901'), array(), $stdio);

        $stdio =<<<END
      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "author"
    value "joe@user.com"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "author"
    value "mary@jane.com"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "main.branch"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "branch"
    value "feature.branch"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "changelog"
    value "something changed"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "changelog"
    value "something changed here as
well, unbelievable!"
    trust "trusted"

      key [1aaecf3a7c227e5545b0504aea5d3716d3128117]
signature "ok"
     name "date"
    value "2011-03-19T13:59:47"
    trust "trusted"
END;
        $instance->getStdio()->setExpectedOutput(array('certs', '2345678901234567890123456789012345678901'), array(), $stdio);

        $commit = $instance->getCommit('t:234');

        $this->assertEquals('2345678901234567890123456789012345678901', $commit->commit);
        $this->assertEquals(array('1234567890123456789012345678901234567891',
                                  '1234567890123456789012345678901234567892'),
                            $commit->parents);
        $this->assertEquals('joe@user.com, mary@jane.com', $commit->author);
        $this->assertEquals('2011-03-19 13:59:47', $commit->date);
        $this->assertEquals('something changed', $commit->title);
        $this->assertEquals("---\nsomething changed here as\nwell, unbelievable!", $commit->full_message);
        $this->assertEquals('main.branch, feature.branch', $commit->branch);
        $this->assertEquals('', $commit->diff);
    }

    public function testGetProperties()
    {
        $rev = "2345678901234567890123456789012345678901";

        $instance = $this->createMock();
        $instance->getStdio()->setExpectedOutput(array('interface_version'), array(), '13.1');

        $stdio =<<<END
attr "foo" "bar"
state "unchanged"

attr "some new
line" "and more <weird>-
nesses"
END;
        $instance->getStdio()->setExpectedOutput(array('get_attributes', 'foo'), array('r' => $rev), $stdio);
        $res = $instance->getProperties($rev, 'foo');

        $this->assertEquals(2, count($res));
        $this->assertEquals(array(
          'foo' => 'bar',
          "some new\nline" => "and more <weird>-\nnesses"
        ), $res);
    }

    public function testGetExtraProperties()
    {
        $instance = $this->createMock();

        $this->assertEquals(array(), $instance->getExtraProperties(new stdClass()));

        $cobj = (object) array('parents' => array('1234567890123456789012345678901234567891'));

        $this->assertEquals(array('parents' => array('1234567890123456789012345678901234567891')),
                            $instance->getExtraProperties($cobj));
    }

    public function testIsCommitLarge()
    {
        $instance = $this->createMock();

        // kind of misleading, I know
        $this->assertFalse($instance->isCommitLarge('890'));

        $stdio = "8901234567890123456789012345678901234567\n";
        $instance->getStdio()->setExpectedOutput(array('select', 't:890'), array(), $stdio);

        $stdio =<<<END
format_version "1"

new_manifest [e3f7896021ae38ea2b5c9766b9dc0e71cffbcbc3]

old_revision [e4b7bfab4dae09770cf1b293d68bef34523fdaf5]

add_dir "foo"

add_file "bar"
 content [56635b977a83788bf17c8225e291feeb9342ef16]
END;
        $instance->getStdio()->setExpectedOutput(array('get_revision', '8901234567890123456789012345678901234567'), array(), $stdio);

        // easy case
        $this->assertFalse($instance->isCommitLarge('t:890'));

        // slightly more complex case
        $stdio =<<<END
format_version "1"

new_manifest [e3f7896021ae38ea2b5c9766b9dc0e71cffbcbc3]

old_revision [e4b7bfab4dae09770cf1b293d68bef34523fdaf5]


END;
        for ($i=0; $i<=100; ++$i) {
            if ($i % 2 == 0)
                $stdio .= 'add_file "foo'.$i.'"'."\n".
                          ' content [ae09770cf1b293d68bef34523fdaf5e4b7bfab4d]'."\n\n";
            else
                $stdio .= 'patch "foo'.$i.'"'."\n".
                          ' from [ae09770cf1b293d68bef34523fdaf5e4b7bfab4d]'."\n".
                          '   to [ef34523fdaf5e4bae09770cf1b293bfab4dd68b7]'."\n\n";
        }

        $instance->getStdio()->setExpectedOutput(array('get_revision', '8901234567890123456789012345678901234567'), array(), $stdio);

        $this->assertTrue($instance->isCommitLarge('t:890'));
    }

    public function testGetChangeLog()
    {
        // test with no commit, empty $n
        // test logging stops at unknown branches
        // test logging stops at $n
        $this->markTestIncomplete();
    }
}

